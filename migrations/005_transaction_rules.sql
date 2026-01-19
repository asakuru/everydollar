CREATE TABLE IF NOT EXISTS transaction_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    household_id INT NOT NULL,
    search_term VARCHAR(255) NOT NULL,
    match_type ENUM('contains', 'exact') DEFAULT 'contains',
    category_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_household_search (household_id, search_term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
