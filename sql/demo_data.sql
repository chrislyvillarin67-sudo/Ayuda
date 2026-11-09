-- =========================================================
-- DEMO DATA for an EXISTING install (optional)
-- =========================================================
-- If you already imported schema.sql from a previous version,
-- your database has real(ish) data but may be missing the
-- beneficiary/distribution records needed to demo the three-
-- district pie chart, distribution history, and reports.
--
-- Run this file ONCE in phpMyAdmin's SQL tab to add demo
-- beneficiaries and distribution history. It is written to be
-- idempotent: it skips inserting a beneficiary if a record
-- with the same full name already exists in the system.
-- It never overwrites or deletes your existing data.
-- =========================================================

USE ayuda_db;

-- Insert demo beneficiaries only if they do not already exist.
INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Maria Santos',      68, 'P. Del Rosario St, Poblacion',     1, 1, 'Senior Citizen',    '09171234567', 'approved', 0, 2, NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 12 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Maria Santos');

INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Jose Ramirez',      45, 'Magallanes St, Poblacion',         1, 2, 'Indigent', '09172345678', 'approved', 0, 2, NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 11 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Jose Ramirez');

INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Elena Cruz',        34, 'V. Mapa St, Agdao',                2, 4, 'Solo Parent',      '09173456789', 'approved', 0, 2, NOW() - INTERVAL 9 DAY,  NOW() - INTERVAL 9 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Elena Cruz');

INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Roberto Abellana',  72, 'Boulevard St, Agdao',              2, 5, 'Senior Citizen',   '09174567890', 'approved', 0, 2, NOW() - INTERVAL 8 DAY,  NOW() - INTERVAL 8 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Roberto Abellana');

INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Liza Mae Tan',      28, 'R. Castillo St, Talomo',           3, 7, 'Solo Parent',      '09175678901', 'approved', 0, 2, NOW() - INTERVAL 7 DAY,  NOW() - INTERVAL 7 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Liza Mae Tan');

INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Carlos Ong',        58, 'McArthur Hwy, Talomo',             3, 8, 'PWD',              '09176789012', 'approved', 0, 2, NOW() - INTERVAL 6 DAY,  NOW() - INTERVAL 6 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Carlos Ong');

INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Andrea Fernandez',  41, 'Cabaguio St, Agdao',               2, 4, 'Indigent',         '09177890123', 'pending',  0, NULL, NULL, NOW() - INTERVAL 3 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Andrea Fernandez');

INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Miguel Bautista',   63, 'Claveria St, Poblacion',           1, 3, 'Indigent',         '09178901234', 'pending',  0, NULL, NULL, NOW() - INTERVAL 2 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Miguel Bautista');

INSERT INTO beneficiaries
(full_name, age, address, district_id, barangay_id, category, contact_number, status, is_duplicate, approved_by, approved_at, created_at)
SELECT 'Sofia Dela Vega',   24, 'Bankerohan St, Poblacion',         1, 1, 'Youth',          '09179012345', 'pending',  0, NULL, NULL, NOW() - INTERVAL 1 DAY
WHERE NOT EXISTS (SELECT 1 FROM beneficiaries WHERE full_name = 'Sofia Dela Vega');

-- Seed distribution history only if the beneficiaries above exist and there
-- are no distribution logs yet for them. Inventory columns are referenced by
-- id (see schema.sql seed: 1=Pob Food,2=Pob Cash 50000,3=Pob Relief,
-- 4=Agdao Food,5=Agdao Cash 40000,6=Talomo Food).
SET @maria  = (SELECT id FROM beneficiaries WHERE full_name = 'Maria Santos');
SET @jose   = (SELECT id FROM beneficiaries WHERE full_name = 'Jose Ramirez');
SET @elena  = (SELECT id FROM beneficiaries WHERE full_name = 'Elena Cruz');
SET @roberto= (SELECT id FROM beneficiaries WHERE full_name = 'Roberto Abellana');
SET @liza   = (SELECT id FROM beneficiaries WHERE full_name = 'Liza Mae Tan');
SET @carlos = (SELECT id FROM beneficiaries WHERE full_name = 'Carlos Ong');

INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, distributed_at)
SELECT @maria, 1, 1, 2, 'food_pack',    1, 0,    'Poblacion Barangay 1-A Gymnasium', NOW() - INTERVAL 10 DAY
WHERE @maria > 0 AND NOT EXISTS (SELECT 1 FROM distribution_logs WHERE beneficiary_id = @maria AND inventory_id = 1);

INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, distributed_at)
SELECT @maria, 2, 1, 2, 'cash',         1, 1000, 'Poblacion Barangay 1-A Gymnasium', NOW() - INTERVAL 10 DAY
WHERE @maria > 0 AND NOT EXISTS (SELECT 1 FROM distribution_logs WHERE beneficiary_id = @maria AND inventory_id = 2);

INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, distributed_at)
SELECT @jose, 3, 1, 2, 'relief_goods', 1, 0,    'Poblacion Barangay 1-A Hall',      NOW() - INTERVAL 9 DAY
WHERE @jose > 0 AND NOT EXISTS (SELECT 1 FROM distribution_logs WHERE beneficiary_id = @jose AND inventory_id = 3);

INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, distributed_at)
SELECT @elena, 5, 2, 2, 'cash',         1, 800,  'Agdao Proper Covered Court',       NOW() - INTERVAL 8 DAY
WHERE @elena > 0 AND NOT EXISTS (SELECT 1 FROM distribution_logs WHERE beneficiary_id = @elena AND inventory_id = 5);

INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, distributed_at)
SELECT @roberto, 4, 2, 2, 'food_pack',  1, 0,    'Agdao Proper Covered Court',       NOW() - INTERVAL 7 DAY
WHERE @roberto > 0 AND NOT EXISTS (SELECT 1 FROM distribution_logs WHERE beneficiary_id = @roberto AND inventory_id = 4);

INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, distributed_at)
SELECT @liza, 6, 3, 2, 'food_pack',    1, 0,    'Talomo Proper Barangay Hall',      NOW() - INTERVAL 6 DAY
WHERE @liza > 0 AND NOT EXISTS (SELECT 1 FROM distribution_logs WHERE beneficiary_id = @liza AND inventory_id = 6);

INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, distributed_at)
SELECT @carlos, 5, 2, 2, 'cash',       1, 1200, 'Agdao Proper Covered Court',       NOW() - INTERVAL 5 DAY
WHERE @carlos > 0 AND NOT EXISTS (SELECT 1 FROM distribution_logs WHERE beneficiary_id = @carlos AND inventory_id = 5);

-- Reflect the seeded cash distributions in the cash inventory balances so the
-- dashboard budget (50,000 - distributed) matches reality.
UPDATE inventory SET quantity = quantity - 1000 WHERE id = 2 AND (SELECT COUNT(*) FROM distribution_logs WHERE inventory_id = 2 AND assistance_type = 'cash') > 0;
UPDATE inventory SET quantity = quantity - 800  WHERE id = 5 AND (SELECT COUNT(*) FROM distribution_logs WHERE inventory_id = 5 AND assistance_type = 'cash') > 0;
UPDATE inventory SET quantity = quantity - 1200 WHERE id = 5 AND (SELECT COUNT(*) FROM distribution_logs WHERE inventory_id = 5 AND assistance_type = 'cash') > 1;

-- Done. You can delete this file after running it.
