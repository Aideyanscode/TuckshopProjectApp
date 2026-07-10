<?php

declare(strict_types=1);

require_once __DIR__ . '/api.php';

function generate_order_uid(): string
{
    return 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function validate_scheduled_date(string $date): bool
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return false;
    }

    $today = new DateTimeImmutable('today');
    return $dt > $today;
}

function parent_can_order_for_student(PDO $pdo, int $parentId, int $studentId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1');
    $stmt->execute([$parentId, $studentId]);
    return (bool) $stmt->fetchColumn();
}

function normalize_scheduled_order_items(PDO $pdo, array $items, bool $lockProducts = false): array
{
    if (empty($items)) {
        throw new RuntimeException('Select at least one item');
    }

    $normalized = [];
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = max(0, (int) ($item['quantity'] ?? 0));
        if ($productId < 1 || $qty < 1) {
            continue;
        }
        $normalized[$productId] = ($normalized[$productId] ?? 0) + $qty;
    }

    if (empty($normalized)) {
        throw new RuntimeException('Select at least one item');
    }

    $productIds = array_keys($normalized);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $sql = "SELECT id, name, price, category, is_active, stock_quantity FROM products
            WHERE id IN ($placeholders) AND category IN ('pastry', 'drink')";
    if ($lockProducts) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($productIds);

    $products = [];
    foreach ($stmt->fetchAll() as $product) {
        $products[(int) $product['id']] = $product;
    }

    $lineItems = [];
    $total = 0.0;
    foreach ($normalized as $productId => $qty) {
        if (!isset($products[$productId]) || (int) $products[$productId]['is_active'] !== 1) {
            throw new RuntimeException('One or more selected items are no longer available');
        }

        $product = $products[$productId];
        $stock = (int) ($product['stock_quantity'] ?? 0);
        if ($stock < $qty) {
            throw new RuntimeException(sprintf('Not enough stock for %s (only %d left)', $product['name'], $stock));
        }

        $lineTotal = (float) $product['price'] * $qty;
        $total += $lineTotal;
        $lineItems[] = [
            'product_id' => $productId,
            'product_name' => $product['name'],
            'category' => $product['category'],
            'unit_price' => (float) $product['price'],
            'quantity' => $qty,
            'line_total' => $lineTotal,
        ];
    }

    return ['items' => $lineItems, 'total' => $total];
}

