-- Run in phpMyAdmin if you already imported schema.sql earlier
USE tuckshop;

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

ALTER TABLE topups MODIFY COLUMN method ENUM('cash', 'bank_transfer', 'other', 'paystack') NOT NULL DEFAULT 'cash';
