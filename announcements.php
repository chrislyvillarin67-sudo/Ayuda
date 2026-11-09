<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = ''; // the "Announcements" label above "Recent" is redundant; drop it from the top bar
$activePage = 'announcements';
$isAdmin = $_SESSION['role'] === 'district_admin';

$districts = getDistricts($pdo);
$errors = [];
$csrfOk = validateCSRF();
$barangayId = (int)($_SESSION['barangay_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

// ---- Staff relay: post a district announcement to their barangay's beneficiaries ----
// For distribution-sourced announcements, the staff also adds the "what to bring"
// note/description which is written to the linked schedule before it goes public.
if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['relay_announcement']) && $csrfOk) {
    $annId = (int)$_POST['relay_announcement'];
    if ($annId > 0 && $barangayId) {
        $stmt = $pdo->prepare("SELECT id, title, message, source_type, source_id FROM announcements WHERE id=? AND (barangay_id IS NULL OR barangay_id=?)");
        $stmt->execute([$annId, $barangayId]);
        $ann = $stmt->fetch();
        if ($ann) {
            $whatToBring = trim($_POST['what_to_bring'] ?? '');
            $note = trim($_POST['note'] ?? '');

            // If this announcement is linked to a distribution schedule, publish the
            // schedule (venue / date / final list) and write the staff's note to it.
            $pubSched = false;
            if ($ann['source_type'] === 'distribution_schedule' && (int)$ann['source_id'] > 0) {
                $schStmt = $pdo->prepare("SELECT * FROM distribution_schedules WHERE id=? AND barangay_id=?");
                $schStmt->execute([(int)$ann['source_id'], $barangayId]);
                $schRow = $schStmt->fetch();
                if ($schRow) {
                    $upd = "status='published', published_by=?, published_at=NOW()";
                    $params = [$_SESSION['user_id']];
                    if ($whatToBring !== '') { $upd .= ", what_to_bring=?"; $params[] = $whatToBring; }
                    if ($note !== '')        { $upd .= ", note=?"; $params[] = $note; }
                    $params[] = (int)$schRow['id'];
                    $pdo->prepare("UPDATE distribution_schedules SET $upd WHERE id=?")->execute($params);
                    $pubSched = true;
                    // Reflect the note on the public announcement itself too.
                    $extra = [];
                    if ($whatToBring !== '') $extra[] = 'What to bring: ' . $whatToBring . ' (to receive the ayuda)';
                    if ($note !== '')        $extra[] = 'Note from Barangay Staff: ' . $note;
                    if ($extra) {
                        $pdo->prepare("UPDATE announcements SET message = CONCAT(message, ?, ?) WHERE id=?")
                            ->execute(["\n", implode("\n", $extra), $annId]);
                    }
                }
            } else {
                // Not linked to a schedule (general notice): still add the
                // staff's what-to-bring note to the public announcement message.
                $extra = [];
                if ($whatToBring !== '') $extra[] = 'What to bring: ' . $whatToBring . ' (to receive the ayuda)';
                if ($note !== '')        $extra[] = 'Note from Barangay Staff: ' . $note;
                if ($extra) {
                    $pdo->prepare("UPDATE announcements SET message = CONCAT(message, ?, ?) WHERE id=?")
                        ->execute(["\n", implode("\n", $extra), $annId]);
                }
            }

            $pdo->prepare("INSERT IGNORE INTO announcement_relays (announcement_id, barangay_id, relayed_by) VALUES (?,?,?)")
                ->execute([$annId, $barangayId, $_SESSION['user_id']]);
            logAudit($pdo, 'announcement_relay', 'Relayed announcement #' . $annId . ' "' . $ann['title'] . '" to barangay #' . $barangayId . ' beneficiaries' .
                ($pubSched ? ' and published the linked distribution schedule' : ''));
            setFlash('success', $pubSched
                ? 'Posted to your barangay\'s beneficiaries. The distribution schedule, final list and "what to bring" note are now public. Residents can view them here.'
                : 'Announcement posted to your barangay\'s beneficiaries (public side).');
        } else {
            setFlash('danger', 'Announcement not found for your barangay.');
        }
    }
    redirect('announcements.php');
}

