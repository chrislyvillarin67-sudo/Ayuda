-- =========================================================
-- MIGRATION: Add beneficiary_code column
-- Run this ONCE if you already have an existing ayuda_db
-- database from before this update (skip this if you're
-- doing a fresh install from schema.sql -- it's already
-- included there).
--
-- How to run: phpMyAdmin > select ayuda_db > SQL tab >
-- paste this and click Go.
-- =========================================================

ALTER TABLE beneficiaries
    ADD COLUMN beneficiary_code VARCHAR(30) DEFAULT NULL UNIQUE AFTER id;
