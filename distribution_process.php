<?php
/**
 * DISTRICT ADMIN - Distribution Process (Day of Distribution).
 *
 * Opened from the Distribution page ("Distribution Process" button). The
 * admin picks District > Barangay > Aid Type > Calendar date and sees the
 * ayudas scheduled for that day. Opening one shows the list of beneficiaries
 * who are to receive the ayuda; names can be searched and each is set to
 * Pending, Released, or Not released (with a reason, e.g. deceased). Completed
 * ayudas move below and are locked (labeled Released / Not released).
 */
require_once __DIR__ . '/includes/functions.php';
requireStaff('district_admin');

$pageTitle = 'Distribution Process';
$activePage = 'distribute';

$districts3 = $pdo->query("SELECT * FROM districts ORDER BY id LIMIT 3")->fetchAll();

$assistanceTypes = [
    'cash_general'   => 'Cash - General Fund',
    'cash_emergency' => 'Cash - Emergency Fund',
    'cash'         => 'Cash',
    'food_pack'    => 'Food Packs',
    'hygiene'      => 'Relief Goods', // kept for display compatibility; no new hygiene aid types are created.
    'relief_goods' => 'Relief Goods',
    'sponsored'    => 'Sponsored',
    'other'        => 'Other',
];
$aidLabels = function (?string $t) use ($assistanceTypes) {
    return $t && isset($assistanceTypes[$t]) ? $assistanceTypes[$t] : 'General';
};

// ---------------- Resolve filter selections ----------------
$districtId = (int)($_GET['district_id'] ?? 0);
$barangayId = (int)($_GET['barangay_id'] ?? 0);
$aidType    = $_GET['aid_type'] ?? '';
$date       = $_GET['date'] ?? '';
if ($districtId <= 0 && $districts3) $districtId = (int)$districts3[0]['id'];
if ($barangayId <= 0) {
    $first = getBarangays($pdo, $districtId);
    $barangayId = $first ? (int)$first[0]['id'] : 0;
}
if ($aidType !== '' && !array_key_exists($aidType, $assistanceTypes)) $aidType = '';
if ($date !== '' && !strtotime($date)) $date = '';
$barangays = ($districtId > 0) ? getBarangays($pdo, $districtId) : [];

// ---------------- Handlers ----------------
$errors = [];
$csrfOk = validateCSRF();

