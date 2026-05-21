<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $seller = get_seller_from_request();
    if (!$seller) {
        json_error('Not logged in', 401);
    }
    json_response(['ok' => true, 'seller' => $seller]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$username = strtolower(trim($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($username === '' || $password === '') {
    json_error('username and password required');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM sellers WHERE username = ? AND is_active = 1');
$stmt->execute([$username]);
$seller = $stmt->fetch();

if (!$seller || !password_verify($password, $seller['password_hash'])) {
    json_error('Invalid username or password', 401);
}

$token = bin2hex(random_bytes(32));
$pdo->prepare('UPDATE sellers SET api_token = ? WHERE id = ?')->execute([$token, $seller['id']]);

json_response([
    'ok' => true,
    'token' => $token,
    'seller' => [
        'id' => (int) $seller['id'],
        'username' => $seller['username'],
        'full_name' => $seller['full_name'],
    ],
]);
