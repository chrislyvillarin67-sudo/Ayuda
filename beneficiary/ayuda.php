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
if (!$beneficiary) { unset($_SESSION['beneficiary_id']); redirect('../login.php'); }

$benCategories = beneficiaryCategories($pdo, (int)$beneficiary['id']);
$status = $beneficiary['status'] ?: 'pending';

$histStmt = $pdo->prepare("
    SELECT dl.distributed_at, dl.quantity, dl.amount, dl.assistance_type, i.item_name
    FROM distribution_logs dl JOIN inventory i ON i.id = dl.inventory_id
    WHERE dl.beneficiary_id = ? ORDER BY dl.distributed_at DESC
");
$histStmt->execute([$beneficiary['id']]);
$history = $histStmt->fetchAll();

$cash = [];
$goods = [];
foreach ($history as $h) {
    if (($h['assistance_type'] === 'cash' && (float)$h['amount'] > 0)) {
        $cash[] = $h;
    } else {
        $goods[] = $h;
    }
}

$hasUnseenUpdates = beneficiaryHasUnseenUpdates($pdo, $beneficiary);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Ayuda - Ayuda Monitoring System</title>
<link rel="stylesheet" href="../assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell">
    <div class="app-header">
        <span style="width:20px;"></span>
        <div style="text-align:center;">
            <h1>My Ayuda</h1>
            <div class="header-sub"><?= e($beneficiary['barangay_name']) ?></div>
        </div>
        <a href="map.php" class="icon-btn" title="Announcements"><?= svgIcon('bell', 20) ?><?php if ($hasUnseenUpdates): ?><span class="notif-dot"></span><?php endif; ?></a>
    </div>

    <div class="content" style="padding-bottom:90px;">
        <div class="card">
            <div style="font-weight:700;color:var(--navy);margin-bottom:6px;">Eligibility</div>
            <div class="small text-muted">Categories: <?= e(implode(', ', $benCategories ?: [(string)$beneficiary['category']])) ?></div>
            <div class="small text-muted mt-1">Status: <?= ucfirst(e(statusLabel($status))) ?></div>
        </div>

        <div class="tabs" style="margin-top:10px;">
            <button class="tab-btn active" data-tab="ay-cash">Cash</button>
            <button class="tab-btn" data-tab="ay-goods">Food Packs / Relief Goods</button>
            <button class="tab-btn" data-tab="ay-all">All</button>
        </div>

        <div class="tab-pane active" id="tab-ay-cash">
            <?php if ($status !== 'approved'): ?>
                <div class="card empty-state"><div class="es-icon"><?= svgIcon('box', 26) ?></div>Available once approved.</div>
            <?php elseif (!$cash): ?>
                <div class="card empty-state"><div class="es-icon"><?= svgIcon('box', 26) ?></div>No cash assistance received yet.</div>
            <?php else: ?>
                <div class="card">
                    <?php foreach ($cash as $h): ?>
                        <div class="list-item">
                            <div>
                                <div class="li-name"><?= e($h['item_name']) ?></div>
                                <div class="li-sub">&#8369; <?= number_format((float)$h['amount'], 2) ?></div>
                            </div>
                            <span class="small text-muted"><?= timeAgo($h['distributed_at']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane" id="tab-ay-goods">
            <?php if ($status !== 'approved'): ?>
                <div class="card empty-state"><div class="es-icon"><?= svgIcon('box', 26) ?></div>Available once approved.</div>
            <?php elseif (!$goods): ?>
                <div class="card empty-state"><div class="es-icon"><?= svgIcon('box', 26) ?></div>No food packs/relief goods received yet.</div>
            <?php else: ?>
                <div class="card">
                    <?php foreach ($goods as $h): ?>
                        <div class="list-item">
                            <div>
                                <div class="li-name"><?= e($h['item_name']) ?></div>
                                <div class="li-sub">Qty: <?= (int)$h['quantity'] ?></div>
                            </div>
                            <span class="small text-muted"><?= timeAgo($h['distributed_at']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane" id="tab-ay-all">
            <?php if ($status !== 'approved'): ?>
                <div class="card empty-state"><div class="es-icon"><?= svgIcon('box', 26) ?></div>Available once approved.</div>
            <?php elseif (!$history): ?>
                <div class="card empty-state"><div class="es-icon"><?= svgIcon('box', 26) ?></div>No ayuda received yet.</div>
            <?php else: ?>
                <div class="card">
                    <?php foreach ($history as $h): ?>
                        <div class="list-item">
                            <div>
                                <div class="li-name"><?= e($h['item_name']) ?></div>
                                <div class="li-sub"><?= $h['assistance_type'] === 'cash' && (float)$h['amount'] > 0 ? '&#8369;' . number_format((float)$h['amount'], 2) : 'Qty: ' . (int)$h['quantity'] ?></div>
                            </div>
                            <span class="small text-muted"><?= timeAgo($h['distributed_at']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="alert alert-info mt-2"><?= svgIcon('info', 14) ?> Bring a valid ID and barangay clearance when claiming ayuda.</div>
    </div>
</div>

<nav class="bottom-nav" style="position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #e5e9f2;display:flex;justify-content:space-around;padding:6px 0;max-width:100%;">
    <a href="dashboard.php" style="flex:1;text-align:center;text-decoration:none;color:#6b7d90;font-size:12px;">
        <div><?= svgIcon('user', 22) ?></div>
        <div>Profile</div>
    </a>
    <a href="ayuda.php" class="active" style="flex:1;text-align:center;text-decoration:none;color:var(--navy);font-size:12px;">
        <div><?= svgIcon('gift', 22) ?></div>
        <div>Ayuda</div>
    </a>
    <a href="map.php" style="flex:1;text-align:center;text-decoration:none;color:#6b7d90;font-size:12px;position:relative;">
        <div><?= svgIcon('bell', 22) ?></div>
        <div>Announcements</div>
        <?php if ($hasUnseenUpdates): ?><span style="position:absolute;top:2px;right:35%;width:8px;height:8px;background:#d33;border-radius:50%;"></span><?php endif; ?>
    </a>
    <a href="../logout.php" style="flex:1;text-align:center;text-decoration:none;color:#6b7d90;font-size:12px;">
        <div><?= svgIcon('logout', 22) ?></div>
        <div>Logout</div>
    </a>
</nav>

<script>
document.querySelectorAll('.tab-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
        document.querySelectorAll('.tab-pane').forEach(function(p){ p.classList.remove('active'); });
        btn.classList.add('active');
        var id = btn.getAttribute('data-tab').replace('ay-', 'tab-ay-');
        var pane = document.getElementById(id);
        if (pane) pane.classList.add('active');
    });
});
</script>
<?php include __DIR__ . '/../includes/chatbot_widget.php'; ?>
</body>
</html>
