<?php
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
require_once __DIR__ . '/includes/functions.php';
requireStaff('district_admin'); // Report generation: District Admin only (Barangay Staff only assist).

$isAdmin = $_SESSION['role'] === 'district_admin';
$myBarangayId = $_SESSION['barangay_id'] ?? null;

$type       = $_GET['type'] ?? 'beneficiary';
$category   = $_GET['category'] ?? 'All';
$ayudaType  = $_GET['ayuda_type'] ?? 'All';
$dateFrom   = $_GET['date_from'] ?? '';
$dateTo     = $_GET['date_to'] ?? '';
$districtId = (int)($_GET['district_id'] ?? 0);
$barangayId = (int)($_GET['barangay_id'] ?? 0);
$status     = $_GET['status'] ?? 'All';

// 'hygiene' rows predate the aid-type change and are reported as Relief Goods.
$ayudaTypeLabels = ['cash'=>'Cash','food_pack'=>'Food Packs','relief_goods'=>'Relief Goods','hygiene'=>'Relief Goods'];

if (!$isAdmin) { $barangayId = (int)$myBarangayId; }

// ---- Beneficiary scope ----
$bWhere = [];
$bParams = [];
if (!$isAdmin) { $bWhere[] = 'b.barangay_id = ?'; $bParams[] = $myBarangayId; }
if ($districtId > 0) { $bWhere[] = 'b.district_id = ?'; $bParams[] = $districtId; }
if ($barangayId > 0) { $bWhere[] = 'b.barangay_id = ?'; $bParams[] = $barangayId; }
if ($category !== 'All') { [$cs, $cp] = categoryMembershipSql($category); $bWhere[] = $cs; foreach ($cp as $p) $bParams[] = $p; }
if ($status !== 'All') { $bWhere[] = 'b.status = ?'; $bParams[] = $status; }
if ($dateFrom !== '') { $bWhere[] = 'b.created_at >= ?'; $bParams[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '')   { $bWhere[] = 'b.created_at <= ?'; $bParams[] = $dateTo . ' 23:59:59'; }
$bWhereSql = $bWhere ? ('WHERE ' . implode(' AND ', $bWhere)) : '';

// ---- Distribution scope ----
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

$distBase = "FROM distribution_logs dl
             JOIN barangays br ON br.id = dl.barangay_id
             LEFT JOIN beneficiaries b ON b.id = dl.beneficiary_id";

$reportTitle = ucwords(str_replace('_',' ', $type)) . ' Report';

$filename = 'ayuda_report_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders special characters correctly
?>
<html>
<head><meta charset="UTF-8"></head>
<body>
<table border="1">
<tr>
    <th colspan="15" style="font-size:14px;background:#0B2E4A;color:#fff;">AYUDA DISTRIBUTION AND MONITORING SYSTEM</th>
</tr>
<tr><td colspan="15"><?= e($reportTitle) ?> | Category: <?= e($category) ?> | Ayuda Type: <?= e($ayudaType === 'All' ? 'All' : ($ayudaTypeLabels[$ayudaType] ?? $ayudaType)) ?> | Status: <?= e($status) ?> | Date Range: <?= e($dateFrom ?: 'All') ?> to <?= e($dateTo ?: 'All') ?> | Generated: <?= date('F j, Y g:i A') ?></td></tr>
<tr><td colspan="15"></td></tr>

<?php if ($type === 'beneficiary' || $type === 'approved' || $type === 'pending' || $type === 'duplicate'): ?>
<tr style="background:#0B2E4A;color:#fff;font-weight:bold;">
    <th>Code</th><th>Surname</th><th>First Name</th><th>Middle Initial</th><th>Gender</th><th>Civil Status</th><th>Occupation</th><th>Monthly Income</th><th>House Occupancy</th><th>Lot Occupancy</th><th>Category</th><th>Barangay</th><th>District</th><th>Status</th><th>Registered On</th>
</tr>
<?php
$listWhere = $bWhere; $listParams = $bParams;
if ($type === 'approved') $listWhere[] = "b.status='approved'";
if ($type === 'pending')  $listWhere[] = "b.status IN ('pending','returned')";
$listWhereSql = $listWhere ? ('WHERE ' . implode(' AND ', $listWhere)) : '';
    $ls = $pdo->prepare("SELECT b.*, br.name barangay_name, d.name district_name FROM beneficiaries b
                         JOIN barangays br ON br.id=b.barangay_id JOIN districts d ON d.id=b.district_id
                         $listWhereSql ORDER BY b.last_name ASC, b.first_name ASC, b.middle_initial ASC, b.id ASC");
$ls->execute($listParams);
$rows = $ls->fetchAll();
$xlCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($r) => (int)$r['id'], $rows));
foreach ($rows as $r): $np = nameParts($r['last_name'] ?? null, $r['first_name'] ?? null, $r['middle_initial'] ?? null, $r['full_name']); ?>
<tr>
    <td><?= e($r['beneficiary_code']) ?></td>
    <td><?= e($np['surname']) ?><?= $r['is_duplicate'] ? ' (Possible Duplicate)' : '' ?></td>
    <td><?= e($np['first_name']) ?></td>
    <td><?= e($np['middle_initial']) ?></td>
    <td><?= e($r['gender'] ?? '') ?></td>
    <td><?= e($r['civil_status'] ?? '') ?></td>
    <td><?= e($r['occupation'] ?? '') ?></td>
    <td><?= ($r['monthly_income'] ?? null) !== null && $r['monthly_income'] !== '' ? 'PHP ' . number_format((float)$r['monthly_income'], 2) : '' ?></td>
    <td><?= e($r['house_occupancy'] ?? '') ?></td>
    <td><?= e($r['lot_occupancy'] ?? '') ?></td>
    <td><?= e(implode(', ', $xlCatMap[(int)$r['id']] ?? [(string)$r['category']])) ?></td>
    <td><?= e($r['barangay_name']) ?></td>
    <td><?= e($r['district_name']) ?></td>
    <td><?= ucfirst(e($r['status'])) ?></td>
    <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="15">No records found for this filter.</td></tr><?php endif; ?>

