<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $student = get_student_from_request();
    if (!$student) {
        json_error('Not logged in', 401);
    }
    json_response(['ok' => true, 'student' => $student]);
}

if ($method !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$studentNumber = trim((string) ($body['student_number'] ?? ''));

if ($studentNumber === '') {
    json_error('student_number required');
}

$stmt = db()->prepare(
    'SELECT id, student_number, full_name, class_name, wallet_id, balance
     FROM students
     WHERE student_number = ? AND is_active = 1
     LIMIT 1'
);
$stmt->execute([$studentNumber]);
$student = $stmt->fetch();

if (!$student) {
    json_error('Invalid student number', 401);
}

json_response([
    'ok' => true,
    'token' => $student['wallet_id'],
    'student' => $student,
]);
