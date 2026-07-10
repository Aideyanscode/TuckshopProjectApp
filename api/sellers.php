<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_admin();
    $rows = $pdo->query(
        'SELECT id, username, full_name, is_active, created_at FROM sellers ORDER BY full_name ASC'
    )->fetchAll();
    json_response(['ok' => true, 'sellers' => $rows]);
}

if ($method === 'POST') {
    require_admin();
    $body = read_json_body();
    $username = strtolower(trim($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $fullName = trim($body['full_name'] ?? '');

    if ($username === '' || $password === '' || $fullName === '') {
        json_error('username, password, and full_name are required');
    }
    if (strlen($password) < 4) {
        json_error('Password must be at least 4 characters');
    }

    $check = $pdo->prepare('SELECT id FROM sellers WHERE username = ?');
    $check->execute([$username]);
    if ($check->fetch()) {
        json_error('Username already exists');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare(
        'INSERT INTO sellers (username, password_hash, full_name) VALUES (?, ?, ?)'
    )->execute([$username, $hash, $fullName]);

    json_response([
        'ok' => true,
        'seller_id' => (int) $pdo->lastInsertId(),
        'username' => $username,
    ], 201);
}

if ($method === 'PUT') {
    require_admin();
    $body = read_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        json_error('id required');
    }

    if (array_key_exists('is_active', $body)) {
        $pdo->prepare('UPDATE sellers SET is_active = ? WHERE id = ?')
            ->execute([(int) $body['is_active'], $id]);
    }

    if (!empty($body['password'])) {
        $hash = password_hash((string) $body['password'], PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE sellers SET password_hash = ?, api_token = NULL WHERE id = ?')
            ->execute([$hash, $id]);
    }

    if (!empty($body['full_name'])) {
        $pdo->prepare('UPDATE sellers SET full_name = ? WHERE id = ?')
            ->execute([trim($body['full_name']), $id]);
    }

    json_response(['ok' => true]);
}

json_error('Method not allowed', 405);
