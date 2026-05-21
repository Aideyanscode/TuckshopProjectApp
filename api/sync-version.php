<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Method not allowed', 405);
}

$pdo = db();

function sync_hash(PDO $pdo, string $sql): string
{
    return (string) $pdo->query($sql)->fetchColumn();
}

$catalog = sync_hash(
    $pdo,
    "SELECT MD5(COALESCE(GROUP_CONCAT(
        CONCAT(id, ':', stock_quantity, ':', is_active, ':', ROUND(price, 2), ':', category, ':', name)
        ORDER BY id SEPARATOR '|'
     ), ''))
     FROM products WHERE category IN ('pastry', 'drink')"
);

$students = sync_hash(
    $pdo,
    "SELECT MD5(COALESCE(GROUP_CONCAT(
        CONCAT(id, ':', student_number, ':', balance, ':', COALESCE(nfc_uid, ''), ':', is_active)
        ORDER BY id SEPARATOR '|'
     ), ''))
     FROM students"
);

$sellers = sync_hash(
    $pdo,
    "SELECT MD5(COALESCE(GROUP_CONCAT(
        CONCAT(id, ':', username, ':', is_active)
        ORDER BY id SEPARATOR '|'
     ), ''))
     FROM sellers"
);

$transactions = sync_hash(
    $pdo,
    "SELECT MD5(CONCAT(COALESCE(MAX(id), 0), ':', COUNT(*)))
     FROM transactions"
);

$settings = sync_hash(
    $pdo,
    "SELECT MD5(COALESCE(GROUP_CONCAT(
        CONCAT(setting_key, ':', setting_value) ORDER BY setting_key SEPARATOR '|'
     ), ''))
     FROM settings"
);

json_response([
    'ok' => true,
    'catalog' => $catalog,
    'students' => $students,
    'sellers' => $sellers,
    'transactions' => $transactions,
    'settings' => $settings,
]);
