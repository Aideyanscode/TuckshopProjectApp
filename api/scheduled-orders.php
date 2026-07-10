<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_parent();
$parent = get_parent_from_request();
$parentId = (int) $parent['id'];

if ($method === 'GET') {
    json_response([
        'ok' => true,
        'orders' => list_scheduled_orders_for_parent($pdo, $parentId),
    ]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $studentId = (int) ($body['student_id'] ?? 0);
    $scheduledDate = trim((string) ($body['scheduled_date'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];

    if ($studentId < 1) {
        json_error('student_id required');
    }
    if (!validate_scheduled_date($scheduledDate)) {
        json_error('Choose a valid future date');
    }
    if (!parent_can_order_for_student($pdo, $parentId, $studentId)) {
        json_error('Student not linked to your account', 403);
    }

    try {
        $order = create_scheduled_order(
            $pdo,
            $parentId,
            $studentId,
            $scheduledDate,
            $items,
            $notes,
            (string) $parent['full_name']
        );
        json_response(['ok' => true, 'order' => $order], 201);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

json_error('Method not allowed', 405);
