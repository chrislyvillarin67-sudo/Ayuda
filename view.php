<?php
/**
 * Public Beneficiary Viewer (NO LOGIN REQUIRED).
 *
 * Beneficiaries no longer register or log in. They simply open this page,
 * choose their District then their Barangay, and view:
 *   - Announcements (citywide + their barangay)
 *   - Distribution schedules / notices (venue, when, what to bring)
 *   - The published final list of beneficiaries for their barangay
 *
 * The page is read-only and public. District selection drives a barangay
 * picker, so beneficiaries only ever see info relevant to them.
 */
require_once __DIR__ . '/includes/functions.php';

// Public page -- do not require any login. We read GET params only.
$districts = $pdo->query("SELECT * FROM districts ORDER BY id LIMIT 3")->fetchAll();

$districtId = (int)($_GET['district_id'] ?? 0);
$barangayId = (int)($_GET['barangay_id'] ?? 0);

// Resolve the selected barangay (and its district) for display.
$selectedBarangay = null;
if ($barangayId > 0) {
    $st = $pdo->prepare("SELECT b.*, d.name AS district_name FROM barangays b JOIN districts d ON d.id=b.district_id WHERE b.id=?");
    $st->execute([$barangayId]);
    $selectedBarangay = $st->fetch() ?: null;
    if ($selectedBarangay) $districtId = (int)$selectedBarangay['district_id'];
}

// Only show barangays belonging to the chosen district.
$barangays = ($districtId > 0) ? getBarangays($pdo, $districtId) : [];

