-- =====================================================
-- Migration 001: Initial Schema
-- Zero-Based Budgeting App Database Structure
-- =====================================================

-- Households table
CREATE TABLE IF NOT EXISTS households (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    
    -- 2FA fields (for future implementation)
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    totp_secret_encrypted TEXT NULL,
    totp_recovery_codes_hashed TEXT NULL,
    last_totp_verified_at DATETIME NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE INDEX idx_email (email),
    INDEX idx_household_id (household_id),
    
    CONSTRAINT fk_users_household 
        FOREIGN KEY (household_id) REFERENCES households(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Budget months table
CREATE TABLE IF NOT EXISTS budget_months (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    month_yyyymm CHAR(7) NOT NULL COMMENT 'Format: YYYY-MM',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE INDEX idx_household_month (household_id, month_yyyymm),
    
    CONSTRAINT fk_budget_months_household 
        FOREIGN KEY (household_id) REFERENCES households(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Category groups table
CREATE TABLE IF NOT EXISTS category_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_household_sort (household_id, sort_order),
    
    CONSTRAINT fk_category_groups_household 
        FOREIGN KEY (household_id) REFERENCES households(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categories table
CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_group_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_group_sort (category_group_id, sort_order),
    INDEX idx_archived (archived),
    
    CONSTRAINT fk_categories_group 
        FOREIGN KEY (category_group_id) REFERENCES category_groups(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Budget items (planned amounts per category per month)
CREATE TABLE IF NOT EXISTS budget_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    budget_month_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    planned_cents INT NOT NULL DEFAULT 0 COMMENT 'Stored as cents to avoid floating point issues',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    
    UNIQUE INDEX idx_month_category (budget_month_id, category_id),
    
    CONSTRAINT fk_budget_items_month 
        FOREIGN KEY (budget_month_id) REFERENCES budget_months(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_budget_items_category 
        FOREIGN KEY (category_id) REFERENCES categories(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_budget_items_user 
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) 
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Income items (income lines per month)
CREATE TABLE IF NOT EXISTS income_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    budget_month_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    planned_cents INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    
    INDEX idx_budget_month (budget_month_id),
    
    CONSTRAINT fk_income_items_month 
        FOREIGN KEY (budget_month_id) REFERENCES budget_months(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_income_items_user 
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) 
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions
CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    budget_month_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    amount_cents INT NOT NULL COMMENT 'Positive for income, negative for expenses',
    type ENUM('income', 'expense') NOT NULL,
    payee VARCHAR(200) NOT NULL,
    memo VARCHAR(500) NULL,
    category_id INT UNSIGNED NULL COMMENT 'NULL = uncategorized',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    
    INDEX idx_household_date (household_id, date),
    INDEX idx_budget_month (budget_month_id),
    INDEX idx_category (category_id),
    INDEX idx_uncategorized (household_id, category_id),
    
    CONSTRAINT fk_transactions_household 
        FOREIGN KEY (household_id) REFERENCES households(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_transactions_month 
        FOREIGN KEY (budget_month_id) REFERENCES budget_months(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_transactions_category 
        FOREIGN KEY (category_id) REFERENCES categories(id) 
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_transactions_user 
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) 
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invite tokens for adding household members
CREATE TABLE IF NOT EXISTS invite_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL COMMENT 'Hashed invite token',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    
    INDEX idx_token_hash (token_hash),
    INDEX idx_household (household_id),
    INDEX idx_expires (expires_at),
    
    CONSTRAINT fk_invite_tokens_household 
        FOREIGN KEY (household_id) REFERENCES households(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_invite_tokens_user 
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) 
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login attempts for rate limiting
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL COMMENT 'IPv4 or IPv6',
    email VARCHAR(255) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_ip_time (ip_address, attempted_at),
    INDEX idx_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrations tracking table
CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE INDEX idx_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
