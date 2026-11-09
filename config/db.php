<?php
/**
 * Database connection settings.
 * Update these values to match your XAMPP / hosting environment.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'ayuda_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Local application timezone. XAMPP's php.ini ships with date.timezone =
// Europe/Berlin, while MySQL uses the machine's SYSTEM clock -- so PHP's "now"
// and MySQL's NOW()/CURRENT_TIMESTAMP disagreed by hours, which put the wrong
// dates/times on distributions, logs and announcements. Pin both to the
// project's locale so every date/time lines up (Davao City, Philippines).
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}
date_default_timezone_set(APP_TIMEZONE);

// Base URL of this app -- used to build links in emails (password reset,
// welcome message, etc).
// Left empty it is auto-detected from the current request, so the project works
// on any machine even if the folder is renamed or moved (e.g. htdocs/Ayuda).
// Set it manually only when the app sits behind a proxy / different domain,
// e.g. define('BASE_URL', 'https://ayuda.example.com/');
if (!defined('BASE_URL')) {
    define('BASE_URL', ayuda_auto_base_url());
}

/** Best-effort detection of this app's own base URL (scheme + folder path). */
function ayuda_auto_base_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
           || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    // SCRIPT_NAME is e.g. "/Ayuda/includes/functions.php"; we need "/Ayuda/".
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    // config/ sits one level below the app root when reached via includes/.
    if (substr($dir, -9) === '/includes') $dir = substr($dir, 0, -9);
    if (substr($dir, -7) === '/config')   $dir = substr($dir, 0, -7);
    $dir = rtrim($dir, '/');

    return $scheme . '://' . $host . $dir . '/';
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // Keep MySQL's NOW()/CURRENT_TIMESTAMP in the same timezone as PHP, so
    // stored timestamps and "today" comparisons agree. Numeric offset avoids
    // depending on MySQL's named timezone tables (which XAMPP may not load).
    $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P'); // e.g. +08:00
    $pdo->exec("SET time_zone = '" . $offset . "'");
} catch (PDOException $e) {
    die("<div style='font-family:sans-serif;max-width:600px;margin:60px auto;padding:24px;border:1px solid #EA4335;border-radius:12px;background:#fff5f5;color:#7a1f1f;'>
        <h2 style='margin-top:0;'>Database Connection Error</h2>
        <p>Could not connect to the database. This usually means MySQL is not running.</p>
        <ul>
            <li>Double-click <code>start-ayuda.cmd</code> (in this app's folder) to start MySQL + the web server and keep them running.</li>
            <li>Or start MySQL in <strong>XAMPP Control Panel &rarr; Admin</strong>.</li>
            <li>Make sure a database named <code>ayuda_db</code> exists (import <code>sql/schema.sql</code> via phpMyAdmin if new).</li>
            <li>Check DB_USER / DB_PASS in <code>config/db.php</code> if changed.</li>
        </ul>
        <p style='color:#999;font-size:13px;'>Technical detail: " . htmlspecialchars($e->getMessage()) . "</p>
    </div>");
}

/**
 * Self-healing schema check.
 * Some installs were set up from an older copy of schema.sql that didn't
 * yet have these columns. Instead of relying on someone remembering to run
 * a migration .sql file by hand, we just check for them and add them
 * automatically, once, the first time the app runs against this database.
 */
function ayuda_ensure_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return; // only run once per request
    $checked = true;

    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!$dbName) return;

        $colExists = function (string $table, string $column) use ($pdo, $dbName): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                                    WHERE table_schema = ? AND table_name = ? AND column_name = ?");
            $stmt->execute([$dbName, $table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        };

        if (!$colExists('beneficiaries', 'beneficiary_code')) {
            $pdo->exec("ALTER TABLE beneficiaries ADD COLUMN beneficiary_code VARCHAR(100) DEFAULT NULL UNIQUE AFTER id");
        }
        // Cash is split into two funds (General / Emergency). Both live in the
        // citywide pool; cash_fund_type tells them apart. eligibility_lists also
        // carries the fund so confirmed lists and distributions stay on the right pot.
        if (!$colExists('inventory', 'cash_fund_type')) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN cash_fund_type ENUM('general','emergency') NULL DEFAULT NULL AFTER assistance_type");
        }
        if (!$colExists('eligibility_lists', 'cash_fund_type')) {
            $pdo->exec("ALTER TABLE eligibility_lists ADD COLUMN cash_fund_type ENUM('general','emergency') NULL DEFAULT NULL AFTER assistance_type");
        }
        if (!$colExists('users', 'status')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER barangay_id");
        }
        if (!$colExists('users', 'photo_path')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL AFTER status");
        }
        // DEVIATION (per doc feedback): needed to track "last seen" so the
        // beneficiary app can show a notification dot / popup for new updates.
        if (!$colExists('beneficiaries', 'last_seen_updates_at')) {
            $pdo->exec("ALTER TABLE beneficiaries ADD COLUMN last_seen_updates_at DATETIME DEFAULT NULL AFTER approved_at");
        }
        // Forgot-password support (reset token + expiry) for both account types.
        if (!$colExists('users', 'reset_token')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_expires DATETIME DEFAULT NULL");
        }
        if (!$colExists('beneficiaries', 'reset_token')) {
            $pdo->exec("ALTER TABLE beneficiaries ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_expires DATETIME DEFAULT NULL");
        }

        // Ayuda categorization (per improvement notes: categorize per ayuda type
        // and add sponsored/location inputs). Added to existing installs so they
        // get the same behavior as a fresh schema.sql import.
        if (!$colExists('inventory', 'assistance_type')) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN assistance_type ENUM('cash','food_pack','relief_goods','sponsored','other') NOT NULL DEFAULT 'other' AFTER unit, ADD COLUMN cash_fund_type ENUM('general','emergency') NULL AFTER assistance_type");
        }
        if (!$colExists('inventory', 'location')) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN location VARCHAR(150) DEFAULT NULL AFTER assistance_type");
        }
        // Inventory quantity now holds a peso budget for cash items, so widen it.
        $qType = $pdo->query("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'inventory' AND column_name = 'quantity'")->fetchColumn();
        if ($qType && strtolower($qType) === 'int') {
            $pdo->exec("ALTER TABLE inventory MODIFY COLUMN quantity DECIMAL(12,2) NOT NULL DEFAULT 0");
        }
        if (!$colExists('distribution_logs', 'assistance_type')) {
            $pdo->exec("ALTER TABLE distribution_logs ADD COLUMN assistance_type ENUM('cash','food_pack','relief_goods','sponsored','other') NOT NULL DEFAULT 'other' AFTER distributed_by");
        }
        if (!$colExists('distribution_logs', 'amount')) {
            $pdo->exec("ALTER TABLE distribution_logs ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantity");
        }
        if (!$colExists('distribution_logs', 'location')) {
            $pdo->exec("ALTER TABLE distribution_logs ADD COLUMN location VARCHAR(150) DEFAULT NULL AFTER amount");
        }
        if (!$colExists('distribution_logs', 'description')) {
            $pdo->exec("ALTER TABLE distribution_logs ADD COLUMN description TEXT DEFAULT NULL AFTER location");
        }
        // beneficiary_id is now optional: the District Admin records a
        // distribution with a free-text description instead of a named
        // beneficiary. Make it nullable on existing installs.
        $bType = $pdo->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'distribution_logs' AND column_name = 'beneficiary_id'")->fetchColumn();
        if ($bType && strtoupper($bType) === 'NO') {
            $pdo->exec("ALTER TABLE distribution_logs MODIFY COLUMN beneficiary_id INT NULL DEFAULT NULL");
        }

        // Audit log (per IT111 Doc: "implement an audit trail for all critical
        // actions"). Created on existing installs too, no manual migration needed.
        $tblExists = function (string $table) use ($pdo, $dbName): bool {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
            $stmt->execute([$dbName, $table]);
            return (int)$stmt->fetchColumn() > 0;
        };
        if (!$tblExists('audit_logs')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                role VARCHAR(30) DEFAULT NULL,
                action VARCHAR(100) NOT NULL,
                details TEXT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_audit_user (user_id),
                INDEX idx_audit_created (created_at)
            ) ENGINE=InnoDB");
        }

        // ── Redesign (Chrisly Villarin doc) ────────────────────────────────
        // 1) Widen beneficiary status to support reject / return-for-correction.
        $stype = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'beneficiaries' AND column_name = 'status'")->fetchColumn();
        if ($stype && stripos($stype, 'rejected') === false) {
            $pdo->exec("ALTER TABLE beneficiaries MODIFY COLUMN status ENUM('pending','approved','rejected','returned') NOT NULL DEFAULT 'pending'");
        }
        // 2a) Beneficiary categories. The CORE list is now Senior Citizen /
        //     Solo Parent / PWD / Indigent / Student. Family / Women / Youth /
        //     Emergency were demo-only and are gone, so fold the demo rows
        //     into Indigent first (invalid ENUM values silently become ''),
        //     then shrink the ENUM and finally map what remains to Student.
        if ($colExists('beneficiaries', 'category')) {
            $ctype = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'beneficiaries' AND column_name = 'category'")->fetchColumn();
            if ($ctype && stripos($ctype, 'Student') === false) {
                $pdo->exec("UPDATE beneficiaries SET category='Indigent' WHERE category IN ('Family','Women','Emergency','Displaced Worker')");
                $pdo->exec("DELETE FROM beneficiaries WHERE category='' OR category IS NULL");
                $pdo->exec("ALTER TABLE beneficiaries MODIFY COLUMN category ENUM('Senior Citizen','Solo Parent','PWD','Indigent','Student') NOT NULL");
                // Whatever was invalid in the old ENUM (Youth) has now become ''.
                $pdo->exec("UPDATE beneficiaries SET category='Student' WHERE category='' OR category IS NULL");
            }
        }
        // 2) Date of birth (duplicate detection by name + DOB + barangay).
        if (!$colExists('beneficiaries', 'date_of_birth')) {
            $pdo->exec("ALTER TABLE beneficiaries ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER monthly_income");
        }
        // 2c) Age is no longer part of a beneficiary's stored profile -- the
        //     date of birth already carries that information, so drop the column.
        if ($colExists('beneficiaries', 'age')) {
            $pdo->exec("ALTER TABLE beneficiaries DROP COLUMN age");
        }
        // 2b) Beneficiary profile detail. Names are split so they can be shown as
        // first name / middle initial / last name instead of one merged string.
        // full_name stays as the canonical display + duplicate-detection key.
        if (!$colExists('beneficiaries', 'first_name')) {
            $pdo->exec("ALTER TABLE beneficiaries
                ADD COLUMN first_name       VARCHAR(100) DEFAULT NULL AFTER full_name,
                ADD COLUMN middle_initial   VARCHAR(10)  DEFAULT NULL AFTER first_name,
                ADD COLUMN last_name        VARCHAR(100) DEFAULT NULL AFTER middle_initial,
                ADD COLUMN gender           ENUM('Female','Male','Prefer not to say') DEFAULT NULL AFTER last_name,
                ADD COLUMN civil_status     ENUM('Single','Married','Widowed','Separated/Annulled') DEFAULT NULL AFTER gender,
                ADD COLUMN occupation       VARCHAR(120) DEFAULT NULL AFTER civil_status,
                ADD COLUMN daily_income     DECIMAL(12,2) DEFAULT NULL AFTER occupation,
                ADD COLUMN monthly_income   DECIMAL(12,2) DEFAULT NULL AFTER daily_income,
                ADD COLUMN house_occupancy  ENUM('Owner','Renter','Sharer','Other') DEFAULT NULL AFTER monthly_income,
                ADD COLUMN lot_occupancy    ENUM('Owner','Renter','Sharer','Other') DEFAULT NULL AFTER house_occupancy");

            // One-time backfill: split every existing "Surname, Given [Middle]" row.
            // NOTE: trim before tokenising - a leading space makes
            // SUBSTRING_INDEX return an empty string for the given name.
            $pdo->exec("UPDATE beneficiaries
                SET first_name = SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(full_name, ',', -1)), ' ', 1),
                    last_name  = TRIM(SUBSTRING_INDEX(full_name, ',', 1))
                WHERE full_name LIKE '%,%'");
            $pdo->exec("UPDATE beneficiaries
                SET middle_initial = UPPER(LEFT(SUBSTRING_INDEX(
                        TRIM(SUBSTRING_INDEX(full_name, ',', -1)), ' ', -1), 1))
                WHERE full_name LIKE '%,%'
                  AND SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(full_name, ',', -1)), ' ', -1)
                   <> SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(full_name, ',', -1)), ' ', 1)");
            // Fallback for un-normalized rows that were never surname-first.
            $pdo->exec("UPDATE beneficiaries
                SET first_name = SUBSTRING_INDEX(TRIM(full_name), ' ', 1),
                    last_name  = SUBSTRING_INDEX(TRIM(full_name), ' ', -1)
                WHERE full_name NOT LIKE '%,%' AND TRIM(full_name) LIKE '% %'");
        }
        // 2d) Multiple eligibility categories per beneficiary. A person can be
        //     e.g. Senior Citizen *and* PWD *and* Solo Parent (up to 3). The
        //     beneficiaries.category column stays as the "primary" (first)
        //     category so older single-category screens keep working; this join
        //     table holds the full membership set. Backfilled once from the
        //     existing column when the table is first created.
        if (!$tblExists('beneficiary_categories')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS beneficiary_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                beneficiary_id INT NOT NULL,
                category ENUM('Senior Citizen','Solo Parent','PWD','Indigent','Student') NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_beneficiary_category (beneficiary_id, category),
                INDEX idx_bc_category (category),
                FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE
            ) ENGINE=InnoDB");
            $pdo->exec("INSERT IGNORE INTO beneficiary_categories (beneficiary_id, category)
                        SELECT id, category FROM beneficiaries
                        WHERE category IS NOT NULL AND category <> ''");
        }
        // 3) Reject / return tracking.
        if (!$colExists('beneficiaries', 'rejected_at')) {
            $pdo->exec("ALTER TABLE beneficiaries ADD COLUMN rejected_at DATETIME DEFAULT NULL AFTER approved_at, ADD COLUMN rejected_reason VARCHAR(255) DEFAULT NULL AFTER rejected_at, ADD COLUMN return_reason VARCHAR(255) DEFAULT NULL AFTER rejected_reason");
        }
        // 4) Verification-step separation (Barangay initial vs CSWDO final).
        if (!$colExists('beneficiaries', 'verification_step')) {
            $pdo->exec("ALTER TABLE beneficiaries ADD COLUMN verification_step ENUM('submitted','barangay','cswdo','approved') DEFAULT 'submitted' AFTER status");
        }
        // 5) Distribution programs + assignments.
        if (!$tblExists('distribution_programs')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS distribution_programs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                assistance_type ENUM('cash','food_pack','relief_goods','hygiene','other') NOT NULL DEFAULT 'other',
                program_date DATE NOT NULL,
                program_time TIME DEFAULT NULL,
                location VARCHAR(150) DEFAULT NULL,
                barangay_id INT DEFAULT NULL,
                status ENUM('scheduled','ongoing','completed','cancelled') NOT NULL DEFAULT 'scheduled',
                created_by INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB");
        }
        if (!$tblExists('distribution_program_assignments')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS distribution_program_assignments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                program_id INT NOT NULL,
                beneficiary_id INT NOT NULL,
                received TINYINT(1) NOT NULL DEFAULT 0,
                received_at DATETIME DEFAULT NULL,
                assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (program_id) REFERENCES distribution_programs(id) ON DELETE CASCADE,
                FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
                UNIQUE KEY uq_program_beneficiary (program_id, beneficiary_id)
            ) ENGINE=InnoDB");
        }
        // Inventory transaction history (per "Chrisly Villarin.docx" Inventory
        // Management: "View inventory transactions" / "Inventory history").
        if (!$tblExists('inventory_transactions')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                inventory_id INT NOT NULL,
                barangay_id INT NOT NULL,
                item_name VARCHAR(100) NOT NULL,
                type ENUM('add','replenish','deduct') NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
                balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
                done_by INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
            ) ENGINE=InnoDB");
        }

        // ── Redesign 2 (beneficiary view-only + district-admin distribution) ──
        // A "distribution schedule / notice" carries the venue, date, time and
        // what-to-bring that Barangay Staff announce and beneficiaries view.
        // Its published recipient list is the "final list".
        if (!$tblExists('distribution_schedules')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS distribution_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                aid_type VARCHAR(30) DEFAULT NULL,
                barangay_id INT NOT NULL,
                district_id INT NOT NULL,
                venue VARCHAR(150) DEFAULT NULL,
                schedule_date DATE DEFAULT NULL,
                schedule_time VARCHAR(30) DEFAULT NULL,
                what_to_bring VARCHAR(255) DEFAULT NULL,
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
            ) ENGINE=InnoDB");
        }
        // The what_to_bring note may now hold a full description (not just a
        // short label), so widen it from VARCHAR(255) to TEXT for existing DBs.
        $wt = $pdo->query("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'distribution_schedules' AND column_name = 'what_to_bring'")->fetchColumn();
        if ($wt && strtolower($wt) !== 'text') {
            $pdo->exec("ALTER TABLE distribution_schedules MODIFY COLUMN what_to_bring TEXT DEFAULT NULL");
        }
        // aid_type lets the District Admin's Distribution Process filter the day's
        // schedules by kind of aid (Cash / Food Packs / Hygiene Kit).
        $atCol = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'distribution_schedules' AND column_name = 'aid_type'")->fetchColumn();
        if (!$atCol) {
            $pdo->exec("ALTER TABLE distribution_schedules ADD COLUMN aid_type VARCHAR(30) DEFAULT NULL AFTER title");
        }
        // is_claimed per member records who actually showed up and received the
        // ayuda on distribution day (the day-of claiming checklist).
        $icCol = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'distribution_schedule_members' AND column_name = 'is_claimed'")->fetchColumn();
        if (!$icCol) {
            $pdo->exec("ALTER TABLE distribution_schedule_members ADD COLUMN is_claimed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_receiver, ADD COLUMN claimed_at DATETIME DEFAULT NULL AFTER is_claimed");
        }
        if (!$tblExists('distribution_schedule_members')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS distribution_schedule_members (
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
            ) ENGINE=InnoDB");
        }

        // Per-beneficiary release outcome for each scheduled distribution, so a
        // profile can say pending / released / not released (deceased, refused,
        // moved away...) instead of only showing what was handed over.
        if (!$colExists('distribution_schedule_members', 'release_status')) {
            $pdo->exec("ALTER TABLE distribution_schedule_members
                ADD COLUMN release_status ENUM('pending','released','not_released') NOT NULL DEFAULT 'pending' AFTER is_claimed,
                ADD COLUMN release_note VARCHAR(255) DEFAULT NULL AFTER release_status,
                ADD COLUMN release_at DATETIME DEFAULT NULL AFTER release_note,
                ADD COLUMN release_by INT DEFAULT NULL AFTER release_at");
            // Backfill: already collected, then finished days where nobody
            // showed up, the rest is still pending.
            $pdo->exec("UPDATE distribution_schedule_members SET release_status='released', release_at=claimed_at WHERE is_claimed=1");
            $pdo->exec("UPDATE distribution_schedule_members m
                JOIN distribution_schedules s ON s.id=m.schedule_id
                   SET m.release_status='not_released', m.release_note='Not claimed on distribution day', m.release_at=NOW()
                 WHERE s.status='completed' AND m.release_status='pending'");
        }

        // ── Redesign 3 (admin-confirmed eligibility / final list) ──────────
        // The District Admin (CSWDO) confirms which members of a barangay are
        // eligible to receive a given type of aid. This confirmed list is then
        // what gets distributed to all at once. It also feeds the staff side.
        if (!$tblExists('eligibility_lists')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS eligibility_lists (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                barangay_id INT NOT NULL,
                district_id INT NOT NULL,
                assistance_type ENUM('cash','food_pack','relief_goods','sponsored','other') NOT NULL DEFAULT 'other',
                inventory_id INT DEFAULT NULL,
                status ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
                created_by INT DEFAULT NULL,
                confirmed_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
                FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE,
                FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB");
        }
        if (!$tblExists('eligibility_list_members')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS eligibility_list_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                list_id INT NOT NULL,
                beneficiary_id INT NOT NULL,
                FOREIGN KEY (list_id) REFERENCES eligibility_lists(id) ON DELETE CASCADE,
                FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE,
                UNIQUE KEY uq_elist_beneficiary (list_id, beneficiary_id)
            ) ENGINE=InnoDB");
        }
        // eligibility list status supports draft / confirmed / completed.
        if ($tblExists('eligibility_lists')) {
            $est = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'eligibility_lists' AND column_name = 'status'")->fetchColumn();
            if ($est && stripos($est, 'completed') === false) {
                $pdo->exec("ALTER TABLE eligibility_lists MODIFY COLUMN status ENUM('draft','confirmed','completed') NOT NULL DEFAULT 'draft'");
            }
        }

        // ── Inventory reservations (scheduled / reserved packs) ────────────
        // Stock can be set aside ("reserved") for an upcoming distribution
        // program or schedule. Reserved quantity is NOT available for ad-hoc
        // distribution and appears in the inventory history as "Reserved".
        if (!$tblExists('inventory_reservations')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_reservations (
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
            ) ENGINE=InnoDB");
        }
        // Widen inventory_transactions to track reserved / released / cancelled
        // stock movements alongside added / replenished / distributed.
        if ($tblExists('inventory_transactions')) {
            $itype = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'inventory_transactions' AND column_name = 'type'")->fetchColumn();
            if ($itype && stripos($itype, 'reserve') === false) {
                $pdo->exec("ALTER TABLE inventory_transactions MODIFY COLUMN type ENUM('add','replenish','reserve','release','cancel','deduct') NOT NULL");
            }
            $isrc = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'inventory_transactions' AND column_name = 'source'")->fetchColumn();
            if (!$isrc) {
                $pdo->exec("ALTER TABLE inventory_transactions ADD COLUMN source VARCHAR(100) DEFAULT NULL AFTER item_name");
            }
            $inotes = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'inventory_transactions' AND column_name = 'notes'")->fetchColumn();
            if (!$inotes) {
                $pdo->exec("ALTER TABLE inventory_transactions ADD COLUMN notes VARCHAR(255) DEFAULT NULL AFTER source");
            }
        }

        // ── Direct messages (staff <-> district admin inbox) ──────────────
        // Private 1:1 threads so Barangay Staff can ask the District Admin /
        // CSWDO for updates and confirmations without using the emergency
        // report flow. Staff talk to their admin; the admin replies to any staff.
        if (!$tblExists('messages')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender_id INT NOT NULL,
                recipient_id INT NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_msg_pair (sender_id, recipient_id),
                INDEX idx_msg_inbox (recipient_id, is_read)
            ) ENGINE=InnoDB");
        }

        // ── Cash as one shared citywide pool ─────────────────────────────
        // Cash used to be a separate inventory row per barangay, so the number on
        // a card (citywide SUM) never matched what a distribution actually drew
        // from (that one barangay's row). It is now a single pool row with
        // barangay_id = NULL that every screen reads and deducts from, so the
        // balance is identical everywhere.
        if ($tblExists('inventory')) {
            // Allow a row that belongs to no single barangay.
            $bNull = $pdo->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = '$dbName' AND table_name = 'inventory' AND column_name = 'barangay_id'")->fetchColumn();
            if ($bNull && strtoupper($bNull) === 'NO') {
                $pdo->exec("ALTER TABLE inventory MODIFY COLUMN barangay_id INT NULL DEFAULT NULL");
            }

            $poolExists = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE assistance_type = 'cash' AND barangay_id IS NULL")->fetchColumn();

            if ($poolExists === 0) {
                // No pool yet: fold every per-barangay cash balance into one row.
                $sum = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE assistance_type='cash' AND barangay_id IS NOT NULL")->fetchColumn();
                $pdo->exec("INSERT INTO inventory (barangay_id, item_name, quantity, unit, source, assistance_type, location, low_stock_threshold)
                            VALUES (NULL, 'Cash Assistance', $sum, 'peso', 'Citywide cash budget (shared pool)', 'cash', 'CSWDO Citywide', 0)");
                $poolId = (int)$pdo->lastInsertId();
                // Re-point every reference at the pool before dropping the old rows.
                $pdo->exec("UPDATE distribution_logs SET inventory_id = $poolId WHERE assistance_type='cash' AND inventory_id <> $poolId");
                $pdo->exec("UPDATE inventory_transactions SET inventory_id = $poolId WHERE inventory_id IN
                            (SELECT id FROM inventory WHERE assistance_type='cash' AND barangay_id IS NOT NULL)");
                $pdo->exec("UPDATE inventory_reservations SET inventory_id = $poolId WHERE inventory_id IN
                            (SELECT id FROM inventory WHERE assistance_type='cash' AND barangay_id IS NOT NULL)");
                $pdo->exec("UPDATE eligibility_lists SET inventory_id = $poolId WHERE assistance_type='cash' AND inventory_id IS NOT NULL");
                $pdo->exec("DELETE FROM inventory WHERE assistance_type='cash' AND barangay_id IS NOT NULL");
            } elseif ($poolExists > 1) {
                // More than one pool row (migration ran twice): keep the oldest,
                // fold the others' balances into it, and drop them. Distinct
                // funds (General/Emergency) are legitimately separate rows, so
                // only legacy rows with an empty cash_fund_type are merged.
                $keep = (int)$pdo->query("SELECT id FROM inventory WHERE assistance_type='cash' AND barangay_id IS NULL AND (cash_fund_type='general' OR cash_fund_type IS NULL) ORDER BY id LIMIT 1")->fetchColumn();
                if ($keep > 0) {
                    $dust = $pdo->query("SELECT id FROM inventory WHERE assistance_type='cash' AND barangay_id IS NULL AND (cash_fund_type IS NULL OR cash_fund_type='general') AND id <> $keep")->fetchAll(PDO::FETCH_COLUMN);
                    $extraTotal = 0.0;
                    foreach ($dust as $did) {
                        $extraTotal += (float)$pdo->query("SELECT quantity FROM inventory WHERE id=$did")->fetchColumn();
                    }
                    $pdo->exec("UPDATE distribution_logs SET inventory_id = $keep WHERE assistance_type='cash' AND inventory_id IN (" . implode(',', $dust) . ")");
                    $pdo->exec("UPDATE inventory_transactions SET inventory_id = $keep WHERE inventory_id IN (" . implode(',', $dust) . ")");
                    $pdo->exec("DELETE FROM inventory WHERE id IN (" . implode(',', $dust) . ")");
                    $pdo->exec("UPDATE inventory SET quantity = quantity + $extraTotal WHERE id = $keep");
                }
            }

            // Every cash pool must expose both funds. If only a fund-less legacy
            // row exists, split it: General Fund keeps the balance, Emergency
            // Fund starts at 0.
            if (!$colExists('inventory', 'cash_fund_type')) {
                // column added above; safe-path guard only.
            }
            $generalId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM inventory WHERE assistance_type='cash' AND barangay_id IS NULL AND cash_fund_type='general'")->fetchColumn();
            $emergencyId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM inventory WHERE assistance_type='cash' AND barangay_id IS NULL AND cash_fund_type='emergency'")->fetchColumn();
            if ($generalId === 0) {
                // Successor to the original single-pool migration: re-tag the
                // fund-less pool row as General Fund (or create it from the sum
                // of any remaining cash rows).
                $poolRow = $pdo->query("SELECT id FROM inventory WHERE assistance_type='cash' AND barangay_id IS NULL AND (cash_fund_type IS NULL OR cash_fund_type='general') ORDER BY id LIMIT 1")->fetchColumn();
                if ($poolRow) {
                    $pdo->exec("UPDATE inventory SET cash_fund_type='general', item_name='General Fund' WHERE id=" . (int)$poolRow);
                    $generalId = (int)$poolRow;
                } else {
                    $sum = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE assistance_type='cash' AND barangay_id IS NULL")->fetchColumn();
                    $pdo->exec("INSERT INTO inventory (barangay_id, item_name, quantity, unit, source, assistance_type, cash_fund_type, location, low_stock_threshold)
                                VALUES (NULL, 'General Fund', $sum, 'peso', 'Citywide cash budget (shared pool)', 'cash', 'general', 'CSWDO Citywide (General Fund)', 0)");
                    $generalId = (int)$pdo->lastInsertId();
                }
            }
            if ($emergencyId === 0) {
                $pdo->exec("INSERT INTO inventory (barangay_id, item_name, quantity, unit, source, assistance_type, cash_fund_type, location, low_stock_threshold)
                            VALUES (NULL, 'Emergency Fund', 0, 'peso', 'Citywide emergency reserve (shared pool)', 'cash', 'emergency', 'CSWDO Citywide (Emergency Fund)', 0)");
            }
        }
    } catch (Throwable $e) {
        // Don't crash the whole app if the schema check itself fails
        // (e.g. DB user lacks ALTER privilege) -- just let the page continue.
    }
}
ayuda_ensure_schema($pdo);
