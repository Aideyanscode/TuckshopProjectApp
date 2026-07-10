<?php

declare(strict_types=1);

/** Create tables/columns added after early installs. Safe to run repeatedly. */
function ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'")->fetchAll();
    if (count($cols) === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 0 AFTER sort_order');
        $pdo->exec('UPDATE products SET stock_quantity = 50 WHERE stock_quantity = 0');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sellers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(120) NOT NULL,
            api_token VARCHAR(64) NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $done = true;
}
