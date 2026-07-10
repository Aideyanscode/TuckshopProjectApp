<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/paystack.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_parent();
    json_response([
        'ok' => true,
        'configured' => paystack_configured(),
        'public_key' => paystack_public_key(),
    ]);
}

if ($method !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$action = $body['action'] ?? '';

if ($action === 'initialize') {
    require_parent();
    if (!paystack_configured()) {
        json_error('Paystack is not configured. Add your API keys in config.', 503);
    }

    $parent = get_parent_from_request();
    $parentId = (int) $parent['id'];
    $studentId = (int) ($body['student_id'] ?? 0);
    $nfcUid = trim($body['nfc_uid'] ?? '');
    $amount = round((float) ($body['amount'] ?? 0), 2);

    if ($amount < 100) {
        json_error('Minimum top-up is ₦100');
    }

    if ($studentId < 1 && $nfcUid !== '') {
        $stmt = $pdo->prepare(
            'SELECT s.id FROM students s
             JOIN parent_students ps ON ps.student_id = s.id
             WHERE ps.parent_id = ? AND s.nfc_uid = ? AND s.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$parentId, $nfcUid]);
        $found = $stmt->fetchColumn();
        if ($found === false) {
            json_error('No linked student found with that NFC ID', 404);
        }
        $studentId = (int) $found;
    }

    if ($studentId < 1) {
        json_error('student_id or nfc_uid required');
    }

    if (!parent_linked_to_student($pdo, $parentId, $studentId)) {
        json_error('Student not linked to your account', 403);
    }

    $stmt = $pdo->prepare('SELECT full_name, nfc_uid FROM students WHERE id = ? AND is_active = 1');
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        json_error('Student not found', 404);
    }

    $reference = paystack_generate_reference();
    $pdo->prepare(
        'INSERT INTO paystack_payments (parent_id, student_id, amount, reference, status)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$parentId, $studentId, $amount, $reference, 'pending']);

    json_response([
        'ok' => true,
        'reference' => $reference,
        'amount' => $amount,
        'amount_kobo' => (int) round($amount * 100),
        'public_key' => paystack_public_key(),
        'email' => $parent['email'],
        'student' => [
            'id' => $studentId,
            'full_name' => $student['full_name'],
            'nfc_uid' => $student['nfc_uid'],
        ],
    ]);
}

if ($action === 'verify') {
    require_parent();
    if (!paystack_configured()) {
        json_error('Paystack is not configured', 503);
    }

    $parent = get_parent_from_request();
    $parentId = (int) $parent['id'];
    $reference = trim($body['reference'] ?? '');

    if ($reference === '') {
        json_error('reference required');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM paystack_payments WHERE reference = ? AND parent_id = ? LIMIT 1'
    );
    $stmt->execute([$reference, $parentId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        json_error('Payment not found', 404);
    }

    if ($payment['status'] === 'success') {
        $balStmt = $pdo->prepare('SELECT balance FROM students WHERE id = ?');
        $balStmt->execute([(int) $payment['student_id']]);
        json_response([
            'ok' => true,
            'already_processed' => true,
            'new_balance' => (float) $balStmt->fetchColumn(),
            'amount' => (float) $payment['amount'],
        ]);
    }

    try {
        $result = paystack_verify_transaction($reference);
    } catch (Throwable $e) {
        json_error('Paystack verification failed: ' . $e->getMessage(), 502);
    }

    if (!($result['status'] ?? false)) {
        $pdo->prepare('UPDATE paystack_payments SET status = ? WHERE id = ?')
            ->execute(['failed', $payment['id']]);
        json_error($result['message'] ?? 'Paystack verification failed', 400);
    }

    $data = $result['data'] ?? [];
    if (($data['status'] ?? '') !== 'success') {
        $pdo->prepare('UPDATE paystack_payments SET status = ? WHERE id = ?')
            ->execute(['failed', $payment['id']]);
        json_error('Payment was not successful', 400);
    }

    $paidKobo = (int) ($data['amount'] ?? 0);
    $paidAmount = round($paidKobo / 100, 2);
    $expectedKobo = (int) round((float) $payment['amount'] * 100);

    if ($paidKobo !== $expectedKobo) {
        json_error('Paid amount does not match requested amount', 400);
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT status FROM paystack_payments WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $payment['id']]);
        $currentStatus = $lock->fetchColumn();
        if ($currentStatus === 'success') {
            $pdo->rollBack();
            $balStmt = $pdo->prepare('SELECT balance FROM students WHERE id = ?');
            $balStmt->execute([(int) $payment['student_id']]);
            json_response([
                'ok' => true,
                'already_processed' => true,
                'new_balance' => (float) $balStmt->fetchColumn(),
                'amount' => $paidAmount,
            ]);
        }

        $credit = credit_student_wallet_from_paystack(
            $pdo,
            (int) $payment['student_id'],
            $paidAmount,
            $reference,
            'parent:' . $parent['email']
        );

        $pdo->prepare(
            'UPDATE paystack_payments SET status = ?, paystack_reference = ?, topup_id = ?, completed_at = NOW()
             WHERE id = ?'
        )->execute(['success', $data['reference'] ?? $reference, $credit['topup_id'], $payment['id']]);

        $pdo->commit();

        json_response([
            'ok' => true,
            'amount' => $paidAmount,
            'new_balance' => $credit['new_balance'],
            'topup_id' => $credit['topup_id'],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error('Wallet update failed: ' . $e->getMessage(), 500);
    }
}

json_error('Unknown action', 400);
