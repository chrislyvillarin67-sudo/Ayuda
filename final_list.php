<?php
/**
 * Final List / Distribution Schedule.
 *
 * BARANGAY STAFF (assisting role): create a distribution notice for their own
 * barangay (venue, when, what to bring), then PUBLISH it. Publishing generates
 * the "final list" from that barangay's approved beneficiaries, which the
 * public beneficiary viewer (view.php) then displays.
 *
 * DISTRICT ADMIN: reviews the published schedules / final lists across all
 * districts and barangays.
 *
 * Staff do NOT directly distribute ayuda here -- that is the District Admin's
 * role (see distribution.php / distribute_ayuda.php).
 */
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$isAdmin = $_SESSION['role'] === 'district_admin';
$myBarangayId = (int)($_SESSION['barangay_id'] ?? 0);
$pageTitle = $isAdmin ? 'Final Lists' : 'Post Final List';
$activePage = 'final_list';
$districts = getDistricts($pdo);
$errors = [];
$csrfOk = validateCSRF();

// ---- CREATE a draft schedule (staff, own barangay) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_schedule']) && !$isAdmin && $csrfOk) {
    $title = trim($_POST['title'] ?? '');
    $venue = trim($_POST['venue'] ?? '');
    $sdate = $_POST['schedule_date'] ?? '';
    $stime = normalizeTimeInput($_POST['schedule_time'] ?? '');
    $what  = trim($_POST['what_to_bring'] ?? '');
    $note  = trim($_POST['note'] ?? '');
    if ($title === '') $errors[] = 'Title is required.';
    if (!$errors) {
        $brgy = getBarangayById($pdo, $myBarangayId);
        $pdo->prepare("INSERT INTO distribution_schedules (title, barangay_id, district_id, venue, schedule_date, schedule_time, what_to_bring, note, status, created_by)
                       VALUES (?,?,?,?,?,?,?,?, 'draft', ?)")
            ->execute([$title, $myBarangayId, $brgy ? (int)$brgy['district_id'] : 0, $venue ?: null,
                       $sdate ?: null, $stime ?: null, $what ?: null, $note ?: null, $_SESSION['user_id']]);
        logAudit($pdo, 'schedule_create', ($_SESSION['full_name'] ?? 'Staff') . " created a draft distribution notice \"$title\"");
        setFlash('success', 'Distribution notice created. Fill the details then publish to generate the final list.');
        redirect('final_list.php');
    }
}