// Save day-of release outcomes (pending / released / not released).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_claims']) && $csrfOk) {
    $sid      = (int)($_POST['schedule_id'] ?? 0);
    $statuses = (array)($_POST['mstatus'] ?? []);
    $notes    = (array)($_POST['mnote'] ?? []);
    $notesOther = (array)($_POST['mnote_other'] ?? []);
    $validSt  = ['pending', 'released', 'not_released'];
    $row = $sid > 0 ? $pdo->query("SELECT status FROM distribution_schedules WHERE id=$sid")->fetch() : false;
    if (!$row || in_array($row['status'], ['completed'], true)) {
        $errors[] = 'This distribution is already completed and can no longer be edited.';
    } else {
        $upd = $pdo->prepare("UPDATE distribution_schedule_members
                SET release_status=?, release_note=?, release_at=?, release_by=?,
                    is_claimed=?, claimed_at=?
              WHERE schedule_id=? AND beneficiary_id=? AND is_receiver=1");
        $counts = ['pending' => 0, 'released' => 0, 'not_released' => 0];
        $pdo->beginTransaction();
        foreach ($statuses as $bid => $st) {
            $bid = (int)$bid;
            $st  = (string)$st;
            if ($bid <= 0 || !in_array($st, $validSt, true)) continue;

            $note   = ($st === 'not_released') ? trim((string)($notes[$bid] ?? '')) : '';
            if ($st === 'not_released' && strtolower((string)$note) === 'other') {
                $note = trim((string)($notesOther[$bid] ?? ''));
                if ($note === '') $note = 'Other';
            }
            $stamp  = ($st === 'pending') ? null : date('Y-m-d H:i:s');
            $by     = ($st === 'pending') ? null : ((int)($_SESSION['user_id'] ?? 0) ?: null);

            $upd->execute([
                $st, $note !== '' ? $note : null, $stamp, $by,
                $st === 'released' ? 1 : 0,
                $st === 'released' ? $stamp : null,
                $sid, $bid,
            ]);
            $counts[$st]++;
        }
        $pdo->commit();

        // Once no receiver is left "pending", every beneficiary has a final
        // outcome (released or not released), so the day itself is over:
        // auto-complete the schedule instead of leaving it in "Ready".
        $left = (int)$pdo->query("SELECT COUNT(*) FROM distribution_schedule_members WHERE schedule_id=$sid AND is_receiver=1 AND release_status='pending'")->fetchColumn();
        $autoCompleted = false;
        if ($left === 0) {
            $pdo->prepare("UPDATE distribution_schedules SET status='completed' WHERE id=? AND status<>'completed'")->execute([$sid]);
            $autoCompleted = true;
            logAudit($pdo, 'dayof_complete', ($_SESSION['full_name'] ?? 'Admin') . " all outcomes finalized (no pending left) - schedule #$sid auto-completed");
        }

        logAudit($pdo, 'dayof_claims', ($_SESSION['full_name'] ?? 'Admin') . " updated release outcomes for schedule #$sid ({$counts['released']} released, {$counts['not_released']} not released, {$counts['pending']} pending)");
        $msg = 'Release status saved: ' . $counts['released'] . ' released, ' . $counts['not_released'] . ' not released, ' . $counts['pending'] . ' pending.';
        if ($autoCompleted) $msg .= ' All beneficiaries have been finalized, so this distribution was marked as Completed.';
        setFlash('success', $msg);
        redirect('distribution_process.php?district_id=' . $districtId . '&barangay_id=' . $barangayId . '&aid_type=' . urlencode($aidType) . ($date !== '' ? '&date=' . urlencode($date) : ''));
    }
}

// Edit schedule details (date/time/venue) before completion.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_schedule']) && $csrfOk) {
    $sid = (int)($_POST['schedule_id'] ?? 0);
    $row = $sid > 0 ? $pdo->query("SELECT id, status FROM distribution_schedules WHERE id=$sid")->fetch() : false;
    $newDate = trim((string)($_POST['schedule_date'] ?? ''));
    if ($newDate !== '' && !strtotime($newDate)) $newDate = '';
    $tFrom = trim((string)($_POST['schedule_time_from'] ?? ''));
    $tTo = trim((string)($_POST['schedule_time_to'] ?? ''));
    $rawTime = '';
    if ($tFrom !== '' && $tTo !== '') $rawTime = $tFrom . ' - ' . $tTo;
    elseif ($tFrom !== '') $rawTime = $tFrom;
    elseif ($tTo !== '') $rawTime = $tTo;
    elseif (isset($_POST['schedule_time'])) $rawTime = trim((string)$_POST['schedule_time']);
    $newTime = normalizeTimeInput($rawTime);
    $newVenue = trim((string)($_POST['venue'] ?? ''));
    if (!$row) {
        $errors[] = 'Schedule not found.';
    } elseif (($row['status'] ?? '') === 'completed') {
        $errors[] = 'Completed distributions can no longer be edited.';
    } else {
        $pdo->prepare("UPDATE distribution_schedules SET schedule_date=?, schedule_time=?, venue=? WHERE id=?")
            ->execute([$newDate !== '' ? $newDate : null, $newTime !== '' ? $newTime : null, $newVenue !== '' ? $newVenue : null, $sid]);
        logAudit($pdo, 'schedule_edit', ($_SESSION['full_name'] ?? 'Admin') . " edited schedule #$sid date/time/venue");
        setFlash('success', 'Schedule updated.' . ($newTime !== '' ? ' Time: ' . $newTime . '.' : ''));
        redirect('distribution_process.php?district_id=' . $districtId . '&barangay_id=' . $barangayId . '&aid_type=' . urlencode($aidType) . ($date !== '' ? '&date=' . urlencode($date) : ''));
    }
}

