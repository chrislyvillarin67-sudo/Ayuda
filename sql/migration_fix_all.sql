-- =========================================================
-- MIGRATION: Fix "Unknown column" / "Undefined array key" errors
-- + add profile photo support
--
-- Run this ONCE against your EXISTING ayuda_db database.
-- It is safe to run even if some columns already exist
-- (uses IF NOT EXISTS -- requires MySQL 8.0.29+ / MariaDB 10.5+,
-- which is what current XAMPP ships).
--
-- How to run:
--   phpMyAdmin > select "ayuda_db" (left sidebar) > SQL tab >
--   paste this entire file > click "Go".
--
-- What this fixes:
--   1) "Undefined array key beneficiary_code" warnings and
--      "Unknown column 'beneficiary_code'" fatal errors on
--      register.php / beneficiary_detail.php / dashboard.php
--      -> beneficiaries table was missing beneficiary_code.
--   2) "Unknown column ...status..." error on manage_users.php
--      -> users table was missing the status column.
--   3) Adds users.photo_path so staff can upload a profile photo.
-- =========================================================

USE ayuda_db;

ALTER TABLE beneficiaries
    ADD COLUMN IF NOT EXISTS beneficiary_code VARCHAR(100) DEFAULT NULL UNIQUE AFTER id;

-- Widen the column in case it already existed at the old, shorter length
-- (Gmail addresses can be longer than the original 30-character limit).
ALTER TABLE beneficiaries
    MODIFY COLUMN beneficiary_code VARCHAR(100) DEFAULT NULL;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER barangay_id;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) DEFAULT NULL AFTER status;

-- If your MySQL/MariaDB version does NOT support "ADD COLUMN IF NOT EXISTS"
-- (older than MySQL 8.0.29), run these three lines ONE AT A TIME instead,
-- skipping any that error out with "Duplicate column name":
--
-- ALTER TABLE beneficiaries ADD COLUMN beneficiary_code VARCHAR(100) DEFAULT NULL UNIQUE AFTER id;
-- ALTER TABLE users ADD COLUMN status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER barangay_id;
-- ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL AFTER status;
