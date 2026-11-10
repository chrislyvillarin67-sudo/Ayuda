<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/spreadsheet.php';
requireStaff('district_admin');

$pageTitle = 'Import Data';
$activePage = 'import';
$errors = [];
$previewRows = [];
$csrfOk = validateCSRF();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfOk) {
    $errors[] = 'Invalid security token. Please refresh the page and try again.';
}

// Step 1: upload & preview (store parsed rows in session)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && $csrfOk) {
    $file = $_FILES['import_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed. Please try again.';
    } else {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $grid = ssParseFile($file['tmp_name'], $ext);
        if (isset($grid['error'])) {
            $errors[] = $grid['error'];
        } else {
            $rows = ssGridToAssoc($grid, ssExpectedColumns());
            if (!$rows) {
                $errors[] = 'No valid data rows found. Expected columns: surname, first_name, middle_initial (or a single full_name), date_of_birth, address, district, barangay, category, contact_number, gender, civil_status, occupation, monthly_income, house_occupancy, lot_occupancy.';
            } else {
                $_SESSION['import_preview'] = array_values($rows);
            }
        }
    }
}

// Preview row CRUD: delete a row before importing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_row']) && $csrfOk && !empty($_SESSION['import_preview'])) {
    $idx = (int)($_POST['row_idx'] ?? -1);
    if (isset($_SESSION['import_preview'][$idx])) {
        $name = $_SESSION['import_preview'][$idx]['full_name'] ?? '';
        unset($_SESSION['import_preview'][$idx]);
        $_SESSION['import_preview'] = array_values($_SESSION['import_preview']);
        setFlash('warning', "Preview row removed" . ($name !== '' ? " ($name)" : '') . ". It will NOT be imported.");
    }
    redirect('import.php');
}

// Preview row CRUD: edit a row before importing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_row']) && $csrfOk && !empty($_SESSION['import_preview'])) {
    $idx = (int)($_POST['row_idx'] ?? -1);
    if (isset($_SESSION['import_preview'][$idx])) {
        $validCategories = BENEFICIARY_CATEGORIES;
        $surname= trim($_POST['surname'] ?? '');
        $fname  = trim($_POST['first_name'] ?? '');
        $mi     = trim($_POST['middle_initial'] ?? '');
        if ($surname === '' && $fname === '') {
            $p = nameParts(null, null, null, $_POST['full_name'] ?? '');
            $surname = $p['surname']; $fname = $p['first_name']; $mi = $p['middle_initial'];
        }
        $name   = displayName($fname, $mi, $surname);
        $dob    = trim($_POST['date_of_birth'] ?? '');
        $addr   = trim($_POST['address'] ?? '');
        $dist   = trim($_POST['district'] ?? '');
        $brgy   = trim($_POST['barangay'] ?? '');
        $catCsv = trim($_POST['category'] ?? '');
        $contact= trim($_POST['contact_number'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $civil  = trim($_POST['civil_status'] ?? '');
        $job    = trim($_POST['occupation'] ?? '');
        $monthly= trim($_POST['monthly_income'] ?? '');
        $house  = trim($_POST['house_occupancy'] ?? '');
        $lot    = trim($_POST['lot_occupancy'] ?? '');
        if ($name === '') {
            $errors[] = "Could not save the edit for row " . ($idx + 1) . ': a surname or first name is required.';
        } else {
            $_SESSION['import_preview'][$idx]['full_name']      = $name;
            $_SESSION['import_preview'][$idx]['surname']        = $surname;
            $_SESSION['import_preview'][$idx]['first_name']     = $fname;
            $_SESSION['import_preview'][$idx]['middle_initial'] = $mi;
            $_SESSION['import_preview'][$idx]['date_of_birth']   = $dob;
            $_SESSION['import_preview'][$idx]['address']         = $addr;
            $_SESSION['import_preview'][$idx]['district']        = $dist;
            $_SESSION['import_preview'][$idx]['barangay']        = $brgy;
            $catList = array_values(array_intersect(
                array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $catCsv)))),
                $validCategories
            ));
            $_SESSION['import_preview'][$idx]['category']        = implode(', ', $catList);
            $_SESSION['import_preview'][$idx]['contact_number']  = $contact;
            $_SESSION['import_preview'][$idx]['gender']          = $gender;
            $_SESSION['import_preview'][$idx]['civil_status']    = $civil;
            $_SESSION['import_preview'][$idx]['occupation']      = $job;
            $_SESSION['import_preview'][$idx]['monthly_income']  = $monthly;
            $_SESSION['import_preview'][$idx]['house_occupancy'] = $house;
            $_SESSION['import_preview'][$idx]['lot_occupancy']   = $lot;
            setFlash('success', "Preview row " . ($idx + 1) . " updated.");
        }
    }
    redirect('import.php');
}