// ---- Create announcement (admin only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement']) && $isAdmin && $csrfOk) {
    $title   = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $brgyId  = (int)($_POST['barangay_id'] ?? 0);

    if ($title === '') $errors[] = 'Title is required.';
    if ($message === '') $errors[] = 'Message is required.';
    if (!in_array($priority, ['high','normal'], true)) $priority = 'normal';

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO announcements (barangay_id, title, message, priority, created_by) VALUES (?,?,?,?,?)");
        $stmt->execute([$brgyId ?: null, $title, $message, $priority, $_SESSION['user_id']]);
        logAudit($pdo, 'announcement_create', 'Created announcement "' . $title . '" (' . $priority . ')');
        setFlash('success', 'Announcement published.');
        redirect('announcements.php');
    }
}

// ---- Delete announcement (admin only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && $isAdmin && $csrfOk) {
    $delId = (int)$_POST['delete_id'];
    $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([$delId]);
    logAudit($pdo, 'announcement_delete', 'Deleted announcement #' . $delId);
    setFlash('success', 'Announcement deleted.');
    redirect('announcements.php');
}

// ---- Listing ----
// Staff see citywide announcements + their own barangay's. Admin sees all.
// The list is ordered newest-first (recent announcements shown up top).
if ($isAdmin) {
    $announcements = $pdo->query("
        SELECT a.*, br.name AS barangay_name, u.full_name AS created_by_name,
               ds.schedule_date, ds.schedule_time, ds.venue AS sched_venue,
               0 AS is_new
        FROM announcements a
        LEFT JOIN barangays br ON br.id = a.barangay_id
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN distribution_schedules ds ON ds.id = a.source_id
            AND a.source_type IN ('distribution_schedule','schedule_final_list')
        ORDER BY a.created_at DESC, a.id DESC
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT a.*, br.name AS barangay_name, u.full_name AS created_by_name,
               ds.schedule_date, ds.schedule_time, ds.venue AS sched_venue,
               EXISTS(SELECT 1 FROM announcement_relays r WHERE r.announcement_id=a.id AND r.barangay_id=?) AS relayed,
               s.user_id IS NULL AS is_new
        FROM announcements a
        LEFT JOIN barangays br ON br.id = a.barangay_id
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN distribution_schedules ds ON ds.id = a.source_id
            AND a.source_type IN ('distribution_schedule','schedule_final_list')
        LEFT JOIN announcement_seen s ON s.announcement_id = a.id AND s.user_id = ?
        WHERE a.barangay_id IS NULL OR a.barangay_id = ?
        ORDER BY a.created_at DESC, a.id DESC
    ");
    $stmt->execute([$barangayId, $userId, $barangayId]);
    $announcements = $stmt->fetchAll();
    // Opening the Announcements page counts as reviewing them, so the red
    // "new" badge clears until the next announcement arrives.
    if ($userId) {
        markAnnouncementsSeen($pdo, $userId, $barangayId);
    }
    // Staff see categories: Distribution, Emergency, General; finished per category at bottom
    $distroNotices = [];
    $generalNotices = [];
    $emergencyNotices = [];
    foreach ($announcements as $a) {
        $st = $a['source_type'] ?? '';
        $prio = $a['priority'] ?? 'normal';
        if ($st === 'emergency_response' || $prio === 'high') {
            $emergencyNotices[] = $a;
        } elseif ($st !== '') {
            $distroNotices[] = $a;
        } else {
            $generalNotices[] = $a;
        }
    }
    $cmp = function($x,$y){
        $rx = !empty($x['relayed']) ? 1 : 0;
        $ry = !empty($y['relayed']) ? 1 : 0;
        if ($rx !== $ry) return $rx <=> $ry;
        return strtotime($y['created_at']) <=> strtotime($x['created_at']);
    };
    usort($distroNotices, $cmp);
    usort($generalNotices, $cmp);
    usort($emergencyNotices, $cmp);
}

// Type label + badge used on each staff announcement item.
$annTypeTag = function (array $a): array {
    $map = [
        'distribution_schedule' => ['Distribution Schedule', 'badge-gold'],
        'schedule_final_list'   => ['Final List',            'badge-navy'],
        'emergency_response'    => ['Emergency Response',     'badge-red'],
    ];
    $st = $a['source_type'] ?? '';
    return $map[$st] ?? ['General', 'badge-secondary'];
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements - Ayuda Monitoring System</title>
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

        <div class="section-title" style="margin-bottom:10px;">Announcements</div>

        <?php if ($isAdmin): ?>
        <div class="card" style="margin-bottom:14px;">
            <div class="li-name" style="margin-bottom:8px;">Publish a General Announcement (for all)</div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" class="form-control" required placeholder="e.g. Citywide Schedule Reminder">
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="message" class="form-control" rows="4" required></textarea>
                </div>
                <div class="d-flex gap-2" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="min-width:150px;margin-bottom:0;">
                        <label>Priority</label>
                        <select name="priority" class="form-control">
                            <option value="normal">Normal</option>
                            <option value="high">High / Emergency</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0;">
                        <label>Scope</label>
                        <select name="barangay_id" class="form-control">
                            <option value="0" selected>Citywide (All Barangays) &mdash; recommended</option>
                            <?php foreach ($districts as $d): ?>
                                <optgroup label="<?= e($d['name']) ?>">
                                <?php foreach (getBarangays($pdo, $d['id']) as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= e($b['name']) ?></option>
                                <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="create_announcement" class="btn btn-primary">Publish</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
        <div class="section-title" style="margin-top:18px;">Recent Announcements</div>
        <div class="card">
            <?php if (!$announcements): ?>
                <div class="empty-state">No announcements yet.</div>
            <?php endif; ?>
            <?php foreach ($announcements as $a): ?>
                <div class="list-item" style="align-items:flex-start;">
                    <div style="flex:1;">
                        <div class="li-name">
                            <?= $a['priority']==='high' ? svgIcon('alert', 14) . ' ' : svgIcon('bell', 14) . ' ' ?><?= e($a['title']) ?>
                            <?php if ((int)$a['is_new']): ?><span class="badge badge-red" style="margin-left:6px;">NEW</span><?php endif; ?>
                        </div>
                        <div class="li-sub"><?= announcementBodyHtmlFor($pdo, $a) ?></div>
                        <div class="small text-muted mt-2">
                            <?= $a['barangay_name'] ? e($a['barangay_name']) : 'Citywide' ?>
                            &bull; <?= $a['created_by_name'] ? e($a['created_by_name']) : 'Unknown' ?>
                            &bull; <?= timeAgo($a['created_at']) ?>
                        </div>
                    </div>
                    <form method="POST" onsubmit="return confirm('Delete this announcement?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="delete_id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><?= svgIcon('trash', 15) ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 10px;">
            <button type="button" class="btn btn-sm btn-gold" onclick="showAnnCat('dist')"><?= svgIcon('gift', 14) ?> Distribution</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="showAnnCat('emerg')"><?= svgIcon('alert', 14) ?> Emergency</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="showAnnCat('gen')"><?= svgIcon('bell', 14) ?> General</button>
        </div>

        <div id="annCat-dist">
            <div class="section-title" style="margin-top:10px;">Distribution &amp; Ayuda Notices</div>
            <div class="card">
                <?php if (!$distroNotices): ?>
                    <div class="empty-state">No distribution notices yet.</div>
                <?php endif; ?>
                <?php foreach ($distroNotices as $a): $tag = $annTypeTag($a); $isDone = !empty($a['relayed']); ?>
                    <div class="list-item" style="align-items:flex-start;<?= $isDone ? 'opacity:.7;' : '' ?>">
                        <div style="flex:1;">
                            <div class="li-name">
                                <?= $a['priority']==='high' ? svgIcon('alert', 14) . ' ' : svgIcon('calendar', 14) . ' ' ?><?= e($a['title']) ?>
                                <span class="badge <?= $tag[1] ?>" style="margin-left:6px;"><?= e($tag[0]) ?></span>
                                <?php if ((int)$a['is_new']): ?><span class="badge badge-red" style="margin-left:6px;">NEW</span><?php endif; ?>
                                <?php if ($isDone): ?><span class="badge badge-success" style="margin-left:6px;">Finished</span><?php endif; ?>
                            </div>
                            <div class="li-sub"><?= announcementBodyHtmlFor($pdo, $a) ?></div>
                            <div class="small text-muted mt-2">
                                <?= $a['barangay_name'] ? e($a['barangay_name']) : 'Citywide' ?>
                                &bull; <?= $a['created_by_name'] ? e($a['created_by_name']) : 'Unknown' ?>
                                &bull; <?= timeAgo($a['created_at']) ?>
                                <?php if (!$isAdmin && $a['relayed']): ?>
                                    &bull; <span style="color:var(--green);font-weight:700;"><?= svgIcon('check', 13) ?> Posted to Beneficiaries</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$a['relayed']): ?>
                            <button type="button" class="btn btn-primary btn-sm"
                                    data-ann-id="<?= (int)$a['id'] ?>" onclick="openPostModal(this);"><?= svgIcon('megaphone', 15) ?> Post to Beneficiaries</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="annCat-emerg" style="display:none;">
            <div class="section-title" style="margin-top:10px;">Emergency Announcements</div>
            <div class="card">
                <?php if (!$emergencyNotices): ?>
                    <div class="empty-state">No emergency announcements yet.</div>
                <?php endif; ?>
                <?php foreach ($emergencyNotices as $a): $isDone = !empty($a['relayed']); ?>
                    <div class="list-item" style="align-items:flex-start;<?= $isDone ? 'opacity:.7;' : '' ?>">
                        <div style="flex:1;">
                            <div class="li-name">
                                <?= svgIcon('alert', 14) ?> <?= e($a['title']) ?>
                                <span class="badge badge-red" style="margin-left:6px;">Emergency</span>
                                <?php if ((int)$a['is_new']): ?><span class="badge badge-red" style="margin-left:6px;">NEW</span><?php endif; ?>
                                <?php if ($isDone): ?><span class="badge badge-success" style="margin-left:6px;">Finished</span><?php endif; ?>
                            </div>
                            <div class="li-sub"><?= announcementBodyHtmlFor($pdo, $a) ?></div>
                            <div class="small text-muted mt-2">
                                <?= $a['barangay_name'] ? e($a['barangay_name']) : 'Citywide' ?>
                                &bull; <?= $a['created_by_name'] ? e($a['created_by_name']) : 'Unknown' ?>
                                &bull; <?= timeAgo($a['created_at']) ?>
                                <?php if (!$isAdmin && $a['relayed']): ?>
                                    &bull; <span style="color:var(--green);font-weight:700;"><?= svgIcon('check', 13) ?> Posted to Beneficiaries</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$a['relayed']): ?>
                            <button type="button" class="btn btn-primary btn-sm"
                                    data-ann-id="<?= (int)$a['id'] ?>" onclick="openPostModal(this);"><?= svgIcon('megaphone', 15) ?> Post to Beneficiaries</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="annCat-gen" style="display:none;">
            <div class="section-title" style="margin-top:10px;">General Announcements</div>
            <div class="card">
                <?php if (!$generalNotices): ?>
                    <div class="empty-state">No general announcements yet.</div>
                <?php endif; ?>
                <?php foreach ($generalNotices as $a): $isDone = !empty($a['relayed']); ?>
                    <div class="list-item" style="align-items:flex-start;<?= $isDone ? 'opacity:.7;' : '' ?>">
                        <div style="flex:1;">
                            <div class="li-name">
                                <?= $a['priority']==='high' ? svgIcon('alert', 14) . ' ' : svgIcon('bell', 14) . ' ' ?><?= e($a['title']) ?>
                                <?php if ((int)$a['is_new']): ?><span class="badge badge-red" style="margin-left:6px;">NEW</span><?php endif; ?>
                                <?php if ($isDone): ?><span class="badge badge-success" style="margin-left:6px;">Finished</span><?php endif; ?>
                            </div>
                            <div class="li-sub"><?= announcementBodyHtmlFor($pdo, $a) ?></div>
                            <div class="small text-muted mt-2">
                                <?= $a['barangay_name'] ? e($a['barangay_name']) : 'Citywide' ?>
                                &bull; <?= $a['created_by_name'] ? e($a['created_by_name']) : 'Unknown' ?>
                                &bull; <?= timeAgo($a['created_at']) ?>
                                <?php if (!$isAdmin && $a['relayed']): ?>
                                    &bull; <span style="color:var(--green);font-weight:700;"><?= svgIcon('check', 13) ?> Posted to Beneficiaries</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$a['relayed']): ?>
                            <button type="button" class="btn btn-primary btn-sm"
                                    data-ann-id="<?= (int)$a['id'] ?>" onclick="openPostModal(this);"><?= svgIcon('megaphone', 15) ?> Post to Beneficiaries</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<script>
