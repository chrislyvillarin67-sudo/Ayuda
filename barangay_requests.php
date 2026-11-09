<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

// This page is the emergency/incident reporting channel between Barangay Staff
// and the CSWDO / District Admin. Staff file a report (fire, landslide, flood,
// etc.); the District Admin immediately sees it and responds.
$isAdmin = $_SESSION['role'] === 'district_admin';
$pageTitle = $isAdmin ? 'Emergency Reports' : 'Report an Incident';
$activePage = 'barangay';
$barangayId = $_SESSION['barangay_id'] ?? null;
$errors = [];
$csrfOk = validateCSRF();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfOk) {
    $errors[] = 'Invalid security token. Please refresh the page and try again.';
}

$incidentTypes = ['Fire', 'Flood', 'Landslide', 'Earthquake', 'Severe Storm', 'Structural Collapse', 'Medical Emergency', 'Power / Water Outage', 'Other'];
$helpOptions = ['Evacuation / Shelter', 'Rescue', 'Food & Water', 'Medical / First Aid', 'Reinforcement / Personnel', 'Other'];

// ----- Handle new emergency report (barangay staff) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report']) && !$isAdmin && $csrfOk) {
    $incidentType  = $_POST['incident_type'] ?? '';
    $severity      = $_POST['severity'] ?? 'medium';
    $locationDetail = trim($_POST['location_detail'] ?? '');
    $affectedPeople = (int)($_POST['affected_people'] ?? 0);
    $helpNeeded    = trim($_POST['help_needed'] ?? '');
    $reporterContact = trim($_POST['reporter_contact'] ?? '');
    $desc          = trim($_POST['description'] ?? '');

    if (!in_array($incidentType, $incidentTypes, true)) $errors[] = 'Please select the type of incident.';
    if (!in_array($severity, ['high', 'medium', 'low'], true)) $severity = 'medium';
    if ($reporterContact !== '') {
        $reporterContact = preg_replace('/[\s\-\+().]/', '', $reporterContact);
        if (!ctype_digit($reporterContact) || strlen($reporterContact) > 11) {
            $errors[] = 'Contact number must be numbers only, with a maximum of 11 digits.';
        }
    }
    if ($desc === '') $errors[] = 'Please describe the situation.';

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO barangay_requests
            (barangay_id, requested_by, request_type, severity, location_detail, affected_people, help_needed, reporter_contact, description, status)
            VALUES (?,?,?,?,?,?,?,?,?,'pending')");
        $stmt->execute([
            $barangayId, $_SESSION['user_id'], $incidentType, $severity,
            $locationDetail ?: null, $affectedPeople ?: null, $helpNeeded ?: null,
            $reporterContact ?: null, $desc
        ]);
        logAudit($pdo, 'emergency_report_submit', ($_SESSION['full_name'] ?? 'Staff') . " reported a $severity $incidentType incident");
        setFlash('success', 'Emergency report sent to the CSWDO / District Admin. Support will be dispatched as soon as possible.');
        redirect('barangay_requests.php');
    }
}

