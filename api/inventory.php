<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/products_helper.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_admin_or_seller();

    $rows = $pdo->query(
        'SELECT id, name, price, category, icon, is_active, stock_quantity, sort_order
         FROM products ORDER BY sort_order ASC, name ASC'
    )->fetchAll();

    $totalUnits = 0;
    $lowStock = 0;
    foreach ($rows as &$row) {
        $qty = (int) $row['stock_quantity'];
        $totalUnits += $qty;
        if ($qty <= 5) {
            $lowStock++;
        }
        $row['stock_quantity'] = $qty;
    }
    unset($row);

    $groups = group_inventory_by_category($rows);
    json_response([
        'ok' => true,
        'summary' => [
            'product_count' => count($rows),
            'total_units' => $totalUnits,
            'low_stock_count' => $lowStock,
            'pastry_count' => count($groups['pastry']),
            'drink_count' => count($groups['drink']),
            'pastry_units' => array_sum(array_map(fn($p) => (int) $p['stock_quantity'], $groups['pastry'])),
            'drink_units' => array_sum(array_map(fn($p) => (int) $p['stock_quantity'], $groups['drink'])),
        ],
        'inventory' => $rows,
        'groups' => $groups,
    ]);
}

if ($method === 'PUT') {
    require_admin_or_seller();
    $body = read_json_body();
    $productId = (int) ($body['product_id'] ?? 0);

    if ($productId < 1) {
        json_error('product_id required');
    }

    if (array_key_exists('stock_quantity', $body)) {
        $qty = max(0, (int) $body['stock_quantity']);
        $pdo->prepare('UPDATE products SET stock_quantity = ? WHERE id = ?')->execute([$qty, $productId]);
    } elseif (array_key_exists('adjust_by', $body)) {
        $delta = (int) $body['adjust_by'];
        $pdo->prepare(
            'UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ?'
        )->execute([$delta, $productId]);
    } else {
        json_error('stock_quantity or adjust_by required');
    }

    $stmt = $pdo->prepare('SELECT id, name, stock_quantity FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) {
        json_error('Product not found', 404);
    }

    json_response(['ok' => true, 'product' => $product]);
}

json_error('Method not allowed', 405);