// Mark a schedule as done: it moves below the pending ayudas and is locked.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_distribution']) && $csrfOk) {
    $sid = (int)($_POST['schedule_id'] ?? 0);
    $row = $sid > 0 ? $pdo->query("SELECT title, status FROM distribution_schedules WHERE id=$sid")->fetch() : false;
    if (!$row) {
        $errors[] = 'Schedule not found.';
    } elseif ($row['status'] === 'completed') {
        $errors[] = 'This distribution is already marked as completed.';
    } else {
        $pdo->prepare("UPDATE distribution_schedules SET status='completed' WHERE id=?")->execute([$sid]);
        logAudit($pdo, 'dayof_complete', ($_SESSION['full_name'] ?? 'Admin') . " completed distribution day for schedule #$sid \"" . $row['title'] . '"');
        setFlash('success', 'Distribution day completed. The ayuda has moved to the Completed list below.', true);
        redirect('distribution_process.php?district_id=' . $districtId . '&barangay_id=' . $barangayId . '&aid_type=' . urlencode($aidType) . ($date !== '' ? '&date=' . urlencode($date) : ''));
    }
}

// ---------------- List schedules matching the filters ----------------
$sql = "SELECT ds.*, br.name AS barangay_name, d.name AS district_name,
               (SELECT COUNT(*) FROM distribution_schedule_members m WHERE m.schedule_id=ds.id AND m.is_receiver=1) AS list_count,
               (SELECT COUNT(*) FROM distribution_schedule_members m WHERE m.schedule_id=ds.id AND m.is_receiver=1 AND m.release_status='released') AS claimed_count
        FROM distribution_schedules ds
        JOIN barangays br ON br.id = ds.barangay_id
        JOIN districts d ON d.id = ds.district_id
        WHERE ds.district_id = ? AND ds.barangay_id = ?";
$params = [$districtId, $barangayId];
if ($aidType !== '') { $sql .= " AND (ds.aid_type = ? OR ds.aid_type IS NULL)"; $params[] = $aidType; }
if ($date !== '')    { $sql .= " AND ds.schedule_date = ?"; $params[] = $date; }
$sql .= " ORDER BY (ds.status='completed') ASC, ds.schedule_date DESC, ds.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allSchedules = $stmt->fetchAll();

$pending = [];
$done = [];
foreach ($allSchedules as $s) {
    if ($s['status'] === 'completed') $done[] = $s; else $pending[] = $s;
}