function create_scheduled_order(
    PDO $pdo,
    ?int $parentId,
    int $studentId,
    string $scheduledDate,
    array $items,
    string $notes = '',
    ?string $parentName = null,
    ?string $originInstance = null,
    ?string $orderUid = null,
    ?string $syncStatus = null
): array {
    $pdo->beginTransaction();
    try {
        $studentStmt = $pdo->prepare(
            'SELECT id, student_number, full_name, class_name, balance
             FROM students
             WHERE id = ? AND is_active = 1
             FOR UPDATE'
        );
        $studentStmt->execute([$studentId]);
        $student = $studentStmt->fetch();
        if (!$student) {
            throw new RuntimeException('Student not found');
        }

        $normalized = normalize_scheduled_order_items($pdo, $items, true);
        $lineItems = $normalized['items'];
        $total = $normalized['total'];

        $balance = (float) $student['balance'];
        if ($balance < $total) {
            throw new RuntimeException(
                sprintf(
                    'Insufficient wallet balance. You need %s but have %s.',
                    number_format($total, 0),
                    number_format($balance, 0)
                )
            );
        }

        if ($parentId !== null && $parentName === null) {
            $parentStmt = $pdo->prepare('SELECT full_name FROM parents WHERE id = ?');
            $parentStmt->execute([$parentId]);
            $parentName = (string) ($parentStmt->fetchColumn() ?: 'Parent');
        }

        $orderUid = $orderUid ?: generate_order_uid();
        $originInstance = $originInstance ?: (string) app_config('order_sync.instance_name', 'local');
        $syncStatus = $syncStatus ?: ((bool) app_config('order_sync.export_orders', false) ? 'pending' : 'not_required');
        $newBalance = $balance - $total;

        $stmt = $pdo->prepare(
            'INSERT INTO scheduled_orders (
                order_uid, parent_id, student_id, student_number, student_name, class_name, parent_name,
                scheduled_date, notes, total_amount, fulfillment_status, sync_status, origin_instance
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderUid,
            $parentId,
            $studentId,
            $student['student_number'],
            $student['full_name'],
            $student['class_name'],
            $parentName ?: 'Student self-service',
            $scheduledDate,
            $notes !== '' ? $notes : null,
            $total,
            'pending',
            $syncStatus,
            $originInstance,
        ]);

        $orderId = (int) $pdo->lastInsertId();
        $itemStmt = $pdo->prepare(
            'INSERT INTO scheduled_order_items
             (scheduled_order_id, product_id, product_name, category, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lineItems as $item) {
            $itemStmt->execute([
                $orderId,
                $item['product_id'],
                $item['product_name'],
                $item['category'],
                $item['unit_price'],
                $item['quantity'],
                $item['line_total'],
            ]);
            $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?')
                ->execute([$item['quantity'], $item['product_id']]);
        }

        $pdo->prepare('UPDATE students SET balance = ? WHERE id = ?')->execute([$newBalance, $studentId]);
        $pdo->commit();

        return [
            'id' => $orderId,
            'order_uid' => $orderUid,
            'student_name' => $student['full_name'],
            'student_number' => $student['student_number'],
            'class_name' => $student['class_name'],
            'parent_name' => $parentName ?: 'Student self-service',
            'scheduled_date' => $scheduledDate,
            'notes' => $notes,
            'total_amount' => $total,
            'balance_after' => $newBalance,
            'fulfillment_status' => 'pending',
            'sync_status' => $syncStatus,
            'origin_instance' => $originInstance,
            'items' => $lineItems,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function list_scheduled_orders_for_parent(PDO $pdo, int $parentId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM scheduled_orders
         WHERE parent_id = ?
         ORDER BY scheduled_date ASC, created_at ASC'
    );
    $stmt->execute([$parentId]);
    $orders = $stmt->fetchAll();

    attach_scheduled_order_items($pdo, $orders);
    return $orders;
}

function list_scheduled_orders_for_student(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM scheduled_orders
         WHERE student_id = ?
         ORDER BY scheduled_date ASC, created_at ASC'
    );
    $stmt->execute([$studentId]);
    $orders = $stmt->fetchAll();

    attach_scheduled_order_items($pdo, $orders);
    return $orders;
}

function attach_scheduled_order_items(PDO $pdo, array &$orders): void
{
    if (empty($orders)) {
        return;
    }

    $ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT scheduled_order_id, product_name, category, unit_price, quantity, line_total
         FROM scheduled_order_items
         WHERE scheduled_order_id IN ($placeholders)
         ORDER BY id ASC"
    );
    $stmt->execute($ids);

    $itemsByOrder = [];
    foreach ($stmt->fetchAll() as $item) {
        $itemsByOrder[$item['scheduled_order_id']][] = $item;
    }

    foreach ($orders as &$order) {
        $order['items'] = $itemsByOrder[$order['id']] ?? [];
    }
    unset($order);
}

function require_sync_token(): void
{
    $expected = trim((string) app_config('order_sync.shared_token', ''));
    $provided = trim($_SERVER['HTTP_X_SYNC_TOKEN'] ?? ($_GET['sync_token'] ?? ''));
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        json_error('Invalid sync token', 401);
    }
}

