<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/paystack.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    json_error('GET required', 405);
}

require_parent();
$parent = get_parent_from_request();
$parentId = (int) $parent['id'];

$nfcUid = trim($_GET['nfc_uid'] ?? '');
if ($nfcUid !== '') {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.student_number, s.full_name, s.class_name, s.wallet_id, s.balance, s.nfc_uid
         FROM students s
         JOIN parent_students ps ON ps.student_id = s.id
         WHERE ps.parent_id = ? AND s.nfc_uid = ? AND s.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$parentId, $nfcUid]);
    $student = $stmt->fetch();
    if (!$student) {
        json_error('No linked student found with that NFC ID', 404);
    }
    json_response(['ok' => true, 'student' => $student]);
}

$stmt = $pdo->prepare(
    'SELECT s.id, s.student_number, s.full_name, s.class_name, s.wallet_id, s.balance, s.nfc_uid
     FROM students s
     JOIN parent_students ps ON ps.student_id = s.id
     WHERE ps.parent_id = ? AND s.is_active = 1
     ORDER BY s.full_name ASC'
);
$stmt->execute([$parentId]);
json_response(['ok' => true, 'students' => $stmt->fetchAll()]);
