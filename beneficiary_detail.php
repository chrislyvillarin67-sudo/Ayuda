<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = 'Beneficiary Details';
$activePage = 'beneficiaries';
$isAdmin = $_SESSION['role'] === 'district_admin';
$myBarangayId = $_SESSION['barangay_id'] ?? null;
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT b.*, br.name AS barangay_name, d.name AS district_name,
           u.full_name AS approved_by_name, u.role AS approved_by_role
    FROM beneficiaries b
    JOIN barangays br ON br.id = b.barangay_id
    JOIN districts d ON d.id = b.district_id
    LEFT JOIN users u ON u.id = b.approved_by
    WHERE b.id = ?
");
$stmt->execute([$id]);
$ben = $stmt->fetch();

if (!$ben) { setFlash('danger', 'Beneficiary not found.'); redirect('beneficiaries.php'); }

$benCats = beneficiaryCategories($pdo, (int)$ben['id']);

// Barangay staff may only view beneficiaries within their own barangay
if (!$isAdmin && (int)$ben['barangay_id'] !== (int)$myBarangayId) {
    setFlash('danger', 'You can only view beneficiaries in your own barangay.');
    redirect('beneficiaries.php');
}

$errors = [];
$csrfOk = validateCSRF();

// ---- Handle edit/update of beneficiary information ----
$genderOpts    = ['Female', 'Male', 'Prefer not to say'];
$civilOpts     = ['Single', 'Married', 'Widowed', 'Separated/Annulled'];
$occupancyOpts = ['Owner', 'Renter', 'Sharer', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile']) && $csrfOk) {
    $first    = trim($_POST['first_name'] ?? '');
    $mi       = mb_strtoupper(trim($_POST['middle_initial'] ?? ''));
    $last     = trim($_POST['last_name'] ?? '');
    $dob      = trim($_POST['date_of_birth'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $categories = normalizeBeneficiaryCategories($_POST['category'] ?? []);
    $contact  = trim($_POST['contact_number'] ?? '');
    $gender   = trim($_POST['gender'] ?? '');
    $civil    = trim($_POST['civil_status'] ?? '');
    $job      = trim($_POST['occupation'] ?? '');
    $monthly  = trim($_POST['monthly_income'] ?? '');
    $house    = trim($_POST['house_occupancy'] ?? '');
    $lot      = trim($_POST['lot_occupancy'] ?? '');

    // full_name stays the canonical "Surname, Given Middle" duplicate-detection key.
    $fname = displayName($first, $mi, $last);

    $errs = [];
    if ($first === '') { $errs[] = 'First name is required.'; }
    if ($last  === '') { $errs[] = 'Last name is required.'; }
    if (!$categories) { $errs[] = 'Please select at least one category (up to ' . MAX_BENEFICIARY_CATEGORIES . ').'; }
    if ($gender !== '' && !in_array($gender, $genderOpts, true)) { $errs[] = 'Invalid gender.'; }
    if ($civil  !== '' && !in_array($civil, $civilOpts, true)) { $errs[] = 'Invalid civil status.'; }
    if ($house  !== '' && !in_array($house, $occupancyOpts, true)) { $errs[] = 'Invalid house occupancy.'; }
    if ($lot    !== '' && !in_array($lot, $occupancyOpts, true)) { $errs[] = 'Invalid lot occupancy.'; }
    if ($monthly !== '' && !is_numeric($monthly)) { $errs[] = 'Monthly income must be a number.'; }

    if ($errs) {
        setFlash('danger', implode(' ', $errs));
    } else {
        // If the name or identifying info changed, re-run duplicate detection.
        $isDup = isDuplicateBeneficiary($pdo, $fname, (int)$ben['barangay_id'], (int)$ben['id'], $dob ?: null, $address ?: null);
        $pdo->prepare("UPDATE beneficiaries SET
                full_name=?, first_name=?, middle_initial=?, last_name=?,
                gender=?, civil_status=?, occupation=?, monthly_income=?,
                house_occupancy=?, lot_occupancy=?,
                date_of_birth=?, address=?, category=?, contact_number=?, is_duplicate=?
            WHERE id=?")
            ->execute([
                $fname, $first, $mi, $last,
                $gender ?: null, $civil ?: null, $job ?: null,
                $monthly === '' ? null : (float)$monthly,
                $house ?: null, $lot ?: null,
                $dob ?: null, $address, $categories[0], $contact ?: null,
                $isDup ? 1 : 0, $id,
            ]);
        syncBeneficiaryCategories($pdo, $id, $categories);
        logAudit($pdo, 'beneficiary_update', 'Updated beneficiary #' . $id . ' (' . $fname . ')');
        setFlash('success', 'Beneficiary information updated.');
    }
    redirect('beneficiary_detail.php?id=' . $id);
}

// ---- Distribution history: scheduled entries (pending / released / not
// released) merged with direct stock releases. A log that has a matching
// schedule entry on the same day is skipped so nothing is counted twice.
$aidLabels = [
    'cash' => 'Cash Assistance', 'food_pack' => 'Food Pack',
    'relief_goods' => 'Relief Goods', 'hygiene' => 'Relief Goods',
    'sponsored' => 'Sponsored', 'other' => 'Other Aid',
];
$relLabels = [
    'pending' => ['Pending', 'badge', 'background:#e3e9f0;color:#5f6b7a;'],
    'released' => ['Released', 'badge badge-success', ''],
    'not_released' => ['Not released', 'badge badge-red', ''],
];

$history = [];

$schedStmt = $pdo->prepare("
    SELECT s.schedule_date, s.schedule_time, s.title, s.aid_type, s.venue, s.created_at,
           m.release_status, m.release_note, m.release_at,
           u.full_name AS released_by_name
    FROM distribution_schedule_members m
    JOIN distribution_schedules s ON s.id = m.schedule_id
    LEFT JOIN users u ON u.id = m.release_by
    WHERE m.beneficiary_id = ?
    ORDER BY COALESCE(s.schedule_date, DATE(s.created_at)) DESC
");
$schedStmt->execute([$id]);
foreach ($schedStmt->fetchAll() as $r) {
    $lbl = $aidLabels[$r['aid_type']] ?? (string)($r['aid_type'] ?: 'Aid Distribution');
    $history[] = [
        'date'    => $r['schedule_date'] ?: date('Y-m-d', strtotime($r['created_at'])),
        'title'   => e($r['title']),
        'detail'  => $lbl . ($r['venue'] ? ' &bull; ' . e($r['venue']) : '') . ($r['schedule_time'] ? ' &bull; ' . e(formatTime12($r['schedule_time'])) : ''),
        'status'  => $r['release_status'] ?: 'pending',
        'note'    => (string)$r['release_note'],
        'when'    => $r['release_at'],
        'who'     => (string)$r['released_by_name'],
    ];
}

$logStmt = $pdo->prepare("
    SELECT dl.distributed_at, dl.quantity, dl.amount, dl.assistance_type,
           i.item_name, u.full_name AS distributed_by_name
    FROM distribution_logs dl
    JOIN inventory i ON i.id = dl.inventory_id
    LEFT JOIN users u ON u.id = dl.distributed_by
    WHERE dl.beneficiary_id = ?
      AND NOT EXISTS (
          SELECT 1 FROM distribution_schedule_members m2
          JOIN distribution_schedules s2 ON s2.id = m2.schedule_id
          WHERE m2.beneficiary_id = dl.beneficiary_id
            AND DATE(s2.created_at) = DATE(dl.distributed_at)
      )
    ORDER BY dl.distributed_at DESC
");
$logStmt->execute([$id]);
foreach ($logStmt->fetchAll() as $r) {
    $lbl  = $aidLabels[$r['assistance_type']] ?? $r['assistance_type'];
    $qty  = $r['assistance_type'] === 'cash' && (float)$r['amount'] > 0
        ? 'PHP ' . number_format((float)$r['amount'], 2)
        : '&times; ' . (int)$r['quantity'];
    $history[] = [
        'date'   => date('Y-m-d', strtotime($r['distributed_at'])),
        'title'  => e($r['item_name']) . ' ' . $qty,
        'detail' => $lbl,
        'status' => 'released',
        'note'   => '',
        'when'   => $r['distributed_at'],
        'who'    => (string)$r['distributed_by_name'],
    ];
}

usort($history, fn ($a, $b) => strcmp($b['date'], $a['date']));

// ---- If flagged as a possible duplicate, show the other matching record ----
$dupMatch = null;
if ($ben['is_duplicate']) {
    $dupStmt = $pdo->prepare("SELECT id, full_name, status, created_at FROM beneficiaries WHERE LOWER(full_name) = LOWER(?) AND barangay_id = ? AND id != ? LIMIT 1");
    $dupStmt->execute([$ben['full_name'], $ben['barangay_id'], $id]);
    $dupMatch = $dupStmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Beneficiary Details - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/includes/flash.php'; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
        <?php endif; ?>

        <a href="beneficiaries.php" class="btn-link">Back to Beneficiaries</a>

        <div class="card mt-2">
            <div class="d-flex justify-between align-center flex-wrap gap-2">
                <div>
                    <div style="font-weight:800;font-size:18px;color:var(--navy);">
<?= e(displayName($ben['first_name'] ?? '', $ben['middle_initial'] ?? '', $ben['last_name'] ?? '') ?: formatName($ben['full_name'])) ?>
                        <?php if ($ben['is_duplicate']): ?><span title="Possible duplicate"><?= svgIcon('alert', 16) ?></span><?php endif; ?>
                    </div>
                    <div class="small text-muted"><?php foreach ($benCats as $bc): ?><span class="badge badge-navy" style="font-size:10.5px;"><?= e($bc) ?></span> <?php endforeach; ?><?php if (!empty($ben['gender'])): ?> &bull; <?= e($ben['gender']) ?><?php endif; ?><?php if (!empty($ben['civil_status'])): ?> &bull; <?= e($ben['civil_status']) ?><?php endif; ?></div>
                </div>
            </div>
        </div>

        <?php if ($dupMatch): ?>
            <div class="alert alert-warning">
                <?= svgIcon('alert', 14) ?> Possible duplicate of another record: <strong><?= e(formatName($dupMatch['full_name'])) ?></strong>
                (<a href="beneficiary_detail.php?id=<?= $dupMatch['id'] ?>" class="btn-link">view that record</a>) &bull;
                Status: <?= ucfirst(e($dupMatch['status'])) ?>
            </div>
        <?php endif; ?>

        <div class="section-title">Personal Information</div>
        <div class="card">
            <?= dataPrivacyNotice() ?>
            <div class="pf-name-row" style="margin-bottom:4px;">
                <?php foreach (['First Name' => $ben['first_name'] ?? '', 'Middle Initial' => $ben['middle_initial'] ?? '', 'Last Name' => $ben['last_name'] ?? ''] as $k => $v): ?>
                <div class="pf-field">
                    <div class="pf-k"><?= e($k) ?></div>
                    <div class="pf-v<?= trim((string)$v) === '' ? ' is-empty' : '' ?>"><?= trim((string)$v) !== '' ? e($v) : '&mdash;' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="pf-grid">
                <?php
                $dob = !empty($ben['date_of_birth']) ? date('F j, Y', strtotime($ben['date_of_birth'])) : '';
                $monthly = $ben['monthly_income'] ?? null;
                $profileFields = [
                    'Date of Birth'   => $dob,
                    'Gender'          => (string)($ben['gender'] ?? ''),
                    'Civil Status'    => (string)($ben['civil_status'] ?? ''),
                    'Occupation'      => (string)($ben['occupation'] ?? ''),
                    'Monthly Income'  => ($monthly !== null && $monthly !== '') ? 'PHP ' . number_format((float)$monthly, 2) : '',
                    'House Occupancy' => (string)($ben['house_occupancy'] ?? ''),
                    'Lot Occupancy'   => (string)($ben['lot_occupancy'] ?? ''),
                    'Category'        => implode(', ', $benCats),
                ];
                foreach ($profileFields as $k => $v): ?>
                <div class="pf-field">
                    <div class="pf-k"><?= e($k) ?></div>
                    <div class="pf-v<?= trim($v) === '' ? ' is-empty' : '' ?>"><?= trim($v) !== '' ? e($v) : 'Not set' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="section-title">Registration</div>
        <div class="card">
            <div class="list-item"><div><div class="li-sub">Registered Gmail</div><div class="li-name"><?= e($ben['beneficiary_code'] ?: '(none — added by staff, no login access)') ?></div></div></div>
            <div class="list-item"><div><div class="li-sub">Address</div><div class="li-name"><?= e($ben['address']) ?></div></div></div>
            <div class="list-item"><div><div class="li-sub">Barangay</div><div class="li-name"><?= e($ben['barangay_name']) ?>, <?= e($ben['district_name']) ?></div></div></div>
            <?php if ($ben['contact_number']): ?>
            <div class="list-item"><div><div class="li-sub">Contact Number</div><div class="li-name"><?= e($ben['contact_number']) ?></div></div></div>
            <?php endif; ?>
            <div class="list-item"><div><div class="li-sub">Registered On</div><div class="li-name"><?= date('F j, Y g:i A', strtotime($ben['created_at'])) ?></div></div></div>
        </div>

        <button class="btn btn-outline btn-block" onclick="var f=document.getElementById('editForm'); f.style.display = f.style.display==='none' ? 'block' : 'none';">
            <?= svgIcon('edit', 15) ?> Edit Information
        </button>
        <div id="editForm" class="card" style="margin-top:8px;display:none;">
            <div class="li-name" style="margin-bottom:8px;">Update Information</div>
            <form method="POST">
                <?= csrfField() ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:2;min-width:170px;">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?= e($ben['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group" style="width:110px;">
                        <label>Middle Initial</label>
                        <input type="text" name="middle_initial" class="form-control" maxlength="10" value="<?= e($ben['middle_initial'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="flex:2;min-width:170px;">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?= e($ben['last_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group" style="width:155px;">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control" value="<?= e($ben['date_of_birth']) ?>">
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="min-width:150px;">
                        <label>Gender</label>
                        <select name="gender" class="form-control">
                            <option value="">Not set</option>
                            <?php foreach ($genderOpts as $g): ?>
                                <option value="<?= e($g) ?>" <?= ($ben['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="min-width:180px;">
                        <label>Civil Status</label>
                        <select name="civil_status" class="form-control">
                            <option value="">Not set</option>
                            <?php foreach ($civilOpts as $cs): ?>
                                <option value="<?= e($cs) ?>" <?= ($ben['civil_status'] ?? '') === $cs ? 'selected' : '' ?>><?= e($cs) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:170px;">
                        <label>Occupation</label>
                        <input type="text" name="occupation" class="form-control" value="<?= e($ben['occupation'] ?? '') ?>">
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="min-width:160px;">
                        <label>Monthly Income (PHP)</label>
                        <input type="number" step="0.01" min="0" name="monthly_income" class="form-control" value="<?= ($ben['monthly_income'] ?? null) !== null && $ben['monthly_income'] !== '' ? e((string)(float)$ben['monthly_income']) : '' ?>">
                    </div>
                    <div class="form-group" style="min-width:160px;">
                        <label>House Occupancy</label>
                        <select name="house_occupancy" class="form-control">
                            <option value="">Not set</option>
                            <?php foreach ($occupancyOpts as $o): ?>
                                <option value="<?= e($o) ?>" <?= ($ben['house_occupancy'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="min-width:160px;">
                        <label>Lot Occupancy</label>
                        <select name="lot_occupancy" class="form-control">
                            <option value="">Not set</option>
                            <?php foreach ($occupancyOpts as $o): ?>
                                <option value="<?= e($o) ?>" <?= ($ben['lot_occupancy'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:200px;">
                        <label>Address</label>
                        <input type="text" name="address" class="form-control" value="<?= e($ben['address']) ?>" required>
                    </div>
                    <div class="form-group" style="min-width:260px;">
                        <label>Category <span class="small text-muted">(up to <?= MAX_BENEFICIARY_CATEGORIES ?>)</span></label>
                        <div style="display:flex;flex-wrap:wrap;gap:6px 16px;padding:4px 0;">
                            <?php foreach (BENEFICIARY_CATEGORIES as $c): ?>
                                <label style="display:flex;align-items:center;gap:6px;font-weight:500;cursor:pointer;">
                                    <input type="checkbox" name="category[]" value="<?= e($c) ?>" <?= in_array($c, $benCats, true) ? 'checked' : '' ?>>
                                    <?= e($c) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group" style="min-width:150px;">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" data-numeric="1" value="<?= e($ben['contact_number']) ?>">
                    </div>
                </div>
                <button type="submit" name="save_profile" class="btn btn-primary">Save Changes</button>
            </form>
        </div>

        <div class="section-title">Distribution History</div>
        <div class="card">
            <?php if (!$history): ?>
                <div class="empty-state"><div class="es-icon"><?= svgIcon('box', 30) ?></div>No distribution recorded yet.</div>
            <?php endif; ?>
            <?php foreach ($history as $h):
                [$stLabel, $stClass, $stStyle] = $relLabels[$h['status']] ?? $relLabels['pending'];
            ?>
                <div class="list-item">
                    <div style="min-width:0;">
                        <div class="li-name"><?= $h['title'] ?></div>
                        <div class="li-sub">
                            <?= $h['detail'] ?>
                            <?php if ($h['who'] !== ''): ?> &bull; <?= $h['status'] === 'released' ? 'Released by ' : 'Recorded by ' ?><?= e($h['who']) ?><?php endif; ?>
                            <?php if ($h['note'] !== ''): ?> &bull; <?= e($h['note']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div style="text-align:right;flex:0 0 auto;">
                        <span class="<?= e($stClass) ?>"<?= $stStyle !== '' ? ' style="' . $stStyle . '"' : '' ?>><?= e($stLabel) ?></span>
                        <div class="small text-muted" style="margin-top:3px;"><?= $h['date'] ? date('M j, Y', strtotime($h['date'])) : '' ?></div>
                    </div>
                </div>
<?php endforeach; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
</body>
</html>
