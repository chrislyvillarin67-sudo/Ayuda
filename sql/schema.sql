-- =========================================================
-- AYUDA DISTRIBUTION AND MONITORING SYSTEM - DATABASE SCHEMA
-- Davao City
-- Just import this whole file directly in phpMyAdmin's
-- "Import" tab (no need to create/select a database first
-- -- the two lines below do that for you).
-- =========================================================

CREATE DATABASE IF NOT EXISTS ayuda_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE ayuda_db;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- DISTRICTS (Davao City CPDO Districts)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS districts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BARANGAYS
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS barangays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    district_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- USERS (Barangay Staff & District Admin only)
-- Beneficiaries are NOT login users - they self-register
-- and are stored in the beneficiaries table.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('district_admin','barangay_staff') NOT NULL,
    barangay_id INT DEFAULT NULL,
    status ENUM('pending','approved') NOT NULL DEFAULT 'approved',
    photo_path VARCHAR(255) DEFAULT NULL,
    mfa_secret VARCHAR(64) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BENEFICIARIES
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS beneficiaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_code VARCHAR(100) DEFAULT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    middle_initial VARCHAR(10) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    gender ENUM('Female','Male','Prefer not to say') DEFAULT NULL,
    civil_status ENUM('Single','Married','Widowed','Separated/Annulled') DEFAULT NULL,
    occupation VARCHAR(120) DEFAULT NULL,
    daily_income DECIMAL(12,2) DEFAULT NULL,
    monthly_income DECIMAL(12,2) DEFAULT NULL,
    house_occupancy ENUM('Owner','Renter','Sharer','Other') DEFAULT NULL,
    lot_occupancy ENUM('Owner','Renter','Sharer','Other') DEFAULT NULL,
    date_of_birth DATE DEFAULT NULL,
    address VARCHAR(255) NOT NULL,
    district_id INT NOT NULL,
    barangay_id INT NOT NULL,
    category ENUM('Senior Citizen','Solo Parent','PWD','Indigent','Student') NOT NULL,
    contact_number VARCHAR(20) DEFAULT NULL,
    status ENUM('pending','approved','rejected','returned') NOT NULL DEFAULT 'pending',
    verification_step ENUM('submitted','barangay','cswdo','approved') DEFAULT 'submitted',
    is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
    password_hash VARCHAR(255) DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    rejected_reason VARCHAR(255) DEFAULT NULL,
    return_reason VARCHAR(255) DEFAULT NULL,
    last_seen_updates_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reset_token VARCHAR(64) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    FOREIGN KEY (district_id) REFERENCES districts(id),
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BENEFICIARY CATEGORIES (membership join table)
-- A person may belong to up to three categories (e.g. Senior
-- Citizen + PWD + Solo Parent). beneficiaries.category keeps the
-- primary/first category so legacy single-value screens still work;
-- this table is the source of truth for category membership.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS beneficiary_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id INT NOT NULL,
    category ENUM('Senior Citizen','Solo Parent','PWD','Indigent','Student') NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_beneficiary_category (beneficiary_id, category),
    KEY idx_bc_category (category),
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- INVENTORY (aid items, tracked per barangay)
-- inventory.quantity holds the remaining balance:
--    * For cash items: the peso budget left (e.g. 50,000)
--    * For goods items: the number of packs/sets left
-- assistance_type categorizes each item so distribution and
-- reports can be grouped by ayuda type.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- NULL = shared citywide stock. Cash Assistance uses exactly ONE such row
    -- so every screen (dashboard, inventory, distribution, reports) reads and
    -- deducts from the same balance. All other items belong to one barangay.
    barangay_id INT DEFAULT NULL,
    item_name VARCHAR(100) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit VARCHAR(30) DEFAULT 'pack',
    source VARCHAR(100) DEFAULT NULL,
    source_details TEXT DEFAULT NULL,
    assistance_type ENUM('cash','food_pack','relief_goods','sponsored','other') NOT NULL DEFAULT 'other',
    cash_fund_type ENUM('general','emergency') DEFAULT NULL,
    location VARCHAR(150) DEFAULT NULL,
    low_stock_threshold INT NOT NULL DEFAULT 20,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- DISTRIBUTION LOGS (who received what aid, when)
