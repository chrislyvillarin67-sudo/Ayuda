<?php
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
require_once __DIR__ . '/includes/functions.php';
requireStaff('district_admin'); // Report generation: District Admin only (Barangay Staff only assist).

$pageTitle = 'Reports';
$activePage = 'reports';
$isAdmin = $_SESSION['role'] === 'district_admin';
$myBarangayId = $_SESSION['barangay_id'] ?? null;

// ---- Filters (GET) ----
$reportType = $_GET['type'] ?? 'distribution';
$category   = $_GET['category'] ?? 'All';
$ayudaType  = $_GET['ayuda_type'] ?? 'All';
$dateFrom   = $_GET['date_from'] ?? '';
    $dateTo     = $_GET['date_to'] ?? '';
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp;
    }
$districtId = (int)($_GET['district_id'] ?? 0);
$barangayId = (int)($_GET['barangay_id'] ?? 0);
$generate   = isset($_GET['generate']);

$categories   = categoryFilterOptions();
$ayudaTypes   = ['All','cash','food_pack','relief_goods'];
// 'hygiene' rows predate the aid-type change and are reported as Relief Goods.
$ayudaTypeLabels = ['cash'=>'Cash','food_pack'=>'Food Packs','relief_goods'=>'Relief Goods','hygiene'=>'Relief Goods'];

$reportTypes = [
    'distribution'       => 'Distribution Report',
    'barangay_dist'      => 'Barangay Distribution Report',
    'district_dist'      => 'District Distribution Report',
    'inventory'          => 'Inventory Report',
    'duplicate'          => 'Duplicate / Verification Report',
    'summary'            => 'Distribution Summary',
];
if (!array_key_exists($reportType, $reportTypes)) $reportType = 'distribution';

$districts = getDistricts($pdo);
$barangaysForFilter = $districtId ? getBarangays($pdo, $districtId) : [];

$report = null;

