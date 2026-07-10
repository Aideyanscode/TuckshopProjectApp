<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

require_student();
$student = get_student_from_request();
$studentId = (int) $student['id'];
run_daily_sync_if_due($pdo);

if ($method === 'GET') {
    json_response([
        'ok' => true,
        'student' => get_student_from_request(),
        'orders' => list_scheduled_orders_for_student($pdo, $studentId),
    ]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $scheduledDate = trim((string) ($body['scheduled_date'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];

    if (!validate_scheduled_date($scheduledDate)) {
        json_error('Choose a valid future date');
    }

    try {
        $order = create_scheduled_order(
            $pdo,
            null,
            $studentId,
            $scheduledDate,
            $items,
            $notes,
            'Student self-service'
        );
        json_response(['ok' => true, 'order' => $order], 201);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

json_error('Method not allowed', 405);