-- assistance_type categorizes the ayuda (cash / food pack /
-- relief goods / sponsored / other). amount holds the peso
-- value for cash distributions, location captures the
-- sponsored/location info for the distribution site, and
-- description records free-text notes (e.g. when no named
-- beneficiary is selected by the District Admin).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS distribution_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_id INT NULL DEFAULT NULL,
    inventory_id INT NOT NULL,
    barangay_id INT NOT NULL,
    distributed_by INT DEFAULT NULL,
    assistance_type ENUM('cash','food_pack','relief_goods','sponsored','other','hygiene') NOT NULL DEFAULT 'other',
    quantity INT NOT NULL DEFAULT 1,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    location VARCHAR(150) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    distributed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    FOREIGN KEY (distributed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- BARANGAY REQUESTS (aid requests / shortages / emergencies
-- raised by Barangay Staff, reviewed by District Admin)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS barangay_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    requested_by INT NOT NULL,
    request_type VARCHAR(60) NOT NULL DEFAULT 'Emergency',
    severity ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    location_detail VARCHAR(160) DEFAULT NULL,
    affected_people INT DEFAULT NULL,
    help_needed VARCHAR(160) DEFAULT NULL,
    reporter_contact VARCHAR(60) DEFAULT NULL,
    item_needed VARCHAR(100) DEFAULT NULL,
    quantity_needed INT DEFAULT NULL,
    description TEXT NOT NULL,
status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
    respond_date DATE DEFAULT NULL,          -- when the CSWDO will respond
    respond_time VARCHAR(30) DEFAULT NULL,
    respond_description TEXT DEFAULT NULL,   -- what support will be dispatched
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME DEFAULT NULL,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- ANNOUNCEMENTS (emergency alerts / distribution schedules
-- shown to beneficiaries on their map screen)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT DEFAULT NULL, -- NULL = citywide
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
priority ENUM('high','normal') NOT NULL DEFAULT 'normal',
    source_type VARCHAR(30) DEFAULT NULL, -- e.g. 'distribution_schedule', 'schedule_final_list', 'emergency_response'
    source_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    INDEX idx_ann_source (source_type, source_id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- DISTRIBUTION PROGRAMS (an Ayuda program / schedule created by
-- CSWDO: assistance type, date, location, eligible beneficiaries)
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

-- ---------------------------------------------------------
-- DISTRIBUTION PROGRAM ASSIGNMENTS (eligible beneficiaries per
-- program + per-beneficiary received tracking for progress)
-- ---------------------------------------------------------
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

-- ---------------------------------------------------------
-- AUDIT LOG (trail of critical actions: logins, distributions,
-- beneficiary approvals/rejections, inventory & user changes)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    role VARCHAR(30) DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Inventory transactions (stock movement history)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    barangay_id INT NULL,          -- NULL = citywide pool movement (e.g. Cash Assistance)
item_name VARCHAR(100) NOT NULL,
    source VARCHAR(100) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
type ENUM('add','replenish','reserve','release','cancel','deduct') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
    done_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- INVENTORY RESERVATIONS (scheduled / reserved packs)
-- Stock set aside for an upcoming distribution program or
-- schedule. Reserved quantity is NOT available for ad-hoc
-- distribution and appears in history as "Reserved".
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    barangay_id INT NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    reference VARCHAR(255) DEFAULT NULL,
    status ENUM('reserved','released','cancelled') NOT NULL DEFAULT 'reserved',
    done_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME DEFAULT NULL,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    FOREIGN KEY (done_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_res_inventory (inventory_id),
    INDEX idx_res_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- DISTRIBUTION SCHEDULES / NOTICES
-- A "distribution schedule" carries the venue, date, time and what-to-bring
-- that Barangay Staff announce and beneficiaries view. Its published
-- recipient list (distribution_schedule_members) is the "final list".
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS distribution_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    aid_type VARCHAR(30) DEFAULT NULL,
    barangay_id INT NOT NULL,
    district_id INT NOT NULL,
    venue VARCHAR(150) DEFAULT NULL,
    schedule_date DATE DEFAULT NULL,
    schedule_time VARCHAR(30) DEFAULT NULL,
    what_to_bring TEXT DEFAULT NULL,
    note TEXT DEFAULT NULL,
    status ENUM('draft','published','completed') NOT NULL DEFAULT 'draft',
    published_by INT DEFAULT NULL,
    published_at DATETIME DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE,
    FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- DISTRIBUTION SCHEDULE MEMBERS (the "final list" per schedule)
-- is_claimed tracks who actually showed up and received the ayuda
-- on distribution day (day-of claiming checklist).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS distribution_schedule_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    beneficiary_id INT NOT NULL,
    is_receiver TINYINT(1) NOT NULL DEFAULT 1,
    is_claimed TINYINT(1) NOT NULL DEFAULT 0,
    release_status ENUM('pending','released','not_released') NOT NULL DEFAULT 'pending',
    release_note VARCHAR(255) DEFAULT NULL,
    release_at DATETIME DEFAULT NULL,
    release_by INT DEFAULT NULL,
    claimed_at DATETIME DEFAULT NULL,
    FOREIGN KEY (schedule_id) REFERENCES distribution_schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
    UNIQUE KEY uq_schedule_beneficiary (schedule_id, beneficiary_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- ELIGIBILITY LISTS (admin-confirmed final list)
-- The District Admin (CSWDO) confirms which members of a barangay are
-- eligible to receive a given type of aid. Distribution then applies to
-- all members on the confirmed list at once. This also feeds the staff side.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS eligibility_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    barangay_id INT NOT NULL,
    district_id INT NOT NULL,
    assistance_type ENUM('cash','food_pack','relief_goods','sponsored','other','hygiene') NOT NULL DEFAULT 'other',
    cash_fund_type ENUM('general','emergency') DEFAULT NULL,
    inventory_id INT DEFAULT NULL,
    status ENUM('draft','confirmed','completed') NOT NULL DEFAULT 'draft',
    created_by INT DEFAULT NULL,
    confirmed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS eligibility_list_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    list_id INT NOT NULL,
    beneficiary_id INT NOT NULL,
    FOREIGN KEY (list_id) REFERENCES eligibility_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
    UNIQUE KEY uq_elist_beneficiary (list_id, beneficiary_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- SEED DATA
-- =========================================================

INSERT INTO districts (name) VALUES
('Poblacion'),('Agdao'),('Talomo'),('Buhangin'),('Bunawan'),
('Paquibato'),('Calinan'),('Toril'),('Tugbok'),('Marilog'),('Baguio');

-- Barangays with approximate coordinates around Davao City (7.1907, 125.4553)
INSERT INTO barangays (district_id, name, latitude, longitude) VALUES
(1,'Poblacion Barangay 1-A', 7.0730, 125.6120),
(1,'Poblacion Barangay 2-A', 7.0745, 125.6135),
(1,'Poblacion Barangay 3-A', 7.0710, 125.6100),
(2,'Agdao Proper', 7.0900, 125.6250),
(2,'Ilang', 7.1000, 125.6400),
(2,'Lapu-Lapu', 7.0850, 125.6200),
(3,'Talomo Proper', 7.0400, 125.5700),
(3,'Bangkal', 7.0500, 125.5800),
(3,'Matina Crossing', 7.0600, 125.5900),
(4,'Buhangin Proper', 7.1200, 125.6300),
(4,'Acacia', 7.1300, 125.6400),
(4,'Cabantian', 7.1400, 125.6500),
(5,'Bunawan Proper', 7.2000, 125.6800),
(5,'Alegre', 7.2100, 125.6900),
(6,'Paquibato Proper', 7.3200, 125.5300),
(6,'Malabog', 7.3300, 125.5400),
(7,'Calinan Proper', 7.2000, 125.4500),
(7,'Riverside', 7.2100, 125.4600),
(8,'Toril Proper', 7.0100, 125.4900),
(8,'Bato', 7.0200, 125.5000),
(9,'Tugbok Proper', 7.1000, 125.4700),
(9,'Mintal', 7.0850, 125.4300),
(10,'Marilog Proper', 7.3500, 125.3000),
(10,'Salapawan', 7.3600, 125.3100),
(11,'Baguio Proper', 7.2200, 125.4000),
(11,'Cadalian', 7.2300, 125.4100),
(1,'Poblacion Barangay 11-B', 7.0625, 125.6045),
(1,'Poblacion Barangay 12-B', 7.0615, 125.6035),
(1,'Poblacion Barangay 13-B', 7.0605, 125.6025),
(1,'Poblacion Barangay 14-B', 7.0595, 125.6015),
(1,'Poblacion Barangay 15-B', 7.0585, 125.6005),
(1,'Poblacion Barangay 16-B', 7.0575, 125.5995),
(1,'Poblacion Barangay 17-B', 7.0565, 125.5985),
(1,'Poblacion Barangay 18-B', 7.0555, 125.5975),
(1,'Poblacion Barangay 19-B', 7.0545, 125.5965),
(1,'Poblacion Barangay 20-B', 7.0535, 125.5955),
(1,'Poblacion Barangay 21-C', 7.0745, 125.6055),
(1,'Poblacion Barangay 22-C', 7.0735, 125.6045),
(1,'Poblacion Barangay 23-C', 7.0725, 125.6035),
(1,'Poblacion Barangay 24-C', 7.0715, 125.6025),
(1,'Poblacion Barangay 25-C', 7.0705, 125.6015),
(1,'Poblacion Barangay 26-C', 7.0695, 125.6005),
(2,'Centro (San Juan)', 7.0965, 125.6315),
(2,'Gov. Paciano Bangoy', 7.0985, 125.6335),
(2,'Gov. Vicente Duterte', 7.1015, 125.6365),
(2,'Kap. Tomas Monteverde, Sr.', 7.1045, 125.6395),
(2,'Leon Garcia', 7.1075, 125.6415),
(2,'Rafael Castillo', 7.1110, 125.6475),
(2,'Ubalde', 7.1135, 125.6495),
(2,'Wilfredo Aquino', 7.1160, 125.6520),
(2,'Alfonso Angliongto Sr.', 7.1240, 125.6560),
(2,'Callawa', 7.1335, 125.6585),
(2,'Communal', 7.1370, 125.6620),
(2,'Indangan', 7.1435, 125.6545),
(2,'Mandug', 7.1470, 125.6655),
(2,'Sasa', 7.1510, 125.6690),
(2,'Tigatto', 7.1550, 125.6725),
(2,'Waan', 7.1585, 125.6760),
(3,'Bago Gallera', 7.0245, 125.5450),
(3,'Baliok', 7.0275, 125.5480),
(3,'Bucana', 7.0335, 125.5520),
(3,'Catalunan Grande', 7.0465, 125.5955),
(3,'Catalunan Pequeño', 7.0485, 125.5975),
(3,'Dumoy', 7.0365, 125.5560),
(3,'Langub', 7.0395, 125.5590),
(3,'Ma-a', 7.0435, 125.5640),
(3,'Magtuod', 7.0455, 125.5675),
(3,'Matina Aplaya', 7.0495, 125.5845),
(3,'Matina Pangi', 7.0525, 125.5885),
(3,'Angalan', 7.1045, 125.4740),
(3,'Bago Oshiro', 7.1085, 125.4780),
(3,'Los Amigos', 7.1125, 125.4820),
(3,'Santo Niño', 7.1165, 125.4860),
(3,'Tacunan', 7.1205, 125.4900);

-- Default users
-- Username: admin / Password: admin123  (District Admin)
-- Username: staff / Password: staff123  (Barangay Staff - assigned to Poblacion Barangay 1-A)
INSERT INTO users (username, password_hash, full_name, role, barangay_id) VALUES
('admin', '$2y$10$uySPAJ6aVh3RTQyxYLxEh.xkv1q1t5lh/7MImni/eYOsqkPJQcLri', 'District Administrator', 'district_admin', NULL),
('staff', '$2y$10$XIEml53C8QsWxfxpylceRejV5WEoR3/QjCyTGQdDIAMFcENL.xuqy', 'Juan Dela Cruz', 'barangay_staff', 1);
-- NOTE: default password for "admin" is admin123, and for "staff" is staff123

-- Sample inventory per barangay
-- (cash items use quantity as their peso budget)
INSERT INTO inventory (barangay_id, item_name, quantity, unit, source, source_details, assistance_type, location, low_stock_threshold) VALUES
(1,'Food Packs', 150, 'pack', 'DSWD - National Government (AICS)', 'Family food packs (rice, canned goods, instant noodles) from the Department of Social Welfare and Development. Distributed per confirmed list.', 'food_pack', 'Poblacion Barangay 1-A Gymnasium', 30),
(1,'Cash Assistance', 50000, 'peso', 'DSWD / City SWDO', 'Seasonal cash assistance (AICS) allocated to qualified beneficiaries per barangay.', 'cash', 'Poblacion Barangay 1-A Gymnasium', 2000),
(1,'Relief Goods', 60, 'set', 'City Social Welfare and Development Office (CSWDO)', 'Relief goods from the City SWDO budget for barangay response and quick distribution.', 'relief_goods', 'Poblacion Barangay 1-A Hall', 15),
(1,'Hygiene Kits', 200, 'kit', 'DSWD / NGO Partners', 'Hygiene and sanitation kits provided alongside food assistance.', 'relief_goods', 'Poblacion Barangay 1-A Hall', 30),
(2,'Food Packs', 100, 'pack', 'DSWD - National Government (AICS)', 'Family food packs (rice, canned goods, instant noodles) from the Department of Social Welfare and Development. Distributed per confirmed list.', 'food_pack', 'Agdao Proper Covered Court', 30),
(2,'Cash Assistance', 40000, 'peso', 'DSWD / City SWDO', 'Seasonal cash assistance (AICS) allocated to qualified beneficiaries per barangay.', 'cash', 'Agdao Proper Covered Court', 2000),
(3,'Food Packs', 90, 'pack', 'DSWD - National Government (AICS)', 'Family food packs (rice, canned goods, instant noodles) from the Department of Social Welfare and Development. Distributed per confirmed list.', 'food_pack', 'Talomo Proper Barangay Hall', 30);

-- Sample announcements shown on the beneficiary map screen
INSERT INTO announcements (barangay_id, title, message, priority, created_by) VALUES
(NULL, 'Relief Distribution Schedule', 'Ayuda distribution will be held every Wednesday and Friday, 8:00 AM - 4:00 PM, at your barangay hall. Please bring a valid ID.', 'normal', 1),
(1, 'Extended Claiming Hours', 'Due to high demand, Poblacion Barangay 1-A will extend claiming hours until 6:00 PM this Friday.', 'high', 1);

-- =========================================================
-- DEMO / DUMMY DATA
-- Realistic development data so the dashboard, three-district
-- pie chart, distribution history, and reports have something
-- to show. Clearly marked as demo data; safe for testing.
-- =========================================================

-- Approved & pending beneficiaries across a few districts/barangays
INSERT INTO beneficiaries
(full_name, date_of_birth, address, district_id, barangay_id, category, contact_number, status, approved_by, approved_at, created_at) VALUES
('Maria Santos',     '1958-03-12', 'P. Del Rosario St, Poblacion',     1, 1, 'Senior Citizen',    '09171234567', 'approved', 2, NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 12 DAY),
('Jose Ramirez',     '1980-07-25', 'Magallanes St, Poblacion',         1, 2, 'Indigent', '09172345678', 'approved', 2, NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 11 DAY),
('Elena Cruz',       '1991-11-03', 'V. Mapa St, Agdao',                2, 4, 'Solo Parent',      '09173456789', 'approved', 2, NOW() - INTERVAL 9 DAY,  NOW() - INTERVAL 9 DAY),
('Roberto Abellana', '1953-05-19', 'Boulevard St, Agdao',              2, 5, 'Senior Citizen',   '09174567890', 'approved', 2, NOW() - INTERVAL 8 DAY,  NOW() - INTERVAL 8 DAY),
('Liza Mae Tan',     '1996-01-27', 'R. Castillo St, Talomo',           3, 7, 'Solo Parent',      '09175678901', 'approved', 2, NOW() - INTERVAL 7 DAY,  NOW() - INTERVAL 7 DAY),
('Carlos Ong',       '1968-09-14', 'McArthur Hwy, Talomo',             3, 8, 'PWD',              '09176789012', 'approved', 2, NOW() - INTERVAL 6 DAY,  NOW() - INTERVAL 6 DAY),
('Andrea Fernandez', '1984-02-21', 'Cabaguio St, Agdao',               2, 4, 'Indigent',         '09177890123', 'pending',  NULL, NULL, NOW() - INTERVAL 3 DAY),
('Miguel Bautista',  '1970-06-08', 'Claveria St, Poblacion',           1, 3, 'Indigent',         '09178901234', 'pending',  NULL, NULL, NOW() - INTERVAL 2 DAY),
('Sofia Dela Vega',  '2002-04-17', 'Bankerohan St, Poblacion',         1, 1, 'Student',          '09179012345', 'pending',  NULL, NULL, NOW() - INTERVAL 1 DAY);

-- Seed the category-membership join table from each beneficiary's primary
-- category (fresh installs start with one membership per person).
INSERT IGNORE INTO beneficiary_categories (beneficiary_id, category)
SELECT id, category FROM beneficiaries WHERE category IS NOT NULL AND category <> '';

-- Distribution history (demo). Uses seeded inventory rows:
--   1 = Poblacion Food Packs, 2 = Poblacion Cash 50000, 3 = Poblacion Relief Goods
--   4 = Agdao Food Packs,     5 = Agdao Cash 40000,     6 = Talomo Food Packs
-- This also establishes a real, up-to-date cash budget (50000 - distributions).
INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, distributed_at) VALUES
(1, 1, 1, 2, 'food_pack',    1, 0,    'Poblacion Barangay 1-A Gymnasium', NOW() - INTERVAL 10 DAY),
(1, 2, 1, 2, 'cash',         1, 1000, 'Poblacion Barangay 1-A Gymnasium', NOW() - INTERVAL 10 DAY),
(2, 3, 1, 2, 'relief_goods', 1, 0,    'Poblacion Barangay 1-A Hall',      NOW() - INTERVAL 9 DAY),
(3, 5, 2, 2, 'cash',         1, 800,  'Agdao Proper Covered Court',       NOW() - INTERVAL 8 DAY),
(4, 4, 2, 2, 'food_pack',    1, 0,    'Agdao Proper Covered Court',       NOW() - INTERVAL 7 DAY),
(5, 6, 3, 2, 'food_pack',    1, 0,    'Talomo Proper Barangay Hall',      NOW() - INTERVAL 6 DAY),
(6, 5, 2, 2, 'cash',         1, 1200, 'Agdao Proper Covered Court',       NOW() - INTERVAL 5 DAY);

-- Track when Barangay Staff relay a district announcement to the public
-- (beneficiary) side of their barangay.
CREATE TABLE IF NOT EXISTS announcement_relays (
  id int(11) NOT NULL AUTO_INCREMENT,
  announcement_id int(11) NOT NULL,
  barangay_id int(11) NOT NULL,
  relayed_by int(11) DEFAULT NULL,
  relayed_at datetime DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ann_brgy (announcement_id, barangay_id),
  KEY barangay_id (barangay_id),
  KEY relayed_by (relayed_by),
  CONSTRAINT announcement_relays_ibfk_1 FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
  CONSTRAINT announcement_relays_ibfk_2 FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE CASCADE,
  CONSTRAINT announcement_relays_ibfk_3 FOREIGN KEY (relayed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Track which announcements each staff member has already seen, so the
-- bottom-nav "Announcements" tab can show a red "new" count.
CREATE TABLE IF NOT EXISTS announcement_seen (
  user_id int(11) NOT NULL,
  announcement_id int(11) NOT NULL,
  seen_at datetime DEFAULT current_timestamp(),
  PRIMARY KEY (user_id, announcement_id),
  KEY announcement_id (announcement_id),
  CONSTRAINT announcement_seen_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT announcement_seen_ibfk_2 FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Private 1:1 message threads between Barangay Staff and the District Admin.
CREATE TABLE IF NOT EXISTS messages (
  id int(11) NOT NULL AUTO_INCREMENT,
  sender_id int(11) NOT NULL,
  recipient_id int(11) NOT NULL,
  message text NOT NULL,
  is_read tinyint(1) NOT NULL DEFAULT 0,
  read_at datetime DEFAULT NULL,
  created_at datetime DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_msg_pair (sender_id, recipient_id),
  KEY idx_msg_inbox (recipient_id, is_read),
  CONSTRAINT messages_ibfk_1 FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT messages_ibfk_2 FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