// -------- Load public info for the selected barangay --------
$announcements  = [];
$schedules      = [];
if ($selectedBarangay) {
    $annStmt = $pdo->prepare("
        SELECT a.*, br.name AS barangay_name, u.full_name AS created_by_name,
               r.id AS relayed_to_this_barangay, ds.status AS sched_status
        FROM announcements a
        LEFT JOIN barangays br ON br.id = a.barangay_id
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN announcement_relays r ON r.announcement_id = a.id AND r.barangay_id = ?
        LEFT JOIN distribution_schedules ds ON ds.id = a.source_id
            AND a.source_type IN ('distribution_schedule','schedule_final_list')
        WHERE a.barangay_id IS NULL
            OR r.id IS NOT NULL
            OR (a.barangay_id = ? AND COALESCE(a.source_type,'') <> 'distribution_schedule')
        ORDER BY a.created_at DESC, a.id DESC LIMIT 12
    ");
    $annStmt->execute([$barangayId, $barangayId]);
    $announcements = $annStmt->fetchAll();

    // Published (and upcoming/completed) distribution schedules for this barangay.
    $schStmt = $pdo->prepare("
        SELECT ds.*, u.full_name AS published_by_name
        FROM distribution_schedules ds
        LEFT JOIN users u ON u.id = ds.published_by
        WHERE ds.barangay_id = ? AND ds.status IN ('published','completed')
        ORDER BY COALESCE(ds.published_at, ds.created_at) DESC, ds.id DESC
    ");
    $schStmt->execute([$barangayId]);
    $schedules = $schStmt->fetchAll();

    // Who each distribution is for, grouped by category (Senior Citizen, PWD,
    // Student, Indigent, Solo Parent) so residents know the make-up of recipients.
    $schedBreakdowns = scheduleRecipientBreakdowns($pdo, array_map(fn($s) => (int)$s['id'], $schedules));

    // Whether a beneficiary has "already claimed" is decided per distribution
    // (see the name-check below), from the admin's actual day-of release -- not
    // from any old distribution log in the barangay.
}

// The final list is confidential. Residents verify their own name per distribution.
$q = trim($_GET['q'] ?? '');
$checkSchedId = (int)($_GET['schedule_id'] ?? 0);
$nameStatus = null; // null = no search yet; true = found; false = not found
$foundClaimed = false;
if ($q !== '' && $selectedBarangay && $checkSchedId > 0) {
    $chk = $pdo->prepare("SELECT schedule_date FROM distribution_schedules WHERE id=? AND barangay_id=? AND status IN ('published','completed')");
    $chk->execute([$checkSchedId, $barangayId]);
    $schedDate = $chk->fetchColumn();
    if ($schedDate !== false) {
        // Order-insensitive name matching: every word in the query must appear
        // somewhere in the full name, so "chrisly villarin" and "villarin
        // chrisly" both find "Villarin, Chrisly".
        $tokens = preg_split('/[\s,]+/', mb_strtolower($q));
        $likeConds = [];
        $likeParams = [];
        foreach ($tokens as $t) {
            if ($t === '') continue;
            $likeConds[] = 'LOWER(b.full_name) LIKE ?';
            $likeParams[] = '%' . $t . '%';
        }

        // A single word is not enough to verify someone -- require a full
        // first + last name before running the lookup.
        if (count($likeConds) < 2) {
            $nameStatus = 'incomplete';
        } elseif ($likeConds) {
            $likeSql = ' AND (' . implode(' AND ', $likeConds) . ')';
            $lkStmt = $pdo->prepare("
                SELECT m.beneficiary_id, b.full_name, m.is_claimed, m.release_status
                FROM distribution_schedule_members m
                JOIN beneficiaries b ON b.id = m.beneficiary_id
                WHERE m.schedule_id = ? AND m.is_receiver = 1" . $likeSql . "
                ORDER BY b.full_name
            ");
            $lkStmt->execute(array_merge([$checkSchedId], $likeParams));
            $nameHits = $lkStmt->fetchAll();
            $nameStatus = !empty($nameHits);
            if ($nameStatus) {
                // "Already claimed" ONLY when the admin actually released this
                // ayuda to this beneficiary on this exact distribution. Until
                // then (pending, or not released) they have not claimed it.
                $foundClaimed = ((int)($nameHits[0]['is_claimed'] ?? 0) === 1)
                    || (($nameHits[0]['release_status'] ?? '') === 'released');
            }
        }
    }
}

// AJAX name-check (no page reload) -- returns JSON for the search form.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check') {
    header('Content-Type: application/json');
    $found = $nameStatus === true;
    $htmlFound = '';
    if ($found) {
        $matchedName = e($nameHits[0]['full_name']);
        $htmlFound = '<div class="alert alert-success v-sched-result">' .
            svgIcon('check', 15) .
            ' <strong>' . $matchedName . ' &mdash; you are on the list!</strong> You can receive this ayuda.' .
            ($foundClaimed ? ' You have already claimed it.' : ' Please bring a valid ID and your barangay clearance when claiming.') .
            '</div>';
    } elseif ($nameStatus === 'incomplete') {
        $htmlFound = '<div class="alert alert-warning v-sched-result">' .
            svgIcon('info', 15) .
            ' Please type your <strong>complete name</strong> (first and last name) to verify that you are on the list.' .
            '</div>';
    } elseif ($nameStatus === false) {
        $htmlFound = '<div class="alert alert-danger v-sched-result">' .
            svgIcon('x', 15) .
            ' We could not find <strong>' . e($q) . '</strong> on this distribution\'s recipient list. Please check the spelling, or contact your Barangay Hall for assistance.' .
            '</div>';
    }
    echo json_encode([
        'found' => $found,
        'status' => $nameStatus,
        'html' => $htmlFound,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title><?= $selectedBarangay ? 'Ayuda Updates - ' . e($selectedBarangay['name']) : 'Ayuda Viewer' ?> - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
.v-header {
    background: linear-gradient(160deg, var(--navy) 0%, var(--navy-dark) 60%, #051624 100%);
    color: #fff; padding: 26px 18px 20px; text-align: center; position: relative;
}
.v-header .v-toplink { position: absolute; top: 12px; right: 14px; display: block; color:#fff; font-size:12.5px; text-decoration:none; padding:6px 12px; border:1px solid rgba(255,255,255,0.5); border-radius:20px; }
.v-header .v-toplink:hover { background: rgba(255,255,255,0.15); }
.v-header .v-logo { font-size: 30px; }
.v-header h1 { margin: 6px 0 2px; font-size: 20px; color: #fff; }
.v-header .v-sub { color: #b9c9da; font-size: 12.5px; }
.pick-card { background:#f7f9fc; border:1px solid #e3e9f0; border-radius:12px; padding:18px; margin:14px 0; }
.pick-card label { font-weight:700; color:var(--navy); font-size:13px; }
.venue-badge { display:inline-block; background:var(--navy-light); color:#fff; border-radius:6px; padding:2px 8px; font-size:12px; margin-right:6px; }
.v-sched-form { display:flex; gap:8px; margin-top:6px; }
.v-sched-result { margin:10px 0 0; }
.v-sched-sepline { border-top:1px dashed #dde4ec; }
</style>
</head>
<body>
<div class="v-header">
    <a href="logout.php" class="v-toplink">Exit</a>
    <div class="v-logo">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="34" height="34" aria-hidden="true">
            <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--gold)"/>
            <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--navy-dark)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <h1>Ayuda Information</h1>
    <div class="v-sub">Davao City &bull; Relief &amp; Aid Distribution</div>
</div>

<div class="content" style="padding:14px;">

    <?php if (!$selectedBarangay): ?>
        <!-- STEP 1 & 2: pick district, then barangay -->
        <div class="pick-card">
            <div class="form-group" style="margin-bottom:12px;">
                <label>Choose your District</label>
                <select id="districtSelect" class="form-control" onchange="loadBarangays()">
                    <option value="">-- Select District --</option>
                    <?php foreach ($districts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $districtId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label>Choose your Barangay</label>
                <select id="barangaySelect" class="form-control" <?= !$barangays && $districtId ? '' : '' ?>>
                    <option value="">-- Select Barangay --</option>
                    <?php foreach ($barangays as $b): ?>
                        <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="btn btn-primary btn-block" onclick="goToBarangay()"><?= svgIcon('search', 16) ?> View Updates</button>
        </div>
    <?php else: ?>
        <!-- RESULTS VIEW -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
            <div>
                <div style="font-size:11px;color:#6b7d90;text-transform:uppercase;letter-spacing:.5px;"><?= e($selectedBarangay['district_name']) ?></div>
                <div style="font-size:17px;font-weight:800;color:var(--navy);"><?= e($selectedBarangay['name']) ?></div>
            </div>
            <a href="view.php" class="btn btn-outline btn-sm">Change Barangay</a>
        </div>

        <!-- What to bring always -->
        <div class="alert alert-info mt-2" style="font-size:13px;">
            <?= svgIcon('info', 15) ?> When claiming ayuda, please bring a <strong>valid ID</strong> and your <strong>barangay clearance</strong>.
        </div>

        <!-- Announcements -->
        <div class="section-title">Announcements</div>
        <?php if (!$announcements): ?>
            <div class="card empty-state">
                <div class="es-icon"><?= svgIcon('bell', 26) ?></div>
                <div class="es-title">No announcements yet</div>
                <div class="es-sub">Check back soon for news from your barangay and the CSWDO.</div>
            </div>
        <?php else: ?>
            <div class="ann-stack">
                <?php foreach ($announcements as $a): ?>
                    <article class="ann<?= $a['priority'] === 'high' ? ' ann-urgent' : '' ?>">
                        <div class="ann-head">
                            <div class="ann-ic"><?= svgIcon($a['priority'] === 'high' ? 'alert' : 'bell', 16) ?></div>
                            <div class="ann-main">
                                <div class="ann-title"><?= e($a['title']) ?></div>
                                <div class="ann-tags">
                                    <?php foreach (announcementChips($a) as [$chipText, $chipClass]): ?>
                                        <span class="badge <?= $chipClass ?>"><?= $chipText ?></span>
                                    <?php endforeach; ?>
                                    <?php if (($a['sched_status'] ?? '') === 'completed'): ?>
                                        <span class="badge badge-success">Finished</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php $rows = announcementBodyRows($pdo, $a); ?>
                        <?php if ($rows): ?>
                            <div class="ann-body">
                                <?php $first = true; foreach ($rows as $r): ?>
                                    <?php if ($r['label'] === null): ?>
                                        <p class="ann-note"<?= $first ? ' style="margin-top:0"' : '' ?>><?= nl2br($r['text']) ?></p>
                                    <?php else: ?>
                                        <div class="ann-row">
                                            <div class="ann-row-k"><?= e($r['label']) ?></div>
                                            <div class="ann-row-v"><?= nl2br($r['text']) ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php $first = false; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="ann-foot"><?= announcementFoot($a) ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Distribution schedules / venue (each box has its own recipient lookup) -->
        <div class="section-title">Distribution Venue &amp; Schedule</div>
        <?= dataPrivacyNotice(true) ?>
        <?php if (!$schedules): ?>
            <div class="card empty-state">No distribution schedule posted yet for your barangay.</div>
        <?php else: ?>
            <?php foreach ($schedules as $s): $thisSched = (int)$s['id']; $isChecked = $checkSchedId === $thisSched; ?>
                <div class="card sched-card" style="margin-bottom:10px;<?= $isChecked ? '' : 'cursor:pointer;' ?>" data-box-sched="<?= $thisSched ?>">
                    <div class="li-name" style="font-size:14px;"><?= e($s['title']) ?><?php if (($s['status'] ?? '') === 'completed'): ?> <span class="badge badge-success" style="margin-left:6px;vertical-align:middle;">Finished</span><?php endif; ?></div>
                    <?php if ($s['venue']): ?>
                        <div class="small mt-2" style="font-size:13px;"><?= svgIcon('home', 14) ?> <strong>Venue:</strong> <?= e($s['venue']) ?></div>
                    <?php endif; ?>
                    <?php if ($s['schedule_date'] || $s['schedule_time']): ?>
                        <div class="small mt-1" style="font-size:13px;"><?= svgIcon('calendar', 14) ?> <strong>When:</strong> <?= $s['schedule_date'] ? e(date('F j, Y', strtotime($s['schedule_date']))) : '' ?><?= ($s['schedule_date'] && $s['schedule_time']) ? ' &bull; ' : '' ?><?= $s['schedule_time'] ? e(formatTime12($s['schedule_time'])) : '' ?></div>
                    <?php endif; ?>
                    <?php if ($s['what_to_bring']): ?>
                        <div class="small mt-1" style="font-size:13px;"><?= svgIcon('file', 14) ?> <strong>What to bring:</strong> <?= e($s['what_to_bring']) ?></div>
                    <?php endif; ?>
                    <?php $bdown = $schedBreakdowns[$thisSched] ?? []; if ($bdown): ?>
                        <div class="small mt-1" style="font-size:13px;"><?= svgIcon('users', 14) ?> <strong>Who receives:</strong> <?= e(categoryBreakdownText($bdown)) ?></div>
                    <?php endif; ?>
                    <?php if ($s['note']): ?>
                        <div class="small text-muted mt-1" style="font-size:12.5px;white-space:pre-wrap;"><?= nl2br(e($s['note'])) ?></div>
                    <?php endif; ?>
                    <?php if ($s['published_by_name']): ?>
                        <div class="small text-muted mt-2">Posted by <?= e($s['published_by_name']) ?> &bull; <?= timeAgo($s['published_at']) ?></div>
                    <?php endif; ?>

                    <div class="v-sched-search" data-sched-search="<?= $thisSched ?>" <?= $isChecked ? '' : 'style="display:none;"' ?>>
                        <div class="v-sched-sepline" style="margin-top:12px;"></div>
                        <form method="GET" action="view.php" class="v-sched-form">
                            <input type="hidden" name="barangay_id" value="<?= (int)$barangayId ?>">
                            <input type="hidden" name="schedule_id" value="<?= $thisSched ?>">
                            <input type="text" name="q" value="<?= $isChecked ? e($q) : '' ?>" class="form-control"
                                   placeholder="Type your full name (e.g. Chrisly Villarin or Villarin, Chrisly)" autocomplete="off" maxlength="120">
                            <button type="submit" class="btn btn-primary" style="flex-shrink:0;"><?= svgIcon('search', 15) ?> Search</button>
                        </form>

                        <?php if ($isChecked && $nameStatus === true): ?>
                            <div class="alert alert-success v-sched-result">
                                <?= svgIcon('check', 15) ?> <strong><?= e($nameHits[0]['full_name']) ?> &mdash; you are on the list!</strong> You can receive this ayuda.<?php if ($foundClaimed): ?> You have already claimed it.<?php else: ?> Please bring a valid ID and your barangay clearance when claiming.<?php endif; ?>
                            </div>
                        <?php elseif ($isChecked && $nameStatus === 'incomplete'): ?>
                            <div class="alert alert-warning v-sched-result">
                                <?= svgIcon('info', 15) ?> Please type your <strong>complete name</strong> (first and last name) to verify that you are on the list.
                            </div>
                        <?php elseif ($isChecked && $nameStatus === false): ?>
                            <div class="alert alert-danger v-sched-result">
                                <?= svgIcon('x', 15) ?> We could not find <strong><?= e($q) ?></strong> on this distribution's recipient list. Please check the spelling, or contact your Barangay Hall for assistance.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

<a href="view.php" class="btn btn-outline btn-block mt-3">Change Barangay</a>
    <?php endif; ?>

    <p class="small text-muted" style="text-align:center;margin:18px 0 6px;">
        For help, contact your Barangay Hall. <a href="login.php" style="color:var(--navy-light);">Sign in as Staff, Admin or Beneficiary</a> &copy; Ayuda Monitoring System
    </p>
</div>

<script>
const barangaysByDistrict = <?php
    $all = [];
    foreach ($districts as $d) { $all[$d['id']] = getBarangays($pdo, $d['id']); }
    echo json_encode($all);
?>;

function loadBarangays() {
    const dist = document.getElementById('districtSelect');
    const brgy = document.getElementById('barangaySelect');
    brgy.innerHTML = '<option value="">-- Select Barangay --</option>';
    (barangaysByDistrict[dist.value] || []).forEach(function(b) {
        const opt = document.createElement('option');
        opt.value = b.id; opt.textContent = b.name;
        brgy.appendChild(opt);
    });
}
function goToBarangay() {
    const brgy = document.getElementById('barangaySelect');
    if (brgy && brgy.value) {
        window.location.href = 'view.php?barangay_id=' + brgy.value;
    } else {
        alert('Please choose your barangay first.');
    }
}

document.addEventListener('click', function (e) {
    const box = e.target.closest('[data-box-sched]');
    if (!box || e.target.closest('.v-sched-form') || e.target.closest('.v-sched-result')) return;
    const id = box.getAttribute('data-box-sched');
    const search = box.querySelector('[data-sched-search="' + id + '"]');
    if (!search) return;
    const closed = search.style.display === 'none';
    search.style.display = closed ? 'block' : 'none';
    if (closed) {
        const input = search.querySelector('input[name=q]');
        if (input) { try { input.focus(); } catch (err) {} }
    }
});

// Typing in the search box -> hide any previous verification result.
// The result only appears after pressing Enter or clicking Search.
document.querySelectorAll('[data-sched-search] input[name=q]').forEach(function (q) {
    q.addEventListener('input', function () {
        const box = q.closest('[data-box-sched]');
        if (!box) return;
        const boxId = box.getAttribute('data-box-sched');
        const search = box.querySelector('[data-sched-search="' + boxId + '"]');
        if (!search) return;
        const result = search.querySelector('.v-sched-result');
        if (result) result.style.display = 'none';
    });
});

// Search without reloading the page (never jumps to the top / loses scroll).
document.querySelectorAll('[data-sched-search] .v-sched-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const search = form.closest('[data-sched-search]');
        const box = form.closest('[data-box-sched]');
        const q = form.querySelector('input[name=q]');
        if (!search || !q || q.value.trim() === '') return;
        const oldResult = search.querySelector('.v-sched-result');
        if (oldResult) oldResult.remove();
        const url = 'view.php?ajax=check&barangay_id=' +
            encodeURIComponent(form.querySelector('input[name=barangay_id]').value) +
            '&schedule_id=' + encodeURIComponent(form.querySelector('input[name=schedule_id]').value) +
            '&q=' + encodeURIComponent(q.value.trim());
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.html) {
                    var div = document.createElement('div');
                    div.innerHTML = d.html;
                    search.appendChild(div.firstChild);
                }
                if (box) box.querySelector('.li-name').scrollIntoView({ block: 'nearest' });
            })
            .catch(function () {});
    });
});
</script>
</body>
</html>