<?php elseif ($type === 'distribution'): ?>
<tr style="background:#0B2E4A;color:#fff;font-weight:bold;">
    <th>Date</th><th>Type</th><th>Item</th><th>Beneficiary</th><th>Qty/Cash</th><th>Barangay</th><th>District</th><th>Distributed By</th>
</tr>
<?php
    $ds = $pdo->prepare("SELECT dl.*, i.item_name, i.unit, b.full_name beneficiary_name, br.name barangay_name, d.name district_name, u.full_name distributed_by_name
                         $distBase LEFT JOIN inventory i ON i.id=dl.inventory_id
                         LEFT JOIN districts d ON d.id=br.district_id LEFT JOIN users u ON u.id=dl.distributed_by
                         $dWhereSql ORDER BY d.name ASC, br.name ASC, FIELD(dl.assistance_type, 'food_pack','cash','hygiene','relief_goods','sponsored','other'), b.full_name ASC, dl.distributed_at DESC");
$ds->execute($dParams);
$distRows = $ds->fetchAll();
$_gd = $_gb = $_gt = null; $first = true;
foreach ($distRows as $r):
    $dist = $r['district_name'] ?? 'No District';
    $brgy = preg_replace('/^' . preg_quote($dist, '/') . '\s+/i', '', trim($r['barangay_name'] ?? 'No Barangay'));
    if (trim($brgy) === '') $brgy = trim($r['barangay_name'] ?? 'No Barangay');
    $type = $ayudaTypeLabels[$r['assistance_type']] ?? $r['assistance_type'];
    if ($dist !== $_gd):
        $pb = $first ? '' : 'page-break-before:always;';
        ?>
        <tr style="<?= $pb ?>background:#0B2E4A;color:#fff;font-weight:bold;"><td colspan="8"><?= e($dist) ?> District</td></tr>
        <?php $_gb = $_gt = null; $first = false;
    endif;
    if ($brgy !== $_gb || $dist !== $_gd): ?>
        <tr style="background:#eef3f9;font-weight:bold;"><td colspan="8"><?= e($brgy) ?></td></tr>
    <?php $_gt = null; endif;
    if ($type !== $_gt): ?>
        <tr style="background:#f7fafc;font-style:italic;"><td colspan="8">Ayuda Type: <?= e($type) ?></td></tr>
    <?php endif; ?>
<tr>
    <td><?= date('M j, Y g:i A', strtotime($r['distributed_at'])) ?></td>
    <td><?= e($type) ?></td>
    <td><?= e($r['item_name']) ?></td>
    <td><?= e($r['beneficiary_name'] ? formatName($r['beneficiary_name']) : '(Description only)') ?></td>
    <td><?= $r['assistance_type']==='cash' && (float)$r['amount']>0 ? 'PHP '.number_format((float)$r['amount'],2) : (int)$r['quantity'] ?></td>
    <td><?= e($brgy) ?></td>
    <td><?= e($dist) ?></td>
    <td><?= e($r['distributed_by_name'] ?: 'Unknown') ?></td>
</tr>
<?php $_gd = $dist; $_gb = $brgy; $_gt = $type; ?>
<?php endforeach; ?>
<?php if (!$distRows): ?><tr><td colspan="8">No distribution records.</td></tr><?php endif; ?>

<?php elseif ($type === 'barangay_dist'): ?>
<tr style="background:#0B2E4A;color:#fff;font-weight:bold;">
    <th colspan="2">Barangay</th><th colspan="2">District</th><th colspan="2">Distributions</th><th colspan="2">Cash Value</th>
</tr>
<?php
    $bs = $pdo->prepare("SELECT br.name barangay_name, d.name district_name, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                         $distBase JOIN districts d ON d.id=br.district_id $dWhereSql GROUP BY br.id, br.name, d.name ORDER BY br.name ASC");
$bs->execute($dParams);
$byBarangay = $bs->fetchAll();
foreach ($byBarangay as $r): ?>
<tr><td colspan="2"><?= e($r['barangay_name']) ?></td><td colspan="2"><?= e($r['district_name']) ?></td><td colspan="2"><?= (int)$r['cnt'] ?></td><td colspan="2"><?= (float)$r['amt']>0 ? 'PHP '.number_format((float)$r['amt'],2) : '-' ?></td></tr>
<?php endforeach; ?>
<?php if (!$byBarangay): ?><tr><td colspan="8">No records.</td></tr><?php endif; ?>

<?php elseif ($type === 'district_dist'): ?>
<tr style="background:#0B2E4A;color:#fff;font-weight:bold;">
    <th colspan="3">District</th><th colspan="3">Distributions</th><th colspan="2">Cash Value</th>
</tr>
<?php
    $ds2 = $pdo->prepare("SELECT d.name district_name, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                          $distBase JOIN districts d ON d.id=br.district_id $dWhereSql GROUP BY d.id, d.name ORDER BY d.name ASC");
$ds2->execute($dParams);
$byDistrict = $ds2->fetchAll();
foreach ($byDistrict as $r): ?>
<tr><td colspan="3"><?= e($r['district_name']) ?></td><td colspan="3"><?= (int)$r['cnt'] ?></td><td colspan="2"><?= (float)$r['amt']>0 ? 'PHP '.number_format((float)$r['amt'],2) : '-' ?></td></tr>
<?php endforeach; ?>
<?php if (!$byDistrict): ?><tr><td colspan="8">No records.</td></tr><?php endif; ?>

<?php elseif ($type === 'inventory'): ?>
<tr style="background:#0B2E4A;color:#fff;font-weight:bold;">
    <th>Item</th><th>Barangay</th><th>District</th><th>Stock</th><th>Unit</th><th>Distributed</th><th>Status</th><th></th>
</tr>
<?php
$invWhere = []; $invParams = [];
if (!$isAdmin) { $invWhere[] = 'i.barangay_id = ?'; $invParams[] = $myBarangayId; }
if ($barangayId > 0) { $invWhere[] = 'i.barangay_id = ?'; $invParams[] = $barangayId; }
$invWhereSql = $invWhere ? ('WHERE ' . implode(' AND ', $invWhere)) : '';
    $is = $pdo->prepare("SELECT i.*, br.name barangay_name, d.name district_name,
                         COALESCE((SELECT SUM(CASE WHEN i.assistance_type='cash' THEN dl2.amount ELSE dl2.quantity END) FROM distribution_logs dl2 WHERE dl2.inventory_id=i.id),0) distributed
                         FROM inventory i LEFT JOIN barangays br ON br.id=i.barangay_id LEFT JOIN districts d ON d.id=br.district_id
                         $invWhereSql ORDER BY (br.name IS NULL) DESC, br.name ASC, i.item_name ASC");
$is->execute($invParams);
$invRows = $is->fetchAll();
foreach ($invRows as $r): $low = (float)$r['quantity'] <= (int)$r['low_stock_threshold']; $isPool = ($r['barangay_name'] === null || $r['barangay_name'] === ''); ?>
<tr>
    <td><?= e($r['item_name']) ?></td><td><?= $isPool ? 'Citywide' : e($r['barangay_name']) ?></td><td><?= $isPool ? 'Citywide' : e($r['district_name']) ?></td>
    <td><?= $isPool ? number_format((float)$r['quantity'], 2) : (float)$r['quantity'] ?></td><td><?= e($r['unit']) ?></td><td><?= $isPool ? number_format((float)$r['distributed'], 2) : (int)$r['distributed'] ?></td>
    <td><?= $low ? 'LOW STOCK' : 'In Stock' ?></td><td></td>
</tr>
<?php endforeach; ?>
<?php if (!$invRows): ?><tr><td colspan="8">No inventory records.</td></tr><?php endif; ?>

<?php else: ?>
<?php // summary + fallback: distribution summary by type + category breakdown ?>
<tr style="background:#0B2E4A;color:#fff;font-weight:bold;">
    <th colspan="3">Ayuda Type</th><th colspan="3">Distributions</th><th colspan="2">Cash Value</th>
</tr>
<?php
$byType = $pdo->prepare("SELECT COALESCE(IF(dl.assistance_type='hygiene','relief_goods',dl.assistance_type),'other') type, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                         $distBase $dWhereSql GROUP BY type ORDER BY cnt DESC");
$byType->execute($dParams);
$summaryTypes = $byType->fetchAll();
foreach ($summaryTypes as $bt): ?>
<tr><td colspan="3"><?= e($ayudaTypeLabels[$bt['type']] ?? ucfirst($bt['type'])) ?></td><td colspan="3"><?= (int)$bt['cnt'] ?></td><td colspan="2"><?= (float)$bt['amt']>0 ? 'PHP '.number_format((float)$bt['amt'],2) : '-' ?></td></tr>
<?php endforeach; ?>
<?php if (!$summaryTypes): ?><tr><td colspan="8">No distribution records.</td></tr><?php endif; ?>
<tr><td colspan="8"></td></tr>
<tr style="background:#0B2E4A;color:#fff;font-weight:bold;">
    <th colspan="4">Category</th><th colspan="4">Beneficiaries</th>
</tr>
<?php
$byCat = $pdo->prepare("SELECT COALESCE(bc.category, b.category) AS category, COUNT(DISTINCT b.id) AS cnt
                        FROM beneficiaries b
                        LEFT JOIN beneficiary_categories bc ON bc.beneficiary_id = b.id
                        $bWhereSql GROUP BY category ORDER BY cnt DESC");
$byCat->execute($bParams);
foreach ($byCat->fetchAll() as $bc): ?>
<tr><td colspan="4"><?= e($bc['category']) ?></td><td colspan="4"><?= (int)$bc['cnt'] ?></td></tr>
<?php endforeach; ?>
<?php endif; ?>

</table>
</body>
</html>