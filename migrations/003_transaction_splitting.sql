-- Migration 003: Transaction Splitting
-- Create table to store split details for a transaction

CREATE TABLE IF NOT EXISTS transaction_splits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    amount_cents INT NOT NULL,
    memo VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for performance
CREATE INDEX idx_split_transaction ON transaction_splits(transaction_id);
CREATE INDEX idx_split_category ON transaction_splits(category_id);
