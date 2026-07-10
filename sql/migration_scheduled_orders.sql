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
