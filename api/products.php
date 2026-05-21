<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/products_helper.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $activeOnly = ($_GET['active'] ?? '1') !== '0';
    $menuOnly = ($_GET['menu'] ?? '0') === '1';
    $sql = 'SELECT * FROM products WHERE 1=1';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    if ($menuOnly) {
        $sql .= " AND category IN ('pastry', 'drink')";
    }
    $sql .= ' ORDER BY category ASC, sort_order ASC, name ASC';
    $rows = $pdo->query($sql)->fetchAll();
    json_response([
        'ok' => true,
        'products' => $rows,
        'groups' => group_inventory_by_category($rows),
    ]);
}

if ($method === 'POST') {
    require_admin_or_seller();
    $body = read_json_body();
    $name = trim($body['name'] ?? '');
    $price = (float) ($body['price'] ?? 0);
    $category = normalize_product_category((string) ($body['category'] ?? 'pastry'));
    $icon = trim($body['icon'] ?? '') ?: default_icon_for_category($category);

    if ($name === '' || $price <= 0) {
        json_error('name and positive price required');
    }
    if (!is_valid_product_category($category)) {
        json_error('Category must be pastry or drink');
    }

    $stock = max(0, (int) ($body['stock_quantity'] ?? 0));
    $pdo->prepare(
        'INSERT INTO products (name, price, category, icon, sort_order, stock_quantity) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$name, $price, $category, $icon, (int) ($body['sort_order'] ?? 99), $stock]);

    json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'category' => $category], 201);
}

if ($method === 'PUT') {
    require_admin();
    $body = read_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        json_error('id required');
    }

    $category = normalize_product_category((string) ($body['category'] ?? 'pastry'));
    $fields = [
        $body['name'] ?? '',
        (float) ($body['price'] ?? 0),
        $category,
        trim($body['icon'] ?? '') ?: default_icon_for_category($category),
        (int) ($body['is_active'] ?? 1),
        (int) ($body['sort_order'] ?? 0),
    ];
    $sql = 'UPDATE products SET name = ?, price = ?, category = ?, icon = ?, is_active = ?, sort_order = ?';
    if (array_key_exists('stock_quantity', $body)) {
        $sql .= ', stock_quantity = ?';
        $fields[] = max(0, (int) $body['stock_quantity']);
    }
    $sql .= ' WHERE id = ?';
    $fields[] = $id;
    $pdo->prepare($sql)->execute($fields);
    json_response(['ok' => true]);
}

json_error('Method not allowed', 405);
