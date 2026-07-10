<?php

declare(strict_types=1);

function paystack_secret_key(): string
{
    return (string) app_config('paystack.secret_key', '');
}

function paystack_public_key(): string
{
    return (string) app_config('paystack.public_key', '');
}

function paystack_configured(): bool
{
    $secret = paystack_secret_key();
    $public = paystack_public_key();
    return $secret !== '' && $public !== ''
        && !str_contains($secret, 'REPLACE_WITH')
        && !str_contains($public, 'REPLACE_WITH');
}

function paystack_request(string $method, string $path, ?array $body = null): array
{
    $secret = paystack_secret_key();
    if ($secret === '') {
        throw new RuntimeException('Paystack secret key not configured');
    }

    $url = 'https://api.paystack.co' . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize Paystack request');
    }

    $headers = [
        'Authorization: Bearer ' . $secret,
        'Content-Type: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? []));
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0 || $raw === false) {
        throw new RuntimeException('Paystack request failed: ' . $error);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid Paystack response');
    }

    return $data;
}

function paystack_verify_transaction(string $reference): array
{
    return paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));
}

function paystack_generate_reference(): string
{
    return 'TS-' . strtoupper(bin2hex(random_bytes(8)));
}

function parent_linked_to_student(PDO $pdo, int $parentId, int $studentId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1'
    );
    $stmt->execute([$parentId, $studentId]);
    return (bool) $stmt->fetchColumn();
}

function credit_student_wallet_from_paystack(
    PDO $pdo,
    int $studentId,
    float $amount,
    string $reference,
    string $recordedBy
): array {
    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT balance FROM students WHERE id = ? FOR UPDATE');
        $stmt->execute([$studentId]);
        $bal = $stmt->fetchColumn();
        if ($bal === false) {
            throw new RuntimeException('Student not found');
        }

        $newBal = (float) $bal + $amount;
        $pdo->prepare('UPDATE students SET balance = ? WHERE id = ?')->execute([$newBal, $studentId]);
        $pdo->prepare(
            'INSERT INTO topups (student_id, amount, method, reference_note, recorded_by) VALUES (?, ?, ?, ?, ?)'
        )->execute([$studentId, $amount, 'paystack', 'Paystack ref: ' . $reference, $recordedBy]);
        $topupId = (int) $pdo->lastInsertId();

        if ($ownsTx) {
            $pdo->commit();
        }

        return ['new_balance' => $newBal, 'topup_id' => $topupId];
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