function showAnnCat(c){
    document.getElementById('annCat-dist').style.display = (c==='dist')?'':'none';
    document.getElementById('annCat-emerg').style.display = (c==='emerg')?'':'none';
    document.getElementById('annCat-gen').style.display = (c==='gen')?'':'none';
    var btns = document.querySelectorAll('button[onclick^="showAnnCat"]');
    btns.forEach(function(b){ b.classList.remove('btn-gold'); b.classList.add('btn-outline'); });
    if(c==='dist'){ var b=document.querySelector('button[onclick="showAnnCat(\'dist\')"]'); if(b){ b.classList.remove('btn-outline'); b.classList.add('btn-gold'); }}
    if(c==='emerg'){ var b=document.querySelector('button[onclick="showAnnCat(\'emerg\')"]'); if(b){ b.classList.remove('btn-outline'); b.classList.add('btn-gold'); }}
    if(c==='gen'){ var b=document.querySelector('button[onclick="showAnnCat(\'gen\')"]'); if(b){ b.classList.remove('btn-outline'); b.classList.add('btn-gold'); }}
}
</script>
<?php if (!$isAdmin): ?>
<!-- Post-to-Beneficiaries modal (distribution notices) -->
<div class="modal-overlay" id="postModal">
    <div class="modal-box" style="max-width:440px;">
        <!-- Step 1: compose -->
        <div id="postStepCompose">
            <h3 style="margin-top:0;color:var(--navy);text-align:center;"><?= svgIcon('megaphone', 18) ?> Post to Beneficiaries</h3>
            <form method="POST" id="postForm">
                <?= csrfField() ?>
                <input type="hidden" name="relay_announcement" id="postAnnId" value="0">
                <div class="form-group">
                    <label>What to Bring</label>
                    <details style="border:1px solid #e3e9f0;border-radius:8px;">
                        <summary style="padding:8px 10px;cursor:pointer;font-size:13px;font-weight:600;">Select IDs / Requirements</summary>
                        <div style="padding:8px 10px;display:flex;flex-direction:column;gap:6px;border-top:1px solid #f1f4f9;">
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;"><input type="checkbox" class="what-bring-chk" value="Valid ID"> Valid ID</label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;"><input type="checkbox" class="what-bring-chk" value="Barangay Clearance"> Barangay Clearance</label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;"><input type="checkbox" class="what-bring-chk" value="Photocopy of Valid ID"> Photocopy of Valid ID</label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;"><input type="checkbox" class="what-bring-chk" value="Photocopy of Barangay Clearance"> Photocopy of Barangay Clearance</label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;"><input type="checkbox" class="what-bring-chk" value="Authorization Letter"> Authorization Letter</label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;"><input type="checkbox" class="what-bring-chk" value="Face mask"> Face mask</label>
                        </div>
                    </details>
                    <textarea name="what_to_bring" id="postWhat" class="form-control" style="margin-top:8px;" rows="3" placeholder="e.g. Valid ID + Barangay Clearance + face mask; seniors bring a companion if needed."></textarea>
                </div>
                <div class="form-group">
                    <label>Additional Note / Description</label>
                    <textarea name="note" id="postNote" class="form-control" rows="3" placeholder="e.g. Please fall in line at the covered court gate by 9:00 AM; seniors are prioritized."></textarea>
                </div>
                <button type="button" onclick="showPostConfirm()" class="btn btn-gold btn-block"><?= svgIcon('megaphone', 15) ?> Post to Beneficiaries</button>
                <button type="button" class="btn btn-outline btn-block mt-2" onclick="closePost()">Cancel</button>
            </form>
        </div>
        <!-- Step 2: confirm summary -->
        <div id="postStepConfirm" style="display:none;">
            <h3 style="margin-top:0;color:var(--navy);text-align:center;"><?= svgIcon('megaphone', 18) ?> Confirm Posting</h3>
            <div class="card" style="background:#f7f9fb;border:1px solid #e3e8ee;">
                <div class="small text-muted" style="margin-bottom:6px;">ANNOUNCEMENT</div>
                <div id="postSummaryTitle" style="font-weight:700;color:var(--navy);font-size:14px;">-</div>
                <div id="postSummaryMsg" class="small" style="white-space:pre-wrap;margin-top:4px;color:#333;"></div>
                <hr style="border-color:#e6ebf1;margin:10px 0;">
                <div class="small text-muted">WHAT TO BRING</div>
                <div id="postSummaryWhat" class="small" style="margin-top:2px;color:#333;"></div>
                <div class="small text-muted" style="margin-top:8px;">ADDITIONAL NOTE</div>
                <div id="postSummaryNote" class="small" style="margin-top:2px;color:#333;"></div>
            </div>
            <button type="button" onclick="postFormSubmit()" class="btn btn-gold btn-block" style="margin-top:14px;"><?= svgIcon('check', 15) ?> Confirm &amp; Post to Beneficiaries</button>
            <button type="button" class="btn btn-outline btn-block mt-2" onclick="showPostCompose()">Back</button>
        </div>
    </div>
