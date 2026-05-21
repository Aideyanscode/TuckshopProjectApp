<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Method not allowed', 405);
}

$pdo = db();
$hash = $pdo->query(
    "SELECT MD5(COALESCE(GROUP_CONCAT(
        CONCAT(id, ':', stock_quantity, ':', is_active, ':', ROUND(price, 2), ':', category, ':', name)
        ORDER BY id SEPARATOR '|'
     ), ''))
     FROM products WHERE category IN ('pastry', 'drink')"
)->fetchColumn();

json_response(['ok' => true, 'version' => (string) $hash]);