// ---- UPDATE schedule details (staff, own barangay, draft/published) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule']) && !$isAdmin && $csrfOk) {
    $id = (int)($_POST['schedule_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $venue = trim($_POST['venue'] ?? '');
    $sdate = $_POST['schedule_date'] ?? '';
    $stime = normalizeTimeInput($_POST['schedule_time'] ?? '');
    $what  = trim($_POST['what_to_bring'] ?? '');
    $note  = trim($_POST['note'] ?? '');
    $chk = $pdo->prepare("SELECT id FROM distribution_schedules WHERE id=? AND barangay_id=?");
    $chk->execute([$id, $myBarangayId]);
    if (!$chk->fetch()) $errors[] = 'Schedule not found.';
    elseif ($title === '') $errors[] = 'Title is required.';
    if (!$errors) {
        $pdo->prepare("UPDATE distribution_schedules SET title=?, venue=?, schedule_date=?, schedule_time=?, what_to_bring=?, note=? WHERE id=?")
            ->execute([$title, $venue ?: null, $sdate ?: null, $stime ?: null, $what ?: null, $note ?: null, $id]);
        setFlash('success', 'Notice updated.');
        redirect('final_list.php');
    }
}

// ---- PUBLISH a schedule (staff, own barangay) => generates final list ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_schedule']) && !$isAdmin && $csrfOk) {
    $id = (int)($_POST['schedule_id'] ?? 0);
    $chk = $pdo->prepare("SELECT * FROM distribution_schedules WHERE id=? AND barangay_id=?");
    $chk->execute([$id, $myBarangayId]);
    $sch = $chk->fetch();
    if (!$sch) {
        $errors[] = 'Schedule not found.';
    } else {
        $pdo->beginTransaction();
        // Generate the final list from this barangay's approved beneficiaries (skip already-listed).
        $stmt = $pdo->prepare("SELECT id FROM beneficiaries WHERE barangay_id=? AND status='approved'");
        $stmt->execute([$myBarangayId]);
        $approved = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare("INSERT IGNORE INTO distribution_schedule_members (schedule_id, beneficiary_id, is_receiver) VALUES (?,?,1)");
        foreach ($approved as $bid) $ins->execute([$id, $bid]);
        $pdo->prepare("UPDATE distribution_schedules SET status='published', published_by=?, published_at=NOW() WHERE id=?")
            ->execute([$_SESSION['user_id'], $id]);
        // Connection: publishing the final list also posts an announcement for the
        // barangay so residents see one consistent notice + list (no duplication).
        $amparts = [];
        if ($sch['venue'])      $amparts[] = 'Venue: ' . $sch['venue'];
        if ($sch['schedule_date'] || $sch['schedule_time']) $amparts[] = 'Schedule: ' . ($sch['schedule_date'] ? date('F j, Y', strtotime($sch['schedule_date'])) : '') . (($sch['schedule_date'] && $sch['schedule_time']) ? ' • ' : '') . ($sch['schedule_time'] ? formatTime12($sch['schedule_time']) : '');
        if ($sch['what_to_bring']) $amparts[] = 'What to bring: ' . $sch['what_to_bring'];
        if ($sch['note'])       $amparts[] = 'Note: ' . $sch['note'];
        $approvedCount = count($approved);
        $amparts[] = 'Recipients: ' . $approvedCount . ' ' . ($approvedCount === 1 ? 'resident' : 'residents') . ' in your barangay.';
        $catBreak = recipientCategoryBreakdown($pdo, $approved);
        if ($catBreak) $amparts[] = 'Recipients by category: ' . categoryBreakdownText($catBreak);
        ensureAutoAnnouncement($pdo, 'schedule_final_list', (int)$sch['id'], $myBarangayId, $sch['title'], implode("\n", $amparts), 'normal', $_SESSION['user_id']);
        $pdo->commit();
        logAudit($pdo, 'schedule_publish', ($_SESSION['full_name'] ?? 'Staff') . " published final list for schedule #$id (" . count($approved) . " recipients)");
        setFlash('success', 'Notice published. Beneficiaries can now view the venue, schedule and final list, and an announcement was posted for the barangay.');
        redirect('final_list.php');
    }
}

// ---- DELETE a schedule (staff, own barangay; admin any) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule']) && $csrfOk) {
    $id = (int)($_POST['schedule_id'] ?? 0);
    if ($isAdmin) {
        $pdo->prepare("DELETE FROM distribution_schedules WHERE id=?")->execute([$id]);
    } else {
        $pdo->prepare("DELETE FROM distribution_schedules WHERE id=? AND barangay_id=?")->execute([$id, $myBarangayId]);
    }
    logAudit($pdo, 'schedule_delete', 'Deleted distribution schedule #' . $id);
    setFlash('success', 'Notice deleted.');
    redirect('final_list.php');
}

// ---- Mark a schedule completed (admin) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_schedule']) && $isAdmin && $csrfOk) {
    $id = (int)($_POST['schedule_id'] ?? 0);
    $pdo->prepare("UPDATE distribution_schedules SET status='completed' WHERE id=?")->execute([$id]);
    setFlash('success', 'Schedule marked as completed.');
    redirect('final_list.php');
}