</div>
<script>
var announcementData = <?= json_encode(array_combine(array_column($announcements, 'id'), array_map(fn($a) => ['title' => $a['title'], 'message' => trim(strip_tags(announcementBodyHtmlFor($pdo, $a)))], $announcements)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.decodeAnnMsg = function(msg) {
    var tmp = document.createElement('div');
    tmp.innerHTML = msg || '';
    return tmp.textContent || '';
};
window.postSummaryData = function(id, title, message) {
    announcementData[id] = { title: title, message: message };
};
function openPostModal(btn) {
    var id = btn.getAttribute('data-ann-id');
    var ann = announcementData[id] || {};
    document.getElementById('postAnnId').value = id;
    var checks = document.querySelectorAll('.what-bring-chk');
    checks.forEach(function(c){ c.checked = false; });
    document.getElementById('postWhat').value = '';
    showPostCompose();
    document.getElementById('postModal').classList.add('show');
}
function getAnnouncementData(id) {
    return announcementData[id] || {};
}
function closePost() { document.getElementById('postModal').classList.remove('show'); }
document.addEventListener('change', function(e){
    if (e.target && e.target.classList && e.target.classList.contains('what-bring-chk')) {
        var checks = document.querySelectorAll('.what-bring-chk:checked');
        var parts = [];
        checks.forEach(function(c){ parts.push(c.value); });
        var ta = document.getElementById('postWhat');
        if (ta) {
            if (parts.length > 0) {
                ta.value = parts.join(' + ');
            } else {
                ta.value = '';
            }
        }
    }
});
function showPostCompose() {
    document.getElementById('postStepCompose').style.display = '';
    document.getElementById('postStepConfirm').style.display = 'none';
}
function showPostConfirm() {
    var id = document.getElementById('postAnnId').value;
    var ann = getAnnouncementData(id);
    var what = (document.getElementById('postWhat').value || '').trim();
    var note = (document.getElementById('postNote').value || '').trim();
    if (!what) { alert('Please fill in the "what to bring" note first.'); return; }
    document.getElementById('postSummaryTitle').textContent = ann.title || 'Announcement';
    document.getElementById('postSummaryMsg').textContent = window.decodeAnnMsg(ann.message) || '';
    document.getElementById('postSummaryWhat').textContent = what;
    document.getElementById('postSummaryNote').textContent = note === '' ? '-' : note;
    document.getElementById('postStepCompose').style.display = 'none';
    document.getElementById('postStepConfirm').style.display = '';
}
function postFormSubmit() {
    document.getElementById('postForm').submit();
}
window.resetPostModal = function() {
};
</script>
<?php endif; ?>
</body>
</html>