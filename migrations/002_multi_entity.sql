-- =====================================================
-- Migration 002: Multi-Entity Budget System
-- Adds support for separate Personal and LLC budgets
-- =====================================================

-- Entities table (Personal, LLC, etc.)
CREATE TABLE IF NOT EXISTS entities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('personal', 'business') NOT NULL DEFAULT 'personal',
    tax_rate_percent DECIMAL(5,2) DEFAULT 25.00 COMMENT 'Default quarterly tax set-aside rate',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_household (household_id),
    
    CONSTRAINT fk_entities_household 
        FOREIGN KEY (household_id) REFERENCES households(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bank/Cash accounts for balance tracking
CREATE TABLE IF NOT EXISTS accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('checking', 'savings', 'credit', 'cash') NOT NULL DEFAULT 'checking',
    balance_cents INT NOT NULL DEFAULT 0 COMMENT 'Current running balance',
    archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_entity (entity_id),
    
    CONSTRAINT fk_accounts_entity 
        FOREIGN KEY (entity_id) REFERENCES entities(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices for LLC tracking
CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id INT UNSIGNED NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    client_name VARCHAR(200) NOT NULL,
    client_email VARCHAR(255) NULL,
    description TEXT NULL,
    amount_cents INT NOT NULL,
    status ENUM('draft', 'sent', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'draft',
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    paid_date DATE NULL,
    paid_transaction_id INT UNSIGNED NULL COMMENT 'Link to income transaction when paid',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    
    INDEX idx_entity (entity_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    
    CONSTRAINT fk_invoices_entity 
        FOREIGN KEY (entity_id) REFERENCES entities(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_invoices_user 
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) 
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quarterly tax estimates
CREATE TABLE IF NOT EXISTS tax_estimates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id INT UNSIGNED NOT NULL,
    year INT NOT NULL,
    quarter TINYINT NOT NULL COMMENT '1-4',
    income_cents INT NOT NULL DEFAULT 0 COMMENT 'Total revenue for quarter',
    expenses_cents INT NOT NULL DEFAULT 0 COMMENT 'Total deductible expenses',
    estimated_tax_cents INT NOT NULL DEFAULT 0 COMMENT 'Calculated estimated tax',
    paid_cents INT NOT NULL DEFAULT 0 COMMENT 'Amount already paid',
    due_date DATE NOT NULL,
    paid_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE INDEX idx_entity_quarter (entity_id, year, quarter),
    
    CONSTRAINT fk_tax_estimates_entity 
        FOREIGN KEY (entity_id) REFERENCES entities(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Linked transfers (owner draws that connect LLC to Personal)
CREATE TABLE IF NOT EXISTS linked_transfers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_transaction_id INT UNSIGNED NOT NULL COMMENT 'Source (e.g., LLC expense)',
    to_transaction_id INT UNSIGNED NOT NULL COMMENT 'Destination (e.g., Personal income)',
    transfer_type ENUM('owner_draw', 'transfer', 'reimbursement') NOT NULL DEFAULT 'owner_draw',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE INDEX idx_from_tx (from_transaction_id),
    UNIQUE INDEX idx_to_tx (to_transaction_id),
    
    CONSTRAINT fk_linked_from 
        FOREIGN KEY (from_transaction_id) REFERENCES transactions(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_linked_to 
        FOREIGN KEY (to_transaction_id) REFERENCES transactions(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add entity_id to category_groups (categories belong to entities now)
ALTER TABLE category_groups 
    ADD COLUMN entity_id INT UNSIGNED NULL AFTER household_id;

-- Add entity_id and account_id to transactions
ALTER TABLE transactions 
    ADD COLUMN entity_id INT UNSIGNED NULL AFTER household_id,
    ADD COLUMN account_id INT UNSIGNED NULL AFTER entity_id,
    ADD COLUMN is_transfer TINYINT(1) NOT NULL DEFAULT 0 AFTER memo;

-- Add entity_id to budget_months
ALTER TABLE budget_months 
    ADD COLUMN entity_id INT UNSIGNED NULL AFTER household_id;

-- Create default Personal entity for existing data
INSERT INTO entities (household_id, name, type)
SELECT id, 'Personal', 'personal' FROM households
WHERE id NOT IN (SELECT household_id FROM entities WHERE type = 'personal');

-- Update existing category_groups to use the default Personal entity
UPDATE category_groups cg
SET entity_id = (
    SELECT e.id FROM entities e 
    WHERE e.household_id = cg.household_id AND e.type = 'personal' 
    LIMIT 1
)
WHERE entity_id IS NULL;

-- Update existing transactions to use the default Personal entity
UPDATE transactions t
SET entity_id = (
    SELECT e.id FROM entities e 
    WHERE e.household_id = t.household_id AND e.type = 'personal' 
    LIMIT 1
)
WHERE entity_id IS NULL;

-- Update existing budget_months to use the default Personal entity
UPDATE budget_months bm
SET entity_id = (
    SELECT e.id FROM entities e 
    WHERE e.household_id = bm.household_id AND e.type = 'personal' 
    LIMIT 1
)
WHERE entity_id IS NULL;

-- Add foreign key constraints after data migration
ALTER TABLE category_groups
    ADD CONSTRAINT fk_category_groups_entity 
    FOREIGN KEY (entity_id) REFERENCES entities(id) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE transactions
    ADD CONSTRAINT fk_transactions_entity 
    FOREIGN KEY (entity_id) REFERENCES entities(id) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE budget_months
    ADD CONSTRAINT fk_budget_months_entity 
    FOREIGN KEY (entity_id) REFERENCES entities(id) 
    ON DELETE CASCADE ON UPDATE CASCADE;
