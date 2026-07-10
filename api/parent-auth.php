<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $parent = get_parent_from_request();
    if (!$parent) {
        json_error('Not logged in', 401);
    }
    json_response(['ok' => true, 'parent' => $parent]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$email = strtolower(trim($body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($email === '' || $password === '') {
    json_error('email and password required');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM parents WHERE email = ? AND is_active = 1');
$stmt->execute([$email]);
$parent = $stmt->fetch();

if (!$parent || !password_verify($password, $parent['password_hash'])) {
    json_error('Invalid email or password', 401);
}

$token = bin2hex(random_bytes(32));
$pdo->prepare('UPDATE parents SET api_token = ? WHERE id = ?')->execute([$token, $parent['id']]);

json_response([
    'ok' => true,
    'token' => $token,
    'parent' => [
        'id' => (int) $parent['id'],
        'email' => $parent['email'],
        'full_name' => $parent['full_name'],
    ],
]);
