<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_sync_token();

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'export'));
    if ($action !== 'export') {
        json_error('Unknown action', 400);
    }

    json_response([
        'ok' => true,
        'instance_name' => (string) app_config('order_sync.instance_name', 'local'),
        'orders' => export_pending_orders($pdo),
    ]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = trim((string) ($body['action'] ?? ''));

    if ($action === 'ack') {
        $orderUids = is_array($body['order_uids'] ?? null) ? $body['order_uids'] : [];
        json_response([
            'ok' => true,
            'acknowledged' => acknowledge_exported_orders($pdo, $orderUids),
        ]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
