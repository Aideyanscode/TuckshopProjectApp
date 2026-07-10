-- Run in phpMyAdmin if you already imported schema.sql earlier
USE tuckshop;

ALTER TABLE products
    ADD COLUMN stock_quantity INT NOT NULL DEFAULT 0 AFTER sort_order;

UPDATE products SET stock_quantity = 50 WHERE stock_quantity = 0;

CREATE TABLE IF NOT EXISTS sellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    api_token VARCHAR(64) NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
