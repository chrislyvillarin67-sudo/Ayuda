<?php

// ─── Session hardening: HttpOnly + SameSite cookies before session_start() ───
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/apis.php';

// ─── Security Headers (CSP, clickjacking, MIME sniffing) ───
function sendSecurityHeaders(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://maps.googleapis.com https://maps.gstatic.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://*.tile.openstreetmap.org https://tile.openstreetmap.de https://maps.googleapis.com https://maps.gstatic.com; connect-src 'self' https://maps.googleapis.com https://maps.gstatic.com; font-src 'self';");
}
sendSecurityHeaders();

// ─── Session Timeout (30 minutes of inactivity) ───
$timeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    session_start();
    setFlash('warning', 'Your session expired due to inactivity. Please log in again.');
    $loginPath = (strpos($_SERVER['REQUEST_URI'] ?? '', '/beneficiary/') !== false)
        ? '../login.php' : 'login.php';
    header("Location: $loginPath");
    exit;
}
$_SESSION['last_activity'] = time();

// ─── CSRF Token Helpers ───
function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrfField(): string {
    return '<input type="hidden" name="_csrf_token" value="' . e(generateCSRFToken()) . '">';
}
function validateCSRF(): bool {
    $token = $_POST['_csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/** Redirect helper */
function redirect(string $path): void {
    header("Location: $path");
    exit;
}

/** Is anyone logged in (staff/admin) */
function isStaffLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']) && in_array($_SESSION['role'], ['district_admin','barangay_staff'], true);
}

/** Is a beneficiary logged in */
function isBeneficiaryLoggedIn(): bool {
    return isset($_SESSION['beneficiary_id']);
}

/** Require staff login, optionally restricted to a role */
function requireStaff(?string $role = null): void {
    if (!isStaffLoggedIn()) {
        redirect('login.php');
    }
    if ($role !== null && $_SESSION['role'] !== $role) {
        redirect('dashboard.php');
    }
}

/** Require beneficiary login */
function requireBeneficiary(): void {
    if (!isBeneficiaryLoggedIn()) {
        redirect('../login.php');
    }
}

/** Escape output */
function e(?string $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Single source of truth for beneficiary eligibility categories.
 * Keep in sync with the `category` ENUM in sql/schema.sql and the
 * MODIFY statement in config/db.php.
 */
const BENEFICIARY_CATEGORIES = ['Senior Citizen', 'Solo Parent', 'PWD', 'Indigent', 'Student'];

/** Category list for filter dropdowns (prepends "All"). */
function categoryFilterOptions(): array {
    return array_merge(['All'], BENEFICIARY_CATEGORIES);
}

/**
 * A person may belong to several eligibility categories at once (e.g. a PWD who
 * is also a Senior Citizen and a Solo Parent). Three is the practical cap the
 * CSWDO uses, so an entry beyond that is ignored.
 */
const MAX_BENEFICIARY_CATEGORIES = 3;

/** Keep only valid, unique categories (in the order given) and cap the count. */
function normalizeBeneficiaryCategories($categories): array {
    $out = [];
    foreach ((array)$categories as $c) {
        $c = trim((string)$c);
        if ($c !== '' && in_array($c, BENEFICIARY_CATEGORIES, true) && !in_array($c, $out, true)) {
            $out[] = $c;
            if (count($out) >= MAX_BENEFICIARY_CATEGORIES) break;
        }
    }
    return $out;
}

/**
 * The full set of categories a beneficiary belongs to. Reads the
 * beneficiary_categories join table; falls back to the primary `category`
 * column for rows written before the join table existed.
 */
function beneficiaryCategories(PDO $pdo, int $beneficiaryId): array {
    if ($beneficiaryId <= 0) return [];
    $stmt = $pdo->prepare("SELECT category FROM beneficiary_categories WHERE beneficiary_id = ? ORDER BY id");
    $stmt->execute([$beneficiaryId]);
    $cats = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$cats) {
        $stmt = $pdo->prepare("SELECT category FROM beneficiaries WHERE id = ?");
        $stmt->execute([$beneficiaryId]);
        $primary = $stmt->fetchColumn();
        if ($primary) $cats = [$primary];
    }
    return array_values(array_unique($cats));
}

/**
 * Bulk version of beneficiaryCategories(): id => [categories]. One query plus a
 * fallback query for any id without join rows.
 *
 * @param array<int,int> $beneficiaryIds
 * @return array<int, array<int,string>>
 */
function beneficiaryCategoriesMap(PDO $pdo, array $beneficiaryIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $beneficiaryIds), fn($i) => $i > 0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT beneficiary_id, category FROM beneficiary_categories WHERE beneficiary_id IN ($ph) ORDER BY id");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int)$r['beneficiary_id']][] = $r['category'];
    }
    $missing = array_values(array_diff($ids, array_keys($map)));
    if ($missing) {
        $ph2 = implode(',', array_fill(0, count($missing), '?'));
        $s2 = $pdo->prepare("SELECT id, category FROM beneficiaries WHERE id IN ($ph2)");
        $s2->execute($missing);
        foreach ($s2->fetchAll() as $r) {
            if (!empty($r['category'])) $map[(int)$r['id']] = [$r['category']];
        }
    }
    return $map;
}

/**
 * Replace a beneficiary's category set and keep the primary `category` column
 * pointing at the first one, so every legacy single-category screen stays
 * correct.
 *
 * @return array<int,string> the categories actually stored
 */
