<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_error('GET required', 405);
}

require_parent();
$parent = get_parent_from_request();
$parentId = (int) $parent['id'];

$studentId = (int) ($_GET['student_id'] ?? 0);
$limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));

if ($studentId > 0) {
    require_once dirname(__DIR__) . '/includes/paystack.php';
    if (!parent_linked_to_student($pdo, $parentId, $studentId)) {
        json_error('Student not linked to your account', 403);
    }
}

$sql = 'SELECT t.id, t.student_id, t.total_amount, t.balance_before, t.balance_after,
               t.status, t.created_at,
               s.full_name, s.class_name, s.student_number, s.nfc_uid
        FROM transactions t
        JOIN students s ON s.id = t.student_id
        JOIN parent_students ps ON ps.student_id = s.id
        WHERE ps.parent_id = ? AND t.status = ?';
$params = [$parentId, 'completed'];

if ($studentId > 0) {
    $sql .= ' AND t.student_id = ?';
    $params[] = $studentId;
}

$sql .= ' ORDER BY t.created_at DESC LIMIT ' . $limit;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

if (!empty($transactions)) {
    $ids = array_column($transactions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $itemsStmt = $pdo->prepare(
        "SELECT transaction_id, product_name, unit_price, quantity, line_total
         FROM transaction_items WHERE transaction_id IN ($placeholders)"
    );
    $itemsStmt->execute($ids);
    $itemsByTx = [];
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsByTx[$item['transaction_id']][] = $item;
    }
    foreach ($transactions as &$tx) {
        $tx['items'] = $itemsByTx[$tx['id']] ?? [];
    }
    unset($tx);
}

$topupSql = 'SELECT t.id, t.student_id, t.amount, t.method, t.reference_note, t.created_at,
                    s.full_name, s.student_number
             FROM topups t
             JOIN students s ON s.id = t.student_id
             JOIN parent_students ps ON ps.student_id = s.id
             WHERE ps.parent_id = ?';
$topupParams = [$parentId];
if ($studentId > 0) {
    $topupSql .= ' AND t.student_id = ?';
    $topupParams[] = $studentId;
}
$topupSql .= ' ORDER BY t.created_at DESC LIMIT ' . $limit;
$topupStmt = $pdo->prepare($topupSql);
$topupStmt->execute($topupParams);

json_response([
    'ok' => true,
    'transactions' => $transactions,
    'topups' => $topupStmt->fetchAll(),
]);