// ----- Handle respond/dismiss (district admin) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_id']) && $isAdmin && $csrfOk) {
    $reqId = (int)$_POST['resolve_id'];
    $decision = $_POST['decision'] === 'approved' ? 'approved' : 'declined';

    // When approving, the CSWDO should say when they will respond (date/time)
    // and/or describe the support being sent -- this reaches the barangay staff
    // and the announced residents.
    $respondDate = trim($_POST['respond_date'] ?? '');
    $respondTime = normalizeTimeInput($_POST['respond_time'] ?? '');
    $respondDesc = trim($_POST['respond_description'] ?? '');
    if ($respondDate !== '' && !strtotime($respondDate)) $respondDate = '';
    if ($decision === 'approved' && $respondDate === '' && $respondDesc === '') {
        $errors[] = 'Please provide when you will respond (date/time) and/or a short description of the response to dispatch.';
    }

    $stmt = $pdo->prepare("SELECT * FROM barangay_requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $req = $stmt->fetch();

    if ($req && !$errors) {
        $upd = $pdo->prepare("UPDATE barangay_requests SET status=?, resolved_at=NOW(), respond_date=?, respond_time=?, respond_description=? WHERE id=?");
        $upd->execute([$decision, $respondDate ?: null, $respondTime ?: null, $respondDesc ?: null, $reqId]);
        logAudit($pdo, 'emergency_report_' . $decision, ucfirst($decision) . ' emergency report #' . $reqId .
            " ($req[request_type] in barangay #$req[barangay_id])" . ($respondDate ? " - response scheduled $respondDate" . ($respondTime ? " at $respondTime" : '') : ''));

        // Connection: a dispatched response is announced to the residents of the
        // affected barangay so they see the CSWDO's update on the public viewer.
        if ($decision === 'approved') {
            $brg = getBarangayById($pdo, (int)$req['barangay_id']);
            $emsg = 'Incident: ' . $req['request_type'];
            if ($req['severity'])   $emsg .= "\nSeverity: " . strtoupper($req['severity']);
            if ($req['location_detail']) $emsg .= "\nLocation: " . $req['location_detail'];
            if ($req['affected_people']) $emsg .= "\nPeople affected: " . $req['affected_people'];
            if ($respondDate)   $emsg .= "\nScheduled response: " . date('F j, Y', strtotime($respondDate)) . ($respondTime ? ' at ' . $respondTime : '');
            if ($respondDesc)   $emsg .= "\nResponse / support being dispatched: " . $respondDesc;
            $emsg .= "\n\nCSWDO / District Admin has responded. Support is being dispatched to " . ($brg['name'] ?? ('barangay #' . $req['barangay_id'])) . ". Please stay safe and follow instructions from your Barangay Staff.";
            ensureAutoAnnouncement($pdo, 'emergency_response', (int)$req['id'], (int)$req['barangay_id'],
                'Emergency Update: ' . $req['request_type'] . ($brg ? ' - ' . $brg['name'] : ''), $emsg, 'high', $_SESSION['user_id']);
        }

    // If a specific aid item was asked for, allocate stock to that barangay.
    // Food Packs are a shared citywide pool, so they are topped up there and
    // never split into a per-barangay bucket.
    if ($decision === 'approved' && $req['item_needed'] && $req['quantity_needed']) {
            $inv = $pdo->prepare("SELECT id FROM inventory
                                  WHERE (barangay_id=? OR (assistance_type='food_pack' AND barangay_id IS NULL))
                                    AND item_name=?
                                  ORDER BY (barangay_id IS NULL) DESC, id LIMIT 1");
            $inv->execute([$req['barangay_id'], $req['item_needed']]);
            $invRow = $inv->fetch();
            if ($invRow) {
                $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?")->execute([$req['quantity_needed'], $invRow['id']]);
            } else {
                $pdo->prepare("INSERT INTO inventory (barangay_id, item_name, quantity) VALUES (?,?,?)")->execute([$req['barangay_id'], $req['item_needed'], $req['quantity_needed']]);
            }
        }
        setFlash('success', $decision === 'approved'
            ? 'Response scheduled and dispatched to the barangay' . ($respondDate ? ' for ' . date('F j, Y', strtotime($respondDate)) . ($respondTime ? ' at ' . $respondTime : '') : '') . '. Residents were notified.'
            : 'Report marked as addressed/dismissed.');
    }
    if (!$errors) redirect('barangay_requests.php');
}

// ----- Listing -----
if ($isAdmin) {
    // Pending first, then most urgent, then newest.
    $rows = $pdo->query("
        SELECT r.*, b.name AS barangay_name, d.name AS district_name, u.full_name AS requested_by_name
        FROM barangay_requests r
        JOIN barangays b ON b.id = r.barangay_id
        JOIN districts d ON d.id = b.district_id
        JOIN users u ON u.id = r.requested_by
        ORDER BY (r.status='pending') DESC, FIELD(r.severity,'high','medium','low') ASC, r.created_at DESC
    ")->fetchAll();
    $urgentCount = 0;
    foreach ($rows as $r) { if ($r['status'] === 'pending' && $r['severity'] === 'high') $urgentCount++; }
} else {
    $stmt = $pdo->prepare("SELECT r.* FROM barangay_requests r WHERE r.barangay_id = ? ORDER BY r.created_at DESC");
    $stmt->execute([$barangayId]);
    $rows = $stmt->fetchAll();
}

function sevBadge($s) {
    if ($s === 'high')  return '<span class="badge" style="background:#a5160a;color:#fff;">' . svgIcon('alert', 12) . ' URGENT</span>';
    if ($s === 'low')   return '<span class="badge badge-green">Low</span>';
    return '<span class="badge" style="background:#e0a100;color:#fff;">' . svgIcon('alert', 12) . ' Medium</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isAdmin ? 'Emergency Reports' : 'Report an Incident' ?> - Ayuda Monitoring System</title>
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

            <?php if ($isAdmin && $urgentCount): ?>
                <div class="alert alert-danger" style="font-size:14px;">
                    <?= svgIcon('alert', 16) ?> <strong><?= $urgentCount ?> urgent emergency report(s)</strong> pending action.
                </div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
            <div class="card">
                <h3 style="margin-top:0;color:var(--navy);font-size:15px;"><?= svgIcon('alert', 16) ?> Report an Emergency / Incident</h3>
                <p class="small text-muted">
                    Report any incident in your barangay &mdash; fire, landslide, flood, earthquake and more.
                    This goes straight to the CSWDO / District Admin, who will respond immediately.
                    Mark <strong>URGENT</strong> if people are at immediate risk.
                </p>
                <form method="POST" id="emergencyForm">
                    <?= csrfField() ?>
                    <div id="emergencyStepCompose">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:0 18px;">
                        <div class="form-group">
                            <label>Type of Incident</label>
                            <select name="incident_type" id="emg_incident_type" class="form-control" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($incidentTypes as $t): ?>
                                    <option value="<?= e($t) ?>"><?= e($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Severity / Urgency</label>
                            <select name="severity" id="emg_severity" class="form-control" required>
                                <option value="high">High / Urgent &mdash; immediate risk to life or property</option>
                                <option value="medium" selected>Medium &mdash; needs support soon</option>
                                <option value="low">Low &mdash; can wait / minor issue</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Exact Location / Affected Area</label>
                            <input type="text" name="location_detail" id="emg_location" class="form-control" placeholder="e.g. Purok 3, Sitio Riverside, near the barangay hall">
                        </div>
                        <div class="form-group">
                            <label>People Affected (approx.)</label>
                            <input type="number" name="affected_people" id="emg_affected" class="form-control" min="0" placeholder="e.g. 25">
                        </div>
                        <div class="form-group">
                            <label>Help Needed</label>
                            <select name="help_needed" id="emg_help" class="form-control">
                                <option value="">-- Select (optional) --</option>
                                <?php foreach ($helpOptions as $h): ?>
                                    <option value="<?= e($h) ?>"><?= e($h) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="reporter_contact" id="emg_contact" class="form-control" data-numeric="1" placeholder="e.g. 09171234567">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>What happened?</label>
                        <textarea name="description" id="emg_desc" class="form-control" rows="3" required placeholder="Describe the situation as clearly as possible..."></textarea>
                    </div>
                    <button type="button" onclick="reviewEmergencyReport()" class="btn btn-danger btn-block"><?= svgIcon('alert', 15) ?> Review &amp; Submit Emergency Report</button>
                    </div>
                    <div id="emergencyStepConfirm" style="display:none;">
                        <div class="card" style="background:#f7f9fb;border:1px solid #e3e8ee;">
                            <div class="small text-muted" style="margin-bottom:6px;">EMERGENCY REPORT SUMMARY</div>
                            <div style="display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:13.5px;">
                                <div style="color:var(--text-muted);">Incident:</div><div id="emgSumType" style="font-weight:700;color:var(--navy);"></div>
                                <div style="color:var(--text-muted);">Severity:</div><div id="emgSumSev"></div>
                                <div style="color:var(--text-muted);">Location:</div><div id="emgSumLoc"></div>
                                <div style="color:var(--text-muted);">Affected:</div><div id="emgSumAff"></div>
                                <div style="color:var(--text-muted);">Help needed:</div><div id="emgSumHelp"></div>
                                <div style="color:var(--text-muted);">Contact:</div><div id="emgSumContact"></div>
                                <div style="color:var(--text-muted);">Description:</div><div id="emgSumDesc" style="white-space:pre-wrap;"></div>
                            </div>
                        </div>
                        <button type="submit" name="submit_report" class="btn btn-danger btn-block" style="margin-top:14px;"><?= svgIcon('check', 15) ?> Confirm &amp; Submit Emergency Report</button>
                        <button type="button" class="btn btn-outline btn-block mt-2" onclick="showEmergencyCompose()">Back</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="section-title"><?= $isAdmin ? 'Reports from Barangays' : 'My Reports' ?></div>
            <?php if (!$rows): ?>
                <div class="card empty-state"><?= $isAdmin ? 'No emergency reports submitted yet.' : 'No reports from your barangay yet.' ?></div>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <div class="card" <?= $r['severity']==='high' && $r['status']==='pending' ? 'style="border-left:5px solid #a5160a;"' : '' ?>>
                    <div class="d-flex justify-between align-center">
                        <div>
                            <span class="badge badge-navy"><?= e($r['request_type']) ?></span>
                            <?= sevBadge($r['severity']) ?>
                            <span class="badge <?= statusBadgeClass($r['status']) ?>"><?= $r['status']==='approved' ? 'Responded' : ($r['status']==='declined' ? 'Dismissed' : 'Pending') ?></span>
                        </div>
                        <span class="small text-muted"><?= timeAgo($r['created_at']) ?></span>
                    </div>
                    <?php if ($isAdmin): ?>
                        <div style="font-weight:700;margin-top:8px;"><?= e($r['barangay_name']) ?> &bull; <?= e($r['district_name']) ?></div>
                        <div class="small text-muted">Reported by <?= e($r['requested_by_name']) ?><?= $r['reporter_contact'] ? ' &bull; ' . e($r['reporter_contact']) : '' ?></div>
                    <?php endif; ?>
                    <?php if ($r['location_detail']): ?>
                        <div class="small" style="margin-top:8px;"><?= svgIcon('pin', 14) ?> <strong>Location:</strong> <?= e($r['location_detail']) ?></div>
                    <?php endif; ?>
                    <?php if ($r['affected_people']): ?>
                        <div class="small mt-1"><?= svgIcon('user', 14) ?> <strong>Affected:</strong> approx. <?= (int)$r['affected_people'] ?> people</div>
                    <?php endif; ?>
                    <p style="margin:8px 0 6px;font-size:13.5px;"><?= nl2br(e($r['description'])) ?></p>
                    <?php if ($r['help_needed']): ?>
                        <div class="small text-muted">Help needed: <?= e($r['help_needed']) ?></div>
                    <?php endif; ?>

                    <?php if ($r['status'] === 'approved' && ($r['respond_date'] || $r['respond_description'])): ?>
                        <div style="margin-top:8px;padding:9px 12px;background:#e9f7ee;border:1px solid #b7e4c4;border-radius:8px;font-size:12.5px;">
                            <div style="font-weight:700;color:var(--green);margin-bottom:3px;">Response / Dispatch Plan</div>
                            <?php if ($r['respond_date']): ?>
                                <div><?= svgIcon('calendar', 14) ?> <strong>When:</strong> <?= date('F j, Y', strtotime($r['respond_date'])) ?><?= $r['respond_time'] ? ' at ' . e(formatTime12($r['respond_time'])) : '' ?></div>
                            <?php endif; ?>
                            <?php if ($r['respond_description']): ?>
                                <div style="margin-top:3px;"><?= svgIcon('box', 14) ?> <strong>Support:</strong> <?= nl2br(e($r['respond_description'])) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isAdmin && $r['status'] === 'pending'): ?>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-success btn-block btn-sm" data-brgy="<?= e($r['barangay_name']) ?>" onclick="openRespond(<?= (int)$r['id'] ?>, this)"><?= svgIcon('box', 15) ?> Respond / Dispatch Support</button>
                            <form method="POST" style="flex:1;">
                                <?= csrfField() ?>
                                <input type="hidden" name="resolve_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="decision" value="declined">
                                <button class="btn btn-outline btn-block btn-sm">Mark Dismissed</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<!-- Respond / Dispatch modal (admin only) -->
<div class="modal-overlay" id="respondModal">
    <div class="modal-box" style="max-width:440px;">
        <h3 style="margin-top:0;color:var(--navy);text-align:center;"><?= svgIcon('box', 18) ?> Respond / Dispatch Support</h3>
        <form method="POST" id="respondForm">
            <?= csrfField() ?>
            <input type="hidden" name="resolve_id" id="respId" value="0">
            <input type="hidden" name="decision" value="approved">
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <div class="form-group" style="flex:1;min-width:150px;">
                    <label>Response Date</label>
                    <input type="date" name="respond_date" class="form-control">
                </div>
                <div class="form-group" style="flex:1;min-width:120px;">
                    <label>Response Time</label>
                    <input type="time" step="60" name="respond_time" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label>Description of Response <span class="small text-muted">(what support will be sent)</span></label>
                <textarea name="respond_description" class="form-control" rows="3" placeholder="e.g. 2 rescue teams + food packs + a first aid unit will be deployed..."></textarea>
            </div>
            <button type="submit" class="btn btn-success btn-block"><?= svgIcon('box', 15) ?> Confirm &amp; Dispatch</button>
            <button type="button" class="btn btn-outline btn-block mt-2" onclick="closeRespond()">Cancel</button>
        </form>
    </div>
</div>

<!-- Dispatch Yes / No confirmation (admin) -->
<div class="modal-overlay" id="dispatchConfirmModal">
    <div class="modal-box" style="max-width:420px;">
        <h3 style="margin-top:0;color:var(--navy);text-align:center;"><?= svgIcon('box', 18) ?> Confirm Dispatch</h3>
        <div style="background:#f7f9fc;border:1px solid #e3e9f0;border-radius:10px;padding:14px;">
            <div class="small text-muted">Barangay</div>
            <div style="font-weight:650;font-size:14px;color:var(--text);" id="dcBrgy">&mdash;</div>
            <div class="small text-muted mt-2">Response date / time</div>
            <div style="font-weight:650;font-size:14px;color:var(--text);" id="dcWhen">&mdash;</div>
            <div class="small text-muted mt-2">Support to be dispatched</div>
            <div style="font-weight:650;font-size:14px;color:var(--text);white-space:pre-wrap;" id="dcDesc">&mdash;</div>
        </div>
        <div class="small text-muted mt-3" style="text-align:center;">Are you sure you want to dispatch this response? The barangay staff and residents will be notified.</div>
        <button type="button" class="btn btn-success btn-block mt-3" id="dcYes"><?= svgIcon('check', 15) ?> Yes &mdash; Dispatch</button>
        <button type="button" class="btn btn-outline btn-block mt-2" id="dcNo">No &mdash; Go back</button>
    </div>
</div>
<script>
var respondForm    = document.getElementById('respondForm');
var respondModalEl = document.getElementById('respondModal');
var dispatchModal  = document.getElementById('dispatchConfirmModal');
var dcBarangay = '';
var dispatchConfirmed = false;

function openRespond(id, btn) {
    dcBarangay = btn ? (btn.getAttribute('data-brgy') || '') : '';
    dispatchConfirmed = false;
    document.getElementById('respId').value = id;
    respondModalEl.classList.add('show');
}
function closeRespond() {
    respondModalEl.classList.remove('show');
}
function closeDispatchConfirm() {
    dispatchModal.classList.remove('show');
}
function fmtResponseDate(v) {
    var p = (v || '').split('-');
    if (p.length !== 3) return v;
    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var m = parseInt(p[1], 10) - 1;
    if (isNaN(m) || !months[m]) return v;
    return months[m] + ' ' + parseInt(p[2], 10) + ', ' + p[0];
}
function fmtResponseTime(v) {
    var p = (v || '').split(':');
    if (p.length < 2) return v;
    var h = parseInt(p[0], 10);
    if (isNaN(h)) return v;
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return h + ':' + p[1] + ' ' + ampm;
}

// Admin dispatch: submitting the respond form opens a Yes / No confirmation
// first; the POST only happens after "Yes - Dispatch" is clicked.
if (respondForm) {
    respondForm.addEventListener('submit', function (e) {
        if (dispatchConfirmed) return;
        e.preventDefault();
        var val = function (n) { var el = respondForm.elements[n]; return el ? String(el.value).trim() : ''; };
        var date = val('respond_date');
        var time = val('respond_time');
        var desc = val('respond_description');
        if (date === '' && desc === '') {
            alert('Please provide when you will respond (date/time) and/or a short description of the response to dispatch.');
            return;
        }
        document.getElementById('dcBrgy').textContent  = dcBarangay || '\u2014';
        document.getElementById('dcWhen').textContent  = date !== ''
            ? fmtResponseDate(date) + (time ? ' at ' + fmtResponseTime(time) : '')
            : 'ASAP - no date set';
        document.getElementById('dcDesc').textContent  = desc !== '' ? desc : '\u2014';
        dispatchModal.classList.add('show');
    });

    document.getElementById('dcNo').addEventListener('click', closeDispatchConfirm);
    document.getElementById('dcYes').addEventListener('click', function () {
        dispatchConfirmed = true;
        closeDispatchConfirm();
        respondForm.submit();
    });
    dispatchModal.addEventListener('click', function (e) {
        if (e.target === dispatchModal) closeDispatchConfirm();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dispatchModal.classList.contains('show')) closeDispatchConfirm();
    });
}

// ----- Staff: emergency report review-then-confirm -----
function reviewEmergencyReport() {
    var f = function (id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
    var type = f('emg_incident_type');
    var sev  = f('emg_severity');
    var loc  = f('emg_location');
    var aff  = f('emg_affected');
    var help = f('emg_help');
    var contact = f('emg_contact');
    var desc = f('emg_desc');
    if (!type) { alert('Please select the type of incident.'); return; }
    if (!desc) { alert('Please describe the situation first.'); return; }

    document.getElementById('emgSumType').textContent = type;
    document.getElementById('emgSumSev').textContent = sev.charAt(0).toUpperCase() + sev.slice(1);
    document.getElementById('emgSumLoc').textContent = loc === '' ? '-' : loc;
    document.getElementById('emgSumAff').textContent = aff === '' ? '-' : aff + ' people';
    document.getElementById('emgSumHelp').textContent = help === '' ? '-' : help;
    document.getElementById('emgSumContact').textContent = contact === '' ? '-' : contact;
    document.getElementById('emgSumDesc').textContent = desc;

    document.getElementById('emergencyStepCompose').style.display = 'none';
    document.getElementById('emergencyStepConfirm').style.display = '';
}
function showEmergencyCompose() {
    document.getElementById('emergencyStepCompose').style.display = '';
    document.getElementById('emergencyStepConfirm').style.display = 'none';
}
</script>
</body>
</html>