<?php
require_once __DIR__ . '/../includes/functions.php';
requireBeneficiary();

$stmt = $pdo->prepare("
    SELECT b.*, br.name AS barangay_name, d.name AS district_name
    FROM beneficiaries b
    JOIN barangays br ON br.id = b.barangay_id
    JOIN districts d ON d.id = b.district_id
    WHERE b.id = ?
");
$stmt->execute([$_SESSION['beneficiary_id']]);
$beneficiary = $stmt->fetch();

if (!$beneficiary) {
    unset($_SESSION['beneficiary_id']);
    redirect('../login.php');
}

$benCategories = beneficiaryCategories($pdo, (int)$beneficiary['id']);
$status = $beneficiary['status'] ?: 'pending';

$histStmt = $pdo->prepare("
    SELECT dl.distributed_at, dl.quantity, dl.amount, dl.assistance_type, i.item_name
    FROM distribution_logs dl JOIN inventory i ON i.id = dl.inventory_id
    WHERE dl.beneficiary_id = ? ORDER BY dl.distributed_at DESC
");
$histStmt->execute([$beneficiary['id']]);
$history = $histStmt->fetchAll();

$distStmt = $pdo->prepare("
    SELECT ds.id, ds.title, ds.aid_type, ds.venue, ds.schedule_date, ds.schedule_time, ds.what_to_bring, ds.note, ds.status,
           m.release_status, m.is_claimed, m.release_note, m.release_at, m.claimed_at
    FROM distribution_schedules ds
    JOIN distribution_schedule_members m ON m.schedule_id = ds.id AND m.beneficiary_id = ? AND m.is_receiver = 1
    WHERE ds.barangay_id = ? AND ds.status IN ('published','completed')
    ORDER BY CASE WHEN (m.is_claimed=1 OR m.release_status='released') THEN 1 ELSE 0 END, CASE WHEN ds.status='published' THEN 0 ELSE 1 END, ds.schedule_date DESC, ds.id DESC
");
$distStmt->execute([(int)$beneficiary['id'], (int)$beneficiary['barangay_id']]);
$myDistributionsAll = $distStmt->fetchAll();
$myDistributionsPending = [];
$myDistributionsClaimed = [];
foreach ($myDistributionsAll as $ds) {
    $rs = $ds['release_status'] ?? 'pending';
    $claimed = (int)($ds['is_claimed'] ?? 0) === 1 || $rs === 'released';
    if ($claimed) $myDistributionsClaimed[] = $ds;
    else $myDistributionsPending[] = $ds;
}

$hasUnseenUpdates = beneficiaryHasUnseenUpdates($pdo, $beneficiary);
$activePage = 'dashboard';
$nameParts = nameParts($beneficiary['last_name'] ?? '', $beneficiary['first_name'] ?? '', $beneficiary['middle_initial'] ?? '', $beneficiary['full_name'] ?? '');
$firstName = trim((string)($nameParts['first_name'] ?? ''));
if ($firstName === '') {
    $fn = trim((string)$beneficiary['full_name']);
    if (strpos($fn, ',') !== false) $firstName = trim(explode(',', $fn, 2)[1] ?? $fn);
    else { $p = preg_split('/\s+/', $fn); $firstName = $p[0] ?? 'there'; }
}
$firstName = explode(' ', $firstName)[0] ?? $firstName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Dashboard - Ayuda Monitoring System</title>
<link rel="stylesheet" href="../assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell" style="padding-bottom:80px;">
    <header class="app-header" style="justify-content:center;position:relative;top:auto;flex-wrap:wrap;height:auto;min-height:0;overflow:visible;padding:12px 10px 10px;box-sizing:border-box;width:100%;">
        <div class="app-header-title" style="position:static;transform:none;max-width:100%;width:100%;text-align:center;flex:1 1 100%;pointer-events:auto;min-width:0;">
            <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:2px;flex-wrap:wrap;min-width:0;width:100%;">
                <span style="width:30px;height:30px;border-radius:10px;background:var(--gold);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" aria-hidden="true">
                        <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--navy-dark)"/>
                        <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--gold)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <h1 style="font-size:clamp(15px,4.8vw,18px);margin:0;color:#fff;line-height:1.15;white-space:normal;">Ayuda Monitoring System</h1>
            </div>
            <div class="header-sub" style="white-space:normal;overflow:visible;text-overflow:clip;line-height:1.2;"><?= e($beneficiary['barangay_name']) ?>, <?= e($beneficiary['district_name']) ?></div>
        </div>
    </header>

    <main class="main-content">
        <?php include __DIR__ . '/../includes/flash.php'; ?>
        <div style="margin:14px 16px 10px;">
            <div style="font-size:18px;font-weight:800;color:var(--navy);">Welcome, <?= e($firstName) ?></div>
            <div class="small text-muted">Beneficiary Dashboard</div>
        </div>

        <div class="card" style="margin:0 16px 12px;background:linear-gradient(135deg, var(--navy), var(--navy-light));color:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                <div>
                    <div style="font-weight:700;"><?= e($beneficiary['full_name']) ?></div>
                    <div class="small" style="opacity:0.9;">ID: <?= e($beneficiary['beneficiary_code'] ?: $beneficiary['id']) ?></div>
                </div>
                <span class="badge" style="background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.4);"><?= ucfirst(e(statusLabel($status))) ?></span>
            </div>
        </div>

        <div class="section-title" style="margin:6px 16px 8px;">Available / Upcoming Ayuda</div>
        <?php if ($status !== 'approved'): ?>
            <div class="card" style="margin:0 16px 12px;"><div class="empty-state" style="padding:18px 10px;"><div class="es-icon"><?= svgIcon('clock', 24) ?></div><div class="es-title">Pending approval</div><div class="es-sub">Distribution details will appear here once your registration is approved.</div></div></div>
        <?php elseif (!$myDistributionsPending): ?>
            <div class="card" style="margin:0 16px 12px;"><div class="empty-state" style="padding:18px 10px;"><div class="es-icon"><?= svgIcon('gift', 24) ?></div><div class="es-title">No upcoming ayuda</div><div class="es-sub">Ayuda you can still receive will appear here.</div></div></div>
        <?php else: ?>
            <div style="margin:0 16px 12px;display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($myDistributionsPending as $ds): ?>
                    <?php $isNotReleased = ($ds['release_status'] ?? 'pending') === 'not_released'; ?>
                    <article class="ann">
                        <div class="ann-head">
                            <div class="ann-ic"><?= svgIcon($isNotReleased ? 'alert' : 'gift', 16) ?></div>
                            <div class="ann-main">
                                <div class="ann-title"><?= e($ds['title']) ?></div>
                                <div class="ann-tags">
                                    <span class="badge badge-gold"><?= e(strtoupper(str_replace('_', ' ', (string)$ds['aid_type']))) ?></span>
                                    <?php if ($ds['status'] === 'completed'): ?><span class="badge badge-success">Finished</span><?php else: ?><span class="badge badge-navy">Ongoing</span><?php endif; ?>
                                    <?php if ($isNotReleased): ?><span class="badge badge-red">Not released</span><?php else: ?><span class="badge badge-gold">Eligible</span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="ann-body">
                            <?php if ($ds['schedule_date'] || $ds['schedule_time']): ?><div class="ann-row"><div class="ann-row-k">Schedule</div><div class="ann-row-v"><?= $ds['schedule_date'] ? e(date('M d, Y', strtotime($ds['schedule_date']))) : '' ?><?= ($ds['schedule_date'] && $ds['schedule_time']) ? ' • ' : '' ?><?= $ds['schedule_time'] ? e(formatTime12($ds['schedule_time'])) : '' ?></div></div><?php endif; ?>
                            <?php if ($ds['venue']): ?><div class="ann-row"><div class="ann-row-k">Venue</div><div class="ann-row-v"><?= e($ds['venue']) ?></div></div><?php endif; ?>
                            <?php if ($ds['what_to_bring']): ?><div class="ann-row"><div class="ann-row-k">What to bring</div><div class="ann-row-v"><?= nl2br(e($ds['what_to_bring'])) ?></div></div><?php endif; ?>
                            <?php if ($ds['note']): ?><div class="ann-row"><div class="ann-row-k">Note</div><div class="ann-row-v"><?= nl2br(e($ds['note'])) ?></div></div><?php endif; ?>
                            <?php if ($isNotReleased && $ds['release_note']): ?><div class="ann-row"><div class="ann-row-k">Reason</div><div class="ann-row-v"><?= e($ds['release_note']) ?></div></div><?php endif; ?>
                        </div>
                        <div class="ann-foot">
                            <?php if ($isNotReleased): ?>
                                <span class="text-muted">Not released for you.</span>
                            <?php else: ?>
                                <span style="color:var(--navy);font-weight:600;"><?= svgIcon('info', 14) ?> You can receive this ayuda. Please follow the schedule and bring the requirements.</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section-title" style="margin:6px 16px 8px;">Recently Claimed</div>
        <div class="card" style="margin:0 16px 14px;">
            <?php if ($status !== 'approved'): ?>
                <div class="empty-state" style="padding:18px 10px;"><div class="es-icon"><?= svgIcon('box', 24) ?></div>Claimed ayuda will appear here once approved.</div>
            <?php elseif (!$myDistributionsClaimed && !$history): ?>
                <div class="empty-state" style="padding:18px 10px;"><div class="es-icon"><?= svgIcon('box', 24) ?></div>No claimed ayuda yet.</div>
            <?php else: ?>
                <?php foreach (array_slice($myDistributionsClaimed, 0, 5) as $ds): ?>
                    <?php $claimedAt = $ds['claimed_at'] ?? $ds['release_at'] ?? null; ?>
                    <div class="list-item">
                        <div>
                            <div class="li-name"><?= e($ds['title']) ?></div>
                            <div class="li-sub"><?= e(strtoupper(str_replace('_', ' ', (string)$ds['aid_type']))) ?><?= $ds['schedule_date'] ? ' • ' . e(date('M d, Y', strtotime($ds['schedule_date']))) : '' ?><?= $ds['schedule_time'] ? ' • ' . e(formatTime12($ds['schedule_time'])) : '' ?></div>
                        </div>
                        <span class="badge badge-success">Claimed</span>
                    </div>
                <?php endforeach; ?>
                <?php foreach (array_slice($history, 0, max(0, 5 - min(5, count($myDistributionsClaimed)))) as $h): ?>
                    <div class="list-item">
                        <div>
                            <div class="li-name"><?= e($h['item_name']) ?></div>
                            <div class="li-sub"><?= ($h['assistance_type'] === 'cash' && (float)$h['amount'] > 0) ? '&#8369;' . number_format((float)$h['amount'], 2) : 'Qty: ' . (int)$h['quantity'] ?></div>
                        </div>
                        <span class="small text-muted"><?= date('M d, Y', strtotime($h['distributed_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php
$benNavActive = 'dashboard';
$benHasUnread = $hasUnseenUpdates;
include __DIR__ . '/_bottomnav.php';
?>
</body>
</html>
