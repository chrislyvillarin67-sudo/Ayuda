<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = 'Messages';
$activePage = 'messages';
$isAdmin = $_SESSION['role'] === 'district_admin';
$meId = (int)$_SESSION['user_id'];
$csrfOk = validateCSRF();
$errors = [];

// ---- Contacts -------------------------------------------------------------
// Admin: every approved barangay staff (with their barangay + district).
// Staff: the district admin (prefer one linked to their barangay, else the
// primary / lowest-id admin account).
if ($isAdmin) {
    $contacts = $pdo->query("
        SELECT u.id, u.full_name, u.photo_path, b.id AS barangay_id, b.name AS barangay_name, b.district_id, d.name AS district_name
        FROM users u
        LEFT JOIN barangays b ON b.id = u.barangay_id
        LEFT JOIN districts d ON d.id = b.district_id
        WHERE u.role = 'barangay_staff' AND u.status = 'approved'
        ORDER BY d.name, b.name, u.full_name
    ")->fetchAll();
    $contactIds = array_map('intval', array_column($contacts, 'id'));
    $contactId = (int)($_GET['with'] ?? 0);
    $validContact = $contactId > 0 && in_array($contactId, $contactIds, true);
} else {
    $brgyId = (int)($_SESSION['barangay_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, full_name, photo_path FROM users WHERE role='district_admin' AND barangay_id=? ORDER BY id LIMIT 1");
    $stmt->execute([$brgyId ?: null]);
    $contact = $stmt->fetch();
    if (!$contact) {
        $stmt = $pdo->prepare("SELECT id, full_name, photo_path FROM users WHERE role='district_admin' ORDER BY id LIMIT 1");
        $stmt->execute();
        $contact = $stmt->fetch();
    }
    $contacts = $contact ? [$contact] : [];
    $contactId = (int)($contact['id'] ?? 0);
    $validContact = $contactId > 0;
}

// ---- Send message ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfOk && isset($_POST['recipient_id'])) {
    $recipient = (int)($_POST['recipient_id'] ?? 0);
    $text = trim($_POST['message'] ?? '');
    $allowed = false;
    if ($isAdmin) {
        $allowed = $recipient > 0 && isset($contactIds) && in_array($recipient, $contactIds, true);
    } else {
        $allowed = $validContact && $recipient === $contactId;
    }
    if (!$allowed) {
        $errors[] = 'Cannot send a message to that account.';
    } elseif ($text === '') {
        $errors[] = 'Message cannot be empty.';
    }
    if (!$errors) {
        $pdo->prepare("INSERT INTO messages (sender_id, recipient_id, message) VALUES (?,?,?)")->execute([$meId, $recipient, $text]);
        logAudit($pdo, 'message_send', 'Sent a private message to user #' . $recipient);
        setFlash('success', 'Message sent.');
        redirect('messages.php' . ($isAdmin ? '?with=' . $recipient : ''));
    }
    if ($isAdmin && $recipient > 0 && isset($contactIds) && in_array($recipient, $contactIds, true)) {
        $contactId = $recipient;
        $validContact = true;
    }
}

// ---- Mark the open thread as read -----------------------------------------
if ($validContact) {
    $pdo->prepare("UPDATE messages SET is_read=1, read_at=NOW() WHERE recipient_id=? AND sender_id=? AND is_read=0")
        ->execute([$meId, $contactId]);
}

// ---- Load thread ----------------------------------------------------------
$thread = [];
$lastMsgId = 0;
if ($validContact) {
    $stmt = $pdo->prepare("SELECT m.*, u.full_name AS sender_name FROM messages m
                           LEFT JOIN users u ON u.id = m.sender_id
                           WHERE (m.sender_id=? AND m.recipient_id=?) OR (m.sender_id=? AND m.recipient_id=?)
                           ORDER BY m.created_at ASC, m.id ASC");
    $stmt->execute([$meId, $contactId, $contactId, $meId]);
    $thread = $stmt->fetchAll();
    $lastMsgId = $thread ? (int)max(array_column($thread, 'id')) : 0;
}

// ---- Conversation list info (unread + last message) ------------------------
$convInfo = [];
foreach ($contacts as $c) {
    $stmt = $pdo->prepare("SELECT message, created_at, sender_id FROM messages
                           WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)
                           ORDER BY created_at DESC, id DESC LIMIT 1");
    $stmt->execute([$c['id'], $meId, $meId, $c['id']]);
    $convInfo[$c['id']] = ['last' => $stmt->fetch(), 'unread' => 0];
    $stmtUn = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id=? AND sender_id=? AND is_read=0");
    $stmtUn->execute([$meId, $c['id']]);
    $convInfo[$c['id']]['unread'] = (int)$stmtUn->fetchColumn();
}
$unreadTotal = 0;
foreach ($convInfo as $ci) { $unreadTotal += $ci['unread']; }

// ---- Compose picker data (district admin only) ----------------------------
$districtsAll = $isAdmin ? $pdo->query("SELECT id, name FROM districts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) : [];
$brgyByDist = [];
foreach ($districtsAll as $d) { $brgyByDist[(int)$d['id']] = getBarangays($pdo, (int)$d['id']); }
$staffByBrgy = [];
foreach ($contacts as $c) { if (!empty($c['barangay_id'])) $staffByBrgy[(int)$c['barangay_id']] = (int)$c['id']; }

$initialsOf = function (string $name): string {
    $out = '';
    foreach (explode(' ', $name) as $part) { if ($part !== '') $out .= strtoupper(mb_substr($part, 0, 1)); }
    return substr($out, 0, 2);
};
$fmtShort = function (?string $dt): string {
    if (!$dt) return '';
    $t = strtotime($dt);
    return date('Y-m-d', $t) === date('Y-m-d') ? date('g:i A', $t) : date('M j', $t);
};
$fmtDay = function (?string $dt): string {
    if (!$dt) return '';
    $t = strtotime($dt);
    $now = time();
    if (date('Y-m-d', $t) === date('Y-m-d', $now)) return 'Today';
    if (date('Y-m-d', $t) === date('Y-m-d', $now - 86400)) return 'Yesterday';
    return date('D, M j', $t);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
.page-phone { max-width: 620px; margin: 0 auto; }

/* ---------- Conversation list (phone SMS style) ---------- */
.sms-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.sms-head h2 { margin:0; font-size:20px; color:var(--navy); }
.sms-pill { display:inline-flex; align-items:center; gap:6px; background:#fdeceb; color:var(--danger);
    border:1px solid #f6c6c0; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:700; }
.sms-pill .num { min-width:18px; height:18px; border-radius:9px; background:var(--danger); color:#fff;
    display:inline-flex; align-items:center; justify-content:center; font-size:11px; padding:0 4px; }
.sms-list { background:#fff; border:1px solid #e9eef3; border-radius:18px; overflow:hidden; box-shadow:var(--shadow); }
.dist-label { font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
    color:var(--navy); padding:10px 16px 4px; background:#fafcfe; border-bottom:1px solid #eef2f6; }
.sms-row { display:flex; align-items:center; gap:12px; padding:12px 14px; text-decoration:none; color:inherit;
    border-bottom:1px solid #f0f3f7; transition:background .12s ease; }
.sms-row:last-child { border-bottom:none; }
.sms-row.active { background:#f3f8fd; }
.sms-row:active { background:#eef4fa; }
.sms-avatar { width:46px; height:46px; border-radius:50%; flex-shrink:0; background:var(--navy); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; overflow:hidden; }
.sms-avatar img { width:100%; height:100%; object-fit:cover; }
.sms-rmid { flex:1 1 auto; min-width:0; }
.sms-rname { font-size:15px; font-weight:600; color:#1c2b3a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sms-row.unread .sms-rname { font-weight:800; color:var(--navy); }
.sms-rpre { font-size:13px; color:var(--text-muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sms-row.unread .sms-rpre { color:#44505c; }
.sms-rright { display:flex; flex-direction:column; align-items:flex-end; gap:5px; flex-shrink:0; }
.sms-rtime { font-size:12px; color:var(--text-muted); }
.sms-row.unread .sms-rtime { color:var(--navy); font-weight:700; }
.sms-unread { min-width:20px; height:20px; padding:0 6px; border-radius:10px; background:var(--danger);
    color:#fff; font-size:12px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }

/* ---------- Conversation / chat (phone SMS style) ---------- */
.sms-chat { display:flex; flex-direction:column; background:#fff; border:1px solid #e9eef3; border-radius:18px;
    overflow:hidden; box-shadow:var(--shadow); height:min(660px, calc(100vh - 200px)); }
.sms-top { display:flex; align-items:center; gap:11px; padding:11px 12px; background:var(--navy); color:#fff; flex-shrink:0; }
.sms-top .sms-bar-btn { background:none; border:none; color:#fff; cursor:pointer; padding:4px; display:inline-flex;
    align-items:center; justify-content:center; border-radius:50%; }
.sms-top .sms-bar-btn:active { background:rgba(255,255,255,0.15); }
.sms-top .sms-avatar { width:38px; height:38px; font-size:13px; background:rgba(255,255,255,0.18); }
.sms-tmid { flex:1 1 auto; min-width:0; }
.sms-tname { font-size:15px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sms-tsub { font-size:12px; color:#b9c9da; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
.sms-msgs { flex:1 1 auto; overflow-y:auto; padding:14px; background:#eef2f6;
    background-image: radial-gradient(rgba(11,46,74,0.05) 1px, transparent 1px); background-size: 18px 18px;
    display:flex; flex-direction:column; gap:14px; }
.sms-day { align-self:center; background:#dbe3eb; color:#5b6b7a; font-size:11px; font-weight:700;
    padding:3px 10px; border-radius:10px; }
.sms-msgrow { display:flex; }
.sms-msgrow.out { justify-content:flex-end; }
.sms-bub { max-width:72%; width:fit-content; padding:8px 12px; border-radius:16px; font-size:14px; line-height:1.45;
    word-break:break-word; white-space:pre-wrap; box-shadow:0 1px 1px rgba(11,46,74,0.08); }
.sms-msgrow.in .sms-bub { background:#fff; border:1px solid #e1e7ee; border-bottom-left-radius:4px; color:#1c2b3a; }
.sms-msgrow.out .sms-bub { background:linear-gradient(180deg,#123b60,#0B2E4A); color:#fff; border-bottom-right-radius:4px; }
.sms-meta { font-size:10.5px; color:#99a6b3; margin-top:3px; text-align:right; }
.sms-msgrow.out .sms-meta { color:rgba(255,255,255,0.72); }
.sms-composer { display:flex; align-items:flex-end; gap:10px; padding:10px 12px; background:#fff;
    border-top:1px solid #eef1f5; flex-shrink:0; }
.sms-composer textarea { flex:1 1 auto; border:1px solid #dde4ec; border-radius:22px; padding:10px 14px;
    font-size:14px; line-height:1.35; resize:none; min-height:42px; max-height:112px; outline:none; font-family:inherit; }
.sms-composer textarea:focus { border-color:var(--navy); }
.sms-send { width:42px; height:42px; border-radius:50%; background:var(--navy); border:none; color:#fff; cursor:pointer;
    display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 2px 6px rgba(11,46,74,0.3); }
.sms-send:active { ; }
.sms-nochat { background:#fff; border:1px solid #e9eef3; border-radius:18px; padding:44px 20px; text-align:center; color:var(--text-muted); box-shadow:var(--shadow); }

/* ---------- New message (+) compose ---------- */
.sms-add { width:34px; height:34px; border-radius:50%; border:none; background:var(--navy); color:#fff;
    cursor:pointer; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
    box-shadow:0 2px 6px rgba(11,46,74,0.3); }
.sms-add:active { ; }
.sms-modal { position:fixed; inset:0; background:rgba(7,26,44,0.45); backdrop-filter:blur(2px);
    display:flex; align-items:center; justify-content:center; padding:16px; z-index:90; }
.sms-modal[hidden] { display:none; }
.sms-modal-box { width:100%; max-width:420px; background:#fff; border-radius:18px; overflow:hidden;
    box-shadow:0 18px 50px rgba(7,26,44,0.35); }
.sms-modal-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px;
    background:var(--navy); color:#fff; }
.sms-modal-head h3 { margin:0; font-size:17px; }
.sms-modal-x { background:none; border:none; color:#fff; cursor:pointer; padding:4px; display:inline-flex;
    align-items:center; justify-content:center; border-radius:50%; }
.sms-modal-x:active { background:rgba(255,255,255,0.15); }
.sms-modal-body { padding:18px; }
.sms-field { display:block; font-size:12px; font-weight:800; letter-spacing:.06em; text-transform:uppercase;
    color:var(--text-muted); margin-bottom:6px; }
.sms-hint { font-size:13px; color:var(--text-muted); margin-top:10px; min-height:18px; }
.sms-hint.ok { color:#1e7e4d; }
.sms-hint.bad { color:var(--danger); }
.sms-modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:12px 18px;
    background:#f7fafd; border-top:1px solid #eef2f6; }
</style>
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/includes/flash.php'; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div>
        <?php endif; ?>

        <div class="page-phone">
            <?php if (!$contacts): ?>
                <div class="sms-nochat">
                    <div class="es-icon"><?= svgIcon('info', 30) ?></div>
                    <?= $isAdmin ? 'No approved Barangay Staff accounts yet.' : 'No District Admin account available to message right now.' ?>
                </div>
            <?php elseif ($isAdmin && !$validContact): ?>
                <!-- ============ Conversation list (phone style) ============ -->
                <div class="sms-head">
                    <h2>Messages</h2>
                    <span style="display:flex;align-items:center;gap:10px;">
                        <?php if ($isAdmin): ?>
                            <button type="button" class="sms-add" id="smsComposeBtn" aria-label="New message"><?= svgIcon('plus', 18) ?></button>
                        <?php endif; ?>
                        <span class="sms-pill" id="smsPill" style="<?= $unreadTotal > 0 ? '' : 'display:none;' ?>"><span class="num" id="smsPillNum"><?= $unreadTotal > 99 ? '99+' : $unreadTotal ?></span>unread</span>
                    </span>
                </div>
                <div class="sms-list">
                    <?php $lastDistrict = null; foreach ($contacts as $c): $ci = $convInfo[$c['id']]; ?>
                        <?php if ($c['district_name'] !== $lastDistrict): $lastDistrict = $c['district_name']; ?>
                            <div class="dist-label">District <?= (int)$c['district_id'] ?> &middot; <?= e($lastDistrict ?: 'Unassigned') ?></div>
                        <?php endif; ?>
                        <a href="messages.php?with=<?= (int)$c['id'] ?>" class="sms-row <?= $ci['unread'] > 0 ? 'unread' : '' ?>">
                            <span class="sms-avatar">
                                <?php if (!empty($c['photo_path']) && is_file(__DIR__ . '/' . $c['photo_path'])): ?>
                                    <img src="<?= e($c['photo_path']) ?>" alt="">
                                <?php else: ?><?= e($initialsOf($c['full_name'])) ?><?php endif; ?>
                            </span>
                            <span class="sms-rmid">
                                <span class="sms-rname">Barangay <?= e($c['barangay_name'] ?: $c['full_name']) ?></span>
                                <span class="sms-rpre">
                                    <?php if (!empty($ci['last'])): ?>
                                        <?= ($ci['last']['sender_id'] === $meId ? 'You: ' : '') ?><?= e(mb_strimwidth($ci['last']['message'], 0, 42, '...')) ?>
                                    <?php else: ?>
                                        Tap to start a conversation
                                    <?php endif; ?>
                                </span>
                            </span>
                            <span class="sms-rright">
                                <span class="sms-rtime"><?= $ci['last'] ? $fmtShort($ci['last']['created_at']) : '' ?></span>
                                <?php if ($ci['unread'] > 0): ?><span class="sms-unread"><?= $ci['unread'] > 99 ? '99+' : $ci['unread'] ?></span><?php endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($validContact): ?>
                <!-- ============ Conversation (phone style) ============ -->
                <?php $contact = null; foreach (($isAdmin ? $contacts : $contacts) as $c) { if ((int)$c['id'] === $contactId) { $contact = $c; break; } } ?>
                <div class="sms-chat">
                    <div class="sms-top">
                        <?php if ($isAdmin): ?>
                            <a href="messages.php" class="sms-bar-btn" aria-label="Back"><?= svgIcon('left', 20) ?></a>
                        <?php endif; ?>
                        <span class="sms-avatar">
                            <?php if (!empty($contact['photo_path']) && is_file(__DIR__ . '/' . $contact['photo_path'])): ?>
                                <img src="<?= e($contact['photo_path']) ?>" alt="">
                            <?php else: ?><?= e($initialsOf($contact['full_name'])) ?><?php endif; ?>
                        </span>
                        <div class="sms-tmid">
                            <div class="sms-tname"><?= $isAdmin ? 'Barangay ' . e($contact['barangay_name'] ?? $contact['full_name']) : e($contact['full_name']) ?></div>
                            <div class="sms-tsub">
                                <?= $isAdmin
                                    ? e($contact['full_name']) . ($contact['district_name'] ? ' &middot; ' . e($contact['district_name']) . ' (District ' . (int)$contact['district_id'] . ')' : '')
                                    : 'District Admin / CSWDO' ?>
                            </div>
                        </div>
                        <?php if ($isAdmin): ?>
                            <button type="button" class="sms-bar-btn" id="smsComposeBtn2" aria-label="New message"><?= svgIcon('plus', 18) ?></button>
                        <?php endif; ?>
                    </div>

                    <div class="sms-msgs" id="smsMsgs">
                        <?php foreach ($thread as $m): $mine = (int)$m['sender_id'] === $meId; ?>
                            <?php if ($m === reset($thread) || date('Y-m-d', strtotime($m['created_at'])) !== date('Y-m-d', strtotime(($prev ?? ['created_at' => null])['created_at'] ?? $m['created_at']))): ?>
                                <div class="sms-day"><?= e($fmtDay($m['created_at'])) ?></div>
                            <?php endif; $prev = $m; ?>
                            <div class="sms-msgrow <?= $mine ? 'out' : 'in' ?>"><div class="sms-bub"><?= nl2br(e($m['message'])) ?><div class="sms-meta"><?= date('g:i A', strtotime($m['created_at'])) ?></div></div></div>
                        <?php endforeach; ?>
                        <?php if (!$thread): ?>
                            <div class="sms-nochat" style="box-shadow:none;border:none;background:transparent;">
                                <div class="es-icon"><?= svgIcon('bell', 30) ?></div>
                                No messages yet. Say hi and ask about the ayuda updates you need.
                            </div>
                        <?php endif; ?>
                    </div>

                    <form class="sms-composer" method="POST" id="smsForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="recipient_id" value="<?= $contactId ?>">
                        <textarea name="message" id="smsInput" rows="1" maxlength="2000" placeholder="Type a message..." autofocus></textarea>
                        <button class="sms-send" type="submit" aria-label="Send"><?= svgIcon('right', 18) ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin): ?>
        <div class="sms-modal" id="smsModal" hidden>
            <div class="sms-modal-box">
                <div class="sms-modal-head">
                    <h3>New Message</h3>
                    <button type="button" class="sms-modal-x" data-sms-close aria-label="Close"><?= svgIcon('x', 18) ?></button>
                </div>
                <div class="sms-modal-body">
                    <label class="sms-field">District</label>
                    <select id="composeDistrict" class="form-control">
                        <option value="">Select District</option>
                        <?php foreach ($districtsAll as $d): ?>
                            <option value="<?= (int)$d['id'] ?>">District <?= (int)$d['id'] ?> &middot; <?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="sms-field" style="margin-top:12px;">Barangay</label>
                    <select id="composeBarangay" class="form-control">
                        <option value="">Select Barangay</option>
                    </select>
                    <p class="sms-hint" id="composeHint"></p>
                </div>
                <div class="sms-modal-foot">
                    <button type="button" class="btn btn-outline" data-sms-close>Cancel</button>
                    <a href="#" class="btn" id="composeGo" hidden>Start Conversation</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
<script>
(function () {
    var contactId = <?= $validContact ? $contactId : 0 ?>;
    var lastMsgId = <?= (int)$lastMsgId ?>;
    var msgs = document.getElementById('smsMsgs');
    if (msgs) { msgs.scrollTop = msgs.scrollHeight; }

    function autoGrow(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 112) + 'px';
    }
    var input = document.getElementById('smsInput');
    if (input) {
        autoGrow(input);
        input.addEventListener('input', function () { autoGrow(input); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                var btn = document.querySelector('.sms-send');
                if (btn) btn.click();
                else document.getElementById('smsForm').submit();
            }
        });
    }

    function refreshBadge(n) {
        document.querySelectorAll('[data-msg-badge]').forEach(function (el) {
            if (Number(n) > 0) { el.textContent = Number(n) > 99 ? '99+' : String(n); el.style.display = 'inline-flex'; }
            else { el.style.display = 'none'; }
        });
        var pill = document.getElementById('smsPill');
        var pillNum = document.getElementById('smsPillNum');
        if (pill && pillNum) {
            pillNum.textContent = Number(n) > 99 ? '99+' : String(n);
            pill.style.display = Number(n) > 0 ? 'inline-flex' : 'none';
        }
    }

    var modal = document.getElementById('smsModal');
    var composeDist = document.getElementById('composeDistrict');
    var composeBrgy = document.getElementById('composeBarangay');
    var composeHint = document.getElementById('composeHint');
    var composeGo = document.getElementById('composeGo');
    var staffByBrgy = <?= json_encode($staffByBrgy) ?>;
    var brgyByDist = <?= json_encode($brgyByDist) ?>;

    function openCompose() {
        if (!modal) return;
        composeDist.value = '';
        composeBrgy.innerHTML = '<option value="">Select Barangay</option>';
        composeHint.textContent = '';
        composeHint.className = 'sms-hint';
        composeGo.hidden = true;
        composeGo.setAttribute('href', '#');
        modal.hidden = false;
    }
    function closeCompose() { if (modal) modal.hidden = true; }
    var btnOpen = document.getElementById('smsComposeBtn');
    if (btnOpen) btnOpen.addEventListener('click', openCompose);
    var btnOpen2 = document.getElementById('smsComposeBtn2');
    if (btnOpen2) btnOpen2.addEventListener('click', openCompose);
    if (modal) {
        modal.addEventListener('click', function (e) { if (e.target === modal) closeCompose(); });
        modal.querySelectorAll('[data-sms-close]').forEach(function (b) { b.addEventListener('click', closeCompose); });
    }
    if (composeDist) composeDist.addEventListener('change', function () {
        var id = composeDist.value;
        composeBrgy.innerHTML = '<option value="">Select Barangay</option>';
        composeHint.textContent = '';
        composeHint.className = 'sms-hint';
        composeGo.hidden = true;
        composeGo.setAttribute('href', '#');
        (brgyByDist[id] || []).forEach(function (b) {
            var opt = document.createElement('option');
            opt.value = b.id; opt.textContent = b.name;
            composeBrgy.appendChild(opt);
        });
    });
    if (composeBrgy) composeBrgy.addEventListener('change', function () {
        var staffId = staffByBrgy[composeBrgy.value] || 0;
        composeHint.textContent = '';
        composeHint.className = 'sms-hint';
        composeGo.hidden = true;
        composeGo.setAttribute('href', '#');
        if (staffId) {
            composeHint.textContent = 'This barangay has an approved staff account.';
            composeHint.className = 'sms-hint ok';
            composeGo.hidden = false;
            composeGo.setAttribute('href', 'messages.php?with=' + staffId);
        } else if (composeBrgy.value) {
            composeHint.textContent = 'No approved Barangay Staff account for this barangay yet.';
            composeHint.className = 'sms-hint bad';
        }
    });

    setInterval(function () {
        fetch('ajax_messages.php?contact=' + contactId + '&last=' + lastMsgId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (typeof d.unread_total !== 'undefined') refreshBadge(d.unread_total);
                if (contactId > 0 && d.contact_id === contactId && Number(d.last_id) > lastMsgId) {
                    lastMsgId = Number(d.last_id);
                    location.reload();
                }
            })
            .catch(function () {});
    }, 6000);
})();
</script>
</body>
</html>