function syncBeneficiaryCategories(PDO $pdo, int $beneficiaryId, $categories): array {
    if ($beneficiaryId <= 0) return [];
    $cats = normalizeBeneficiaryCategories($categories);
    if (!$cats) {
        // A beneficiary must have at least one category: keep whatever primary
        // is already on the row rather than leaving both empty.
        $stmt = $pdo->prepare("SELECT category FROM beneficiaries WHERE id = ?");
        $stmt->execute([$beneficiaryId]);
        $primary = $stmt->fetchColumn();
        if ($primary) $cats = [$primary];
    }
    $pdo->prepare("DELETE FROM beneficiary_categories WHERE beneficiary_id = ?")->execute([$beneficiaryId]);
    if ($cats) {
        $ins = $pdo->prepare("INSERT INTO beneficiary_categories (beneficiary_id, category) VALUES (?, ?)");
        foreach ($cats as $c) $ins->execute([$beneficiaryId, $c]);
        $pdo->prepare("UPDATE beneficiaries SET category = ? WHERE id = ?")->execute([$cats[0], $beneficiaryId]);
    }
    return $cats;
}

/**
 * SQL fragment + params that match every beneficiary belonging to a category,
 * whether it is their primary category or an extra one. Works even before the
 * join table is backfilled because it still honors the primary column.
 *
 * @return array{0:string,1:array} [sql, params]
 */
function categoryMembershipSql(string $category, string $idExpr = 'b.id', string $primaryExpr = 'b.category'): array {
    return ["($primaryExpr = ? OR EXISTS (SELECT 1 FROM beneficiary_categories bcx WHERE bcx.beneficiary_id = $idExpr AND bcx.category = ?))",
            [$category, $category]];
}

/** Display a person's name with the surname first: "Juan Dela Cruz" -> "Dela Cruz, Juan".
 *  Idempotent: "Dela Cruz, Juan" stays as-is (safely re-runnable on already-flipped names). */
function formatName(?string $fullName): string {
    $fn = trim((string)$fullName);
    if ($fn === '') return $fn;
    if (strpos($fn, ',') !== false) return $fn; // already "Surname, Given"
    $parts = preg_split('/\s+/', $fn);
    if (count($parts) < 2) return $fn;
    $surname = array_pop($parts);
    return $surname . ', ' . implode(' ', $parts);
}

/** Normalize an entered name to "Surname, Given" (for storage). No-op on empty or already-surname-first. */
function surnameFirst(?string $fullName): string {
    return formatName($fullName);
}

/** Split a beneficiary record into [surname, first_name, middle_initial].
 *  Prefers the stored split columns; falls back to parsing the merged
 *  "Surname, Given M." (or "Given M. Surname") string from full_name. */
function nameParts(?string $last, ?string $first, ?string $mi, ?string $fullName = null): array {
    $surname = trim((string)$last);
    $given   = trim((string)$first);
    $mi      = trim((string)$mi);
    if ($surname === '' && $given === '') {
        $raw = trim((string)$fullName);
        $rest = $raw;
        if (strpos($raw, ',') !== false) {
            [$surname, $rest] = array_pad(explode(',', $raw, 2), 2, '');
            $surname = trim($surname);
            $rest = trim($rest);
        }
        $parts = $rest !== '' ? preg_split('/\s+/', $rest) : [];
        if ($parts) {
            $given = (string)array_shift($parts);
            if ($parts) $mi = rtrim((string)array_pop($parts), '.');
        }
    }
    $mi = preg_replace('/[\.\s]+/u', '', (string)$mi);
    return ['surname' => $surname, 'first_name' => $given, 'middle_initial' => mb_strtoupper($mi)];
}

/** Render the split name back out as one display string: "Santos, Maria M." */
function displayName(?string $first, ?string $mi, ?string $last): string {
    $first = trim((string)$first);
    $last  = trim((string)$last);
    $mi    = trim((string)$mi);
    if ($first === '' && $last === '') return '';
    $base = trim($first . ($mi !== '' ? ' ' . strtoupper($mi) . '.' : '') . ' ' . $last);
    return $last !== '' && $first !== '' ? ($last . ', ' . trim($first . ' ' . ($mi !== '' ? strtoupper($mi) . '.' : ''))) : $base;
}

/**
 * Data Privacy Act (R.A. 10173) notice.
 * Shown anywhere personal data is displayed: the beneficiary detail screen
 * (staff side) and the public name lookup (beneficiary side). The full text
 * lives in terms.php section 4.
 *
 * $compact = true gives the one-line version for tight layouts.
 */
function dataPrivacyNotice(bool $compact = false): string {
    if ($compact) {
        return '<div class="dp-notice dp-notice-inline">'
            . svgIcon('shield', 14)
            . '<span>Personal data shown here is processed under the <strong>Data Privacy Act of 2012 (R.A. No. 10173)</strong> '
            . 'solely to verify eligibility and coordinate aid. Access is limited to authorized barangay and district staff.</span></div>';
    }
    return '<div class="dp-notice">'
        . '<div class="dp-notice-title">' . svgIcon('shield', 16) . ' Data Privacy Act of 2012 (R.A. No. 10173)</div>'
        . '<p>The personal information displayed on this screen is collected and processed solely to verify eligibility, '
        . 'prevent duplicate claims, and coordinate aid distribution. It is accessible only to authorized Barangay Staff '
        . 'and District Admin accounts, retained only for as long as that purpose requires, and will not be sold or shared '
        . 'with third parties outside legitimate government aid-coordination purposes.</p>'
        . '<p class="dp-notice-foot">You may request access to, correction of, or removal of your record, or raise a concern '
        . 'about how it is handled, by contacting your Barangay Hall or the CSWDO. Complaints may be lodged with the '
        . 'National Privacy Commission (npc.gov.ph). Full terms are in <a href="terms.php?back=login.php" target="_blank" rel="noopener">Terms &amp; Conditions</a>.</p>'
        . '</div>';
}

