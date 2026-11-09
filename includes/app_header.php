<?php
// Expects $pageTitle to be set by the including page
$isAdmin = ($_SESSION['role'] ?? '') === 'district_admin';
$fullName = $_SESSION['full_name'] ?? 'User';
$initials = '';
foreach (explode(' ', $fullName) as $part) { if ($part !== '') $initials .= strtoupper($part[0]); }
$initials = substr($initials, 0, 2);

// Header subtitle shows role + assigned barangay (e.g. "Barangay Staff - Acacia, Poblacion").
// The user's name is deliberately omitted; it is still shown as avatar initials.
$headerBarangayName = '';
if (!$isAdmin && !empty($_SESSION['barangay_id']) && isset($pdo)) {
    $b = getBarangayById($pdo, (int)$_SESSION['barangay_id']);
    if ($b) {
        $headerBarangayName = trim($b['name'] . ', ' . ($b['district_name'] ?? ''), ', ');
    }
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
        <?php $hdrTitle = trim((string)($pageTitle ?? '')); ?>
        <?php if ($hdrTitle !== ''): ?>
            <h1><?= e($hdrTitle) ?></h1>
        <?php endif; ?>
        <div class="header-sub"><?= $isAdmin
            ? 'Admin'
            : 'Barangay Staff' . ($headerBarangayName ? ' &bull; ' . e($headerBarangayName) : '') ?></div>
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