// ---- List schedules (staff: own barangay; admin: all) ----
if ($isAdmin) {
    $schedules = $pdo->query("
        SELECT ds.*, br.name AS barangay_name, d.name AS district_name,
               (SELECT COUNT(*) FROM distribution_schedule_members m WHERE m.schedule_id=ds.id AND m.is_receiver=1) AS list_count,
               u.full_name AS published_by_name
        FROM distribution_schedules ds
        JOIN barangays br ON br.id = ds.barangay_id
        JOIN districts d ON d.id = ds.district_id
        LEFT JOIN users u ON u.id = ds.published_by
        ORDER BY (ds.status='published') DESC, ds.id DESC
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT ds.*, br.name AS barangay_name, d.name AS district_name,
               (SELECT COUNT(*) FROM distribution_schedule_members m WHERE m.schedule_id=ds.id AND m.is_receiver=1) AS list_count,
               u.full_name AS published_by_name
        FROM distribution_schedules ds
        JOIN barangays br ON br.id = ds.barangay_id
        JOIN districts d ON d.id = ds.district_id
        LEFT JOIN users u ON u.id = ds.published_by
        WHERE ds.barangay_id = ?
        ORDER BY (ds.status='published') DESC, ds.id DESC
    ");
    $stmt->execute([$myBarangayId]);
    $schedules = $stmt->fetchAll();
}

// Fetch member counts per schedule for a small preview (used on expand).
$membersById = [];
if ($schedules) {
    $ids = array_map(fn($s) => (int)$s['id'], $schedules);
    $in = implode(',', $ids);
    foreach ($pdo->query("SELECT m.schedule_id, b.id AS beneficiary_id, b.barangay_id, b.full_name, b.category, m.is_claimed, m.release_status
                          FROM distribution_schedule_members m
                          JOIN beneficiaries b ON b.id=m.beneficiary_id
                          WHERE m.schedule_id IN ($in) AND m.is_receiver=1
                          ORDER BY b.full_name")->fetchAll() as $m) {
        $membersById[(int)$m['schedule_id']][] = $m;
    }
    // A recipient may belong to several categories; load the full set so the
    // final list shows every badge, not only the primary one.
    if ($membersById) {
        $flIds = [];
        foreach ($membersById as $rows) { foreach ($rows as $r) $flIds[] = (int)$r['beneficiary_id']; }
        $flCatMap = beneficiaryCategoriesMap($pdo, $flIds);
        foreach ($membersById as &$rows) {
            foreach ($rows as &$r) { $r['categories'] = $flCatMap[(int)$r['beneficiary_id']] ?? [(string)$r['category']]; }
        }
        unset($rows, $r);
    }
}

// A recipient counts as "claimed" only once the admin actually released this
// ayuda to them on this schedule (day-of process) -- not merely because a past
// distribution log exists for the barangay.
$memberIsClaimed = fn(array $m): bool => ((int)($m['is_claimed'] ?? 0) === 1) || (($m['release_status'] ?? '') === 'released');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Ayuda Monitoring System</title>
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

        <?php if (!$isAdmin): ?>
        <div class="section-title" style="margin-bottom:10px;">New Distribution Notice</div>
        <div class="card">
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group">
                    <label>Notice Title</label>
                    <input type="text" name="title" class="form-control" required placeholder="e.g. Ayuda Distribution - Food Packs">
                </div>
                <div class="grid-2" style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:150px;">
                        <label>Venue</label>
                        <input type="text" name="venue" class="form-control" placeholder="Barangay Hall / Covered Court">
                    </div>
                    <div class="form-group" style="flex:1;min-width:140px;">
                        <label>Date</label>
                        <input type="date" name="schedule_date" class="form-control">
                    </div>
                    <div class="form-group" style="flex:1;min-width:120px;">
                        <label>Time</label>
                        <input type="time" step="60" name="schedule_time" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>What to Bring</label>
                    <input type="text" name="what_to_bring" class="form-control" placeholder="e.g. Valid ID + Barangay Clearance">
                </div>
                <div class="form-group">
                    <label>Additional Note</label>
                    <textarea name="note" class="form-control" rows="2"></textarea>
                </div>
                <button type="submit" name="create_schedule" class="btn btn-primary btn-block">Create Draft</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:18px;"><?= $isAdmin ? 'All Distribution Notices &amp; Final Lists' : 'My Distribution Notices' ?></div>

        <?php if (!$schedules): ?>
            <div class="card empty-state">No distribution notices yet.</div>
        <?php endif; ?>

        <?php foreach ($schedules as $s): ?>
            <div class="card" style="margin-bottom:12px;">
                <div class="d-flex justify-between align-center" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div>
                        <div class="li-name"><?= e($s['title']) ?></div>
                        <div class="small text-muted">
                            <?= $isAdmin ? e($s['district_name']) . ' &bull; ' . e($s['barangay_name']) : e($s['barangay_name']) ?>
                            &bull; <span class="badge <?= statusBadgeClass($s['status']) ?>"><?= ucfirst(e($s['status'])) ?></span>
                            <?= $s['published_by_name'] ? '&bull; Published by ' . e($s['published_by_name']) : '' ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <?php if (!$isAdmin && $_SESSION['user_id'] && (int)$s['created_by'] === (int)$_SESSION['user_id']): ?>
                            <button class="btn btn-outline btn-sm" onclick="editSchedule(<?= (int)$s['id'] ?>)">Edit</button>
                        <?php endif; ?>
                        <?php if (!$isAdmin && $s['status'] === 'draft'): ?>
                            <form method="POST" onsubmit="return confirm('Publish this notice and generate the final list?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" name="publish_schedule" class="btn btn-success btn-sm"><?= svgIcon('list', 15) ?> Publish Final List</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($isAdmin && $s['status'] === 'published'): ?>
                            <form method="POST" onsubmit="return confirm('Mark this schedule as completed?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" name="complete_schedule" class="btn btn-navy btn-sm">Mark Completed</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Delete this notice?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" name="delete_schedule" class="btn btn-danger btn-sm"><?= svgIcon('trash', 15) ?></button>
                        </form>
                    </div>
                </div>

                <div class="small mt-2" style="display:flex;gap:6px;flex-wrap:wrap;">
                    <?php if ($s['venue']): ?><span class="venue-badge" style="background:var(--navy-light);"><?= svgIcon('home', 13) ?> <?= e($s['venue']) ?></span><?php endif; ?>
                    <?php if ($s['schedule_date'] || $s['schedule_time']): ?><span class="venue-badge" style="background:var(--navy-light);"><?= svgIcon('calendar', 13) ?> <?= $s['schedule_date'] ? e(date('F j, Y', strtotime($s['schedule_date']))) : '' ?><?= ($s['schedule_date'] && $s['schedule_time']) ? ' • ' : '' ?><?= $s['schedule_time'] ? e(formatTime12($s['schedule_time'])) : '' ?></span><?php endif; ?>
                    <?php if ($s['what_to_bring']): ?><span class="venue-badge" style="background:#5f6b7a;"><?= svgIcon('file', 13) ?> <?= e($s['what_to_bring']) ?></span><?php endif; ?>
                </div>
                <?php if ($s['note']): ?>
                    <div class="small text-muted mt-2" style="white-space:pre-wrap;"><?= nl2br(e($s['note'])) ?></div>
                <?php endif; ?>

                <?php if (isset($membersById[(int)$s['id']])): $sClaimStageIn = $s['schedule_date'] && strtotime($s['schedule_date']) <= strtotime(date('Y-m-d')); $claims = 0; foreach ($membersById[(int)$s['id']] as $m) { if ($memberIsClaimed($m)) $claims++; } ?>
                    <details style="margin-top:10px;">
                        <summary class="small text-muted" style="cursor:pointer;">
                            <?= svgIcon('user', 14) ?> Final List (<?= count($membersById[(int)$s['id']]) ?> recipients<?php if ($sClaimStageIn): ?> &bull; <?= $claims ?> claimed<?php endif; ?>)
                        </summary>
                        <div class="card" style="margin-top:8px;padding:6px 14px;">
                            <?php foreach ($membersById[(int)$s['id']] as $m): $isClaimed = $memberIsClaimed($m); ?>
                                <div class="final-list-item" style="border-bottom:1px solid #eef1f5;padding:7px 0;">
                                    <div style="flex:1;font-size:14px;font-weight:600;"><?= e(formatName($m['full_name'])) ?></div>
                                    <span style="margin-right:8px;" class="small text-muted"><?= e(implode(', ', $m['categories'] ?? [(string)$m['category']])) ?></span>
                                    <?php if ($sClaimStageIn): ?>
                                        <?php if ($isClaimed): ?>
                                            <span class="badge badge-success"><?= svgIcon('check', 12) ?> Claimed</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#e3e9f0;color:#5f6b7a;">Not yet claimed</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<!-- Edit modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box" style="max-width:440px;">
        <h3 style="margin-top:0;color:var(--navy);text-align:center;">Edit Distribution Notice</h3>
        <form method="POST" id="editForm">
            <?= csrfField() ?>
            <input type="hidden" name="schedule_id" id="e_id">
            <div class="form-group">
                <label>Notice Title</label>
                <input type="text" name="title" id="e_title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Venue</label>
                <input type="text" name="venue" id="e_venue" class="form-control">
            </div>
            <div class="grid-2" style="display:flex;gap:10px;">
                <div class="form-group" style="flex:1;">
                    <label>Date</label>
                    <input type="date" name="schedule_date" id="e_date" class="form-control">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Time</label>
                    <input type="time" step="60" name="schedule_time" id="e_time" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label>What to Bring</label>
                <input type="text" name="what_to_bring" id="e_what" class="form-control">
            </div>
            <div class="form-group">
                <label>Additional Note</label>
                <textarea name="note" id="e_note" class="form-control" rows="2"></textarea>
            </div>
            <button type="submit" name="update_schedule" class="btn btn-primary btn-block">Save Changes</button>
            <button type="button" class="btn btn-outline btn-block mt-2" onclick="closeEdit()">Cancel</button>
        </form>
    </div>
</div>

<script>
const schedules = <?= json_encode(array_map(function($s){
    return [
        'id'=>(int)$s['id'], 'title'=>$s['title'], 'venue'=>$s['venue'] ?? '',
        'sdate'=>($s['schedule_date'] ?? '') ? date('Y-m-d', strtotime($s['schedule_date'])) : '',
        'stime'=>$s['schedule_time'] ?? '', 'what_to_bring'=>$s['what_to_bring'] ?? '', 'note'=>$s['note'] ?? '',
        'mine'=>(int)$s['created_by'] === (int)($_SESSION['user_id'] ?? 0)
    ];
}, $schedules), JSON_UNESCAPED_UNICODE) ?>;
function editSchedule(id) {
    const s = schedules.find(x => x.id === id);
    if (!s) return;
    document.getElementById('e_id').value = s.id;
    document.getElementById('e_title').value = s.title;
    document.getElementById('e_venue').value = s.venue;
    document.getElementById('e_date').value = s.sdate;
    document.getElementById('e_time').value = toTimeValue(s.stime);
    document.getElementById('e_what').value = s.what_to_bring;
    document.getElementById('e_note').value = s.note;
    document.getElementById('editModal').classList.add('show');
}
// "9:00 AM" / "14:30" -> "09:00" so the <input type="time"> picker can show it.
function toTimeValue(v) {
    const t = String(v || '').trim();
    if (!t) return '';
    const parts = t.split(' ');
    const hm = parts[0].split(':');
    if (hm.length < 2) return '';
    let h = parseInt(hm[0], 10);
    const m = hm[1].slice(0, 2);
    if (isNaN(h)) return '';
    const ap = (parts[1] || '').toUpperCase();
    if (ap === 'PM' && h < 12) h += 12;
    if (ap === 'AM' && h === 12) h = 0;
    return (h < 10 ? '0' : '') + h + ':' + m;
}
function closeEdit() { document.getElementById('editModal').classList.remove('show'); }
</script>
</body>
</html>
