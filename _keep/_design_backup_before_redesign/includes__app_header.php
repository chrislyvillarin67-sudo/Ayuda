<?php
// Expects $pageTitle to be set by the including page
$isAdmin = ($_SESSION['role'] ?? '') === 'district_admin';
$fullName = $_SESSION['full_name'] ?? 'User';
$initials = '';
foreach (explode(' ', $fullName) as $part) { if ($part !== '') $initials .= strtoupper($part[0]); }
$initials = substr($initials, 0, 2);

// DEVIATION (per doc feedback: "Pag login sa barangay staff dapat naa
// makabutang ilang name and asa nga barangay") -- Barangay Staff should see
// their own name AND their assigned barangay in the header, not just "Barangay
// Staff". District Admin oversees everything, so no single barangay applies.
$headerBarangayName = '';
if (!$isAdmin && !empty($_SESSION['barangay_id']) && isset($pdo)) {
    $b = getBarangayById($pdo, (int)$_SESSION['barangay_id']);
    if ($b) $headerBarangayName = $b['name'] . ' (' . $b['district_name'] . ')';
}
// Live unread-message count for the red burger badge (kept fresh via AJAX).
$hdrMsgUnread = 0;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id=? AND is_read=0");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $hdrMsgUnread = (int)$stmt->fetchColumn();
}
?>
<div class="app-header">
    <button class="icon-btn burger-btn" style="position:relative;" onclick="openMoreSheet()" aria-label="Menu"><?= svgIcon('menu', 20) ?><span class="hdr-msg-badge" data-msg-badge style="<?= $hdrMsgUnread > 0 ? '' : 'display:none;' ?>"><?= $hdrMsgUnread > 99 ? '99+' : $hdrMsgUnread ?></span></button>
    <div class="app-header-title">
        <h1><?= e($pageTitle ?? 'Dashboard') ?></h1>
        <div class="header-sub">
            <?php if ($isAdmin): ?>
                Admin
            <?php else: ?>
                Barangay Staff &bull; <?= e($fullName) ?><?= $headerBarangayName ? ' &bull; ' . e($headerBarangayName) : '' ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="user-avatar" style="width:30px;height:30px;font-size:11px;background:rgba(255,255,255,0.18);color:#fff;flex-shrink:0;overflow:hidden;">
        <?php $photo = $_SESSION['photo_path'] ?? null; ?>
        <?php if ($photo && is_file(__DIR__ . '/../' . $photo)): ?>
            <img src="<?= e($photo) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
            <?= e($initials) ?>
        <?php endif; ?>
    </div>
</div>
