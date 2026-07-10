<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_admin_or_seller();

if ($method === 'GET') {
    $dailySync = run_daily_sync_if_due($pdo);
    $date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
    $status = trim((string) ($_GET['status'] ?? 'active'));
    $shouldSync = ($_GET['sync'] ?? '0') === '1';
    $syncResult = $dailySync;

    if ($shouldSync) {
        try {
            $syncResult = pull_remote_orders($pdo);
        } catch (Throwable $e) {
            $syncResult = ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    $sql = 'SELECT * FROM scheduled_orders WHERE scheduled_date = ?';
    $params = [$date];
    if ($status === 'active') {
        $sql .= " AND fulfillment_status IN ('pending', 'prepared')";
    } elseif ($status !== 'all') {
        $sql .= ' AND fulfillment_status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY created_at ASC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    attach_scheduled_order_items($pdo, $orders);

    $pendingCount = 0;
    $preparedCount = 0;
    foreach ($orders as $order) {
        if ($order['fulfillment_status'] === 'pending') {
            $pendingCount++;
        }
        if ($order['fulfillment_status'] === 'prepared') {
            $preparedCount++;
        }
    }

    json_response([
        'ok' => true,
        'date' => $date,
        'queue' => $orders,
        'pending_count' => $pendingCount,
        'prepared_count' => $preparedCount,
        'sync' => $syncResult,
    ]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = trim((string) ($body['action'] ?? ''));

    if ($action === 'update_status') {
        $id = (int) ($body['id'] ?? 0);
        $status = trim((string) ($body['status'] ?? ''));
        $allowed = ['pending', 'prepared', 'completed', 'cancelled'];
        if ($id < 1 || !in_array($status, $allowed, true)) {
            json_error('Valid id and status required');
        }

        $stmt = $pdo->prepare('UPDATE scheduled_orders SET fulfillment_status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        json_response(['ok' => true]);
    }

    if ($action === 'sync_pull') {
        try {
            json_response(['ok' => true, 'sync' => pull_remote_orders($pdo)]);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }
    }

    if ($action === 'serve') {
        $id = (int) ($body['id'] ?? 0);
        if ($id < 1) {
            json_error('Valid order id required');
        }

        try {
            json_response(archive_served_order_and_delete($pdo, $id));
        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
