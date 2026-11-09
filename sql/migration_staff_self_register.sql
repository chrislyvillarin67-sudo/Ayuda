-- =========================================================
-- MIGRATION: Add status column to users table
-- Run this ONCE if you already have an existing ayuda_db
-- database from before this update (skip this if you're
-- doing a fresh install from schema.sql -- it's already
-- included there).
--
-- All existing accounts are set to 'approved' automatically
-- so nobody already using the system gets locked out.
--
-- How to run: phpMyAdmin > select ayuda_db > SQL tab >
-- paste this and click Go.
-- =========================================================

ALTER TABLE users
    ADD COLUMN status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER barangay_id;