if ($generate) {
    // Barangay staff are always scoped to their own barangay.
    if (!$isAdmin) { $barangayId = (int)$myBarangayId; }

    // ------- Beneficiary scope filters -------
    $bWhere = [];
    $bParams = [];
    if (!$isAdmin) { $bWhere[] = 'b.barangay_id = ?'; $bParams[] = $myBarangayId; }
    if ($districtId > 0) { $bWhere[] = 'b.district_id = ?'; $bParams[] = $districtId; }
    if ($barangayId > 0) { $bWhere[] = 'b.barangay_id = ?'; $bParams[] = $barangayId; }
    if ($category !== 'All') { [$cs, $cp] = categoryMembershipSql($category); $bWhere[] = $cs; foreach ($cp as $p) $bParams[] = $p; }
    if ($dateFrom !== '') { $bWhere[] = 'b.created_at >= ?'; $bParams[] = $dateFrom . ' 00:00:00'; }
    if ($dateTo !== '')   { $bWhere[] = 'b.created_at <= ?'; $bParams[] = $dateTo . ' 23:59:59'; }
    $bWhereSql = $bWhere ? ('WHERE ' . implode(' AND ', $bWhere)) : '';

    // ------- Distribution scope filters -------
    $dWhere = [];
    $dParams = [];
    if (!$isAdmin) { $dWhere[] = 'dl.barangay_id = ?'; $dParams[] = $myBarangayId; }
    if ($districtId > 0) { $dWhere[] = 'br.district_id = ?'; $dParams[] = $districtId; }
    if ($barangayId > 0) { $dWhere[] = 'dl.barangay_id = ?'; $dParams[] = $barangayId; }
    if ($category !== 'All') { [$cs, $cp] = categoryMembershipSql($category); $dWhere[] = $cs; foreach ($cp as $p) $dParams[] = $p; }
    if ($ayudaType !== 'All') {
        // Historical hygiene rows are reported under Relief Goods.
        if ($ayudaType === 'relief_goods') { $dWhere[] = "dl.assistance_type IN ('relief_goods','hygiene')"; }
        else { $dWhere[] = 'dl.assistance_type = ?'; $dParams[] = $ayudaType; }
    }
    if ($dateFrom !== '') { $dWhere[] = 'dl.distributed_at >= ?'; $dParams[] = $dateFrom . ' 00:00:00'; }
    if ($dateTo !== '')   { $dWhere[] = 'dl.distributed_at <= ?'; $dParams[] = $dateTo . ' 23:59:59'; }
    $dWhereSql = $dWhere ? ('WHERE ' . implode(' AND ', $dWhere)) : '';

    // Reusable distribution JOIN base (barangays br for district lookups)
    $distBase = "FROM distribution_logs dl
                 JOIN barangays br ON br.id = dl.barangay_id
                 LEFT JOIN beneficiaries b ON b.id = dl.beneficiary_id";

    $report = [];

    // ----- Beneficiary list -----
    $rows = [];
    if ($reportType === 'duplicate') {
        $listWhere = $bWhere;
        $listParams = $bParams;
        $listWhereSql = $listWhere ? ('WHERE ' . implode(' AND ', $listWhere)) : '';
        $ls = $pdo->prepare("SELECT b.id, b.beneficiary_code, b.full_name, b.first_name, b.middle_initial, b.last_name, b.category, b.address,
                                    b.status, b.verification_step, b.is_duplicate, b.approved_at, b.created_at,
                                    br.name AS barangay_name, d.name AS district_name
                             FROM beneficiaries b
                             JOIN barangays br ON br.id = b.barangay_id
                             JOIN districts d ON d.id = b.district_id
                             $listWhereSql ORDER BY b.last_name ASC, b.first_name ASC, b.middle_initial ASC, b.id ASC");
        $ls->execute($listParams);
        $rows = $ls->fetchAll();
        // Show every category each beneficiary belongs to, not only the primary.
        $rCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($r) => (int)$r['id'], $rows));
        foreach ($rows as &$rr) { $rr['categories'] = $rCatMap[(int)$rr['id']] ?? [(string)$rr['category']]; }
        unset($rr);
        $report['rows'] = $rows;
    }

    // ----- Distribution detail list -----
    $distRows = [];
    if (in_array($reportType, ['distribution','barangay_dist','district_dist'], true)) {
                    $ds = $pdo->prepare("SELECT dl.distributed_at, dl.assistance_type, dl.quantity, dl.amount, dl.location,
                                    i.item_name, i.unit,
                                    b.full_name AS beneficiary_name,
                                    br.name AS barangay_name, d.name AS district_name,
                                    u.full_name AS distributed_by_name
                             $distBase
                             LEFT JOIN inventory i ON i.id = dl.inventory_id
                             LEFT JOIN districts d ON d.id = br.district_id
                             LEFT JOIN users u ON u.id = dl.distributed_by
                             $dWhereSql ORDER BY d.name ASC, br.name ASC, FIELD(dl.assistance_type, 'food_pack','cash','hygiene','relief_goods','sponsored','other'), b.full_name ASC, dl.distributed_at DESC");
        $ds->execute($dParams);
        $distRows = $ds->fetchAll();
        $report['rows'] = $distRows;
    }

    // ----- Inventory list -----
    if ($reportType === 'inventory') {
        $invWhere = [];
        $invParams = [];
        if (!$isAdmin) { $invWhere[] = 'i.barangay_id = ?'; $invParams[] = $myBarangayId; }
        if ($barangayId > 0) { $invWhere[] = 'i.barangay_id = ?'; $invParams[] = $barangayId; }
        $invWhereSql = $invWhere ? ('WHERE ' . implode(' AND ', $invWhere)) : '';
        $is = $pdo->prepare("SELECT i.item_name, i.assistance_type, i.quantity, i.unit, i.low_stock_threshold,
                                    br.name AS barangay_name, d.name AS district_name,
                                    COALESCE((SELECT SUM(CASE WHEN i.assistance_type='cash' THEN dl2.amount ELSE dl2.quantity END) FROM distribution_logs dl2 WHERE dl2.inventory_id = i.id),0) AS distributed
                             FROM inventory i
                             LEFT JOIN barangays br ON br.id = i.barangay_id
                             LEFT JOIN districts d ON d.id = br.district_id
                             $invWhereSql ORDER BY (br.name IS NULL) DESC, br.name ASC, i.item_name ASC");
        $is->execute($invParams);
        $report['rows'] = $is->fetchAll();
    }

    // ----- Aggregations -----
    // Summary stats
    $totalBeneficiaries = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries b $bWhereSql");
    $totalBeneficiaries->execute($bParams);
    $report['total_beneficiaries'] = (int)$totalBeneficiaries->fetchColumn();

    $approxWhere = $bWhereSql ? ($bWhereSql . ' AND ') : 'WHERE ';

    $approved = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries b $approxWhere b.status='approved'");
    $approved->execute($bParams);
    $report['total_approved'] = (int)$approved->fetchColumn();

    $pending = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries b $approxWhere b.status IN ('pending','returned')");
    $pending->execute($bParams);
    $report['total_pending'] = (int)$pending->fetchColumn();

    $duplicates = $pdo->prepare("SELECT COUNT(*) FROM beneficiaries b $approxWhere b.is_duplicate = 1");
    $duplicates->execute($bParams);
    $report['total_duplicates'] = (int)$duplicates->fetchColumn();

    $distQty = $pdo->prepare("SELECT COALESCE(SUM(dl.quantity),0) AS qty, COALESCE(SUM(dl.amount),0) AS amt $distBase $dWhereSql");
    $distQty->execute($dParams);
    $dr = $distQty->fetch();
    $report['total_distributed'] = (int)$dr['qty'];
    $report['total_cash'] = (float)$dr['amt'];

    // Distribution by aid type. Hygiene Kit was retired, so those historical
    // rows are folded into Relief Goods instead of showing as their own type.
    $byTypeStmt = $pdo->prepare("SELECT COALESCE(IF(dl.assistance_type='hygiene','relief_goods',dl.assistance_type),'other') type, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                                 $distBase $dWhereSql GROUP BY type ORDER BY FIELD(type, 'food_pack','cash','relief_goods','sponsored','other') LIMIT 100");
    $byTypeStmt->execute($dParams);
    $report['by_type'] = $byTypeStmt->fetchAll();

    // Distribution by barangay
    $byBrgyStmt = $pdo->prepare("SELECT br.name barangay_name, d.name district_name, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                                 $distBase JOIN districts d ON d.id = br.district_id $dWhereSql
                                 GROUP BY br.id ORDER BY cnt DESC");
    $byBrgyStmt->execute($dParams);
    $report['by_barangay'] = $byBrgyStmt->fetchAll();

    // Distribution by district
    $byDistStmt = $pdo->prepare("SELECT d.name district_name, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                                 $distBase JOIN districts d ON d.id = br.district_id $dWhereSql
                                 GROUP BY d.id ORDER BY cnt DESC");
    $byDistStmt->execute($dParams);
    $report['by_district'] = $byDistStmt->fetchAll();

    // Beneficiaries by category (membership-based: a person in several
    // categories is counted once under each, so the column sums to more than
    // the number of people — matches the category chips on the list page).
    $byCatStmt = $pdo->prepare("SELECT COALESCE(bc.category, b.category) AS category, COUNT(DISTINCT b.id) AS cnt
                                FROM beneficiaries b
                                LEFT JOIN beneficiary_categories bc ON bc.beneficiary_id = b.id
                                $bWhereSql GROUP BY category ORDER BY cnt DESC");
    $byCatStmt->execute($bParams);
    $report['by_category'] = $byCatStmt->fetchAll();

    // Summary overlay fields
    $report['meta'] = [
        'category' => $category, 'ayuda_type' => $ayudaType,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
        'district_id' => $districtId, 'barangay_id' => $barangayId,
    ];
}

// Export URL carries all filters + report type
$exportQuery = http_build_query([
    'type' => $reportType, 'category' => $category, 'ayuda_type' => $ayudaType,
    'date_from' => $dateFrom, 'date_to' => $dateTo,
    'district_id' => $districtId, 'barangay_id' => $barangayId,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/includes/flash.php'; ?>

        <div class="card" style="margin-bottom:14px;">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="flex:1 1 200px;min-width:170px;margin-bottom:0;">
                    <label>Report Type</label>
                    <select name="type" class="form-control">
                        <?php foreach ($reportTypes as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $reportType===$k?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($isAdmin): ?>
                <div class="form-group" style="flex:1 1 140px;min-width:120px;margin-bottom:0;">
                    <label>District</label>
                    <select name="district_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">All Districts</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $d['id']===$districtId?'selected':'' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1 1 140px;min-width:120px;margin-bottom:0;">
                    <label>Barangay</label>
                    <select name="barangay_id" class="form-control">
                        <option value="0">All Barangays</option>
                        <?php foreach ($barangaysForFilter as $bb): ?>
                            <option value="<?= $bb['id'] ?>" <?= $bb['id']===$barangayId?'selected':'' ?>><?= e($bb['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group" style="flex:1 1 130px;min-width:110px;margin-bottom:0;">
                    <label>Category</label>
                    <select name="category" class="form-control">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c) ?>" <?= $category===$c?'selected':'' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1 1 130px;min-width:110px;margin-bottom:0;">
                    <label>Ayuda Type</label>
                    <select name="ayuda_type" class="form-control">
                        <?php foreach ($ayudaTypes as $at): ?>
                            <option value="<?= e($at) ?>" <?= $ayudaType===$at?'selected':'' ?>><?= $at==='All' ? 'All' : e($ayudaTypeLabels[$at]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1 1 120px;min-width:115px;margin-bottom:0;">
                    <label>Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
                </div>
                <div class="form-group" style="flex:1 1 120px;min-width:115px;margin-bottom:0;">
                    <label>Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
                </div>
                <button type="submit" name="generate" value="1" class="btn btn-primary" style="min-width:130px;">Generate Report</button>
                <?php if ($report): ?>
                    <button type="button" class="btn btn-gold" onclick="document.getElementById('exportModal').classList.add('show')" style="min-width:120px;"><?= svgIcon('download', 14) ?> Export</button>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($report): ?>
            <div class="section-title"><?= e($reportTypes[$reportType]) ?> <span></span></div>

            <?php if (in_array($reportType, ['duplicate'], true)): ?>
            <div class="grid-2 mb-3" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));">
                <div class="stat-card stat-navy"><div class="stat-label">Total Beneficiaries</div><div class="stat-num"><?= $report['total_beneficiaries'] ?></div></div>
                <div class="stat-card stat-green"><div class="stat-label">Approved</div><div class="stat-num"><?= $report['total_approved'] ?></div></div>
                <div class="stat-card stat-gold"><div class="stat-label">Pending</div><div class="stat-num"><?= $report['total_pending'] ?></div></div>
                <div class="stat-card stat-red"><div class="stat-label">Duplicates</div><div class="stat-num"><?= $report['total_duplicates'] ?></div></div>
            </div>
            <?php endif; ?>
            <?php if ($reportType === 'summary'): ?>
            <div class="grid-2 mb-3" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));">
                <div class="stat-card stat-navy"><div class="stat-label">Total Beneficiaries</div><div class="stat-num"><?= $report['total_beneficiaries'] ?></div></div>
                <div class="stat-card stat-green"><div class="stat-label">Approved</div><div class="stat-num"><?= $report['total_approved'] ?></div></div>
                <div class="stat-card stat-gold"><div class="stat-label">Pending</div><div class="stat-num"><?= $report['total_pending'] ?></div></div>
                <div class="stat-card stat-green"><div class="stat-label">Total Distributed</div><div class="stat-num"><?= $report['total_distributed'] ?></div></div>
                <div class="stat-card stat-gold"><div class="stat-label">Cash Value</div><div class="stat-num">&#8369;<?= number_format($report['total_cash'], 2) ?></div></div>
            </div>
            <?php endif; ?>

            <?php if ($reportType === 'duplicate'): ?>
                <div class="card" style="padding:0;">
                    <div class="table-responsive">
                    <table class="data-table data-table-wide">
                        <thead><tr><th>Surname</th><th>First Name</th><th>M.I.</th><th>Category</th><th>Barangay</th><th>Status</th><th>Verification</th><th>Flag</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['rows'] as $r): $np = nameParts($r['last_name'] ?? null, $r['first_name'] ?? null, $r['middle_initial'] ?? null, $r['full_name']); ?>
                            <tr>
                                <td><?= e($np['surname']) ?></td>
                                <td><?= e($np['first_name']) ?></td>
                                <td><?= e($np['middle_initial']) ?></td>
                                <td><?= e(implode(', ', $r['categories'] ?? [(string)$r['category']])) ?></td>
                                <td><?= e($r['barangay_name']) ?></td>
                                <td><span class="badge <?= statusBadgeClass($r['status']) ?>"><?= ucfirst(e($r['status'])) ?></span></td>
                                <td><?= e(statusLabel($r['verification_step'] ?? $r['status'])) ?></td>
                                <td><?= $r['is_duplicate'] ? '<span class="badge badge-warning">Possible Duplicate</span>' : '<span class="badge badge-success">Clear</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$report['rows']): ?><tr><td colspan="8" class="text-center text-muted">No records.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

            <?php elseif ($reportType === 'distribution' || $reportType === 'barangay_dist' || $reportType === 'district_dist'): ?>
                <?php if ($reportType === 'barangay_dist'): ?>
                <div class="card" style="padding:0;">
                    <div class="table-responsive">
                    <table class="data-table data-table-wide">
                        <thead><tr><th>Barangay</th><th>District</th><th>Distributions</th><th>Cash Value</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['by_barangay'] as $r): ?>
                            <tr><td><?= e($r['barangay_name']) ?></td><td><?= e($r['district_name']) ?></td><td><?= (int)$r['cnt'] ?></td><td><?= (float)$r['amt'] > 0 ? '&#8369;' . number_format((float)$r['amt'],2) : '&mdash;' ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$report['by_barangay']): ?><tr><td colspan="4" class="text-center text-muted">No records.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php elseif ($reportType === 'district_dist'): ?>
                <div class="card" style="padding:0;">
                    <div class="table-responsive">
                    <table class="data-table data-table-wide">
                        <thead><tr><th>District</th><th>Distributions</th><th>Cash Value</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['by_district'] as $r): ?>
                            <tr><td><?= e($r['district_name']) ?></td><td><?= (int)$r['cnt'] ?></td><td><?= (float)$r['amt'] > 0 ? '&#8369;' . number_format((float)$r['amt'],2) : '&mdash;' ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$report['by_district']): ?><tr><td colspan="3" class="text-center text-muted">No records.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="card" style="padding:0;">
                    <div class="table-responsive">
                    <table class="data-table data-table-wide">
                        <thead><tr><th>Date</th><th>Beneficiary</th><th>Type</th><th>Item</th><th>Qty/Cash</th><th>Barangay</th><th>Distributed By</th></tr></thead>
                        <tbody>
                        <?php
                        $_gd = $_gb = $_gt = null;
                        foreach ($report['rows'] as $r):
                            $dist = $r['district_name'] ?? 'No District';
                            $brgy = $r['barangay_name'] ?? 'No Barangay';
                            $type = $ayudaTypeLabels[$r['assistance_type']] ?? $r['assistance_type'];
                            if ($dist !== $_gd): ?>
                                <tr class="group-dist"><td colspan="7"><?= e($dist) ?> District</td></tr>
                            <?php $_gb = $_gt = null; endif;
                            if ($brgy !== $_gb || $dist !== $_gd): ?>
                                <tr class="group-brgy"><td colspan="7"><?= e($brgy) ?></td></tr>
                            <?php $_gt = null; endif;
                            if ($type !== $_gt): ?>
                                <tr class="group-type"><td colspan="7">Ayuda Type: <?= e($type) ?></td></tr>
                            <?php endif; ?>
                            <tr>
                                <td><?= date('M j, Y g:i A', strtotime($r['distributed_at'])) ?></td>
                                <td><?= e($r['beneficiary_name'] ?: '(Description only)') ?></td>
                                <td><?= e($type) ?></td>
                                <td><?= e($r['item_name']) ?></td>
                                <td><?= $r['assistance_type'] === 'cash' && (float)$r['amount'] > 0 ? '&#8369;' . number_format((float)$r['amount'],2) : (int)$r['quantity'] ?></td>
                                <td><?= e($brgy) ?></td>
                                <td><?= e($r['distributed_by_name'] ?: 'Unknown') ?></td>
                            </tr>
                            <?php $_gd = $dist; $_gb = $brgy; $_gt = $type; ?>
                        <?php endforeach; ?>
                        <?php if (!$report['rows']): ?><tr><td colspan="7" class="text-center text-muted">No distribution records.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>

            <?php elseif ($reportType === 'inventory'): ?>
                <div class="card" style="padding:0;">
                    <div class="table-responsive">
                    <table class="data-table data-table-wide">
                        <thead><tr><th>Item</th><th>Barangay</th><th>District</th><th>Stock</th><th>Unit</th><th>Distributed</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['rows'] as $r): $low = (float)$r['quantity'] <= (int)$r['low_stock_threshold']; ?>
                            <tr>
                                <td><?= e($r['item_name']) ?></td>
                                <?php $isPoolRow = ($r['barangay_name'] === null || $r['barangay_name'] === ''); ?>
                                <td><?= $isPoolRow ? 'Citywide' : e($r['barangay_name']) ?></td>
                                <td><?= $isPoolRow ? 'Citywide' : e($r['district_name']) ?></td>
                                <td><?= $isPoolRow ? '&#8369;' . number_format((float)$r['quantity'], 2) : (float)$r['quantity'] ?></td>
                                <td><?= e($r['unit']) ?></td>
                                <td><?= $isPoolRow ? '&#8369;' . number_format((float)$r['distributed'], 2) : (int)$r['distributed'] ?></td>
                                <td><span class="badge <?= $low ? 'badge-danger':'badge-success' ?>"><?= $low ? 'Low Stock':'In Stock' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$report['rows']): ?><tr><td colspan="7" class="text-center text-muted">No inventory records.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

            <?php elseif ($reportType === 'summary'): ?>
                <div class="card" style="padding:0;">
                    <div class="table-responsive">
                    <table class="data-table data-table-wide">
                        <thead><tr><th>Ayuda Type</th><th>Distributions</th><th>Cash Value</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['by_type'] as $bt): ?>
                            <tr>
                                <td><?= e($ayudaTypeLabels[$bt['type']] ?? ucfirst($bt['type'])) ?></td>
                                <td><?= (int)$bt['cnt'] ?></td>
                                <td><?= (float)$bt['amt'] > 0 ? '&#8369;' . number_format((float)$bt['amt'],2) : '&mdash;' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$report['by_type']): ?><tr><td colspan="3" class="text-center text-muted">No distribution records.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <div class="card mt-3"><div class="li-sub mb-2">Beneficiaries by Category</div>
                    <div class="table-responsive">
                    <table class="data-table">
                        <thead><tr><th>Category</th><th>Beneficiaries</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['by_category'] as $bc): ?>
                            <tr><td><?= e($bc['category']) ?></td><td><?= (int)$bc['cnt'] ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$report['by_category']): ?><tr><td colspan="2" class="text-center text-muted">No records.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>


        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<div class="modal-overlay" id="exportModal">
    <div class="modal-box">
        <span class="mb-icon"><?= svgIcon('file', 28) ?></span>
        <h3 style="text-align:center;color:var(--navy);margin-top:0;">Export Report</h3>
        <p class="small text-muted text-center">Choose a format to save this report to your device.</p>
        <a class="btn btn-primary btn-block mb-2" href="export_excel.php?<?= e($exportQuery) ?>">Export as Excel (.xls)</a>
        <a class="btn btn-outline btn-block mb-2" href="export_pdf.php?<?= e($exportQuery) ?>">Export as PDF</a>
        <button class="btn btn-link" style="display:block;margin:8px auto 0;" onclick="document.getElementById('exportModal').classList.remove('show')">Cancel</button>
    </div>
</div>
</body>
</html>