function remote_json_request(string $method, string $url, string $token, ?array $body = null): array
{
    $headers = [
        'Content-Type: application/json',
        'X-Sync-Token: ' . $token,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException($error !== '' ? $error : 'Remote sync request failed');
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'timeout' => 15,
            'header' => implode("\r\n", $headers),
            'content' => $body !== null ? json_encode($body) : '',
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Remote sync request failed');
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function export_pending_orders(PDO $pdo, int $limit = 100): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM scheduled_orders
         WHERE sync_status = 'pending' AND fulfillment_status <> 'cancelled'
         ORDER BY created_at ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();
    attach_scheduled_order_items($pdo, $orders);
    return $orders;
}

function acknowledge_exported_orders(PDO $pdo, array $orderUids): int
{
    $orderUids = array_values(array_filter(array_map('strval', $orderUids)));
    if (empty($orderUids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($orderUids), '?'));
    $stmt = $pdo->prepare(
        "UPDATE scheduled_orders
         SET sync_status = 'synced', synced_at = NOW()
         WHERE order_uid IN ($placeholders)"
    );
    $stmt->execute($orderUids);
    return $stmt->rowCount();
}

function import_remote_orders(PDO $pdo, array $orders): array
{
    $imported = 0;
    $skipped = 0;
    $acked = [];

    foreach ($orders as $order) {
        $orderUid = trim((string) ($order['order_uid'] ?? ''));
        if ($orderUid === '') {
            continue;
        }

        $existsStmt = $pdo->prepare('SELECT id FROM scheduled_orders WHERE order_uid = ? LIMIT 1');
        $existsStmt->execute([$orderUid]);
        if ($existsStmt->fetchColumn()) {
            $skipped++;
            $acked[] = $orderUid;
            continue;
        }

        $studentNumber = trim((string) ($order['student_number'] ?? ''));
        $studentStmt = $pdo->prepare('SELECT id, full_name, class_name FROM students WHERE student_number = ? LIMIT 1');
        $studentStmt->execute([$studentNumber]);
        $student = $studentStmt->fetch();
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        if (empty($items)) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $productName = trim((string) ($item['product_name'] ?? ''));
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                if ($productName === '') {
                    continue;
                }

                $productStmt = $pdo->prepare(
                    "SELECT id, stock_quantity FROM products
                     WHERE name = ? AND category IN ('pastry', 'drink') AND is_active = 1
                     LIMIT 1 FOR UPDATE"
                );
                $productStmt->execute([$productName]);
                $product = $productStmt->fetch();
                if (!$product) {
                    throw new RuntimeException('Product missing on local server: ' . $productName);
                }
                $stock = (int) ($product['stock_quantity'] ?? 0);
                if ($stock < $qty) {
                    throw new RuntimeException('Not enough local stock for synced order: ' . $productName);
                }
                $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?')
                    ->execute([$qty, (int) $product['id']]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO scheduled_orders (
                    order_uid, parent_id, student_id, student_number, student_name, class_name, parent_name,
                    scheduled_date, notes, total_amount, fulfillment_status, sync_status, origin_instance, synced_at
                 ) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $orderUid,
                $student ? (int) $student['id'] : null,
                $studentNumber,
                $student['full_name'] ?? (string) ($order['student_name'] ?? 'Unknown student'),
                $student['class_name'] ?? (string) ($order['class_name'] ?? ''),
                (string) ($order['parent_name'] ?? 'Student self-service'),
                (string) ($order['scheduled_date'] ?? date('Y-m-d')),
                trim((string) ($order['notes'] ?? '')) ?: null,
                (float) ($order['total_amount'] ?? 0),
                (string) ($order['fulfillment_status'] ?? 'pending'),
                'synced',
                (string) ($order['origin_instance'] ?? 'remote'),
            ]);

            $newOrderId = (int) $pdo->lastInsertId();
            $itemStmt = $pdo->prepare(
                'INSERT INTO scheduled_order_items
                 (scheduled_order_id, product_id, product_name, category, unit_price, quantity, line_total)
                 VALUES (?, NULL, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $itemStmt->execute([
                    $newOrderId,
                    (string) ($item['product_name'] ?? 'Item'),
                    (string) ($item['category'] ?? 'pastry'),
                    (float) ($item['unit_price'] ?? 0),
                    max(1, (int) ($item['quantity'] ?? 1)),
                    (float) ($item['line_total'] ?? 0),
                ]);
            }

            $pdo->commit();
            $imported++;
            $acked[] = $orderUid;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'acked' => $acked];
}

function pull_remote_orders(PDO $pdo): array
{
    if (!(bool) app_config('order_sync.pull_remote_orders', false)) {
        return ['ok' => false, 'message' => 'Remote pull is disabled'];
    }

    $baseUrl = rtrim((string) app_config('order_sync.remote_base_url', ''), '/');
    $token = trim((string) app_config('order_sync.shared_token', ''));
    if ($baseUrl === '' || $token === '') {
        return ['ok' => false, 'message' => 'Order sync is not configured'];
    }

    $exportUrl = $baseUrl . '/order-sync.php?action=export';
    $payload = remote_json_request('GET', $exportUrl, $token);
    $orders = is_array($payload['orders'] ?? null) ? $payload['orders'] : [];

    $result = import_remote_orders($pdo, $orders);
    if (!empty($result['acked'])) {
        remote_json_request('POST', $baseUrl . '/order-sync.php', $token, [
            'action' => 'ack',
            'order_uids' => $result['acked'],
        ]);
    }

    return [
        'ok' => true,
        'fetched' => count($orders),
        'imported' => $result['imported'],
        'skipped' => $result['skipped'],
    ];
}

function export_inventory_snapshot(PDO $pdo): array
{
    return $pdo->query(
        "SELECT name, category, stock_quantity
         FROM products
         WHERE category IN ('pastry', 'drink')"
    )->fetchAll();
}

function apply_inventory_snapshot(PDO $pdo, array $inventory): int
{
    $updated = 0;
    $stmt = $pdo->prepare(
        "UPDATE products
         SET stock_quantity = ?
         WHERE name = ? AND category = ? AND category IN ('pastry', 'drink')"
    );

    foreach ($inventory as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $category = trim((string) ($row['category'] ?? ''));
        $stock = max(0, (int) ($row['stock_quantity'] ?? 0));
        if ($name === '' || $category === '') {
            continue;
        }
        $stmt->execute([$stock, $name, $category]);
        $updated += $stmt->rowCount();
    }

    return $updated;
}

function push_inventory_snapshot(PDO $pdo): array
{
    if (!(bool) app_config('order_sync.push_inventory_snapshot', false)) {
        return ['ok' => false, 'message' => 'Inventory push is disabled'];
    }

    $baseUrl = rtrim((string) app_config('order_sync.remote_base_url', ''), '/');
    $token = trim((string) app_config('order_sync.shared_token', ''));
    if ($baseUrl === '' || $token === '') {
        return ['ok' => false, 'message' => 'Inventory sync is not configured'];
    }

    $inventory = export_inventory_snapshot($pdo);
    $result = remote_json_request('POST', $baseUrl . '/inventory-sync.php', $token, [
        'inventory' => $inventory,
    ]);

    return [
        'ok' => true,
        'pushed' => count($inventory),
        'updated_remote' => (int) ($result['updated'] ?? 0),
    ];
}

function attempt_inventory_push(PDO $pdo): void
{
    try {
        push_inventory_snapshot($pdo);
    } catch (Throwable $e) {
        // Best effort only; daily sync will reconcile later.
    }
}

function run_daily_sync_if_due(PDO $pdo): ?array
{
    $hour = (int) app_config('order_sync.daily_sync_hour', 15);
    $now = new DateTimeImmutable('now');
    if ((int) $now->format('G') < $hour) {
        return null;
    }

    $today = $now->format('Y-m-d');
    $lastRun = get_setting($pdo, 'last_daily_sync_date', '');
    if ($lastRun === $today) {
        return null;
    }

    $results = [];
    try {
        if ((bool) app_config('order_sync.pull_remote_orders', false)) {
            $results['orders'] = pull_remote_orders($pdo);
        }
        if ((bool) app_config('order_sync.push_inventory_snapshot', false)) {
            $results['inventory'] = push_inventory_snapshot($pdo);
        }
    } finally {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['last_daily_sync_date', $today]);
    }

    return $results;
}

