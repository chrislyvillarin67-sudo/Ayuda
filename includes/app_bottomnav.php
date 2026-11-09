<?php
// Expects $activePage to be set by the including page
$isAdmin = ($_SESSION['role'] ?? '') === 'district_admin';
$active = $activePage ?? '';

// Live notification: how many emergency reports are pending right now.
// Admin sees all pending reports; Staff sees only their own barangay's.
if ($isAdmin) {
    $pendingReports = (int)$pdo->query("SELECT COUNT(*) FROM barangay_requests WHERE status='pending'")->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM barangay_requests WHERE status='pending' AND barangay_id=?");
    $stmt->execute([(int)($_SESSION['barangay_id'] ?? 0)]);
    $pendingReports = (int)$stmt->fetchColumn();
}

// DEVIATION (per doc feedback): District Admin keeps "Beneficiaries" as a
// primary tab to browse ALL members per barangay, separated by category
// (All / Senior / Solo Parent / PWD / etc). Barangay Staff assist on-site only.
$primary = [
    ['page' => 'dashboard',    'icon' => 'home', 'label' => 'Home',          'href' => 'dashboard.php'],
];
if ($isAdmin) {
    $primary[] = ['page' => 'beneficiaries', 'icon' => 'users', 'label' => 'Beneficiaries', 'href' => 'beneficiaries.php'];
    // Inventory is District-Admin only; Barangay Staff don't manage stock.
    $primary[] = ['page' => 'inventory',     'icon' => 'box', 'label' => 'Inventory',     'href' => 'inventory.php'];
    // Distribution is a primary tab for the District Admin (focus of this task),
    // kept distinct from Inventory. It is the hub for direct distribution,
    // tracking, final lists and emergency reports.
    $primary[] = ['page' => 'distribute', 'icon' => 'gift', 'label' => 'Distribution', 'href' => 'distribution.php'];
    // Emergency Reports move to the primary nav too; the red badge counts
    // pending reports awaiting the CSWDO's response.
    $primary[] = ['page' => 'barangay', 'icon' => 'alert', 'label' => 'Emergency', 'href' => 'barangay_requests.php', 'badge' => true];
} else {
    // Staff's Inventory slot is replaced with Announcements: they relay district
    // news to their barangay's beneficiaries. The red badge counts announcements
    // created since the staff last opened the Announcements page.
    $newAnnouncements = getNewAnnouncementCount($pdo, (int)($_SESSION['user_id'] ?? 0), (int)($_SESSION['barangay_id'] ?? 0));
    $primary[] = ['page' => 'announcements', 'icon' => 'bell', 'label' => 'Announcements', 'href' => 'announcements.php', 'annbadge' => $newAnnouncements];
}
if (!$isAdmin) {
    // Barangay Staff get a dedicated Emergency tab so they can quickly file
    // incident reports (fire, landslide, flood, etc.) to the CSWDO / District Admin.
    // The badge shows reports from their barangay still awaiting a response.
    $primary[] = ['page' => 'barangay', 'icon' => 'alert', 'label' => 'Emergency', 'href' => 'barangay_requests.php', 'badge' => true];
}
if ($isAdmin) {
    // Reports tab is District Admin only; Barangay Staff only assist on-site.
    $primary[] = ['page' => 'reports',   'icon' => 'file', 'label' => 'Reports',   'href' => 'reports.php'];
}

// DEVIATION (per doc feedback): the Map is District-Admin-only now, so it's
// dropped from Barangay Staff's "More" sheet entirely. Barangay Requests is
// relabeled to "Request Aid" for staff since that's what it actually is for
// them (requesting aid from the District Admin), while Admin keeps the
// original "Barangay Requests" label (they're the ones reviewing requests).
$more = [];
if ($isAdmin) {
    $more[] = ['icon' => 'pin',  'label' => 'Map',               'href' => 'map.php',               'page' => 'map'];
    $more[] = ['icon' => 'users', 'label' => 'Manage Users',      'href' => 'manage_users.php',       'page' => 'users'];
    $more[] = ['icon' => 'bell', 'label' => 'Announcements',     'href' => 'announcements.php',      'page' => 'announcements'];
}
$more[] = ['icon' => 'calendar', 'label' => 'Distribution Calendar', 'href' => 'calendar.php', 'page' => 'calendar'];
$more[] = ['icon' => 'user', 'label' => 'Profile / Settings', 'href' => 'profile.php', 'page' => 'profile'];

$msgUnread = 0;
$stmtUn = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id=? AND is_read=0");
$stmtUn->execute([(int)($_SESSION['user_id'] ?? 0)]);
$msgUnread = (int)$stmtUn->fetchColumn();
$more[] = ['icon' => 'bell', 'label' => 'Messages', 'href' => 'messages.php', 'page' => 'messages', 'badge' => $msgUnread];

