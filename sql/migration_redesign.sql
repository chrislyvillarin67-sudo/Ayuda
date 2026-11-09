-- =========================================================
-- AYUDA MIGRATION — Implements the Chrisly Villarin redesign:
--   • Beneficiary status lifecycle (rejected / returned-for-correction)
--   • Date of Birth (needed for stronger duplicate detection)
--   • Verification-step separation (Barangay initial vs CSWDO final)
--   • Distribution Program / schedule management
-- =========================================================
SET FOREIGN_KEY_CHECKS = 0;

-- Widen the beneficiary status enum to support reject / return-for-correction.
ALTER TABLE beneficiaries
    MODIFY COLUMN status ENUM('pending','approved','rejected','returned') NOT NULL DEFAULT 'pending';

-- Date of birth strengthens duplicate detection (name + DOB + barangay).
ALTER TABLE beneficiaries
    ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER age;

-- Tracking for reject / return-for-correction lifecycles.
ALTER TABLE beneficiaries
    ADD COLUMN rejected_at DATETIME DEFAULT NULL AFTER approved_at,
    ADD COLUMN rejected_reason VARCHAR(255) DEFAULT NULL AFTER rejected_at,
    ADD COLUMN return_reason VARCHAR(255) DEFAULT NULL AFTER rejected_reason;

-- Verification-step separation: Barangay Staff performs initial verification
-- and submission; CSWDO performs the final validation/approval.
--   wartości: submitted -> barangay -> cswdo -> approved
ALTER TABLE beneficiaries
    ADD COLUMN verification_step ENUM('submitted','barangay','cswdo','approved') DEFAULT 'submitted' AFTER status;

-- ---------------------------------------------------------
-- DISTRIBUTION PROGRAMS (an Ayuda program / schedule) —
-- CSWDO creates a program, sets assistance type, date, location,
-- defines eligible beneficiaries, and monitors distributed vs pending.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS distribution_programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    assistance_type ENUM('cash','food_pack','relief_goods','hygiene','other') NOT NULL DEFAULT 'other',
    program_date DATE NOT NULL,
    program_time TIME DEFAULT NULL,
    location VARCHAR(150) DEFAULT NULL,
    barangay_id INT DEFAULT NULL,               -- NULL = citywide
    status ENUM('scheduled','ongoing','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Assignment of eligible beneficiaries to a distribution program, tracking
-- whether each assigned beneficiary has already received their ayuda.
CREATE TABLE IF NOT EXISTS distribution_program_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    beneficiary_id INT NOT NULL,
    received TINYINT(1) NOT NULL DEFAULT 0,
    received_at DATETIME DEFAULT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES distribution_programs(id) ON DELETE CASCADE,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
    UNIQUE KEY uq_program_beneficiary (program_id, beneficiary_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;