function archive_served_order_and_delete(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM scheduled_orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Queue order not found');
    }

    $itemsStmt = $pdo->prepare(
        'SELECT product_name, category, quantity, unit_price, line_total
         FROM scheduled_order_items
         WHERE scheduled_order_id = ?
         ORDER BY id ASC'
    );
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll();

    $dir = dirname(__DIR__) . '/storage/served-orders';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create served-orders archive directory');
    }

    $csvPath = $dir . '/served-orders-' . date('Y-m-d') . '.csv';
    $isNew = !file_exists($csvPath);
    $fh = fopen($csvPath, 'ab');
    if (!$fh) {
        throw new RuntimeException('Unable to write served order archive');
    }

    if ($isNew) {
        fputcsv($fh, [
            'served_at',
            'scheduled_date',
            'student_number',
            'student_name',
            'class_name',
            'notes',
            'item_name',
            'category',
            'quantity',
            'unit_price',
            'line_total',
            'order_total',
            'origin_instance',
        ]);
    }

    $servedAt = date('c');
    foreach ($items as $item) {
        fputcsv($fh, [
            $servedAt,
            $order['scheduled_date'],
            $order['student_number'],
            $order['student_name'],
            $order['class_name'],
            $order['notes'] ?? '',
            $item['product_name'],
            $item['category'],
            $item['quantity'],
            $item['unit_price'],
            $item['line_total'],
            $order['total_amount'],
            $order['origin_instance'],
        ]);
    }
    fclose($fh);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM scheduled_order_items WHERE scheduled_order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM scheduled_orders WHERE id = ?')->execute([$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'ok' => true,
        'csv_path' => $csvPath,
        'student_name' => $order['student_name'],
    ];
}
