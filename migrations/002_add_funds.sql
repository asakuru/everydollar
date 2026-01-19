-- Migration 002: Add Sinking Funds support
-- Adds is_fund and fund_target_cents to categories table

ALTER TABLE categories
ADD COLUMN is_fund TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN fund_target_cents INT NULL DEFAULT NULL;

-- Log migration (using INSERT IGNORE to be safe, though migrate.php handles logic)
INSERT IGNORE INTO migrations (migration) VALUES ('002_add_funds');
