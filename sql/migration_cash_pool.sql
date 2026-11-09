-- ─────────────────────────────────────────────────────────────────────────────
-- Cash as ONE shared citywide pool.
--
-- Previously every barangay had its own "Cash Assistance" inventory row, so the
-- balance shown on a card (citywide SUM) never matched the balance the
-- distribution page actually drew from (that one barangay's row). Cash is a
-- citywide budget, so this collapses all cash rows into a single pool row with
-- barangay_id = NULL. Every screen then reads/writes that one number.
--
-- Safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1) Allow a pool row that belongs to no single barangay.
ALTER TABLE inventory MODIFY COLUMN barangay_id INT NULL DEFAULT NULL;

-- 2) Create the pool row if it does not exist yet. Its starting balance is the
--    sum of every per-barangay cash balance that is being folded in.
INSERT INTO inventory
    (barangay_id, item_name, quantity, unit, source, assistance_type, location, low_stock_threshold)
SELECT NULL, 'Cash Assistance', COALESCE(SUM(quantity), 0), 'peso',
       'Citywide cash budget (shared pool)', 'cash', 'CSWDO Citywide', 0
FROM inventory
WHERE assistance_type = 'cash' AND barangay_id IS NOT NULL
HAVING COUNT(*) > 0;

-- 3) Make sure the pool is the one row that survives, and re-point every
--    foreign key that referenced a per-barangay cash row onto it.
SET @pool := (SELECT id FROM inventory
              WHERE assistance_type = 'cash' AND barangay_id IS NULL
              ORDER BY id LIMIT 1);

UPDATE distribution_logs        SET inventory_id = @pool WHERE assistance_type = 'cash' AND inventory_id <> @pool;
UPDATE inventory_transactions   SET inventory_id = @pool WHERE inventory_id IN
       (SELECT id FROM inventory WHERE assistance_type = 'cash' AND barangay_id IS NOT NULL);
UPDATE inventory_reservations   SET inventory_id = @pool WHERE inventory_id IN
       (SELECT id FROM inventory WHERE assistance_type = 'cash' AND barangay_id IS NOT NULL);
UPDATE eligibility_lists        SET inventory_id = @pool WHERE assistance_type = 'cash' AND inventory_id IS NOT NULL;

-- 4) Drop the now-redundant per-barangay cash rows (balances already folded in).
DELETE FROM inventory WHERE assistance_type = 'cash' AND barangay_id IS NOT NULL;

-- 5) Keep only one pool row even if this ran twice.
DELETE i1 FROM inventory i1
JOIN inventory i2 ON i2.assistance_type = 'cash' AND i2.barangay_id IS NULL AND i2.id < i1.id
WHERE i1.assistance_type = 'cash' AND i1.barangay_id IS NULL AND i1.id > i2.id;