/** Simple flash message system (set $html=true only for server-authored markup like links) */
function setFlash(string $type, string $message, bool $html = false): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'html' => $html];
}
function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/** Badge color class per status */
function statusBadgeClass(string $status): string {
    return match ($status) {
        'approved' => 'badge-success',
        'pending'  => 'badge-warning',
        'declined' => 'badge-danger',
        'rejected' => 'badge-danger',
        'returned' => 'badge-secondary',
        'scheduled'=> 'badge-navy',
        'ongoing'  => 'badge-warning',
        'completed'=> 'badge-success',
        'cancelled'=> 'badge-secondary',
        default    => 'badge-secondary',
    };
}

/**
 * Workflow-friendly label for the status lifecycle (per redesign doc):
 * Submitted -> Barangay Verification -> CSWDO Validation -> Approved ->
 * Scheduled for Distribution -> Distributed. Rejected / Returned are terminal
 * (returned may be corrected and resubmitted).
 */
function statusLabel(?string $status): string {
    return match ($status) {
        'approved'  => 'Approved',
        'pending'   => 'Pending',
        'rejected'  => 'Rejected',
        'returned'  => 'Returned for Correction',
        'submitted' => 'Submitted',
        'barangay'  => 'Under Barangay Verification',
        'cswdo'     => 'For CSWDO Validation',
        'scheduled' => 'Scheduled for Distribution',
        'ongoing'   => 'Distribution Ongoing',
        'completed' => 'Distributed',
        'cancelled' => 'Cancelled',
        default     => 'Unknown',
    };
}

// ── Cash: one shared citywide pool ───────────────────────────────────────────
// Cash used to be tracked as a separate budget row per barangay, so the number
// on a card (citywide SUM) never matched what a distribution actually drew from
// (that one barangay's row). Cash is a citywide budget, so it now lives in a
// single inventory row with barangay_id = NULL and every screen reads that one
// balance. See sql/migration_cash_pool.sql.

/**
 * The cash pool row for one fund ('general'|'emergency', default 'general').
 * Cash is a shared citywide pool (barangay_id IS NULL); General Fund and
 * Emergency Fund are two such rows distinguished by cash_fund_type.
 */
function getCashPool(PDO $pdo, string $fund = 'general'): ?array {
    static $cache = [];
    if (array_key_exists($fund, $cache)) return $cache[$fund];
    try {
        $stmt = $pdo->prepare("SELECT * FROM inventory
                               WHERE assistance_type = 'cash' AND barangay_id IS NULL
                                 AND (cash_fund_type = ? OR (cash_fund_type IS NULL AND ? = 'general'))
                               ORDER BY id LIMIT 1");
        $stmt->execute([$fund, $fund]);
        $cache[$fund] = $stmt->fetch() ?: null;
        if ($cache[$fund] === null && $fund === 'general') {
            // Pre-migration DB: fall back to any cash pool row.
            $stmt2 = $pdo->prepare("SELECT * FROM inventory WHERE assistance_type='cash' AND barangay_id IS NULL ORDER BY id LIMIT 1");
            $stmt2->execute();
            $cache[$fund] = $stmt2->fetch() ?: null;
        }
    } catch (Throwable $e) {
        $cache[$fund] = null;
    }
    return $cache[$fund];
}

/** All citywide cash pool rows (General Fund + Emergency Fund). */
function getCashPools(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT * FROM inventory WHERE assistance_type='cash' AND barangay_id IS NULL ORDER BY FIELD(cash_fund_type,'general','emergency'), id");
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Cheap identity check so pages can tell a pool row from a barangay-owned row. */
function isCashPoolRow(?array $inv): bool {
    return $inv !== null
        && $inv['assistance_type'] === 'cash'
        && ($inv['barangay_id'] === null || (int)$inv['barangay_id'] === 0);
}

/** True when this aid group draws from the shared pool rather than a barangay. */
function aidTypeUsesCashPool(string $aidType): bool {
    return $aidType === 'cash' || $aidType === 'cash_general' || $aidType === 'cash_emergency';
}

/**
 * True when the aid group draws from a shared citywide pool. Food Packs are a
 * single citywide pool (like Cash) since the last migration, so a barangay's
 * distribution deducts from one balance every card shows.
 */
function aidTypeUsesPool(string $aidType): bool {
    return aidTypeUsesCashPool($aidType) || $aidType === 'food_pack';
}

/** Map an aid-group key to the DB assistance_type value (cash funds both map to 'cash'). */
function dbAidType(string $aidType): string {
    return ($aidType === 'cash_general' || $aidType === 'cash_emergency') ? 'cash' : $aidType;
}

/** The cash fund key ('general'|'emergency') for an aid-group key, or null for non-cash. */
function cashFundOf(string $aidType): ?string {
    if ($aidType === 'cash_emergency') return 'emergency';
    if ($aidType === 'cash_general') return 'general';
    return null;
}

/**
 * SQL fragment + params selecting the inventory rows a page may distribute from
 * for one barangay. Non-cash stock is owned by the barangay; cash is the shared
 * pool, so it is included regardless of which barangay is selected.
 *
 * @return array{0:string,1:array}
 */
function inventoryScopeForBarangay(PDO $pdo, int $barangayId, string $aidType): array {
    if (aidTypeUsesCashPool($aidType)) {
        return ["barangay_id IS NULL", []];
    }
    return ["barangay_id = ?", [$barangayId]];
}

/** Clear the per-request cash pool cache (used after a cash deduction). */
function forgetCashPool(): void {
    // Static caches in PHP are per-request, so there is nothing to invalidate
    // here. Kept as an explicit hook so call sites read clearly.
}

/**
 * Get all districts (app is scoped to the first 3: Poblacion, Agdao, Talomo).
 *
 * If the `districts` table is missing the database hasn't been set up on this
 * machine yet (or the folder was copied over without importing the dump). Show
 * a setup page instead of a raw "table not found" fatal error.
 */
function getDistricts(PDO $pdo): array {
    try {
        return $pdo->query("SELECT * FROM districts ORDER BY id LIMIT 3")->fetchAll();
    } catch (PDOException $e) {
        ayuda_database_not_setup($pdo, $e);
    }
}

/**
 * Last-resort page shown when core tables are missing. Points the user at the
 * bundled installer (setup_database.php) or phpMyAdmin import instead of
 * letting a PDOException bubble up as a white-screen fatal error.
 */
function ayuda_database_not_setup(PDO $pdo, ?Throwable $e = null): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $dbName = 'ayuda_db';
    try {
        $n = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($n !== '') $dbName = $n;
    } catch (Throwable $ignored) {}

    $detail = $e ? htmlspecialchars($e->getMessage()) : '';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Ayuda - Database not set up</title>'
       . '<style>body{font-family:"Segoe UI",system-ui,sans-serif;background:#f4f7fb;padding:32px 16px;'
       . 'margin:0}.box{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e3e9f2;'
       . 'border-radius:14px;padding:28px 30px}h1{margin:0 0 8px;font-size:21px;color:#14324f}'
       . 'p,li{font-size:14px;color:#334155;line-height:1.6}code{background:#eef2f7;padding:1px 5px;'
       . 'border-radius:4px}a{color:#0f766e}ol{padding-left:20px}</style></head><body><div class="box">'
       . '<h1>Database not set up yet</h1>'
       . '<p>This machine does not have the Ayuda tables installed (database <code>'
       . htmlspecialchars($dbName) . '</code>). This happens when the project folder is copied to a new '
       . 'computer without also importing the database.</p>'
       . '<p><strong>Fastest fix:</strong> make sure MySQL is running in the XAMPP Control Panel, then open '
       . '<a href="setup_database.php">setup_database.php</a> and click <em>Set up the database</em>. '
       . 'It imports the bundled <code>sql/ayuda_db_full.sql</code> automatically.</p>'
       . '<p><strong>Manual fix:</strong> in phpMyAdmin create a database named <code>'
       . htmlspecialchars($dbName) . '</code>, then <strong>Import</strong> &rarr; '
       . '<code>sql/ayuda_db_full.sql</code> &rarr; Go.</p>';
    if ($detail !== '') {
        echo '<p style="color:#94a3b8;font-size:12px">Technical detail: ' . $detail . '</p>';
    }
    echo '</div></body></html>';
    exit;
}

/** Get barangays for a district */
function getBarangays(PDO $pdo, int $districtId): array {
    $stmt = $pdo->prepare("SELECT * FROM barangays WHERE district_id = ? ORDER BY name");
    $stmt->execute([$districtId]);
    return $stmt->fetchAll();
}

/** Get barangay by id */
function getBarangayById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT b.*, d.name AS district_name FROM barangays b JOIN districts d ON d.id = b.district_id WHERE b.id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Canonical key for a person's name so that formatting differences still
 * compare equal: order-insensitive ("Villarin, Chrisly" == "Chrisly Villarin"),
 * case-insensitive, and punctuation-insensitive (commas and middle-initial
 * dots are ignored). Used to flag duplicate beneficiary names.
 */
function beneficiaryNameKey(?string $fullName): string {
    $s = mb_strtolower(trim((string)$fullName));
    if ($s === '') return '';
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s); // drop , . ' etc.
    $tokens = preg_split('/\s+/', trim((string)$s), -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) return '';
    sort($tokens);
    return implode(' ', $tokens);
}

