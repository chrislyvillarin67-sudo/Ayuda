<?php
/**
 * One-click database installer.
 *
 * Run this ONCE on a new machine (open http://localhost/ayuda/setup_database.php
 * in the browser, or double-click install-ayuda.cmd). It builds the `ayuda_db`
 * database from the bundled dump:
 *
 *   1. sql/ayuda_db_full.sql  -- current live data (recommended, everything included)
 *   2. sql/schema.sql         -- fallback: structure only, no sample data
 *
 * Then it optionally imports sql/demo_data.sql for extra sample records.
 * Nothing else is needed -- the app's own config/db.php already points at
 * `ayuda_db`, and config/db.php self-heals missing columns on first run.
 *
 * Safety: refuses to overwrite an existing, non-empty database unless the user
 * ticks "Replace existing data". That prevents accidentally wiping a working
 * install (e.g. when re-running on your own laptop).
 */
declare(strict_types=1);

// ── Locate a usable MySQL/MariaDB client ────────────────────────────────────
// We shell out to mysqldump/mysql so we can stream a 14 MB dump without
// PHP's memory limit or max_allowed_packet getting in the way.
$binCandidates = [
    // Derived from this very PHP binary first: works whatever drive/folder the
    // stack lives in (D:\xampp, "Program Files", portable USB XAMPP, ...).
    dirname(PHP_BINDIR) . '/mysql/bin',
    dirname(PHP_BINDIR) . '/mariadb/bin',
    PHP_BINDIR . '/../mysql/bin',
    PHP_BINDIR . '/../mariadb/bin',
    'C:/xampp/mysql/bin',
    'C:/xampp/mariadb/bin',
    'C:/Program Files/XAMPP/mysql/bin',
    'C:/Program Files/MariaDB/MariaDB 10.4/bin',
    'C:/Program Files/MariaDB/MariaDB 10.5/bin',
    'C:/Program Files/MariaDB/MariaDB 10.11/bin',
    'C:/wamp64/bin/mysql',
    'C:/laragon/bin/mysql',
    'C:/xampp8/mysql/bin',
];

$mysqlBin = null;
foreach ($binCandidates as $dir) {
    if (is_dir($dir)) {
        // Prefer mysql.exe over mariadb.exe when both exist (both work).
        foreach (['mysql.exe', 'mariadb.exe'] as $exe) {
            if (is_file($dir . '/' . $exe)) { $mysqlBin = $dir; break 2; }
        }
    }
}
// Fall back to anything on PATH.
if ($mysqlBin === null) {
    $out = [];
    @exec('where mysql 2>nul', $out);
    if ($out && preg_match('#^(.+)[\\\\/](mysql|mariadb)\.exe$#i', trim($out[0]), $m)) {
        $mysqlBin = str_replace('/', '\\', dirname($m[0]));
    }
}

$dbName = 'ayuda_db';
$dbUser = 'root';
$dbPass = '';   // default XAMPP/MariaDB root password is empty

// ── Handle the "Import demo data" checkbox ──────────────────────────────────
$withDemo = isset($_POST['demo']) && $_POST['demo'] === '1';
$replace  = isset($_POST['replace']) && $_POST['replace'] === '1';

$steps   = [];   // human-readable log
$done    = false;
$error   = null;

