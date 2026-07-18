-- =============================================================================
-- migrations/003_leave_types_max_days.sql
--
-- Leave Types management screen needs to read/write an entitlement value
-- per leave type. routes/leave_records.php already SELECTs
-- leave_types.max_days_per_year when auto-creating a leave_balances row on
-- first approval, but no column existed for admins to actually set it.
--
-- This is safe to run even if the column already exists (MySQL 8.0+
-- supports IF NOT EXISTS on ADD COLUMN). For older MySQL/MariaDB without
-- that support, check information_schema first or drop the column if the
-- ALTER fails with "Duplicate column name".
--
-- Run this once against the existing database, e.g.:
--   mysql -u <user> -p <database> < migrations/003_leave_types_max_days.sql
-- =============================================================================

ALTER TABLE leave_types
    ADD COLUMN IF NOT EXISTS max_days_per_year DECIMAL(5,2) NOT NULL DEFAULT 15.00
        AFTER is_paid;
