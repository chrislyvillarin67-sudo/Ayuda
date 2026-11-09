<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = 'Monitoring';
$activePage = 'monitoring';
$isAdmin = $_SESSION['role'] === 'district_admin';
$barangayId = $_SESSION['barangay_id'] ?? null;

// ---- Timeline: real distribution events (matches the dashboard's
//      "Recent Activity" so "View all" shows the same kind of data) ----
$where = [];
$params = [];
if (!$isAdmin) { $where[] = 'dl.barangay_id = ?'; $params[] = $barangayId; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT dl.distributed_at, dl.inventory_id, dl.barangay_id, i.item_name,
           b.name AS barangay_name, d.name AS district_name,
           COUNT(dl.id) AS recipient_count,
           MAX(dl.quantity) AS qty_per,
           COALESCE(SUM(CASE WHEN dl.assistance_type='cash' THEN dl.amount ELSE 0 END),0) AS total_amt
    FROM distribution_logs dl
    JOIN inventory i ON i.id = dl.inventory_id
    JOIN barangays b ON b.id = dl.barangay_id
    JOIN districts d ON d.id = b.district_id
    $whereSql
    GROUP BY dl.distributed_at, dl.inventory_id, dl.barangay_id, i.item_name, b.name, d.name
    ORDER BY dl.distributed_at DESC LIMIT 15
");
$stmt->execute($params);
$recent = $stmt->fetchAll();

// Beneficiary details per event, so tapping an event shows the recipients
// ("who received this ayuda") right below the row.
$recentEvents = [];
$memPrep = $pdo->prepare("
    SELECT b.id, b.full_name, b.category,
           CASE WHEN dl.assistance_type='cash' THEN dl.amount ELSE dl.quantity END AS received,
           dl.assistance_type
    FROM distribution_logs dl
    JOIN beneficiaries b ON b.id = dl.beneficiary_id
    WHERE dl.barangay_id = ? AND dl.inventory_id = ? AND dl.distributed_at = ? AND dl.beneficiary_id IS NOT NULL
    ORDER BY b.full_name
");
foreach ($recent as $ev) {
    $rkey = date('YmdHis', strtotime($ev['distributed_at'])) . '|' . (int)$ev['barangay_id'] . '|' . (int)$ev['inventory_id'];
    $memPrep->execute([(int)$ev['barangay_id'], (int)$ev['inventory_id'], $ev['distributed_at']]);
    $memRows = $memPrep->fetchAll();
    // Show every category each recipient belongs to, not only the primary.
    $mCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($r) => (int)$r['id'], $memRows));
    foreach ($memRows as &$mr) { $mr['categories'] = $mCatMap[(int)$mr['id']] ?? [(string)$mr['category']]; }
    unset($mr);
    $recentEvents[$rkey] = [
        'place' => $isAdmin ? $ev['district_name'] . ' / ' . $ev['barangay_name'] : $ev['barangay_name'],
        'when'  => date('F j, Y g:i A', strtotime($ev['distributed_at'])),
        'members' => $memRows,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitoring - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
            <?php include __DIR__ . '/includes/flash.php'; ?>

            <div class="section-title" style="margin-bottom:10px;">Recent Activity Timeline
                <a href="javascript:history.back()" class="btn-link">Back</a>
            </div>
            <div class="card">
                <?php foreach ($recent as $r): ?>
                    <?php $rkey = date('YmdHis', strtotime($r['distributed_at'])) . '|' . (int)$r['barangay_id'] . '|' . (int)$r['inventory_id']; ?>
                    <?php $rcount = count($recentEvents[$rkey]['members'] ?? []); $ev = $recentEvents[$rkey] ?? null; ?>
                    <div class="list-item clickable" onclick="toggleRecp(this)">
                        <div>
                            <div class="li-name"><?= e($r['item_name']) ?><?= (float)$r['total_amt'] > 0 ? ' &mdash; &#8369;' . number_format((float)$r['total_amt'], 2) : ' &times; ' . (int)$r['qty_per'] . ' each' ?></div>
                            <div class="li-sub"><?= $isAdmin ? e($r['district_name']) . ' &bull; ' . e($r['barangay_name']) : e($r['barangay_name']) ?></div>
                        </div>
                        <span class="small text-muted" style="white-space:nowrap;"><?= timeAgo($r['distributed_at']) ?></span>
                    </div>
                    <div class="recp-drop" style="display:none;">
                        <?php if ($ev): ?>
                            <div class="small text-muted" style="padding:10px 16px;border-bottom:1px solid var(--line);">
                                <?= svgIcon('pin', 14) ?> <?= e($ev['place']) ?> &bull; <?= svgIcon('calendar', 14) ?> <?= e($ev['when']) ?>
                            </div>
                            <?php if ($ev['members']): ?>
                                <?php foreach ($ev['members'] as $m): $got = $m['assistance_type'] === 'cash' ? '&#8369;' . number_format((float)$m['received'], 2) : (int)$m['received'] . ' unit(s)'; ?>
                                    <div class="recp-person">
                                        <div style="flex:1;min-width:0;">
                                            <div style="color:#5f6b7a;font-size:14px;"><?= e(formatName($m['full_name'])) ?></div>
                                            <div class="small text-muted"><?= e(implode(', ', $m['categories'] ?? [(string)$m['category']])) ?></div>
                                        </div>
                                        <span class="small" style="white-space:nowrap;color:#5f6b7a;">Received: <?= $got ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="small text-muted" style="padding:10px 16px;">No named recipients recorded for this distribution.</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recent): ?><div class="empty-state">No distribution activity yet.</div><?php endif; ?>
            </div>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<script>
function toggleRecp(row) {
    var drop = row.nextElementSibling;
    if (drop && drop.classList.contains('recp-drop')) {
        var isOpen = drop.style.display === 'block';
        drop.style.display = isOpen ? 'none' : 'block';
        row.classList.toggle('expanded', !isOpen);
    }
}
</script>
</body>
</html>