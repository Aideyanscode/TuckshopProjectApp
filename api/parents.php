<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_admin();
    $rows = $pdo->query(
        'SELECT p.id, p.email, p.full_name, p.is_active, p.created_at
         FROM parents p ORDER BY p.full_name ASC'
    )->fetchAll();

    $linkStmt = $pdo->prepare(
        'SELECT s.id, s.student_number, s.full_name, s.class_name, s.nfc_uid
         FROM students s
         JOIN parent_students ps ON ps.student_id = s.id
         WHERE ps.parent_id = ?
         ORDER BY s.full_name ASC'
    );

    foreach ($rows as &$parent) {
        $linkStmt->execute([(int) $parent['id']]);
        $parent['students'] = $linkStmt->fetchAll();
    }
    unset($parent);

    json_response(['ok' => true, 'parents' => $rows]);
}

if ($method === 'POST') {
    require_admin();
    $body = read_json_body();
    $action = $body['action'] ?? 'create';

    if ($action === 'create') {
        $email = strtolower(trim($body['email'] ?? ''));
        $name = trim($body['full_name'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $name === '' || strlen($password) < 4) {
            json_error('email, full_name, and password (min 4 chars) required');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO parents (email, password_hash, full_name) VALUES (?, ?, ?)')
            ->execute([$email, $hash, $name]);

        json_response(['ok' => true, 'parent_id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($action === 'link') {
        $parentId = (int) ($body['parent_id'] ?? 0);
        $studentId = (int) ($body['student_id'] ?? 0);
        $studentRef = trim((string) ($body['student_ref'] ?? ''));
        if ($parentId < 1 || ($studentId < 1 && $studentRef === '')) {
            json_error('parent_id and student reference required');
        }

        $check = $pdo->prepare('SELECT id FROM parents WHERE id = ?');
        $check->execute([$parentId]);
        if (!$check->fetch()) {
            json_error('Parent not found', 404);
        }

        $student = null;
        if ($studentId > 0) {
            $check = $pdo->prepare('SELECT id FROM students WHERE id = ?');
            $check->execute([$studentId]);
            $student = $check->fetch();
        }

        if (!$student && $studentRef !== '') {
            $check = $pdo->prepare('SELECT id FROM students WHERE student_number = ?');
            $check->execute([$studentRef]);
            $student = $check->fetch();
        }

        if (!$student) {
            json_error('Student not found', 404);
        }

        $studentId = (int) $student['id'];

        $pdo->prepare('INSERT IGNORE INTO parent_students (parent_id, student_id) VALUES (?, ?)')
            ->execute([$parentId, $studentId]);

        json_response(['ok' => true]);
    }

    json_error('Unknown action', 400);
}

if ($method === 'PUT') {
    require_admin();
    $body = read_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        json_error('id required');
    }

    if (array_key_exists('unlink_student_id', $body)) {
        $studentId = (int) $body['unlink_student_id'];
        $pdo->prepare('DELETE FROM parent_students WHERE parent_id = ? AND student_id = ?')
            ->execute([$id, $studentId]);
        json_response(['ok' => true]);
    }

    $fields = [];
    $params = [];
    if (array_key_exists('full_name', $body)) {
        $fields[] = 'full_name = ?';
        $params[] = trim((string) $body['full_name']);
    }
    if (array_key_exists('is_active', $body)) {
        $fields[] = 'is_active = ?';
        $params[] = (int) $body['is_active'];
    }
    if (!empty($body['password'])) {
        $fields[] = 'password_hash = ?';
        $params[] = password_hash((string) $body['password'], PASSWORD_DEFAULT);
        $fields[] = 'api_token = NULL';
    }

    if (empty($fields)) {
        json_error('No fields to update');
    }

    $params[] = $id;
    $pdo->prepare('UPDATE parents SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    json_response(['ok' => true]);
}

json_error('Method not allowed', 405);