/**
 * All non-rejected beneficiaries in a barangay, indexed by canonical name key.
 * Lets callers detect duplicates against order/comma/case variants in one pass.
 *
 * @return array<string, array<int, array>> key => list of beneficiary rows
 */
function beneficiaryNameIndex(PDO $pdo, int $barangayId): array {
    $stmt = $pdo->prepare("SELECT id, full_name, status, created_at, date_of_birth, address
                           FROM beneficiaries WHERE barangay_id = ? AND status != 'rejected'");
    $stmt->execute([$barangayId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $key = beneficiaryNameKey($r['full_name']);
        if ($key === '') continue;
        $out[$key][] = $r;
    }
    return $out;
}

/**
 * Decide whether two same-named people are actually the same person, using the
 * birthdate first (the strongest signal) and the address as a fallback.
 *
 * Because many people share a name, a matching name alone is NOT proof: when
 * both sides have a birthdate and they differ, they are different people. When
 * a birthdate is missing on either side, two known-but-different addresses also
 * separate them. Otherwise they are treated as the same person.
 */
function beneficiarySamePerson(?string $dobA, ?string $dobB, ?string $addrA = null, ?string $addrB = null): bool {
    $dA = trim((string)$dobA);
    $dB = trim((string)$dobB);
    if ($dA !== '' && $dB !== '') return $dA === $dB; // different DOB => different person
    $aA = trim((string)$addrA);
    $aB = trim((string)$addrB);
    if ($aA !== '' && $aB !== '') return strcasecmp($aA, $aB) === 0;
    return true;
}

/**
 * Detect duplicate beneficiary (same full name + barangay; the birthdate — or,
 * when that is unknown, the address — distinguishes genuinely different people
 * who happen to share a name). Name comparison is canonical, so order/comma/
 * case differences still match.
 */
function isDuplicateBeneficiary(PDO $pdo, string $fullName, int $barangayId, ?int $excludeId = null, ?string $dateOfBirth = null, ?string $address = null): bool {
    $targetKey = beneficiaryNameKey($fullName);
    if ($targetKey === '') return false;
    $sql = "SELECT id, full_name, date_of_birth, address FROM beneficiaries WHERE barangay_id = ? AND status != 'rejected'";
    $params = [$barangayId];
    if ($excludeId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        if (beneficiaryNameKey($row['full_name']) !== $targetKey) continue;
        if (beneficiarySamePerson($dateOfBirth, $row['date_of_birth'], $address, $row['address'])) return true;
    }
    return false;
}

/**
 * Split a free-text name query into lowercase search tokens. Splits on spaces
 * and commas so "Villarin, Chrisly" and "Chrisly Villarin" yield the same
 * tokens; every token must appear somewhere in the stored name.
 *
 * @return array<int, string>
 */
function nameSearchTokens(string $query): array {
    $q = mb_strtolower(trim($query));
    if ($q === '') return [];
    return preg_split('/[\s,]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/**
 * Build SQL + params that match every token of a name query against a column,
 * order/comma-insensitively, plus an optional exact-whole-query match against
 * a secondary column (e.g. category).
 *
 * @return array{0: string, 1: array<int, mixed>}|null SQL fragment and params, or null when blank
 */
function nameSearchFilter(string $query, string $nameColumn, ?string $otherColumn = null): ?array {
    $tokens = nameSearchTokens($query);
    $raw = mb_strtolower(trim($query));
    $hasText = preg_match('/[\p{L}\p{N}]/u', $raw) === 1; // ignore punctuation-only queries
    if (!$tokens && !$hasText) return null;
    $conds = [];
    $params = [];
    foreach ($tokens as $t) {
        $conds[] = 'LOWER(' . $nameColumn . ') LIKE ?';
        $params[] = '%' . $t . '%';
    }
    $namePart = $conds ? '(' . implode(' AND ', $conds) . ')' : '';
    if ($otherColumn !== null && $hasText) {
        $catPart = 'LOWER(' . $otherColumn . ') LIKE ?';
        $params[] = '%' . $raw . '%';
        return [($namePart !== '' ? $namePart . ' OR ' : '') . $catPart, $params];
    }
    return $namePart !== '' ? [$namePart, $params] : null;
}

/**
 * Does this beneficiary have unseen updates (newly approved, newly received
 * ayuda, or a new announcement for their barangay/citywide) since they last
 * opened the Updates page? Used to show the red notification dot / popup.
 */
function beneficiaryHasUnseenUpdates(PDO $pdo, array $beneficiary): bool {
    $since = $beneficiary['last_seen_updates_at'] ?? $beneficiary['created_at'];
    if (!$since) return false;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM distribution_logs WHERE beneficiary_id = ? AND distributed_at > ?");
    $stmt->execute([$beneficiary['id'], $since]);
    if ((int)$stmt->fetchColumn() > 0) return true;

    if ($beneficiary['status'] === 'approved' && $beneficiary['approved_at'] && $beneficiary['approved_at'] > $since) return true;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE (barangay_id IS NULL OR barangay_id = ?) AND created_at > ?");
    $stmt->execute([$beneficiary['barangay_id'], $since]);
    if ((int)$stmt->fetchColumn() > 0) return true;

    return false;
}

/** Mark a beneficiary's updates as seen (called when they open the Updates page) */
function markBeneficiaryUpdatesSeen(PDO $pdo, int $beneficiaryId): void {
    $pdo->prepare("UPDATE beneficiaries SET last_seen_updates_at = NOW() WHERE id = ?")->execute([$beneficiaryId]);
}

/**
 * How many announcements are new (not yet seen) for this staff member:
 * citywide ones plus their own barangay's, minus any they've already opened.
 */
function getNewAnnouncementCount(PDO $pdo, int $userId, int $barangayId): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM announcements a
        LEFT JOIN announcement_seen s ON s.announcement_id = a.id AND s.user_id = ?
        WHERE (a.barangay_id IS NULL OR a.barangay_id = ?)
          AND s.user_id IS NULL
    ");
    $stmt->execute([$userId, $barangayId]);
    return (int)$stmt->fetchColumn();
}

/** Mark every announcement visible to this staff member as seen (opened). */
function markAnnouncementsSeen(PDO $pdo, int $userId, int $barangayId): void {
    $stmt = $pdo->prepare("SELECT id FROM announcements WHERE barangay_id IS NULL OR barangay_id = ?");
    $stmt->execute([$barangayId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO announcement_seen (user_id, announcement_id) VALUES (?,?)");
    foreach (array_column($stmt->fetchAll(), 'id') as $annId) {
        $ins->execute([$userId, (int)$annId]);
    }
}

/**
 * Password strength check used on registration forms (Beneficiary &
 * Barangay Staff), per IT111 security assessment: password must be
 * at least 8 characters, contain at least one uppercase letter,
 * one lowercase letter, one number, and one special character.
 */
function isPasswordStrong(string $password): bool {
    if (strlen($password) < 8) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return false;
    return true;
}
const PASSWORD_RULE_HINT = 'At least 8 characters, with 1 uppercase letter, 1 lowercase letter, 1 number, and 1 special character.';

/**
 * Login rate limiting: blocks login attempts after 5 failures
 * within 15 minutes per identifier.
 */
function checkLoginRateLimit(string $identifier): bool {
    $key = 'login_attempts_' . md5($identifier);
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_at' => 0];
    if ($attempts['count'] >= 5 && (time() - $attempts['first_at']) < 900) {
        return false; // locked
    }
    if ((time() - $attempts['first_at']) >= 900) {
        $_SESSION[$key] = ['count' => 0, 'first_at' => time()];
    }
    return true;
}
function recordLoginFailure(string $identifier): void {
    $key = 'login_attempts_' . md5($identifier);
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'first_at' => 0];
    if ($attempts['count'] === 0) $attempts['first_at'] = time();
    $attempts['count']++;
    $_SESSION[$key] = $attempts;
}
function clearLoginAttempts(string $identifier): void {
    unset($_SESSION['login_attempts_' . md5($identifier)]);
}

/**
 * Record an audit-trail entry (per IT111 Doc: audit trail for all critical
 * actions). Never throws -- a logging failure must not break the action.
 * Captures the acting user id + role from the session and the client IP.
 */
function logAudit(PDO $pdo, string $action, ?string $details = null): void {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $role   = $_SESSION['role'] ?? null;
        $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, details, ip_address) VALUES (?,?,?,?,?)");
        $stmt->execute([$userId, $role, $action, $details, $ip]);
    } catch (Throwable $e) {
        // never let logging crash the main operation
    }
}

/**
 * Record an inventory stock movement (add / replenish / deduct).
 * Used for the "Inventory history / transactions" view.
 *
 * barangay_id is resolved from the inventory row itself, NOT from the caller.
 * A citywide pool row (inventory.barangay_id IS NULL, e.g. Cash Assistance) is
 * logged with a NULL barangay so the movement reads "Citywide" instead of being
 * mislabelled with whichever barangay the admin happened to have open.
 */
function logInventoryTransaction(PDO $pdo, int $inventoryId, int $barangayId, string $itemName, string $type, float $quantity, float $balanceAfter, ?string $source = null, ?string $notes = null): void {
    try {
        // false = no such inventory row -> trust the caller.
        // NULL  = citywide pool row -> keep NULL so it logs as "Citywide".
        $q = $pdo->prepare("SELECT barangay_id FROM inventory WHERE id=?");
        $q->execute([$inventoryId]);
        $v = $q->fetchColumn();
        $invBrgy = ($v === false) ? $barangayId : ($v === null ? null : (int)$v);

        $stmt = $pdo->prepare("INSERT INTO inventory_transactions (inventory_id, barangay_id, item_name, type, quantity, balance_after, source, notes, done_by)
                               VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$inventoryId, $invBrgy, $itemName, $type, $quantity, $balanceAfter, $source, $notes, $_SESSION['user_id'] ?? null]);
    } catch (Throwable $e) {
        // never let logging crash the main operation
    }
}

/**
 * Total quantity currently reserved (status='reserved') for an inventory item.
 */
function getReservedQuantity(PDO $pdo, int $inventoryId): float {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM inventory_reservations WHERE inventory_id=? AND status='reserved'");
    $stmt->execute([$inventoryId]);
    return (float)$stmt->fetchColumn();
}

/**
 * Stock that is actually usable right now = quantity minus reserved.
 */
function getAvailableQuantity(float $quantity, float $reserved): float {
    return max(0.0, $quantity - $reserved);
}

/**
 * Count a set of beneficiaries by their category (Senior Citizen, PWD,
 * Student, Indigent, Solo Parent, ...). Used to show WHO an ayuda is for so
 * staff and residents know the make-up of the recipients.
 *
 * @param array<int, int|string> $beneficiaryIds
 * @return array<string, int> category => count (highest count first)
 */
function recipientCategoryBreakdown(PDO $pdo, array $beneficiaryIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $beneficiaryIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $rows = $pdo->query("SELECT COALESCE(bc.category, b.category) AS category, COUNT(DISTINCT b.id) AS c
                         FROM beneficiaries b
                         LEFT JOIN beneficiary_categories bc ON bc.beneficiary_id = b.id
                         WHERE b.id IN (" . implode(',', $ids) . ")
                         GROUP BY category ORDER BY c DESC, category ASC")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $cat = trim((string)($r['category'] ?? ''));
        $out[$cat !== '' ? $cat : 'Uncategorized'] = (int)$r['c'];
    }
    return $out;
}

/** Category => count map rendered as one line, e.g. "Student: 5 • Indigent: 3". */
function categoryBreakdownText(array $breakdown): string {
    $parts = [];
    foreach ($breakdown as $cat => $n) {
        $parts[] = $cat . ': ' . $n;
    }
    return implode(' • ', $parts);
}

/**
 * Category breakdown per distribution schedule, in one grouped query.
 *
 * @param array<int, int|string> $scheduleIds
 * @return array<int, array<string, int>> schedule_id => (category => count)
 */
function scheduleRecipientBreakdowns(PDO $pdo, array $scheduleIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $scheduleIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $out = [];
    $rows = $pdo->query("SELECT m.schedule_id, COALESCE(bc.category, b.category) AS category, COUNT(DISTINCT b.id) AS c
                         FROM distribution_schedule_members m
                         JOIN beneficiaries b ON b.id = m.beneficiary_id
                         LEFT JOIN beneficiary_categories bc ON bc.beneficiary_id = b.id
                         WHERE m.schedule_id IN (" . implode(',', $ids) . ") AND m.is_receiver = 1
                         GROUP BY m.schedule_id, category
                         ORDER BY c DESC, category ASC")->fetchAll();
    foreach ($rows as $r) {
        $cat = trim((string)($r['category'] ?? ''));
        $out[(int)$r['schedule_id']][$cat !== '' ? $cat : 'Uncategorized'] = (int)$r['c'];
    }
    return $out;
}

/**
 * Auto-post an announcement ONLY once per source (distribution schedule,
 * published final list, emergency response). This connects the modules so
 * one event creates exactly one announcement, never duplicates.
 *
 * @return int the announcement id (new or existing)
 */
function ensureAutoAnnouncement(PDO $pdo, string $sourceType, int $sourceId, ?int $barangayId, string $title, string $message, string $priority = 'normal', ?int $userId = null): int {
    $check = $pdo->prepare("SELECT id FROM announcements WHERE source_type=? AND source_id=? LIMIT 1");
    $check->execute([$sourceType, $sourceId]);
    if ($existing = $check->fetch()) return (int)$existing['id'];
    $stmt = $pdo->prepare("INSERT INTO announcements (barangay_id, title, message, priority, source_type, source_id, created_by)
                           VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$barangayId, $title, $message, $priority, $sourceType, $sourceId, $userId]);
    return (int)$pdo->lastInsertId();
}

/**
 * Announcement body -> tidy, human-readable rows.
 *
 * Auto-generated announcements are stored as "Label: value" lines, and older
 * records can contain a literal HTML entity such as "&#8369;" (written by an
 * earlier generator). Decoding first stops that from being double-escaped and
 * displayed to residents as "&#8369;".
 *
 * @return array<int, array{label: ?string, text: string}> label is null for
 *         free-form note lines; every value is already HTML-escaped.
 */
function announcementBody(?string $message): array {
    $raw = html_entity_decode((string)$message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    // Label => short display label. Only these become key/value rows, so
    // ordinary prose that happens to contain a colon is left untouched.
    $labels = [
        'schedule' => 'When',
        'venue' => 'Venue',
        'details' => 'Details',
        'recipients' => 'Recipients',
        'recipients by category' => 'Recipients by category',
        'what to bring' => 'What to bring',
        'incident' => 'Type',
        'severity' => 'Severity',
        'location' => 'Location',
        'people affected' => 'People affected',
        'scheduled response' => 'Response',
        'response / support being dispatched' => 'Support sent',
    ];
    $internal = '/posted to your barangay staff\./i';
    $notePrefix = '/^note from (?:barangay staff|district admin|cswdo)\s*:\s*/i';

    $rows = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || preg_match($internal, $line)) continue;

        if (preg_match('/^([A-Za-z][^:]{0,40}):\s*(.+)$/', $line, $m)
            && isset($labels[strtolower(trim($m[1]))])) {
            $key = strtolower(trim($m[1]));
            $value = trim($m[2]);

            // Legacy wording "3 member(s) - each receives X" reads badly; the
            // current generator writes "3 residents - each receives X".
            if ($key === 'recipients'
                && preg_match('/^(\d+)\s+member\(s\)\s*[-–—]\s*each receives\s+(.+)$/iu', $value, $mm)) {
                $n = (int)$mm[1];
                $value = $n . ' ' . ($n === 1 ? 'resident' : 'residents') . ' — each receives ' . trim($mm[2]);
            }

            $rows[] = ['label' => $labels[$key], 'text' => e($value)];
            continue;
        }

        // "Note from Barangay Staff: Fall in line" -> plain note.
        $rows[] = ['label' => null, 'text' => e(trim(preg_replace($notePrefix, '', $line)))];
    }
    return $rows;
}

