<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

require_admin();

$pdo = db();
$date = $_GET['date'] ?? date('Y-m-d');
$terminalId = (int) ($_GET['terminal_id'] ?? 0);

$sql = 'SELECT t.id, t.created_at, t.total_amount, t.balance_before, t.balance_after,
               s.student_number, s.full_name, s.class_name,
               term.name AS terminal_name
        FROM transactions t
        JOIN students s ON s.id = t.student_id
        LEFT JOIN terminals term ON term.id = t.terminal_id
        WHERE t.status = ? AND DATE(t.created_at) = ?';
$params = ['completed', $date];

if ($terminalId > 0) {
    $sql .= ' AND t.terminal_id = ?';
    $params[] = $terminalId;
}
$sql .= ' ORDER BY t.created_at ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'tuckshop-daily-sales-' . $date . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Transaction ID', 'Date/Time', 'Student Number', 'Student Name', 'Class', 'Terminal', 'Amount', 'Balance Before', 'Balance After']);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['id'],
        $row['created_at'],
        $row['student_number'],
        $row['full_name'],
        $row['class_name'],
        $row['terminal_name'] ?? '',
        $row['total_amount'],
        $row['balance_before'],
        $row['balance_after'],
    ]);
}
fclose($out);
exit;
