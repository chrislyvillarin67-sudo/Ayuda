<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$isAdmin = $_SESSION['role'] === 'district_admin';
$barangayId = $_SESSION['barangay_id'] ?? null;
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$csrfOk = validateCSRF();

// ---- Summary numbers ----
if ($isAdmin) {
    $totalBeneficiaries = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries")->fetchColumn();
    $approvedBeneficiaries = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE status='approved'")->fetchColumn();
    $rejectedBeneficiaries = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE status IN ('rejected','returned')")->fetchColumn();
    $totalDistributed   = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM distribution_logs")->fetchColumn();
    $totalCashDistributed = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM distribution_logs WHERE assistance_type='cash'")->fetchColumn();
    $pendingRequests    = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE status IN ('pending','returned')")->fetchColumn();
    $pendingBrgyReq     = (int)$pdo->query("SELECT COUNT(*) FROM barangay_requests WHERE status='pending'")->fetchColumn();
    $urgentReports      = (int)$pdo->query("SELECT COUNT(*) FROM barangay_requests WHERE status='pending' AND severity='high'")->fetchColumn();
    // Stock snapshot for the main aid cards (general stock)
    $foodStock    = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE assistance_type='food_pack'")->fetchColumn();
    $hygieneStock = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE item_name LIKE '%Hygiene%'")->fetchColumn();
    $foodDistributed    = (float)$pdo->query("SELECT COALESCE(SUM(dl.quantity),0) FROM distribution_logs dl JOIN inventory i ON i.id=dl.inventory_id WHERE i.assistance_type='food_pack'")->fetchColumn();
    $hygieneDistributed = (float)$pdo->query("SELECT COALESCE(SUM(dl.quantity),0) FROM distribution_logs dl JOIN inventory i ON i.id=dl.inventory_id WHERE i.item_name LIKE '%Hygiene%'")->fetchColumn();
    // Total cash budget & total cash remaining (citywide)
    // NOTE: inventory.quantity is ALREADY net -- distribution.php / distribute_action.php
    // decrement it when money is released. Subtracting the distributed total again here
    // double-counted every payout and drove the card deeply negative.
    $cashBudget = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE assistance_type='cash'")->fetchColumn();
    $cashRemaining = $cashBudget;

    // Dynamic home aid cards: every aid type that has inventory gets a clickable
    // card (Food Pack / Cash / Hygiene Kit + any program-created groups like a
    // "First Aid Kit"), matching the Inventory overview so created programs show
    // up here automatically.
    $homeCards = [];
    $homeDefs = [
        ['aidtype' => 'food_pack', 'label' => 'Food Pack', 'icon' => 'box', 'theme' => 'green',
         'w' => "i.assistance_type='food_pack'", 'dw' => "i3.assistance_type='food_pack'", 'href' => 'inventory.php?tab=food_pack'],
        ['aidtype' => 'cash', 'label' => 'Cash', 'icon' => 'dollar', 'theme' => 'gold',
         'w' => "i.assistance_type='cash'", 'dw' => "i3.assistance_type='cash'", 'href' => 'inventory.php?tab=cash'],
        ['aidtype' => 'hygiene', 'label' => 'Hygiene Kit', 'icon' => 'heart', 'theme' => 'navy',
         'w' => "i.assistance_type='relief_goods' AND i.item_name LIKE '%Hygiene%'", 'dw' => "i3.item_name LIKE '%Hygiene%'", 'href' => 'inventory.php?tab=hygiene'],
    ];
    foreach ($homeDefs as $md) {
        $q = $pdo->prepare("SELECT GROUP_CONCAT(DISTINCT i.item_name ORDER BY i.item_name SEPARATOR '||') AS kinds,
                                   COUNT(DISTINCT i.item_name) AS kind_count,
                                   SUM(i.quantity) AS total_qty,
                                   COALESCE((SELECT SUM(CASE WHEN i3.assistance_type='cash' THEN dl.amount ELSE dl.quantity END)
                                             FROM distribution_logs dl JOIN inventory i3 ON i3.id=dl.inventory_id
                                             WHERE {$md['dw']}),0) AS total_distributed
                            FROM inventory i WHERE {$md['w']}");
        $q->execute();
        $row = $q->fetch() ?: ['kinds' => '', 'kind_count' => 0, 'total_qty' => 0, 'total_distributed' => 0];
        $homeCards[] = $md + $row;
    }
    $othersHome = $pdo->query("
        SELECT i.assistance_type,
               GROUP_CONCAT(DISTINCT i.item_name ORDER BY i.item_name SEPARATOR '||') AS kinds,
               COUNT(DISTINCT i.item_name) AS kind_count,
               SUM(i.quantity) AS total_qty,
               COALESCE((SELECT SUM(CASE WHEN i3.assistance_type='cash' THEN dl.amount ELSE dl.quantity END)
                         FROM distribution_logs dl JOIN inventory i3 ON i3.id=dl.inventory_id
                         WHERE i3.assistance_type=i.assistance_type),0) AS total_distributed,
               CASE i.assistance_type WHEN 'relief_goods' THEN 'Relief Goods'
                                      WHEN 'sponsored' THEN 'Sponsored'
                                      WHEN 'other' THEN 'Other' END AS label
        FROM inventory i
        WHERE NOT (i.assistance_type IN ('food_pack','cash'))
          AND NOT (i.assistance_type='relief_goods' AND i.item_name LIKE '%Hygiene%')
        GROUP BY i.assistance_type
        ORDER BY FIELD(i.assistance_type,'relief_goods','sponsored','other')
    ")->fetchAll();
    $otherThemes = ['teal', 'purple', 'red'];
    $oi = 0;
    foreach ($othersHome as $oh) {
        $homeCards[] = $oh + [
            'icon' => 'box',
            'theme' => $otherThemes[$oi % 3],
            'href' => 'inventory.php?aidtype=' . urlencode($oh['assistance_type']),
        ];
        $oi++;
    }

    // Distribution programs: completion % + pending distributions + upcoming schedules
    $programStats = $pdo->query("
        SELECT COUNT(*) programs,
               COALESCE(SUM(CASE WHEN status IN ('scheduled','ongoing') THEN 1 ELSE 0 END),0) pending_programs,
               COALESCE(SUM(a.total),0) total_assigned,
               COALESCE(SUM(a.received),0) received
        FROM distribution_programs p
        LEFT JOIN (SELECT program_id, COUNT(*) total, SUM(received) received
                   FROM distribution_program_assignments GROUP BY program_id) a ON a.program_id = p.id
    ")->fetch();
    $totalProgramAssigned = (int)$programStats['total_assigned'];
    $programReceived = (int)$programStats['received'];
    $pendingDistributions = (int)$programStats['pending_programs'];
    $completionPct = $totalProgramAssigned > 0 ? round((($programReceived / $totalProgramAssigned) * 100), 1) : 0;

    $upcoming = $pdo->query("
        SELECT title, program_date, program_time, location, status,
               COALESCE(a.total,0) total_assigned, COALESCE(a.received,0) received
        FROM distribution_programs p
        LEFT JOIN (SELECT program_id, COUNT(*) total, SUM(received) received
                   FROM distribution_program_assignments GROUP BY program_id) a ON a.program_id = p.id
        WHERE p.program_date >= CURDATE() AND p.status IN ('scheduled','ongoing')
        ORDER BY p.program_date ASC LIMIT 5
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries WHERE barangay_id=?");
    $stmt->execute([$barangayId]); $totalBeneficiaries = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries WHERE barangay_id=? AND status='approved'");
    $stmt->execute([$barangayId]); $approvedBeneficiaries = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(dl.quantity),0) FROM distribution_logs dl WHERE dl.barangay_id=?");
    $stmt->execute([$barangayId]); $totalDistributed = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(dl.amount),0) FROM distribution_logs dl WHERE dl.barangay_id=? AND dl.assistance_type='cash'");
    $stmt->execute([$barangayId]); $totalCashDistributed = (float)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries WHERE barangay_id=? AND status='pending'");
    $stmt->execute([$barangayId]); $pendingRequests = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM barangay_requests WHERE barangay_id=? AND status='pending'");
    $stmt->execute([$barangayId]); $pendingBrgyReq = (int)$stmt->fetchColumn();
    // Cash is one shared citywide pool (a single inventory row), so every
    // barangay and every card reads the exact same balance.
    $cashBudget    = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE assistance_type='cash'")->fetchColumn();
    $cashRemaining = $cashBudget;
}

// ---- Beneficiary Breakdown by District (all districts, live counts) ----
$districtData = [];
$districtBreakdown = [];
$pieTotal = 0;
if ($isAdmin) {
    $districtData = $pdo->query("SELECT d.name, COUNT(b.id) cnt FROM districts d LEFT JOIN barangays br ON br.district_id=d.id LEFT JOIN beneficiaries b ON b.barangay_id=br.id GROUP BY d.id, d.name ORDER BY cnt DESC LIMIT 3")->fetchAll();
}
$pieLabels = []; $pieValues = []; $pieColors = [];
$palette = ['#0B2E4A','#F4B400','#34A853','#DB4437','#4285F4','#A66A00','#0F7B37','#7B1FA2','#E91E63','#00ACC1','#5D4037'];
foreach ($districtData as $i => $dd) {
    $pieTotal += (int)$dd['cnt'];
    $pieLabels[] = $dd['name'];
    $pieValues[] = (int)$dd['cnt'];
    $pieColors[] = $palette[$i % count($palette)];
}
foreach ($districtData as $dd) {
    $pct = $pieTotal > 0 ? round(((int)$dd['cnt'] / $pieTotal) * 100, 1) : 0;
    $districtBreakdown[] = ['name' => $dd['name'], 'cnt' => (int)$dd['cnt'], 'pct' => $pct];
}

// ---- Recent activity: distribution events grouped per distribution run ----
if ($isAdmin) {
    $recent = $pdo->query("
        SELECT dl.distributed_at, dl.inventory_id, dl.barangay_id, i.item_name,
               b.name AS barangay_name, d.name AS district_name,
               COUNT(dl.id) AS recipient_count,
               MAX(dl.quantity) AS qty_per,
               COALESCE(SUM(CASE WHEN dl.assistance_type='cash' THEN dl.amount ELSE 0 END),0) AS total_amt
        FROM distribution_logs dl
        JOIN inventory i ON i.id = dl.inventory_id
        JOIN barangays b ON b.id = dl.barangay_id
        JOIN districts d ON d.id = b.district_id
        GROUP BY dl.distributed_at, dl.inventory_id, dl.barangay_id, i.item_name, b.name, d.name
        ORDER BY dl.distributed_at DESC LIMIT 8
    ")->fetchAll();
} else {
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
        WHERE dl.barangay_id = ?
        GROUP BY dl.distributed_at, dl.inventory_id, dl.barangay_id, i.item_name, b.name, d.name
        ORDER BY dl.distributed_at DESC LIMIT 8
    ");
    $stmt->execute([$barangayId]);
    $recent = $stmt->fetchAll();
}

// Beneficiary details per event, so clicking a recent activity opens the
// recipient list ("who received this ayuda").
// ---- Upcoming distribution for staff's barangay ----
$stmtNext = $pdo->prepare("
    SELECT p.title, p.assistance_type, p.program_date, p.program_time, p.location, p.status, b.name AS barangay_name
    FROM distribution_programs p
    LEFT JOIN barangays b ON b.id = p.barangay_id
    WHERE p.program_date >= CURDATE() AND p.status IN ('scheduled','ongoing')
      AND (p.barangay_id IS NULL OR p.barangay_id = ?)
    ORDER BY p.program_date ASC, p.program_time ASC
    LIMIT 1
");
$stmtNext->execute([$barangayId]); $nextDist = $stmtNext->fetch();

// ---- Active announcements (latest 5) ----
$stmtAnn = $pdo->prepare("
    SELECT a.title, a.priority, a.created_at, a.barangay_id, b.name AS barangay_name
    FROM announcements a
    LEFT JOIN barangays b ON b.id = a.barangay_id
    WHERE (a.barangay_id IS NULL OR a.barangay_id = ?)
    ORDER BY a.created_at DESC
    LIMIT 5
");
$stmtAnn->execute([$barangayId]); $announcements = $stmtAnn->fetchAll();

// ---- Request counts ----
$reqPending = (int)$pendingRequests; // already computed
$stmtA = $pdo->prepare("SELECT COUNT(*) FROM barangay_requests WHERE barangay_id=? AND status='approved'");
$stmtA->execute([$barangayId]); $reqApproved = (int)$stmtA->fetchColumn();
$stmtR = $pdo->prepare("SELECT COUNT(*) FROM barangay_requests WHERE barangay_id=? AND status='declined'");
$stmtR->execute([$barangayId]); $reqDeclined = (int)$stmtR->fetchColumn();

$recentEvents = [];
$memPrep = $pdo->prepare("
    SELECT b.full_name, b.age, b.category,
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
    $recentEvents[$rkey] = [
        'title' => $ev['item_name'],
        'place' => $isAdmin ? $ev['district_name'] . ' / ' . $ev['barangay_name'] : $ev['barangay_name'],
        'when'  => date('F j, Y g:i A', strtotime($ev['distributed_at'])),
        'members' => $memPrep->fetchAll(),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20260930d">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
            <?php include __DIR__ . '/includes/flash.php'; ?>

            <?php if ($isAdmin && $urgentReports): ?>
                <div class="alert alert-danger" style="font-size:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <span><?= svgIcon('alert', 16) ?> <strong><?= $urgentReports ?> urgent emergency report(s)</strong> from barangay staff. Immediate action needed.</span>
                    <a href="barangay_requests.php" class="btn btn-danger btn-sm" style="background:var(--navy);color:#fff;text-decoration:none;">Respond now &rarr;</a>
                </div>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
            <div class="card welcome-card">
                <span class="welcome-emoji ic-svg"><?= svgIcon('heart', 26) ?></span>
                <div>
                    <div style="font-size:16px;font-weight:700;color:var(--navy);">Welcome, Admin!</div>
                    <div class="small" style="margin-top:4px;">Every Food Pack, Cash Aid, and Hygiene Kit you prepare brings relief to families in need. Salamat sa inyong pagserbisyo!</div>
                </div>
            </div>
            <div class="grid-3">
                <?php foreach ($homeCards as $hc): $hcCash = $hc['aidtype'] === 'cash'; ?>
                    <a href="<?= e($hc['href']) ?>" class="stat-card stat-<?= e($hc['theme']) ?>" style="display:block;text-decoration:none;">
                        <span class="stat-icon"><?= svgIcon($hc['icon'], 24) ?></span>
                        <div class="stat-label"><?= e(strtoupper($hc['label'])) ?></div>
                        <div class="stat-num"><?= $hcCash ? '&#8369;' . number_format((float)$hc['total_qty'], 2) : number_format((float)$hc['total_qty']) . ' units' ?></div>
                        <div class="stat-sub">Distributed: <?= $hcCash ? '&#8369;' . number_format((float)$hc['total_distributed'], 2) : number_format((float)$hc['total_distributed']) . ' units' ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
                <?php else: ?>
            <div class="card welcome-card">
                <span class="welcome-emoji ic-svg"><?= svgIcon('heart', 26) ?></span>
                <div>
                    <div style="font-size:16px;font-weight:700;color:var(--navy);">Welcome, <?= e($_SESSION['full_name'] ?? 'Staff') ?>!</div>
                    <div class="small" style="margin-top:4px;">Follow the updates, respond to ayuda requests, and relay the latest announcements to your barangay. Salamat sa inyong pagserbisyo!</div>
                </div>
            </div>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin && $pieLabels): ?>
            <div class="section-title">Beneficiary Breakdown by District</div>
            <div class="card">
                <p class="small text-muted">Share of registered beneficiaries across the top three districts.</p>
                <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;justify-content:center;">
                    <div style="flex:0 0 auto;width:190px;">
                        <canvas id="districtPieChart" style="max-height:200px;width:100%;"></canvas>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:12px;justify-content:center;align-self:center;padding:6px 4px;">
                        <?php foreach ($districtBreakdown as $i => $db): ?>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="width:18px;height:11px;border-radius:3px;background:<?= $pieColors[$i] ?>;flex-shrink:0;"></span>
                                <span style="font-size:13px;"><?= e($db['name']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
            <div class="section-title">Ayuda Requests</div>
            <div class="quick-actions mb-3" style="grid-template-columns:repeat(3,1fr);">
                <div class="qa-stat"><span class="qa-icon stat-icon-pend"><?= svgIcon('alert', 22) ?></span><span class="qa-stat-num"><?= $reqPending ?></span><span class="qa-stat-lbl">Pending</span></div>
                <div class="qa-stat"><span class="qa-icon"><?= svgIcon('check', 22) ?></span><span class="qa-stat-num"><?= $reqApproved ?></span><span class="qa-stat-lbl">Approved</span></div>
                <div class="qa-stat"><span class="qa-icon"><?= svgIcon('x', 22) ?></span><span class="qa-stat-num"><?= $reqDeclined ?></span><span class="qa-stat-lbl">Rejected</span></div>
            </div>
            <style>
            .qa-stat { background:#fff; border:1px solid var(--line); border-radius:12px; padding:12px 8px;
                display:flex; flex-direction:column; align-items:center; gap:4px; text-align:center; }
            .qa-stat-num { font-size:20px; font-weight:800; color:var(--navy); }
            .qa-stat-lbl { font-size:11px; color:var(--text-muted); text-transform:capitalize; }
            </style>

            <div class="card">
                <?php if (!$announcements): ?>
                    <div class="empty-state"><div class="es-icon"><?= svgIcon('bell', 28) ?></div>No announcements yet.</div>
                <?php endif; ?>
                <?php foreach ($announcements as $an): ?>
                    <div class="list-item">
                        <div style="flex:1;min-width:0;">
                            <div class="li-name">
                                <?= e($an['title']) ?><?php if ($an['priority'] === 'high'): ?><span class="badge badge-red" style="margin-left:6px;">High</span><?php endif; ?>
                            </div>
                            <div class="li-sub"><?= $an['barangay_id'] ? e('Barangay ' . $an['barangay_name']) : 'Citywide' ?> &middot; <?= timeAgo($an['created_at']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="section-title">
                Recent Activity
                <a href="monitoring.php" class="btn-link">View all &rarr;</a>
            </div>
            <?php if (!$isAdmin): ?>
                <div class="alert alert-info" style="font-size:12.5px;margin-top:0;">
                    <?= svgIcon('info', 14) ?> Distributions shown here are carried out by the CSWDO / District Admin for your barangay. As Barangay Staff, you assist on-site (announce venue, post the final list, and report any need/emergency).
                </div>
            <?php endif; ?>
            <div class="card">
                <?php if (!$recent): ?>
                    <div class="empty-state"><div class="es-icon"><?= svgIcon('list', 30) ?></div>No recent distribution activity.</div>
                <?php endif; ?>
                <?php foreach ($recent as $r): ?>
                    <?php $rkey = date('YmdHis', strtotime($r['distributed_at'])) . '|' . (int)$r['barangay_id'] . '|' . (int)$r['inventory_id']; ?>
                    <?php $rcount = count($recentEvents[$rkey]['members'] ?? []); $ev = $recentEvents[$rkey] ?? null; ?>
                    <div class="list-item clickable" onclick="toggleRecp(this)">
                        <div>
                            <div class="li-name">
                                <?= e($r['item_name']) ?><?= (float)$r['total_amt'] > 0 ? ' &mdash; &#8369;' . number_format((float)$r['total_amt'], 2) : ' &times; ' . (int)$r['qty_per'] . ' each' ?>
                                <span class="badge badge-navy" style="margin-left:6px;"><?= $rcount ?> recipient<?= $rcount === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="li-sub">
                                <?= $isAdmin ? e($r['district_name']) . ' &bull; ' . e($r['barangay_name']) : e($r['barangay_name']) ?>
                            </div>
                        </div>
                        <span class="small text-muted" style="white-space:nowrap;"><?= timeAgo($r['distributed_at']) ?> &rsaquo;</span>
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
                                            <div class="small text-muted"><?= e($m['category']) ?></div>
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

<script>
<?php if ($isAdmin && $pieLabels): ?>
const pieCtx = document.getElementById('districtPieChart');
const pctPlugin = {
    id: 'pctLabels',
    afterDatasetsDraw(chart) {
        const arcs = chart.getDatasetMeta(0).data;
        const total = chart.data.datasets[0].data.reduce(function (s, v) { return s + v; }, 0);
        const ctx = chart.ctx;
        chart.data.datasets[0].data.forEach(function (value, i) {
            const arc = arcs[i];
            const pct = total > 0 ? (value / total) * 100 : 0;
            if (pct < 6) return;
            const angle = (arc.startAngle + arc.endAngle) / 2;
            const r = arc.outerRadius * 0.62;
            const x = arc.x + Math.cos(angle) * r;
            const y = arc.y + Math.sin(angle) * r;
            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.shadowColor = 'rgba(0,0,0,0.25)';
            ctx.shadowBlur = 3;
            ctx.fillText(pct.toFixed(0) + '%', x, y);
            ctx.restore();
        });
    }
};
new Chart(pieCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode($pieLabels) ?>,
        datasets: [{
            data: <?= json_encode($pieValues) ?>,
            backgroundColor: <?= json_encode($pieColors) ?>,
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function (ctx) {
                        var total = <?= (int)$pieTotal ?>;
                        var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                        return ctx.label + ': ' + ctx.parsed + ' beneficiary(ies) (' + pct + '%)';
                    }
                }
            }
        }
    },
    plugins: [pctPlugin]
});
<?php endif; ?>
</script>
</body>
</html>
