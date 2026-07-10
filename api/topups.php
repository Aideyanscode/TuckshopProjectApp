<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_admin();
    $studentId = (int) ($_GET['student_id'] ?? 0);
    $sql = 'SELECT t.*, s.full_name, s.student_number FROM topups t
            JOIN students s ON s.id = t.student_id WHERE 1=1';
    $params = [];
    if ($studentId > 0) {
        $sql .= ' AND t.student_id = ?';
        $params[] = $studentId;
    }
    $sql .= ' ORDER BY t.created_at DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['ok' => true, 'topups' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    require_admin();
    $body = read_json_body();
    $studentId = (int) ($body['student_id'] ?? 0);
    $amount = (float) ($body['amount'] ?? 0);
    $methodType = $body['method'] ?? 'cash';
    $note = trim($body['reference_note'] ?? '');
    $recordedBy = trim($body['recorded_by'] ?? 'admin');

    if ($studentId < 1 || $amount <= 0) {
        json_error('student_id and positive amount required');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT balance FROM students WHERE id = ? FOR UPDATE');
        $stmt->execute([$studentId]);
        $bal = $stmt->fetchColumn();
        if ($bal === false) {
            $pdo->rollBack();
            json_error('Student not found', 404);
        }

        $newBal = (float) $bal + $amount;
        $pdo->prepare('UPDATE students SET balance = ? WHERE id = ?')->execute([$newBal, $studentId]);
        $pdo->prepare(
            'INSERT INTO topups (student_id, amount, method, reference_note, recorded_by) VALUES (?, ?, ?, ?, ?)'
        )->execute([$studentId, $amount, $methodType, $note ?: null, $recordedBy]);
        $pdo->commit();

        json_response([
            'ok' => true,
            'new_balance' => $newBal,
            'topup_id' => (int) $pdo->lastInsertId(),
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Top-up failed: ' . $e->getMessage(), 500);
    }
}

json_error('Method not allowed', 405);
