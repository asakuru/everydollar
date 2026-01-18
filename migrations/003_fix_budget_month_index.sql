-- Fix Budget Month Unique Index
-- Problem: Old index enforced uniqueness on (household_id, month), preventing multiple entities in same household from having budgets for the same month.
-- Fix: Change uniqueness to (entity_id, month).

-- 1. Drop the old restrictive index
DROP INDEX idx_household_month ON budget_months;

-- 2. Add the new correct index
CREATE UNIQUE INDEX idx_entity_month ON budget_months (entity_id, month_yyyymm);