$morePages = array_column($more, 'page');
$moreActive = in_array($active, $morePages, true);
?>
<nav class="app-sidebar">
    <div class="sb-brand">
        <span class="sb-logo">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" aria-hidden="true">
                <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--navy-dark)"/>
                <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--gold)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        Ayuda Monitoring
    </div>
    <div class="sb-section">Menu</div>
    <?php foreach ($primary as $item): ?>
        <a href="<?= $item['href'] ?>" class="sb-link <?= $active === $item['page'] ? 'active' : '' ?>">
            <span class="sb-ic"><?= svgIcon($item['icon'], 19) ?>
                <?php if (!empty($item['badge'])): ?>
                    <span class="sb-badge" data-pending-badge style="<?= $pendingReports > 0 ? '' : 'display:none;' ?>"><?= $pendingReports > 99 ? '99+' : $pendingReports ?></span>
                <?php endif; ?>
                <?php if (isset($item['annbadge'])): ?>
                    <span class="sb-badge" data-ann-badge style="<?= (int)$item['annbadge'] > 0 ? '' : 'display:none;' ?>"><?= (int)$item['annbadge'] > 99 ? '99+' : (int)$item['annbadge'] ?></span>
                <?php endif; ?>
            </span><?= $item['label'] ?>
        </a>
    <?php endforeach; ?>
    <div class="sb-section">More</div>
    <?php foreach ($more as $item): ?>
        <a href="<?= $item['href'] ?>" class="sb-link <?= $active === $item['page'] ? 'active' : '' ?>">
            <span class="sb-ic"><?= svgIcon($item['icon'], 19) ?></span><?= $item['label'] ?>
            <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                <span class="sb-badge" data-msg-badge><?= (int)$item['badge'] > 99 ? '99+' : (int)$item['badge'] ?></span>
            <?php else: ?>
                <span class="sb-badge" data-msg-badge style="display:none;"></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    <div class="sb-foot">
        <a href="logout.php"><span class="sb-ic"><?= svgIcon('logout', 18) ?></span>Logout</a>
    </div>
</nav>
<div class="bottom-nav wide">
    <?php foreach ($primary as $item): ?>
        <a href="<?= $item['href'] ?>" class="<?= $active === $item['page'] ? 'active' : '' ?>">
            <span class="bn-icwrap"><span class="bn-icon"><?= svgIcon($item['icon'], 22) ?></span>
                <?php if (!empty($item['badge'])): ?>
                    <span class="bn-badge" data-pending-badge style="<?= $pendingReports > 0 ? '' : 'display:none;' ?>"><?= $pendingReports > 99 ? '99+' : $pendingReports ?></span>
                <?php endif; ?>
                <?php if (isset($item['annbadge'])): ?>
                    <span class="bn-badge" data-ann-badge style="<?= (int)$item['annbadge'] > 0 ? '' : 'display:none;' ?>"><?= (int)$item['annbadge'] > 99 ? '99+' : (int)$item['annbadge'] ?></span>
                <?php endif; ?>
            </span><?= $item['label'] ?>
        </a>
    <?php endforeach; ?>
</div>
<!-- DEVIATION (per doc feedback: "Walaon ang more navigation kay naa na sya
     sa burger icon") -- the "More" tab is removed from the bottom nav since
     it duplicated the burger menu button already in the header. The sheet
     itself is unchanged and still opens from the header burger icon. -->

<div class="modal-overlay" id="moreSheet">
    <div class="modal-box" style="max-width:420px;">
        <h3 style="margin-top:0;color:var(--navy);text-align:center;">More</h3>
        <?php foreach ($more as $item): ?>
            <a href="<?= $item['href'] ?>" class="list-item" style="text-decoration:none;color:inherit;<?= $active === $item['page'] ? 'background:#f3f8fd;' : '' ?>">
                <div class="li-name"><span style="margin-right:10px;"><?= svgIcon($item['icon'], 19) ?></span><?= $item['label'] ?>
                    <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                        <span class="msg-badge" data-msg-badge><?= (int)$item['badge'] > 99 ? '99+' : (int)$item['badge'] ?></span>
                    <?php else: ?>
                        <span class="msg-badge" data-msg-badge style="display:none;"></span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
        <a href="logout.php" class="btn btn-danger btn-block mt-3" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;"><?= svgIcon('logout', 18) ?> Logout</a>
        <button class="btn btn-outline btn-block mt-2" onclick="closeMoreSheet()">Close</button>
    </div>
</div>
<script>
function openMoreSheet() {
    document.getElementById('moreSheet').classList.add('show');
}
function closeMoreSheet() {
    document.getElementById('moreSheet').classList.remove('show');
}
// Live pending-report notification badge (updates without a page reload).
function refreshPendingBadges() {
    fetch('ajax_pending_reports.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var n = Number(d.count) || 0;
            document.querySelectorAll('[data-pending-badge]').forEach(function (el) {
                el.textContent = n > 99 ? '99+' : String(n);
                el.style.display = n > 0 ? 'inline-block' : 'none';
            });
        })
        .catch(function () {});
}
setInterval(refreshPendingBadges, 30000);
// Live unread-message badge in the burger sheet (Messages).
function refreshMsgBadge() {
    fetch('ajax_messages.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var n = Number(d.unread_total) || 0;
            document.querySelectorAll('[data-msg-badge]').forEach(function (el) {
                el.textContent = n > 99 ? '99+' : String(n);
                el.style.display = n > 0 ? 'inline-flex' : 'none';
            });
        })
        .catch(function () {});
}
setInterval(refreshMsgBadge, 20000);
// Live new-announcement badge on the staff Announcements tab (clears once
// they open the Announcements page and it records them as seen).
function refreshAnnBadge() {
    fetch('ajax_new_announcements.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var n = Number(d.count) || 0;
            document.querySelectorAll('[data-ann-badge]').forEach(function (el) {
                el.textContent = n > 99 ? '99+' : String(n);
                el.style.display = n > 0 ? 'inline-block' : 'none';
            });
        })
        .catch(function () {});
}
refreshAnnBadge();
setInterval(refreshAnnBadge, 30000);
</script>
<script src="assets/js/input-guards.js"></script>
<?php include __DIR__ . '/chatbot_widget.php'; ?>
