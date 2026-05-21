<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && isset($_GET['verify'])) {
    require_admin();
    json_response(['ok' => true, 'authenticated' => true]);
}

if ($method === 'GET') {
    $settings = get_settings($pdo);
    $terminals = $pdo->query('SELECT * FROM terminals WHERE is_active = 1 ORDER BY id')->fetchAll();
    json_response(['ok' => true, 'settings' => $settings, 'terminals' => $terminals]);
}

if ($method === 'PUT' || $method === 'POST') {
    require_admin();
    $body = read_json_body();
    $allowed = ['max_daily_spend', 'max_drinks_per_day', 'max_pastries_per_day', 'currency_symbol', 'school_name'];
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($allowed as $key) {
        if (array_key_exists($key, $body)) {
            $stmt->execute([$key, (string) $body[$key]]);
        }
    }
    json_response(['ok' => true, 'settings' => get_settings($pdo)]);
}

json_error('Method not allowed', 405);
