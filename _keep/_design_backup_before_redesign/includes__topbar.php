<?php
$fullName = $_SESSION['full_name'] ?? 'User';
$initials = '';
foreach (explode(' ', $fullName) as $part) { if ($part !== '') $initials .= strtoupper($part[0]); }
$initials = substr($initials, 0, 2);
?>
<div class="topbar">
    <h1><?= e($pageTitle ?? 'Dashboard') ?></h1>
    <div class="user-chip">
        <div class="text-right">
            <div style="font-size:13px;font-weight:700;color:#1c2b3a;"><?= e($fullName) ?></div>
            <div class="small text-muted"><?= $_SESSION['role'] === 'district_admin' ? 'District Admin' : 'Barangay Staff' ?></div>
        </div>
        <div class="user-avatar" style="overflow:hidden;">
            <?php $photo = $_SESSION['photo_path'] ?? null; ?>
            <?php if ($photo && is_file(__DIR__ . '/../' . $photo)): ?>
                <img src="<?= e($photo) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
                <?= e($initials) ?>
            <?php endif; ?>
        </div>
    </div>
</div>
