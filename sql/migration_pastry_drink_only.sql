-- Run in phpMyAdmin if you already have the tuckshop database
USE tuckshop;

UPDATE products SET category = 'pastry' WHERE category IN ('snack', 'other', 'pastry');
UPDATE products SET category = 'drink' WHERE category = 'drink';

ALTER TABLE products
    MODIFY COLUMN category ENUM('pastry', 'drink') NOT NULL DEFAULT 'pastry';