// Members for every schedule (search + claim checkboxes).
    $membersBySchedule = [];
    $ids = array_merge(array_column($pending, 'id'), array_column($done, 'id'));
    if ($ids) {
        $in = implode(',', $ids);
        foreach ($pdo->query("
            SELECT m.schedule_id, m.is_claimed, m.release_status, m.release_note,
                   b.id AS beneficiary_id, b.full_name, b.category
            FROM distribution_schedule_members m
            JOIN beneficiaries b ON b.id = m.beneficiary_id
            WHERE m.schedule_id IN ($in) AND m.is_receiver=1
            ORDER BY CASE WHEN m.release_status='released' THEN 1 WHEN m.release_status='not_released' THEN 2 ELSE 0 END, b.full_name
        ")->fetchAll() as $m) {
            $membersBySchedule[(int)$m['schedule_id']][] = $m;
        }
    // Show every category each recipient belongs to, not only the primary.
    $dpAllIds = [];
    foreach ($membersBySchedule as $rows) { foreach ($rows as $r) $dpAllIds[] = (int)$r['beneficiary_id']; }
    $dpCatMap = beneficiaryCategoriesMap($pdo, $dpAllIds);
    foreach ($membersBySchedule as &$rows) {
        foreach ($rows as &$r) { $r['categories'] = $dpCatMap[(int)$r['beneficiary_id']] ?? [(string)$r['category']]; }
    }
    unset($rows, $r);
}

$dateLabel = $date !== '' ? date('F j, Y', strtotime($date)) : 'All Dates';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
.claim-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 4px; border-bottom:1px solid #eef2f7; }
.sched-summary { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
details > summary { list-style:none; }
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

        <div class="section-title"><?= svgIcon('box', 18) ?> Distribution Process <span class="small text-muted" style="font-weight:400;">&mdash; day of distribution claiming</span></div>

        <!-- Filters: district > barangay > aid type > calendar -->
        <div class="card" style="margin-bottom:14px;">
            <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                    <label>District</label>
                    <select name="district_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($districts3 as $i => $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $districtId === (int)$d['id'] ? 'selected' : '' ?>>District <?= (int)$i + 1 ?> (<?= e($d['name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                    <label>Barangay</label>
                    <select name="barangay_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $barangayId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                    <label>Type of Aid</label>
                    <select name="aid_type" class="form-control" onchange="this.form.submit()">
                        <option value="">All Aid Types</option>
                        <?php foreach ($assistanceTypes as $k => $label): ?>
                            <option value="<?= $k ?>" <?= $aidType === $k ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                    <label>Calendar (Date)</label>
                    <input type="date" name="date" class="form-control" value="<?= e($date) ?>" onchange="this.form.submit()">
                </div>
                <a href="distribution.php?district_id=<?= (int)$districtId ?>&barangay_id=<?= (int)$barangayId ?>&aid_type=<?= urlencode($aidType ?: 'food_pack') ?>" class="btn btn-gold" style="height:44px;font-size:12.5px;padding:0 14px;border-radius:8px;">Distribution</a>
            </form>
        </div>

        <?php if (!$allSchedules): ?>
            <div class="card empty-state">
                <div class="es-icon"><?= svgIcon('calendar', 30) ?></div>
                No <?= e($assistanceTypes[$aidType] ?? '') ?> distribution scheduled for <?= e($dateLabel) ?> in
                <?php $selBar = $barangayId > 0 ? getBarangayById($pdo, $barangayId) : null; ?><?= $selBar ? e($selBar['name']) : 'this barangay' ?> yet.
            </div>
        <?php endif; ?>

        <?php if ($pending): ?>
            <div class="section-title">Ready for Distribution (<?= count($pending) ?>) &mdash; <?= e($dateLabel) ?></div>
            <?php foreach ($pending as $s): $members = $membersBySchedule[(int)$s['id']] ?? [];
                $stParts = preg_split('/\s*[-–—]\s*/u', (string)($s['schedule_time'] ?? ''), 2);
                $stFromVal = timeToInputValue(trim((string)($stParts[0] ?? '')));
                $stToVal = timeToInputValue(trim((string)($stParts[1] ?? '')));
            ?>
                <details class="card" style="margin-bottom:12px;">
                    <summary class="sched-summary" style="cursor:pointer;">
                        <div style="flex:1;min-width:220px;">
                            <div class="li-name">
                                <?= svgIcon('box', 16) ?> <?= e($s['title']) ?>
                                <span class="badge" style="margin-left:6px;background:#eef2f7;color:#5f6b7a;"><?= e($aidLabels($s['aid_type'])) ?></span>
                            </div>
                            <div class="small text-muted">
                                <?= e($s['district_name']) ?> &bull; <?= e($s['barangay_name']) ?>
                                <?php if ($s['schedule_date'] || $s['schedule_time']): ?>&bull; <?= $s['schedule_time'] ? e(formatTime12($s['schedule_time'])) : '' ?><?= ($s['schedule_time'] && $s['schedule_date']) ? ' • ' : '' ?><?= $s['schedule_date'] ? e(date('F j, Y', strtotime($s['schedule_date']))) : '' ?><?php endif; ?>
                                <?php if ($s['venue']): ?>&bull; <?= e($s['venue']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="small" style="min-width:120px;text-align:right;">
                            <span class="claim-counter" data-sched="<?= (int)$s['id'] ?>" data-total="<?= (int)$s['list_count'] ?>">
                                <strong><?= (int)$s['claimed_count'] ?></strong> of <?= (int)$s['list_count'] ?> released
                            </span>
                            &bull; <span class="badge badge-red" style="<?= $s['status'] === 'published' ? 'background:var(--green);' : 'background:#d9921c;' ?>"><?= $s['status'] === 'published' ? 'Published' : 'Draft' ?></span>
                            <span class="small text-muted" style="white-space:nowrap;">View list</span>
                        </div>
                    </summary>
                    <div style="margin-top:12px;">
                        <details style="margin:0 0 10px;border:1px solid #e3e9f0;border-radius:10px;padding:8px 10px;background:#fff;">
                            <summary class="small" style="cursor:pointer;font-weight:700;color:var(--navy);">Edit schedule (date / time / venue)</summary>
                            <form method="POST" style="margin-top:8px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                                    <div class="form-group" style="flex:1;min-width:150px;margin:0;"><label class="small">Date</label><input type="date" name="schedule_date" class="form-control" value="<?= e($s['schedule_date'] ?? '') ?>"></div>
                                    <div class="form-group" style="flex:2;min-width:220px;margin:0;"><label class="small">Time (from - to)</label><div style="display:flex;gap:6px;align-items:center;"><input type="time" step="60" name="schedule_time_from" class="form-control" value="<?= e($stFromVal) ?>"><span class="small text-muted">-</span><input type="time" step="60" name="schedule_time_to" class="form-control" value="<?= e($stToVal) ?>"></div></div>
                                    <div class="form-group" style="flex:2;min-width:180px;margin:0;"><label class="small">Venue</label><input type="text" name="venue" class="form-control" value="<?= e($s['venue'] ?? '') ?>"></div>
                                    <button type="submit" name="edit_schedule" class="btn btn-outline btn-sm">Save schedule</button>
                                </div>
                            </form>
                        </details>
                        <?php if (!$members): ?>
                            <div class="empty-state" style="padding:16px;">No recipients on the final list yet (publish the final list to add beneficiaries).</div>
                        <?php else: ?>
                            <?php if (count($members) > 5): ?>
                                <div class="form-group" style="margin-bottom:8px;">
                                    <input type="search" class="form-control" placeholder="Search beneficiary names..." oninput="filterClaimRows(this, <?= (int)$s['id'] ?>)">
                                </div>
                            <?php endif; ?>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                                <div style="max-height:360px;overflow:auto;border:1px solid #e3e9f0;border-radius:10px;padding:4px 12px;">
                                    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-weight:700;color:var(--navy);padding:8px 0 7px;border-bottom:1px solid #e3e9f0;font-size:13px;">
                                        <span><?= count($members) ?> on this list</span>
                                        <span style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-weight:600;color:#5f6b7a;">
                                            <button type="button" class="btn btn-sm" onclick="markAllReleased(<?= (int)$s['id'] ?>)" style="padding:3px 10px;font-size:12px;">Mark all as Released</button>
                                        </span>
                                    </div>
                                    <?php foreach ($members as $m):
                                        $st   = ($m['release_status'] ?? 'pending') ?: 'pending';
                                        $isClaimedLocked = ($st === 'released');
                                        $isNotReleasedLocked = ($st === 'not_released');
                                        $rnote = (string)($m['release_note'] ?? '');
                                        $reasonOptions = ['Dead', 'Moved Away', 'No valid ID', 'Hospitalized / Sick', 'Refused the ayuda', 'Could not attend'];
                                        $rnoteKnown = in_array($rnote, $reasonOptions, true);
                                        $rnoteOther = (!$rnoteKnown && $rnote !== '') ? $rnote : '';
                                    ?>
                                        <div class="claim-row" data-sched="<?= (int)$s['id'] ?>" data-name="<?= e(strtolower(formatName($m['full_name']))) ?>"
                                             style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f3f6fa;flex-wrap:wrap;<?= ($isClaimedLocked || $isNotReleasedLocked) ? 'opacity:.7;' : '' ?>">
                                            <div style="flex:1 1 170px;min-width:0;font-weight:600;font-size:14px;">
                                                <?= e(formatName($m['full_name'])) ?>
                                                <div class="small text-muted" style="font-weight:400;margin-top:1px;"><?= e(implode(', ', $m['categories'] ?? [(string)$m['category']])) ?></div>
                                            </div>
                                            <?php if ($isClaimedLocked): ?>
                                                <div style="display:flex;align-items:center;gap:10px;flex:0 0 auto;flex-wrap:wrap;">
                                                    <span class="badge badge-success"><?= svgIcon('check', 12) ?> Claimed</span>
                                                    <input type="hidden" name="mstatus[<?= (int)$m['beneficiary_id'] ?>]" value="released">
                                                </div>
                                                <div style="flex:1 1 280px;min-width:220px;"></div>
                                            <?php elseif ($isNotReleasedLocked): ?>
                                                <div style="display:flex;align-items:center;gap:10px;flex:0 0 auto;flex-wrap:wrap;">
                                                    <span class="badge badge-red">Not released<?= $rnote !== '' ? ' &bull; ' . e($rnote) : '' ?></span>
                                                    <input type="hidden" name="mstatus[<?= (int)$m['beneficiary_id'] ?>]" value="not_released">
                                                    <?php if ($rnoteKnown): ?>
                                                        <input type="hidden" name="mnote[<?= (int)$m['beneficiary_id'] ?>]" value="<?= e($rnote) ?>">
                                                    <?php elseif ($rnote !== ''): ?>
                                                        <input type="hidden" name="mnote[<?= (int)$m['beneficiary_id'] ?>]" value="Other">
                                                        <input type="hidden" name="mnote_other[<?= (int)$m['beneficiary_id'] ?>]" value="<?= e($rnote) ?>">
                                                    <?php endif; ?>
                                                </div>
                                                <div style="flex:1 1 280px;min-width:220px;"></div>
                                            <?php else: ?>
                                                <div style="display:flex;align-items:center;gap:14px;flex:0 0 auto;flex-wrap:wrap;">
                                                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                                                        <input type="radio" name="mstatus[<?= (int)$m['beneficiary_id'] ?>]" class="mstatus" value="pending" <?= $st === 'pending' ? 'checked' : '' ?> onchange="relNoteToggle(this)">
                                                        <span>Pending</span>
                                                    </label>
                                                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                                                        <input type="radio" name="mstatus[<?= (int)$m['beneficiary_id'] ?>]" class="mstatus" value="released" <?= $st === 'released' ? 'checked' : '' ?> onchange="relNoteToggle(this)">
                                                        <span>Released</span>
                                                    </label>
                                                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                                                        <input type="radio" name="mstatus[<?= (int)$m['beneficiary_id'] ?>]" class="mstatus" value="not_released" <?= $st === 'not_released' ? 'checked' : '' ?> onchange="relNoteToggle(this)">
                                                        <span>Not released</span>
                                                    </label>
                                                </div>
                                                <div style="display:flex;align-items:center;gap:8px;flex:1 1 280px;min-width:220px;flex-wrap:wrap;">
                                                    <select name="mnote[<?= (int)$m['beneficiary_id'] ?>]" class="form-control rel-note"
                                                           style="flex:0 0 190px;padding:5px 8px;font-size:13px;<?= $st === 'not_released' ? '' : 'display:none;' ?>"
                                                           onchange="relOtherToggle(this)">
                                                        <option value="">Select reason&hellip;</option>
                                                        <?php foreach ($reasonOptions as $__ro): ?>
                                                            <option value="<?= e($__ro) ?>"<?= $rnoteKnown && $rnote === $__ro ? ' selected' : '' ?>><?= e($__ro) ?></option>
                                                        <?php endforeach; ?>
                                                        <option value="Other"<?= $rnoteOther !== '' ? ' selected' : '' ?>>Other&hellip;</option>
                                                    </select>
                                                    <input type="text" name="mnote_other[<?= (int)$m['beneficiary_id'] ?>]" class="form-control rel-note-other"
                                                           placeholder="Specify the reason" value="<?= e($rnoteOther) ?>"
                                                           style="flex:1 1 170px;padding:5px 8px;font-size:13px;<?= $st === 'not_released' && $rnoteOther !== '' ? '' : 'display:none;' ?>">
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
                                    <button type="submit" name="save_claims" class="btn btn-primary btn-sm"><?= svgIcon('check', 14) ?> Save Release Status</button>
                                    <button type="button" class="btn btn-success btn-sm" data-sched="<?= (int)$s['id'] ?>" data-title="<?= e($s['title']) ?>" onclick="openCompleteConfirm(this)"><?= svgIcon('flag', 14) ?> Complete Distribution</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="section-title">Completed (<?= count($done) ?>)</div>
            <?php foreach ($done as $s): $members = $membersBySchedule[(int)$s['id']] ?? []; $claimed = (int)$s['claimed_count']; ?>
                <div class="card" style="margin-bottom:12px;opacity:.85;background:#f6f8fa;">
                    <div class="sched-summary">
                        <div style="flex:1;min-width:220px;">
                            <div class="li-name">
                                <?= svgIcon('ok', 16) ?> <?= e($s['title']) ?>
                                <span class="badge" style="background:var(--green);"><?= svgIcon('check', 12) ?> Claimed</span>
                                <span class="badge" style="background:#5f6b7a;"><?= e($aidLabels($s['aid_type'])) ?></span>
                            </div>
                            <div class="small text-muted">
                                <?= e($s['district_name']) ?> &bull; <?= e($s['barangay_name']) ?>
                                <?php if ($s['schedule_date'] || $s['schedule_time']): ?>&bull; <?= $s['schedule_date'] ? e(date('F j, Y', strtotime($s['schedule_date']))) : '' ?><?= ($s['schedule_date'] && $s['schedule_time']) ? ' • ' : '' ?><?= $s['schedule_time'] ? e(formatTime12($s['schedule_time'])) : '' ?><?php endif; ?>
                                &bull; <?= $claimed ?> of <?= (int)$s['list_count'] ?> released
                            </div>
                        </div>
                        <span class="small text-muted" style="min-width:110px;text-align:right;">This distribution is finished.</span>
                    </div>
                    <?php if ($members): ?>
                        <details style="margin-top:10px;">
                            <summary class="small text-muted" style="cursor:pointer;"><?= svgIcon('user', 13) ?> View beneficiaries (<?= count($members) ?>)</summary>
                            <div style="max-height:300px;overflow:auto;border:1px solid #e3e9f0;border-radius:10px;padding:4px 12px;margin-top:8px;background:#fff;">
                                <?php foreach ($members as $m):
                                    $mst = ($m['release_status'] ?? 'pending') ?: 'pending';
                                    $mrn = (string)($m['release_note'] ?? '');
                                ?>
                                    <div class="claim-row" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid #f3f6fa;">
                                        <div style="flex:1 1 160px;min-width:0;font-size:14px;font-weight:600;"><?= e(formatName($m['full_name'])) ?></div>
                                        <span class="small text-muted"><?= e(implode(', ', $m['categories'] ?? [(string)$m['category']])) ?></span>
                                        <?php if ($mst === 'released'): ?>
                                            <span class="badge badge-success"><?= svgIcon('check', 12) ?> Released</span>
                                        <?php elseif ($mst === 'not_released'): ?>
                                            <span class="badge badge-red" <?= $mrn !== '' ? 'title="' . e($mrn) . '"' : '' ?>>Not released<?= $mrn !== '' ? ' &bull; ' . e($mrn) : '' ?></span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#e3e9f0;color:#5f6b7a;">Pending</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<!-- YES / NO CONFIRM MODAL (complete a distribution) -->
<div class="modal-overlay" id="completeModal">
    <div class="modal-box" style="max-width:440px;">
        <span class="mb-icon"><?= svgIcon('alert', 30) ?></span>
        <h3 style="margin-top:0;color:var(--navy);text-align:center;">Complete Distribution?</h3>
        <p class="small text-muted" style="text-align:center;line-height:1.6;margin-bottom:18px;">
            Are you sure you want to finish
            &ldquo;<strong id="completeTitle" style="color:var(--navy);"></strong>&rdquo;?
            It will move to the Completed list below and can no longer be edited.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="schedule_id" id="completeSchedId" value="">
            <button type="submit" name="complete_distribution" class="btn btn-success btn-block mt-3"><?= svgIcon('check', 15) ?> Yes, Complete</button>
            <button type="button" class="btn btn-outline btn-block mt-2" id="completeNo">No, Cancel</button>
        </form>
    </div>
</div>

<script>
// Yes / No confirmation before completing a distribution.
function openCompleteConfirm(btn) {
    document.getElementById('completeSchedId').value = btn.getAttribute('data-sched');
    document.getElementById('completeTitle').textContent = btn.getAttribute('data-title');
    document.getElementById('completeModal').classList.add('show');
}
document.getElementById('completeNo').addEventListener('click', function () {
    document.getElementById('completeModal').classList.remove('show');
});
document.getElementById('completeModal').addEventListener('click', function (ev) {
    if (ev.target === this) this.classList.remove('show');
});
// Per-schedule name search (day of distribution).
function filterClaimRows(input, sid) {
    const q = input.value.toLowerCase();
    document.querySelectorAll('.claim-row[data-sched="' + sid + '"]').forEach(function (row) {
        row.style.display = row.getAttribute('data-name').indexOf(q) > -1 ? 'flex' : 'none';
    });
}
// Reveal the reason dropdown only when a member is marked Not released, and
// the "specify" box only when Other is chosen from it.
function relNoteToggle(sel) {
    const row = sel.closest('.claim-row');
    let val = '';
    if (sel.type === 'radio') {
        if (sel.checked) val = sel.value;
        else if (row) {
            const checked = row.querySelector('.mstatus:checked');
            if (checked) val = checked.value;
        }
    } else {
        val = sel.value;
    }
    if (!val && row) {
        const checked = row.querySelector('.mstatus:checked');
        if (checked) val = checked.value;
    }
    const note = row ? row.querySelector('.rel-note') : null;
    if (note) note.style.display = val === 'not_released' ? '' : 'none';
    const other = row ? row.querySelector('.rel-note-other') : null;
    if (other) other.style.display = 'none';
    if (note && val === 'not_released' && typeof relOtherToggle === 'function') relOtherToggle(note);
    refreshCounterFor(sel);
}
function relOtherToggle(sel) {
    const row = sel.closest('.claim-row');
    const other = row ? row.querySelector('.rel-note-other') : null;
    if (other) other.style.display = sel.value === 'Other' ? '' : 'none';
}
// Keep the "X of N released" counter in step as statuses change.
function refreshCounterFor(el) {
    let sid = el;
    if (el && typeof el === 'object' && el.getAttribute) {
        const row = el.closest ? el.closest('.claim-row') : null;
        sid = row ? row.getAttribute('data-sched') : el.getAttribute('data-sched');
    }
    if (!sid) return;
    const all = Array.from(document.querySelectorAll('.claim-row[data-sched="' + sid + '"] input.mstatus'));
    const released = all.filter(function (s) { return s.checked && s.value === 'released'; }).length;
    const counter = document.querySelector('.claim-counter[data-sched="' + sid + '"]');
    if (counter) counter.innerHTML = '<strong>' + released + '</strong> of ' + all.length + ' released';
}
function markAllReleased(sid) {
    document.querySelectorAll('.claim-row[data-sched="' + sid + '"]').forEach(function(row){
        const hidden = row.querySelector('input[type="hidden"][name^="mstatus"]');
        if (hidden) return;
        const r = row.querySelector('input.mstatus[value="released"]');
        if (r && !r.disabled) { r.checked = true; if (typeof relNoteToggle === 'function') relNoteToggle(r); }
    });
    refreshCounterFor(document.querySelector('.claim-counter[data-sched="' + sid + '"]') || sid);
}
</script>
</body>
</html>