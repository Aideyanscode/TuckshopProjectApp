<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Process a purchase. Returns ['ok' => true, 'transaction' => ...] or ['ok' => false, 'error' => ...]
 */
function process_purchase(PDO $pdo, int $studentId, array $items, ?int $terminalId): array
{
    if (empty($items)) {
        return ['ok' => false, 'error' => 'Cart is empty'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ? AND is_active = 1 FOR UPDATE');
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Student not found'];
        }

        $settings = get_settings($pdo);
        $today = date('Y-m-d');
        $maxDaily = (float) ($settings['max_daily_spend'] ?? 0);
        $maxDrinks = (int) ($settings['max_drinks_per_day'] ?? 0);
        $maxPastries = (int) ($settings['max_pastries_per_day'] ?? 0);

        $productIds = array_map(fn($i) => (int) $i['product_id'], $items);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $pStmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) AND is_active = 1 FOR UPDATE");
        $pStmt->execute($productIds);
        $products = [];
        foreach ($pStmt->fetchAll() as $p) {
            $products[(int) $p['id']] = $p;
        }

        $lineItems = [];
        $total = 0.0;
        $drinksInCart = 0;
        $pastriesInCart = 0;

        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            if (!isset($products[$pid])) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Invalid product in cart'];
            }
            $product = $products[$pid];
            $stock = (int) ($product['stock_quantity'] ?? 0);
            if ($stock < $qty) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => sprintf('Not enough stock for %s (only %d left)', $product['name'], $stock),
                ];
            }
            $lineTotal = (float) $product['price'] * $qty;
            $total += $lineTotal;
            if ($product['category'] === 'drink') {
                $drinksInCart += $qty;
            }
            if ($product['category'] === 'pastry') {
                $pastriesInCart += $qty;
            }
            $lineItems[] = [
                'product_id' => $pid,
                'product_name' => $product['name'],
                'unit_price' => (float) $product['price'],
                'quantity' => $qty,
                'line_total' => $lineTotal,
                'category' => $product['category'],
            ];
        }

        if ($maxDaily > 0) {
            $spentToday = student_daily_spend($pdo, $studentId, $today);
            if ($spentToday + $total > $maxDaily) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => sprintf(
                        'Daily limit exceeded (max ₦%s, already spent ₦%s)',
                        number_format($maxDaily, 0),
                        number_format($spentToday, 0)
                    ),
                ];
            }
        }

        if ($maxDrinks > 0 && $drinksInCart > 0) {
            $drinksToday = category_count_today($pdo, $studentId, 'drink', $today);
            if ($drinksToday + $drinksInCart > $maxDrinks) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => "Daily drink limit reached (max $maxDrinks per day)"];
            }
        }

        if ($maxPastries > 0 && $pastriesInCart > 0) {
            $pastriesToday = category_count_today($pdo, $studentId, 'pastry', $today);
            if ($pastriesToday + $pastriesInCart > $maxPastries) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => "Daily pastry limit reached (max $maxPastries per day)"];
            }
        }

        $balance = (float) $student['balance'];
        if ($balance < $total) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'error' => sprintf(
                    'Insufficient balance (need ₦%s, have ₦%s)',
                    number_format($total, 0),
                    number_format($balance, 0)
                ),
            ];
        }

        $newBalance = $balance - $total;
        $pdo->prepare('UPDATE students SET balance = ? WHERE id = ?')->execute([$newBalance, $studentId]);

        $tStmt = $pdo->prepare(
            'INSERT INTO transactions (student_id, terminal_id, total_amount, balance_before, balance_after, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $tStmt->execute([$studentId, $terminalId, $total, $balance, $newBalance, 'completed']);
        $transactionId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO transaction_items (transaction_id, product_id, product_name, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lineItems as $li) {
            $itemStmt->execute([
                $transactionId,
                $li['product_id'],
                $li['product_name'],
                $li['unit_price'],
                $li['quantity'],
                $li['line_total'],
            ]);
            $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?')
                ->execute([$li['quantity'], $li['product_id']]);
        }

        if ($drinksInCart > 0 && $maxDrinks > 0) {
            $pdo->prepare(
                'INSERT INTO daily_purchase_limits (student_id, purchase_date, category, count)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE count = count + VALUES(count)'
            )->execute([$studentId, $today, 'drink', $drinksInCart]);
        }
        if ($pastriesInCart > 0 && $maxPastries > 0) {
            $pdo->prepare(
                'INSERT INTO daily_purchase_limits (student_id, purchase_date, category, count)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE count = count + VALUES(count)'
            )->execute([$studentId, $today, 'pastry', $pastriesInCart]);
        }

        $pdo->commit();

        return [
            'ok' => true,
            'transaction' => [
                'id' => $transactionId,
                'total' => $total,
                'balance_before' => $balance,
                'balance_after' => $newBalance,
                'items' => $lineItems,
                'student_name' => $student['full_name'],
                'created_at' => date('c'),
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Purchase failed: ' . $e->getMessage()];
    }
}