function runMysql(string $bin, string $user, string $pass, string $args, ?string $sqlFile = null): array
{
    $cmd = escapeshellarg($bin . '/mysql.exe');
    if ($user !== '') $cmd .= ' -u ' . escapeshellarg($user);
    // Pass the password via env var so it never shows up in a visible command line.
    $cmd = 'set "MYSQL_PWD=' . str_replace('"', '', $pass) . '" && ' . $cmd . $args;
    if ($sqlFile !== null) {
        $cmd .= ' < ' . escapeshellarg($sqlFile);
    }
    $out = [];
    $rc  = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", array_filter($out, 'strlen'))];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mysqlBin === null) {
    $error = 'Could not find the mysql/mariadb command-line client. Open phpMyAdmin and import sql/ayuda_db_full.sql by hand instead.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null && !function_exists('exec')) {
    $error = 'PHP has the <code>exec()</code> function disabled on this machine, so the installer cannot run
              the mysql client. Use phpMyAdmin instead: create a database named <code>ayuda_db</code>, then
              <strong>Import</strong> &rarr; <code>sql/ayuda_db_full.sql</code> &rarr; Go.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    // Install is a machine-local operation. Refuse to run when the request did
    // not come from this computer, so that once this app is put online nobody
    // can reach the installer and overwrite the live database. On your own
    // machine you browse to http://localhost/Ayuda/setup_database.php and this
    // check passes.
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLocalRequest = ($remoteAddr === '' || $remoteAddr === '127.0.0.1' || $remoteAddr === '::1'
                       || strpos($remoteAddr, '127.') === 0);
    if (!$isLocalRequest) {
        $error = 'For safety, the installer only runs when opened from the machine itself.<br>'
               . 'Open <code>http://localhost/Ayuda/setup_database.php</code> on the computer running XAMPP.<br>'
               . '<small>Request came from ' . htmlspecialchars($remoteAddr) . '.</small>';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    $fullDump = __DIR__ . '/sql/ayuda_db_full.sql';
    $schema   = __DIR__ . '/sql/schema.sql';
    $demoFile = __DIR__ . '/sql/demo_data.sql';

    // Pick the source: prefer the full dump, fall back to schema.sql.
    $source = is_file($fullDump) ? $fullDump : $schema;
    $steps[] = 'Using source: <code>' . basename($source) . '</code>'
             . (is_file($fullDump) ? ' (includes all current data)' : ' (structure + base seed)');

    // 1) Make sure the server is reachable.
    [$rc, $out] = runMysql($mysqlBin, $dbUser, $dbPass, ' -e "SELECT 1"');
    if ($rc !== 0) {
        $error = 'Could not connect to MySQL. Make sure MySQL is running in the XAMPP Control Panel, '
               . 'then reload this page.<br><small>' . htmlspecialchars($out) . '</small>';
    }

    // 2) Create the database if needed.
    if ($error === null) {
        [$rc, $out] = runMysql($mysqlBin, $dbUser, $dbPass,
            ' -e "CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"');
        $steps[] = $rc === 0
            ? 'Database <code>' . htmlspecialchars($dbName) . '</code> is ready.'
            : 'Note: could not create the database automatically (' . htmlspecialchars($out) . '). Continuing.';
    }

    // 3) Guard: don't clobber an existing populated DB unless confirmed.
    if ($error === null) {
        [$rc, $out] = runMysql($mysqlBin, $dbUser, $dbPass,
            ' ' . escapeshellarg($dbName) . ' -N -B -e "SHOW TABLES"');
        $existing = array_values(array_filter(array_map('trim', explode("\n", (string)$out))));
        if (count($existing) > 0 && !$replace) {
            $error = 'The database <code>' . htmlspecialchars($dbName) . '</code> already has '
                   . count($existing) . ' table(s). Tick <strong>Replace existing data</strong> below '
                   . 'to overwrite it, or untick and cancel if you want to keep it.';
        } else {
            if (count($existing) > 0) {
                // Drop every table so the import is clean and repeatable.
                $drop = 'SET FOREIGN_KEY_CHECKS=0; ';
                foreach ($existing as $t) {
                    $safe = preg_replace('/[^A-Za-z0-9_$]/', '', $t);
                    if ($safe !== '') $drop .= 'DROP TABLE IF EXISTS `' . $safe . '`; ';
                }
                $drop .= 'SET FOREIGN_KEY_CHECKS=1;';
                runMysql($mysqlBin, $dbUser, $dbPass,
                    ' ' . escapeshellarg($dbName) . ' -e "' . str_replace('"', '\\"', $drop) . '"');
                $steps[] = 'Dropped ' . count($existing) . ' existing table(s).';
            }
            // 4) Import the dump.
            [$rc, $out] = runMysql($mysqlBin, $dbUser, $dbPass,
                ' ' . escapeshellarg($dbName), $source);
            if ($rc !== 0) {
                $error = 'Import failed. phpMyAdmin may handle the large dump better -- import '
                       . '<code>sql/' . basename($source) . '</code> there instead.<br><small>'
                       . htmlspecialchars(mb_substr($out, 0, 800)) . '</small>';
            } else {
                $steps[] = 'Imported <code>' . basename($source) . '</code>.';
            }
        }
    }

    // 5) Optional demo data.
    if ($error === null && $withDemo && is_file($demoFile)) {
        [$rc, $out] = runMysql($mysqlBin, $dbUser, $dbPass,
            ' ' . escapeshellarg($dbName), $demoFile);
        $steps[] = $rc === 0
            ? 'Imported <code>demo_data.sql</code>.'
            : 'Note: demo data import reported an issue (' . htmlspecialchars(mb_substr($out, 0, 300)) . ').';
    }

    // 6) Verify the tables the app needs actually exist now.
    if ($error === null) {
        [$rc, $out] = runMysql($mysqlBin, $dbUser, $dbPass,
            ' ' . escapeshellarg($dbName) . ' -N -B -e "SHOW TABLES"');
        $tables = array_values(array_filter(array_map('trim', explode("\n", (string)$out))));
        $required = ['districts', 'barangays', 'users', 'beneficiaries', 'inventory',
                     'distribution_programs', 'distribution_schedules', 'eligibility_lists',
                     'eligibility_list_members', 'inventory_transactions', 'messages'];
        $missing = array_values(array_diff($required, $tables));
        if ($missing) {
            $error = 'Import finished but these tables are still missing: <code>'
                   . implode('</code>, <code>', $missing) . '</code>.';
        } else {
            $steps[] = 'Verified all ' . count($required) . ' required tables are present.';
            $done = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ayuda - Database Setup</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
  body { background: #f4f7fb; font-family: 'Segoe UI', system-ui, sans-serif; padding: 32px 16px; }
  .box { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #e3e9f2;
         border-radius: 14px; padding: 28px 30px; }
  h1 { margin: 0 0 6px; font-size: 22px; color: #14324f; }
  .sub { color: #64748b; margin: 0 0 20px; font-size: 14px; }
  .alert { border-radius: 10px; padding: 12px 14px; font-size: 14px; margin-bottom: 16px; line-height: 1.5; }
  .alert-err { background: #fff5f5; border: 1px solid #f0b4b4; color: #8a1f1f; }
  .alert-ok  { background: #f0fdf4; border: 1px solid #a7e3bd; color: #14532d; }
  ul.log { margin: 0 0 18px; padding-left: 20px; font-size: 14px; color: #334155; }
  ul.log li { margin-bottom: 5px; }
  label { display: block; font-size: 14px; margin: 10px 0 4px; color: #1e293b; }
  .hint { font-size: 13px; color: #64748b; margin: 4px 0 0; }
  button { margin-top: 18px; background: #0f766e; color: #fff; border: 0; border-radius: 10px;
           padding: 11px 22px; font-size: 15px; font-weight: 600; cursor: pointer; }
  button:hover { background: #0d6358; }
  .btn2 { background: #1e3a5f; margin-left: 10px; }
  code { background: #eef2f7; padding: 1px 5px; border-radius: 4px; font-size: 13px; }
  .box2 { margin-top: 14px; background: #f8fafc; border: 1px solid #e3e9f2; border-radius: 10px; padding: 12px 14px; font-size: 13px; color: #475569; }
</style>
</head>
<body>
<div class="box">
  <h1>Ayuda &mdash; Database Setup</h1>
  <p class="sub">One-time step on a new machine. This builds the <code>ayuda_db</code> database the app expects.</p>

  <?php if ($error !== null): ?>
    <div class="alert alert-err"><?= $error /* already-escaped / controlled HTML */ ?></div>
  <?php endif; ?>

  <?php if ($steps): ?>
    <ul class="log">
      <?php foreach ($steps as $s): ?>
        <li><?= $s /* controlled: escaped internally */ ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($done): ?>
    <div class="alert alert-ok">
      <strong>Setup complete.</strong> You can now open <a href="login.php">the Ayuda login page</a>.
      Sign in as <code>admin</code> / <code>admin123</code>.
    </div>
    <p class="hint">Tip: delete <code>setup_database.php</code> once it works, so nobody else can re-run it.</p>
  <?php else: ?>
    <?php if ($mysqlBin === null && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
      <div class="alert alert-err">
        Could not find the <code>mysql</code> client on this machine.
        Import <code>sql/ayuda_db_full.sql</code> manually through phpMyAdmin instead.
      </div>
    <?php endif; ?>

    <form method="post" action="setup_database.php">
      <label><input type="checkbox" name="demo" value="1"> Also import extra sample data (<code>sql/demo_data.sql</code>)</label>
      <p class="hint">Optional &mdash; only if you want more example beneficiaries on a fresh install.</p>

      <label><input type="checkbox" name="replace" value="1"> Replace existing data in <code><?= htmlspecialchars($dbName) ?></code></label>
      <p class="hint">This <strong>deletes all current tables and data</strong> and rebuilds them from the bundled dump. Leave unticked if the database is empty (first-time install).</p>

      <button type="submit">Set up the database</button>
    </form>

    <div class="box2">
      <strong>Prefer phpMyAdmin?</strong> Create a database named <code>ayuda_db</code>, then
      <strong>Import</strong> &rarr; choose <code>sql/ayuda_db_full.sql</code> &rarr; Go.
      That's all this page does, just automatically.
    </div>
  <?php endif; ?>
</div>
</body>
</html>
