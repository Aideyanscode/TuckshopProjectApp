<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $uid = trim($_GET['uid'] ?? '');
    if ($uid === '') {
        json_error('NFC UID required');
    }

    $stmt = $pdo->prepare(
        'SELECT id, student_number, full_name, class_name, wallet_id, balance, nfc_uid
         FROM students WHERE nfc_uid = ? AND is_active = 1'
    );
    $stmt->execute([$uid]);
    $student = $stmt->fetch();

    if ($student) {
        $settings = get_settings($pdo);
        $today = date('Y-m-d');
        json_response([
            'ok' => true,
            'status' => 'linked',
            'student' => format_student($student, $pdo, $today, $settings),
        ]);
    }

    json_response([
        'ok' => true,
        'status' => 'unlinked',
        'uid' => $uid,
        'message' => 'New card — bind to a student in admin or below',
    ]);
}

if ($method === 'POST') {
    require_admin();
    $body = read_json_body();
    $uid = trim($body['nfc_uid'] ?? '');
    $studentId = (int) ($body['student_id'] ?? 0);

    if ($uid === '' || $studentId < 1) {
        json_error('nfc_uid and student_id required');
    }

    $check = $pdo->prepare('SELECT id, full_name FROM students WHERE nfc_uid = ? AND id != ?');
    $check->execute([$uid, $studentId]);
    if ($existing = $check->fetch()) {
        json_error('This card is already linked to ' . $existing['full_name']);
    }

    $pdo->prepare('UPDATE students SET nfc_uid = ? WHERE id = ?')->execute([$uid, $studentId]);
    $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    json_response([
        'ok' => true,
        'message' => 'Card linked successfully',
        'student' => format_student($student, $pdo, date('Y-m-d'), get_settings($pdo)),
    ]);
}

json_error('Method not allowed', 405);

function format_student(array $student, PDO $pdo, string $today, array $settings): array
{
    $dailySpent = student_daily_spend($pdo, (int) $student['id'], $today);
    $maxDaily = (float) ($settings['max_daily_spend'] ?? 0);

    return [
        'id' => (int) $student['id'],
        'student_number' => $student['student_number'],
        'full_name' => $student['full_name'],
        'class_name' => $student['class_name'],
        'wallet_id' => $student['wallet_id'],
        'balance' => (float) $student['balance'],
        'nfc_uid' => $student['nfc_uid'],
        'daily_spent' => $dailySpent,
        'daily_remaining' => $maxDaily > 0 ? max(0, $maxDaily - $dailySpent) : null,
    ];
}
