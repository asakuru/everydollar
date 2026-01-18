-- 1. Create a plain index to support the Foreign Key (fk_budget_months_household)
-- which currently relies on idx_household_month.
CREATE INDEX idx_household_fk ON budget_months (household_id);

-- 2. NOW we can drop the old restrictive index safely
DROP INDEX idx_household_month ON budget_months;

-- 3. Add the new correct index
CREATE UNIQUE INDEX idx_entity_month ON budget_months (entity_id, month_yyyymm);
