-- NFC Tuckshop System - MySQL Schema
-- Run once: mysql -u root -p tuckshop < sql/schema.sql

CREATE DATABASE IF NOT EXISTS tuckshop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tuckshop;

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_number VARCHAR(32) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    class_name VARCHAR(64) NOT NULL,
    wallet_id VARCHAR(36) NOT NULL UNIQUE,
    balance DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    nfc_uid VARCHAR(64) NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nfc_uid (nfc_uid),
    INDEX idx_class (class_name)
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    category ENUM('pastry', 'drink') NOT NULL DEFAULT 'pastry',
    icon VARCHAR(16) DEFAULT '🍞',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    stock_quantity INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    api_token VARCHAR(64) NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS terminals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    location VARCHAR(120) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    terminal_id INT NULL,
    total_amount DECIMAL(12, 2) NOT NULL,
    balance_before DECIMAL(12, 2) NOT NULL,
    balance_after DECIMAL(12, 2) NOT NULL,
    status ENUM('completed', 'failed', 'cancelled') NOT NULL DEFAULT 'completed',
    failure_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (terminal_id) REFERENCES terminals(id),
    INDEX idx_student_date (student_id, created_at),
    INDEX idx_created (created_at)
);

CREATE TABLE IF NOT EXISTS transaction_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(120) NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    line_total DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE IF NOT EXISTS parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    api_token VARCHAR(64) NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS parent_students (
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (parent_id, student_id),
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS paystack_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    reference VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
    paystack_reference VARCHAR(64) NULL,
    topup_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (parent_id) REFERENCES parents(id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (topup_id) REFERENCES topups(id),
    INDEX idx_paystack_ref (reference),
    INDEX idx_parent_student (parent_id, student_id)
);

CREATE TABLE IF NOT EXISTS topups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    method ENUM('cash', 'bank_transfer', 'other', 'paystack') NOT NULL DEFAULT 'cash',
    reference_note VARCHAR(255) NULL,
    recorded_by VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    INDEX idx_topup_date (created_at)
);

CREATE TABLE IF NOT EXISTS daily_purchase_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    purchase_date DATE NOT NULL,
    category ENUM('drink', 'pastry') NOT NULL,
    count INT NOT NULL DEFAULT 0,
    UNIQUE KEY uk_student_date_cat (student_id, purchase_date, category),
    FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE IF NOT EXISTS scheduled_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_uid VARCHAR(64) NOT NULL UNIQUE,
    parent_id INT NULL,
    student_id INT NULL,
    student_number VARCHAR(32) NOT NULL,
    student_name VARCHAR(120) NOT NULL,
    class_name VARCHAR(64) NOT NULL,
    parent_name VARCHAR(120) NOT NULL,
    scheduled_date DATE NOT NULL,
    notes VARCHAR(255) NULL,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    fulfillment_status ENUM('pending', 'prepared', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    sync_status ENUM('not_required', 'pending', 'synced') NOT NULL DEFAULT 'not_required',
    origin_instance VARCHAR(64) NOT NULL DEFAULT 'local',
    synced_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_scheduled_date (scheduled_date, fulfillment_status, created_at),
    INDEX idx_sync_status (sync_status),
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS scheduled_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scheduled_order_id INT NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(120) NOT NULL,
    category ENUM('pastry', 'drink') NOT NULL DEFAULT 'pastry',
    unit_price DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    line_total DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (scheduled_order_id) REFERENCES scheduled_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO settings (setting_key, setting_value) VALUES
    ('max_daily_spend', '3000'),
    ('max_drinks_per_day', '1'),
    ('max_pastries_per_day', '1'),
    ('currency_symbol', '₦'),
    ('school_name', 'School Tuckshop')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO terminals (name, location) VALUES
    ('Counter 1', 'Main tuckshop'),
    ('Counter 2', 'Secondary outlet'),
    ('Counter 3', 'Sports block')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO products (name, price, category, icon, sort_order, stock_quantity) VALUES
    ('Meat pie', 200.00, 'pastry', '🥧', 1, 50),
    ('Sausage roll', 180.00, 'pastry', '🌭', 2, 50),
    ('Bread', 100.00, 'pastry', '🍞', 3, 50),
    ('Biscuits', 80.00, 'pastry', '🍪', 4, 50),
    ('Water', 70.00, 'drink', '💧', 5, 50),
    ('Juice', 150.00, 'drink', '🧃', 6, 50);
