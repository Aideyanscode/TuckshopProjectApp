<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_error('GET required', 405);
}

require_admin_or_seller();

$date = $_GET['date'] ?? date('Y-m-d');
$studentId = (int) ($_GET['student_id'] ?? 0);
$terminalId = (int) ($_GET['terminal_id'] ?? 0);
$id = (int) ($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $pdo->prepare(
        'SELECT t.*, s.full_name, s.class_name, s.student_number, term.name AS terminal_name
         FROM transactions t
         JOIN students s ON s.id = t.student_id
         LEFT JOIN terminals term ON term.id = t.terminal_id
         WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $tx = $stmt->fetch();
    if (!$tx) {
        json_error('Transaction not found', 404);
    }
    $items = $pdo->prepare('SELECT * FROM transaction_items WHERE transaction_id = ?');
    $items->execute([$id]);
    $tx['items'] = $items->fetchAll();
    json_response(['ok' => true, 'transaction' => $tx]);
}

$sql = 'SELECT t.id, t.total_amount, t.balance_after, t.created_at, t.status,
               s.full_name, s.class_name, s.student_number,
               term.name AS terminal_name
        FROM transactions t
        JOIN students s ON s.id = t.student_id
        LEFT JOIN terminals term ON term.id = t.terminal_id
        WHERE t.status = ?';
$params = ['completed'];

if ($date !== 'all') {
    $sql .= ' AND DATE(t.created_at) = ?';
    $params[] = $date;
}
if ($studentId > 0) {
    $sql .= ' AND t.student_id = ?';
    $params[] = $studentId;
}
if ($terminalId > 0) {
    $sql .= ' AND t.terminal_id = ?';
    $params[] = $terminalId;
}

$sql .= ' ORDER BY t.created_at DESC LIMIT 1000';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalSales = array_sum(array_map(fn($r) => (float) $r['total_amount'], $rows));

json_response([
    'ok' => true,
    'date' => $date,
    'total_sales' => $totalSales,
    'count' => count($rows),
    'transactions' => $rows,
]);
