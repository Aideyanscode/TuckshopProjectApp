<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_sync_token();

if ($method === 'GET') {
    json_response([
        'ok' => true,
        'instance_name' => (string) app_config('order_sync.instance_name', 'local'),
        'inventory' => export_inventory_snapshot($pdo),
    ]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $inventory = is_array($body['inventory'] ?? null) ? $body['inventory'] : [];
    json_response([
        'ok' => true,
        'updated' => apply_inventory_snapshot($pdo, $inventory),
    ]);
}

json_error('Method not allowed', 405);