// Step 2: confirm import
// A row is only inserted when it is NOT an existing person. Two rows with the
// same name are the same person when their birthdate matches (or is unknown on
// either side); when both birthdates are known and different -- or, failing a
// birthdate, the addresses differ -- they are different people and both stay.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && $csrfOk && !empty($_SESSION['import_preview'])) {
    $inserted = 0; $skipped = 0; $dupSkipped = 0;
    $dupIndex = []; // barangay_id => (name key => [ ['dob'=>, 'address'=>], ... ]) existing + already-imported
    $genderOpts = ['Female', 'Male', 'Prefer not to say'];
    $civilOpts  = ['Single', 'Married', 'Widowed', 'Separated/Annulled'];
    $occupancyOpts = ['Owner', 'Renter', 'Sharer', 'Other'];

    foreach ($_SESSION['import_preview'] as $row) {
        // Name is stored split (surname / first name / middle initial). Prefer
        // the explicit split columns; fall back to parsing a combined full_name.
        $np      = nameParts($row['surname'] ?? null, $row['first_name'] ?? null, $row['middle_initial'] ?? null, $row['full_name'] ?? '');
        $surname = $np['surname'];
        $fname   = $np['first_name'];
        $mi      = $np['middle_initial'];
        $name    = displayName($fname, $mi, $surname);
        if ($name === '') $name = surnameFirst($row['full_name'] ?? '');
        $dob  = importDateToYmd($row['date_of_birth'] ?? '');
        $address = trim($row['address'] ?? '');
        $districtName = trim($row['district'] ?? '');
        $barangayName = trim($row['barangay'] ?? '');
        // A category cell may hold several categories separated by , ; or |
        // (the roster export joins them that way), so keep every valid one.
        $categories = array_values(array_intersect(
            array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', (string)($row['category'] ?? ''))))),
            BENEFICIARY_CATEGORIES
        ));
        $category = $categories[0] ?? '';
        $contact = trim($row['contact_number'] ?? '');
        $gender = trim($row['gender'] ?? '');
        $civil  = trim($row['civil_status'] ?? '');
        $job    = trim($row['occupation'] ?? '');
        $monthly= trim($row['monthly_income'] ?? '');
        $house  = trim($row['house_occupancy'] ?? '');
        $lot    = trim($row['lot_occupancy'] ?? '');
        $validCategories = BENEFICIARY_CATEGORIES;

        if ($name === '' || $districtName === '' || $barangayName === '' || !$categories) {
            $skipped++; continue;
        }
        $bStmt = $pdo->prepare("SELECT b.id, b.district_id FROM barangays b JOIN districts d ON d.id=b.district_id WHERE b.name=? AND d.name=? LIMIT 1");
        $bStmt->execute([$barangayName, $districtName]);
        $brgy = $bStmt->fetch();
        if (!$brgy) { $skipped++; continue; }

        $bid = (int)$brgy['id'];
        if (!isset($dupIndex[$bid])) {
            $dupIndex[$bid] = [];
            foreach (beneficiaryNameIndex($pdo, $bid) as $k => $rows) {
                foreach ($rows as $r) {
                    $dupIndex[$bid][$k][] = ['dob' => $r['date_of_birth'], 'address' => $r['address']];
                }
            }
        }
        $nameKey = beneficiaryNameKey($name);
        $isDup = false;
        if ($nameKey !== '') {
            foreach ($dupIndex[$bid][$nameKey] ?? [] as $prev) {
                if (beneficiarySamePerson($dob, $prev['dob'], $address, $prev['address'])) { $isDup = true; break; }
            }
        }
        if ($isDup) { $dupSkipped++; $skipped++; continue; } // never add a duplicate to the list

        $ins = $pdo->prepare("INSERT INTO beneficiaries (full_name, first_name, middle_initial, last_name, date_of_birth, address, district_id, barangay_id, category, contact_number, gender, civil_status, occupation, monthly_income, house_occupancy, lot_occupancy, is_duplicate, status)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,'pending')");
        $ins->execute([
            $name, $fname, $mi, $surname, $dob, $address, $brgy['district_id'], $brgy['id'], $category, $contact ?: null,
            in_array($gender, $genderOpts, true) ? $gender : null,
            in_array($civil, $civilOpts, true) ? $civil : null,
            $job ?: null,
            $monthly !== '' && is_numeric($monthly) ? (float)$monthly : null,
            in_array($house, $occupancyOpts, true) ? $house : null,
            in_array($lot, $occupancyOpts, true) ? $lot : null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        syncBeneficiaryCategories($pdo, $newId, $categories);
        if ($nameKey !== '') $dupIndex[$bid][$nameKey][] = ['dob' => $dob, 'address' => $address]; // catch repeats within this file
        $inserted++;
    }
    unset($_SESSION['import_preview']);
    $parts = ["$inserted record(s) added"];
    if ($dupSkipped) $parts[] = "$dupSkipped duplicate(s) skipped (same name and birthdate)";
    if ($skipped - $dupSkipped) $parts[] = ($skipped - $dupSkipped) . " skipped due to errors or unmatched location";
    setFlash('success', 'Import complete: ' . implode(', ', $parts) . '.');
    redirect('import.php');
}

if (isset($_GET['cancel'])) { unset($_SESSION['import_preview']); redirect('import.php'); }

// Export links with current role scope (export.php honors admin/staff restrictions)
$previewRows = array_values($_SESSION['import_preview'] ?? []);
$previewSample = array_slice($previewRows, 0, 10);
// Classify each row BEFORE importing. A row is a duplicate (and will be
// skipped) only when it matches an existing person or an earlier row in this
// same file: same name AND a matching birthdate. Two rows that share a name but
// have different birthdates are different people and are both kept.
$nameIndex = [];   // barangay_id => (name key => [existing rows])
$seenInFile = [];  // barangay_id => (name key => [ ['row'=>n,'dob'=>,'address'=>] ])
$dupRowCount = 0;  // rows that WILL be skipped (true duplicate)
$sameNameKept = 0; // rows kept because the birthdate proves a different person
foreach ($previewRows as $i => &$pr) {
    $bStmt = $pdo->prepare("SELECT b.id, b.name AS barangay_name FROM barangays b JOIN districts d ON d.id=b.district_id WHERE b.name=? AND d.name=? LIMIT 1");
    $bStmt->execute([$pr['barangay'] ?? '', $pr['district'] ?? '']);
    $brgy = $bStmt->fetch();
    $pr['_location_found'] = (bool)$brgy;
    $pr['_duplicate'] = null;    // existing same-person record (=> skipped), or null
    $pr['_dup_in_file'] = null;  // row numbers of earlier same-person rows (=> skipped)
    $pr['_diff_dob'] = null;     // same name but different birthdate (kept)
    $pr['_will_skip'] = false;
    $pr['_dob'] = importDateToYmd($pr['date_of_birth'] ?? '');
    // Rows may arrive with only the split name columns; keep full_name in sync
    // so duplicate detection and display both work against a single value.
    if (trim((string)($pr['full_name'] ?? '')) === '') {
        $np0 = nameParts($pr['surname'] ?? null, $pr['first_name'] ?? null, $pr['middle_initial'] ?? null, null);
        $synth = displayName($np0['first_name'], $np0['middle_initial'], $np0['surname']);
        if ($synth !== '') $pr['full_name'] = $synth;
    }
    if ($brgy) {
        $bid = (int)$brgy['id'];
        $pr['_barangay_name'] = $brgy['barangay_name'];
        if (!isset($nameIndex[$bid])) $nameIndex[$bid] = beneficiaryNameIndex($pdo, $bid);
        $key = beneficiaryNameKey($pr['full_name'] ?? '');
        $addr = trim((string)($pr['address'] ?? ''));
        if ($key !== '') {
            foreach (($nameIndex[$bid][$key] ?? []) as $ex) {
                if ($pr['_duplicate'] === null && beneficiarySamePerson($pr['_dob'], $ex['date_of_birth'], $addr, $ex['address'])) {
                    $pr['_duplicate'] = $ex;
                } elseif ($pr['_diff_dob'] === null) {
                    $pr['_diff_dob'] = $ex;
                }
            }
            foreach (($seenInFile[$bid][$key] ?? []) as $prev) {
                if (beneficiarySamePerson($pr['_dob'], $prev['dob'], $addr, $prev['address'])) {
                    $pr['_dup_in_file'][] = $prev['row'];
                } elseif ($pr['_diff_dob'] === null) {
                    $pr['_diff_dob'] = ['full_name' => (string)($pr['full_name'] ?? ''), 'same_file' => true];
                }
            }
            $seenInFile[$bid][$key][] = ['row' => $i + 1, 'dob' => $pr['_dob'], 'address' => $addr];
        }
        $pr['_will_skip'] = $pr['_duplicate'] !== null || !empty($pr['_dup_in_file']);
        if ($pr['_will_skip']) $dupRowCount++;
        elseif ($pr['_diff_dob'] !== null) $sameNameKept++;
    }
}
unset($pr);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import Data - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
.prev-actions { white-space:nowrap; }
.prev-actions form { display:inline; }
.dup-warn { color:#8a6d1a; background:#fff8e1; border-radius:6px; padding:5px 8px; font-size:12px; margin-top:4px; }
.edit-inline { background:#f6f9ff; }
</style>
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
            <?php include __DIR__ . '/includes/flash.php'; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
            <?php endif; ?>

            <?php if (!$previewRows): ?>
            <div class="card" style="max-width:620px;">
                <h3 style="margin-top:0;color:var(--navy);font-size:15px;">Import Beneficiary List (CSV or Excel)</h3>
                <p class="small text-muted">
                    Accepted formats: <code>.csv</code>, <code>.xls</code> (Excel 97-2003), <code>.xml</code>.
                    Expected columns (a header row is recommended): <code>surname, first_name, middle_initial, date_of_birth, address, district, barangay, category, contact_number, gender, civil_status, occupation, monthly_income, house_occupancy, lot_occupancy</code>.
                    A single <code>full_name</code> column is still accepted, and the name is stored as separate surname / first name / middle initial either way.
                    Rows whose name <em>and</em> birthdate already exist are skipped automatically; the same name with a different birthdate is treated as a different person.
                </p>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <input type="file" name="import_file" accept=".csv,.xls,.xlsx,.xml" class="form-control" required style="padding:8px;">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Upload &amp; Preview</button>
                </form>
                <hr>
                <p class="small text-muted" style="margin-bottom:6px;">No file yet? You can:</p>
                <div class="d-flex gap-2">
                    <div class="dropdown" style="position:relative;display:inline-block;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="toggleExportMenu(this)">&#8593; Export current list (CSV / Excel)</button>
                        <div class="dropdown-menu" style="display:none;position:absolute;z-index:50;top:calc(100% + 4px);left:0;min-width:220px;background:#fff;border:1px solid #e3e9f0;border-radius:10px;box-shadow:0 8px 24px rgba(15,42,70,.12);overflow:hidden;">
                            <a class="dropdown-item" style="display:block;padding:10px 14px;font-size:13px;color:var(--navy);text-decoration:none;" href="export.php?format=csv">Download as CSV (.csv)</a>
                            <a class="dropdown-item" style="display:block;padding:10px 14px;font-size:13px;color:var(--navy);text-decoration:none;" href="export.php?format=xls">Download as Excel (.xls)</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <div class="section-title">Preview (<?= count($previewRows) ?> rows)</div>
                <?php if ($dupRowCount > 0): ?>
                    <div class="alert alert-danger" style="display:flex;gap:8px;align-items:flex-start;">
                        <?= svgIcon('alert', 16) ?>
                        <div><strong><?= $dupRowCount ?> row<?= $dupRowCount === 1 ? '' : 's' ?> already exist and will NOT be imported</strong> (highlighted red below) &mdash; the same name and birthdate are already on the list. Edit a row if it is really a different person, or Delete it.</div>
                    </div>
                <?php endif; ?>
                <?php if ($sameNameKept > 0): ?>
                    <div class="alert alert-warning" style="display:flex;gap:8px;align-items:flex-start;">
                        <?= svgIcon('info', 16) ?>
                        <div><strong><?= $sameNameKept ?> row<?= $sameNameKept === 1 ? '' : 's' ?> share a name but a different birthdate</strong> (highlighted yellow) and will be imported &mdash; same name, different person.</div>
                    </div>
                <?php endif; ?>
                <div class="card" style="padding:0;">
                    <div class="table-responsive">
                    <table class="data-table data-table-wide">
                        <thead><tr><th>Surname</th><th>First Name</th><th>M.I.</th><th>Birthdate</th><th>District</th><th>Barangay</th><th>Category</th><th>Gender</th><th>Civil Status</th><th>Occupation</th><th>Monthly Income</th><th>House</th><th>Lot</th><th>Flags</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($previewRows as $pi => $pr): $np = nameParts($pr['surname'] ?? null, $pr['first_name'] ?? null, $pr['middle_initial'] ?? null, $pr['full_name'] ?? ''); ?>
                            <tr style="<?= !$pr['_location_found'] ? 'background:#fdecea;' : ($pr['_will_skip'] ? 'background:#fde8e8;' : ($pr['_diff_dob'] !== null ? 'background:#fff8e1;' : '')) ?>">
                                <td><?= e($np['surname']) ?></td>
                                <td><?= e($np['first_name']) ?></td>
                                <td><?= e($np['middle_initial']) ?></td>
                                <td><?= e($pr['_dob'] ?: ($pr['date_of_birth'] ?? '')) ?></td>
                                <td><?= e($pr['district'] ?? '') ?></td>
                                <td><?= e($pr['barangay'] ?? '') ?></td>
                                <td><?= e($pr['category'] ?? '') ?></td>
                                <td><?= e($pr['gender'] ?? '') ?></td>
                                <td><?= e($pr['civil_status'] ?? '') ?></td>
                                <td><?= e($pr['occupation'] ?? '') ?></td>
                                <td><?= ($pr['monthly_income'] ?? '') !== '' ? '&#8369;' . number_format((float)$pr['monthly_income'], 2) : '' ?></td>
                                <td><?= e($pr['house_occupancy'] ?? '') ?></td>
                                <td><?= e($pr['lot_occupancy'] ?? '') ?></td>
                                <td>
                                    <?php if (!$pr['_location_found']): ?>
                                        <span class="badge badge-danger">Unknown Location</span>
                                    <?php endif; ?>
                                    <?php if (!empty($pr['_duplicate'])): $dup = $pr['_duplicate']; ?>
                                        <span class="badge badge-danger"><?= svgIcon('alert', 12) ?> Duplicate &mdash; will be skipped</span>
                                        <div class="dup-warn">
                                            "<?= e(formatName($pr['full_name'])) ?>" (born <?= e($pr['_dob'] ?: '&mdash;') ?>) is already in <?= e($pr['_barangay_name'] ?? 'this barangay') ?><br>
                                            Existing: <a href="beneficiary_detail.php?id=<?= (int)$dup['id'] ?>"><?= e(formatName($dup['full_name'])) ?></a>
                                            <?= ucfirst(e($dup['status'])) ?> (added <?= date('M j, Y', strtotime($dup['created_at'])) ?>)
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($pr['_dup_in_file'])): $rowsTxt = implode(', ', array_map('intval', (array)$pr['_dup_in_file'])); ?>
                                        <span class="badge badge-danger"><?= svgIcon('alert', 12) ?> Duplicate in this file &mdash; will be skipped</span>
                                        <div class="dup-warn">
                                            Same name and birthdate as row<?= count((array)$pr['_dup_in_file']) === 1 ? '' : 's' ?> <strong><?= e($rowsTxt) ?></strong> of this upload. Only the first one is kept.
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($pr['_diff_dob'] !== null && !$pr['_will_skip']): $dd = $pr['_diff_dob']; ?>
                                        <span class="badge badge-warning"><?= svgIcon('info', 12) ?> Same name, different birthdate</span>
                                        <div class="dup-warn">
                                            Shares a name with <?= !empty($dd['id']) ? 'an existing record (born ' . e((string)$dd['date_of_birth'] ?: '&mdash;') . ')' : 'another row in this file' ?> but the birthdate differs &mdash; kept as a different person.
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="prev-actions">
                                    <button type="button" class="btn btn-outline btn-sm" onclick="toggleEdit(<?= $pi ?>)">Edit</button>
                                    <form method="POST" onsubmit="return confirm('Remove this row from the preview?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="delete_row" value="1">
                                        <input type="hidden" name="row_idx" value="<?= $pi ?>">
                                        <button class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <tr class="edit-inline" id="editRow<?= $pi ?>" style="display:none;">
                                <td colspan="15">
                                    <form method="POST">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="edit_row" value="1">
                                        <input type="hidden" name="row_idx" value="<?= $pi ?>">
                                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:10px 4px;">
                                            <div class="form-group" style="flex:1;min-width:130px;margin-bottom:0;">
                                                <label>Surname</label>
                                                <input type="text" name="surname" class="form-control" value="<?= e($np['surname']) ?>" required>
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:120px;margin-bottom:0;">
                                                <label>First Name</label>
                                                <input type="text" name="first_name" class="form-control" value="<?= e($np['first_name']) ?>">
                                            </div>
                                            <div class="form-group" style="flex:0 0 90px;min-width:80px;margin-bottom:0;">
                                                <label>M.I.</label>
                                                <input type="text" name="middle_initial" class="form-control" maxlength="10" value="<?= e($np['middle_initial']) ?>">
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:140px;margin-bottom:0;">
                                                <label>Birthdate</label>
                                                <input type="date" name="date_of_birth" class="form-control" value="<?= e($pr['_dob'] ?? '') ?>">
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:120px;margin-bottom:0;">
                                                <label>District</label>
                                                <input type="text" name="district" class="form-control" value="<?= e($pr['district'] ?? '') ?>">
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                                                <label>Barangay</label>
                                                <input type="text" name="barangay" class="form-control" value="<?= e($pr['barangay'] ?? '') ?>">
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                                                <label>Category <span class="small text-muted">(up to <?= MAX_BENEFICIARY_CATEGORIES ?>, comma-separated)</span></label>
                                                <input type="text" name="category" class="form-control" list="catList" value="<?= e($pr['category'] ?? '') ?>" placeholder="<?= e(implode(', ', array_slice(BENEFICIARY_CATEGORIES, 0, 2))) ?>">
                                                <datalist id="catList">
                                                    <?php foreach (BENEFICIARY_CATEGORIES as $cc): ?><option value="<?= e($cc) ?>"><?php endforeach; ?>
                                                </datalist>
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:130px;margin-bottom:0;">
                                                <label>Gender</label>
                                                <select name="gender" class="form-control">
                                                    <option value="">Select...</option>
                                                    <?php foreach (['Female', 'Male', 'Prefer not to say'] as $g): ?>
                                                        <option value="<?= e($g) ?>" <?= ($pr['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:130px;margin-bottom:0;">
                                                <label>Civil Status</label>
                                                <select name="civil_status" class="form-control">
                                                    <option value="">Select...</option>
                                                    <?php foreach (['Single', 'Married', 'Widowed', 'Separated/Annulled'] as $cs): ?>
                                                        <option value="<?= e($cs) ?>" <?= ($pr['civil_status'] ?? '') === $cs ? 'selected' : '' ?>><?= e($cs) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                                                <label>Occupation</label>
                                                <input type="text" name="occupation" class="form-control" value="<?= e($pr['occupation'] ?? '') ?>">
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:130px;margin-bottom:0;">
                                                <label>Monthly Income</label>
                                                <input type="number" name="monthly_income" class="form-control" min="0" step="0.01" value="<?= ($pr['monthly_income'] ?? '') !== '' ? e((string)(float)$pr['monthly_income']) : '' ?>">
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:120px;margin-bottom:0;">
                                                <label>House Occupancy</label>
                                                <select name="house_occupancy" class="form-control">
                                                    <option value="">Select...</option>
                                                    <?php foreach (['Owner', 'Renter', 'Sharer', 'Other'] as $o): ?>
                                                        <option value="<?= e($o) ?>" <?= ($pr['house_occupancy'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:120px;margin-bottom:0;">
                                                <label>Lot Occupancy</label>
                                                <select name="lot_occupancy" class="form-control">
                                                    <option value="">Select...</option>
                                                    <?php foreach (['Owner', 'Renter', 'Sharer', 'Other'] as $o): ?>
                                                        <option value="<?= e($o) ?>" <?= ($pr['lot_occupancy'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:120px;margin-bottom:0;">
                                                <label>Address</label>
                                                <input type="text" name="address" class="form-control" value="<?= e($pr['address'] ?? '') ?>">
                                            </div>
                                            <div class="form-group" style="flex:1;min-width:120px;margin-bottom:0;">
                        <label>Contact</label>
                        <input type="text" name="contact_number" class="form-control" data-numeric="1" value="<?= e($pr['contact_number'] ?? '') ?>">
                                            </div>
                                            <button class="btn btn-success btn-sm">Save</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3" style="flex-wrap:wrap;justify-content:space-between;align-items:center;">
                    <div class="small text-muted">
                        <?= svgIcon('info', 14) ?> Tip: rows highlighted <strong style="color:#8a1c1c;">red</strong> are duplicates and will be skipped; <strong style="color:#8a6d1a;">yellow</strong> rows just share a name but a different birthdate. Edit a name/birthdate or Delete a row to change the outcome.
                    </div>
                    <div class="d-flex gap-2">
                        <form method="POST" style="flex:1;">
                            <?= csrfField() ?>
                            <input type="hidden" name="confirm_import" value="1">
                            <button class="btn btn-primary btn-block">Confirm Import (<?= count($previewRows) - $dupRowCount ?>)</button>
                        </form>
                        <a href="?cancel=1" class="btn btn-outline" style="text-align:center;">Cancel</a>
                    </div>
                </div>
            <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
<script>
function toggleExportMenu(btn) {
    const menu = btn.nextElementSibling;
    const isOpen = menu.style.display === 'block';
    document.querySelectorAll('.dropdown-menu').forEach(function(m) { m.style.display = 'none'; });
    menu.style.display = isOpen ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu').forEach(function(m) { m.style.display = 'none'; });
    }
});
function toggleEdit(idx) {
    const row = document.getElementById('editRow' + idx);
    if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>
</body>
</html>