/** Chips shown under an announcement title. */
function announcementChips(array $a): array {
    $chips = [];
    if (($a['priority'] ?? '') === 'high') {
        $chips[] = ['Urgent', 'badge-red'];
    }
    $chips[] = [($a['barangay_name'] ?? null) ? e($a['barangay_name']) : 'Citywide', 'badge-navy'];
    if (!empty($a['relayed_to_this_barangay'])) {
        $chips[] = ['Relayed by CSWDO', 'badge-secondary'];
    }
    return $chips;
}

/** Stored announcement message as tidy escaped rows for list views. */
function announcementBodyHtml(?string $message): string {
    $html = '';
    foreach (announcementBody((string)$message) as $r) {
        if ($r['label'] === null) {
            $html .= '<div>' . nl2br($r['text']) . '</div>';
        } else {
            $html .= '<div><strong>' . e($r['label']) . ':</strong> ' . nl2br($r['text']) . '</div>';
        }
    }
    return $html;
}

/**
 * Category breakdown of a distribution-linked announcement's recipients,
 * computed live from the linked schedule's members (so notices posted before
 * this was generated into the message still show who the ayuda is for).
 * Returns e.g. "Student: 5 • Indigent: 3", or '' when not applicable.
 */
function announcementCategoryBreakdownText(PDO $pdo, array $a): string {
    $st = $a['source_type'] ?? '';
    $sid = (int)($a['source_id'] ?? 0);
    if ($sid <= 0 || !in_array($st, ['distribution_schedule', 'schedule_final_list'], true)) return '';
    $stmt = $pdo->prepare("SELECT COALESCE(bc.category, b.category) AS category, COUNT(DISTINCT b.id) AS c
                           FROM distribution_schedule_members m
                           JOIN beneficiaries b ON b.id = m.beneficiary_id
                           LEFT JOIN beneficiary_categories bc ON bc.beneficiary_id = b.id
                           WHERE m.schedule_id = ? AND m.is_receiver = 1
                           GROUP BY category ORDER BY c DESC, category ASC");
    $stmt->execute([$sid]);
    $rows = $stmt->fetchAll();
    if (!$rows) return '';
    $b = [];
    foreach ($rows as $r) {
        $cat = trim((string)($r['category'] ?? ''));
        $b[$cat !== '' ? $cat : 'Uncategorized'] = (int)$r['c'];
    }
    return categoryBreakdownText($b);
}

/**
 * Announcement body rows for a list/card view, augmented with a live
 * "Recipients by category" row for distribution notices when the stored
 * message does not already carry one.
 *
 * @return array<int, array{label: ?string, text: string}>
 */
function announcementScheduleText(PDO $pdo, array $a): string {
    $st = $a['source_type'] ?? '';
    $sid = (int)($a['source_id'] ?? 0);
    if ($sid > 0 && in_array($st, ['distribution_schedule', 'schedule_final_list'], true)) {
        $sch = $pdo->prepare("SELECT schedule_date, schedule_time FROM distribution_schedules WHERE id=?");
        $sch->execute([$sid]);
        $s = $sch->fetch();
        if ($s && (!empty($s['schedule_date']) || !empty($s['schedule_time']))) {
            $parts = [];
            if (!empty($s['schedule_date'])) $parts[] = date('F j, Y', strtotime($s['schedule_date']));
            if (!empty($s['schedule_time'])) $parts[] = formatTime12($s['schedule_time']);
            return implode(' • ', $parts);
        }
    }
    return '';
}

function announcementBodyRows(PDO $pdo, array $a): array {
    $rows = announcementBody((string)($a['message'] ?? ''));
    $schedText = announcementScheduleText($pdo, $a);
    $hasCat = false;
    foreach ($rows as &$r) {
        if (($r['label'] ?? '') === 'Recipients by category') $hasCat = true;
        if (($r['label'] ?? '') === 'When' && $schedText !== '') $r['text'] = e($schedText);
    }
    unset($r);
    if (!$hasCat) {
        $txt = announcementCategoryBreakdownText($pdo, $a);
        if ($txt !== '') $rows[] = ['label' => 'Recipients by category', 'text' => e($txt)];
    }
    return $rows;
}

/** Like announcementBodyHtml(), but enriched with the live category row. */
function announcementBodyHtmlFor(PDO $pdo, array $a): string {
    $html = '';
    foreach (announcementBodyRows($pdo, $a) as $r) {
        if ($r['label'] === null) {
            $html .= '<div>' . nl2br($r['text']) . '</div>';
        } else {
            $html .= '<div><strong>' . e($r['label']) . ':</strong> ' . nl2br($r['text']) . '</div>';
        }
    }
    return $html;
}

/** Bottom meta line of an announcement card (already escaped). */
function announcementFoot(array $a): string {
    $who = !empty($a['relayed_to_this_barangay'])
        ? 'Relayed by your District Admin'
        : (!empty($a['created_by_name']) ? 'Posted by ' . e($a['created_by_name']) : '');
    $bits = array_values(array_filter([e(timeAgo($a['created_at'])), $who]));
    return implode('<span class="ann-sep"></span>', $bits);
}

/**
 * Beneficiary ids that already claimed ayuda in this barangay
 * (derived from distribution_logs) -- used for Claimed / Not yet claimed.
 *
 * @return array<int, bool> map of beneficiary_id => true
 */
function getClaimedBeneficiaryIds(PDO $pdo, int $barangayId): array {
    $stmt = $pdo->prepare("SELECT DISTINCT beneficiary_id FROM distribution_logs WHERE barangay_id=? AND beneficiary_id IS NOT NULL");
    $stmt->execute([$barangayId]);
    return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

/** Time ago formatting */
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

/**
 * Normalize a time submitted from a <input type="time"> picker (which posts a
 * 24-hour "HH:MM") into the canonical 12-hour "g:i A" format stored in the
 * database (e.g. "09:00" -> "9:00 AM", "14:30" -> "2:30 PM"). Already-12-hour
 * values like "9:00 AM" pass through unchanged. Returns '' when empty or when
 * the text cannot be understood as a time.
 */
function normalizeTimeInput($value): string {
    $v = trim((string)$value);
    if ($v === '') return '';
    // Support time ranges like "08:00 - 17:00" or "8:00 AM - 5:00 PM"
    if (preg_match('/\s*[-–—]\s*/u', $v)) {
        $parts = preg_split('/\s*[-–—]\s*/u', $v, 2);
        $a = normalizeTimeInput($parts[0] ?? '');
        $b = normalizeTimeInput($parts[1] ?? '');
        if ($a !== '' && $b !== '') return $a . ' - ' . $b;
        if ($a !== '') return $a;
        if ($b !== '') return $b;
        return '';
    }
    $ts = strtotime($v);
    if ($ts === false) return '';
    return date('g:i A', $ts);
}

/**
 * Render a stored time value consistently as 12-hour "g:i A" (e.g. "9:00 AM")
 * no matter whether it was saved as "09:00", "9:00 AM" or "2:00 PM". Falls back
 * to the raw text when it is not a parseable time (legacy free-text entries).
 */
function formatTime12($value): string {
    $v = trim((string)$value);
    if ($v === '') return '';
    if (preg_match('/\s*[-–—]\s*/u', $v)) {
        $parts = preg_split('/\s*[-–—]\s*/u', $v, 2);
        $a = formatTime12($parts[0] ?? '');
        $b = formatTime12($parts[1] ?? '');
        if ($a !== '' || $b !== '') return trim($a . ($a !== '' && $b !== '' ? ' - ' : '') . $b);
        return $v;
    }
    $ts = strtotime($v);
    if ($ts === false) return $v;
    return date('g:i A', $ts);
}

/**
 * Convert a stored time into the "HH:MM" value a <input type="time"> expects,
 * so edit forms can pre-fill the picker. Returns '' for empty/unparseable text.
 */
function timeToInputValue($value): string {
    $v = trim((string)$value);
    if ($v === '') return '';
    $ts = strtotime($v);
    if ($ts === false) return '';
    return date('H:i', $ts);
}

/** Minimal professional line icon (inline SVG, Feather-style, currentColor). */
function svgIcon(string $name, int $size = 20): string
{
    $paths = [
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'userPlus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/>',
        'box' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05"/><path d="M12 22.08V12"/>',
        'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'gift' => '<path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
        'alert' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'bell' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'megaphone' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'ok' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'left' => '<path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>',
        'right' => '<path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>',
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'dollar' => '<path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'list' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 14l2 2 4-4"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
        'x' => '<path d="M18 6L6 18"/><path d="M6 6l12 12"/>',
        'menu' => '<path d="M3 12h18"/><path d="M3 6h18"/><path d="M3 18h18"/>',
        'chart' => '<path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'eyeOff' => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg class="ic-svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}
