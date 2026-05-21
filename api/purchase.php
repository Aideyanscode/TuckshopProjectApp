<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/purchase.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$studentId = (int) ($body['student_id'] ?? 0);
$items = $body['items'] ?? [];
$terminalId = isset($body['terminal_id']) ? (int) $body['terminal_id'] : null;

if ($studentId < 1) {
    json_error('student_id required');
}

$result = process_purchase(db(), $studentId, $items, $terminalId ?: null);

if (!$result['ok']) {
    json_response($result, 400);
}

json_response($result);
