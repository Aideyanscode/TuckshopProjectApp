<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            json_error('Student not found', 404);
        }
        json_response(['ok' => true, 'student' => $row]);
    }

    $search = trim($_GET['q'] ?? '');
    $sql = 'SELECT id, student_number, full_name, class_name, wallet_id, balance, nfc_uid, is_active, created_at
            FROM students WHERE 1=1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (full_name LIKE ? OR student_number LIKE ? OR class_name LIKE ? OR nfc_uid LIKE ?)';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like];
    }
    $sql .= ' ORDER BY full_name ASC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['ok' => true, 'students' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    require_admin();
    $body = read_json_body();
    $name = trim($body['full_name'] ?? '');
    $class = trim($body['class_name'] ?? '');
    $number = trim($body['student_number'] ?? '');
    $nfcUid = trim($body['nfc_uid'] ?? '') ?: null;
    $initialBalance = (float) ($body['balance'] ?? 0);

    if ($name === '' || $class === '' || $number === '') {
        json_error('full_name, class_name, and student_number are required');
    }

    $walletId = $number;
    $stmt = $pdo->prepare(
        'INSERT INTO students (student_number, full_name, class_name, wallet_id, balance, nfc_uid)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$number, $name, $class, $walletId, $initialBalance, $nfcUid]);

    json_response([
        'ok' => true,
        'student_id' => (int) $pdo->lastInsertId(),
        'wallet_id' => $walletId,
    ], 201);
}

if ($method === 'PUT') {
    require_admin();
    $body = read_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        json_error('id required');
    }

    $fields = [];
    $params = [];
    foreach (['full_name', 'class_name', 'student_number', 'nfc_uid', 'is_active'] as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "$f = ?";
            $params[] = $body[$f];
        }
    }
    if (array_key_exists('student_number', $body)) {
        $fields[] = 'wallet_id = ?';
        $params[] = trim((string) $body['student_number']);
    }
    if (empty($fields)) {
        json_error('No fields to update');
    }
    $params[] = $id;
    $pdo->prepare('UPDATE students SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    json_response(['ok' => true]);
}

json_error('Method not allowed', 405);
