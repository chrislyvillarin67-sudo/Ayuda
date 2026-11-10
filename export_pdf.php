<?php
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
require_once __DIR__ . '/includes/functions.php';
requireStaff('district_admin'); // Report generation: District Admin only (Barangay Staff only assist).
require_once __DIR__ . '/lib/fpdf.php';

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

$bWhere = []; $bParams = [];
if (!$isAdmin) { $bWhere[] = 'b.barangay_id = ?'; $bParams[] = $myBarangayId; }
if ($districtId > 0) { $bWhere[] = 'b.district_id = ?'; $bParams[] = $districtId; }
if ($barangayId > 0) { $bWhere[] = 'b.barangay_id = ?'; $bParams[] = $barangayId; }
if ($category !== 'All') { [$cs, $cp] = categoryMembershipSql($category); $bWhere[] = $cs; foreach ($cp as $p) $bParams[] = $p; }
if ($status !== 'All') { $bWhere[] = 'b.status = ?'; $bParams[] = $status; }
if ($dateFrom !== '') { $bWhere[] = 'b.created_at >= ?'; $bParams[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '')   { $bWhere[] = 'b.created_at <= ?'; $bParams[] = $dateTo . ' 23:59:59'; }
$bWhereSql = $bWhere ? ('WHERE ' . implode(' AND ', $bWhere)) : '';

$dWhere = []; $dParams = [];
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

$distBase = "FROM distribution_logs dl JOIN barangays br ON br.id = dl.barangay_id LEFT JOIN beneficiaries b ON b.id = dl.beneficiary_id";

function cleanText($txt) {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string)$txt);
}

function shortBarangay($brgy, $dist) {
    $prefix = trim((string)$dist) . ' ';
    if (stripos((string)$brgy, $prefix) === 0) {
        return trim(substr((string)$brgy, strlen($prefix)));
    }
    return trim((string)$brgy);
}

function fitText($pdf, $txt, $maxW) {
    $txt = (string)$txt;
    if ($pdf->GetStringWidth($txt) <= $maxW) return $txt;
    while (strlen($txt) > 1 && $pdf->GetStringWidth($txt . '..') > $maxW) {
        $txt = substr($txt, 0, -1);
    }
    return $txt . '..';
}

function pdfColWidths($pdf, array $cols): array {
    $usable = $pdf->GetPageWidth() - 16; // 8mm left + 8mm right margins
    $total = 0;
    foreach ($cols as $c) $total += (float)$c['w'];
    $widths = [];
    foreach ($cols as $c) $widths[] = $usable * (float)$c['w'] / $total;
    $last = count($widths) - 1;
    $widths[$last] += $usable - array_sum($widths);
    return $widths;
}

class AyudaPDF extends FPDF {
    public function pageBreakTriggerY(): float { return (float)$this->PageBreakTrigger; }
}

function pdfTableHeader($pdf, array $cols): void {
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(11, 46, 74);
    $pdf->SetTextColor(255, 255, 255);
    $w = pdfColWidths($pdf, $cols);
    foreach ($cols as $i => $c) {
        $pdf->Cell($w[$i], 8, cleanText($c['t']), 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
}

function pdfRow($pdf, array $cols, array $cells): void {
    if ($pdf->GetY() > $pdf->pageBreakTriggerY() - 15) {
        $pdf->AddPage();
        pdfTableHeader($pdf, $cols);
    }
    $w = pdfColWidths($pdf, $cols);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
    foreach ($cells as $i => $v) {
        $txt = cleanText(fitText($pdf, (string)$v, $w[$i] - 2));
        $pdf->Cell($w[$i], 9, $txt, 1, 0, 'L');
    }
    $pdf->Ln();
}

function pdfFullRow($pdf, array $cols, string $text, bool $band = false, bool $italic = false): void {
    if ($pdf->GetY() > $pdf->pageBreakTriggerY() - 12) $pdf->AddPage();
    $w = pdfColWidths($pdf, $cols);
    if ($band) { $pdf->SetFillColor(11, 46, 74); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 7); }
    elseif ($italic) { $pdf->SetFillColor(247, 250, 252); $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', 'I', 7); }
    else { $pdf->SetFillColor(238, 243, 249); $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', 'B', 7); }
    $pdf->Cell(array_sum($w), 8, cleanText($text), 1, 0, 'L', true);
    $pdf->Ln();
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0, 0, 0);
}

$reportTitle = ucwords(str_replace('_',' ', $type)) . ' Report';

$pdf = new AyudaPDF('L', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->AddPage();
$pdf->SetFillColor(11, 46, 74);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(0, 11, cleanText('AYUDA DISTRIBUTION AND MONITORING SYSTEM'), 0, 1, 'C', true);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 7, cleanText($reportTitle), 0, 1, 'C', true);
$pdf->Ln(2);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 5, cleanText('Category: ' . $category . '   |   Ayuda Type: ' . ($ayudaType === 'All' ? 'All' : ($ayudaTypeLabels[$ayudaType] ?? $ayudaType)) . '   |   Status: ' . $status
    . '   |   Date Range: ' . ($dateFrom ?: 'All') . ' to ' . ($dateTo ?: 'All')));
$pdf->Ln(5);

// ------------------------- Beneficiary roster -------------------------
if ($type === 'beneficiary' || $type === 'approved' || $type === 'pending' || $type === 'duplicate') {
    $cols = [
        ['t' => 'Code',             'w' => 5],
        ['t' => 'Surname',          'w' => 11],
        ['t' => 'First Name',       'w' => 10],
        ['t' => 'M.I.',             'w' => 2.5],
        ['t' => 'Gender',           'w' => 6],
        ['t' => 'Civil Status',     'w' => 7],
        ['t' => 'Occupation',       'w' => 9],
        ['t' => 'Monthly Income',   'w' => 8],
        ['t' => 'House',            'w' => 5],
        ['t' => 'Lot',              'w' => 5],
        ['t' => 'Category',         'w' => 8],
        ['t' => 'Barangay',         'w' => 9],
        ['t' => 'District',         'w' => 8],
        ['t' => 'Status',           'w' => 6],
        ['t' => 'Registered On',    'w' => 7],
    ];
    $listWhere = $bWhere; $listParams = $bParams;
    if ($type === 'approved') $listWhere[] = "b.status='approved'";
    if ($type === 'pending')  $listWhere[] = "b.status IN ('pending','returned')";
    $listWhereSql = $listWhere ? ('WHERE ' . implode(' AND ', $listWhere)) : '';
    $ls = $pdo->prepare("SELECT b.*, br.name barangay_name, d.name district_name FROM beneficiaries b
                         JOIN barangays br ON br.id=b.barangay_id JOIN districts d ON d.id=b.district_id
                         $listWhereSql ORDER BY b.last_name ASC, b.first_name ASC, b.middle_initial ASC, b.id ASC");
    $ls->execute($listParams);
    $rows = $ls->fetchAll();
    $pdfCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($r) => (int)$r['id'], $rows));
    if ($rows) pdfTableHeader($pdf, $cols);
    foreach ($rows as $r) {
        $np = nameParts($r['last_name'] ?? null, $r['first_name'] ?? null, $r['middle_initial'] ?? null, $r['full_name']);
        pdfRow($pdf, $cols, [
            $r['beneficiary_code'],
            $np['surname'] . ($r['is_duplicate'] ? ' (Dup)' : ''),
            $np['first_name'],
            $np['middle_initial'],
            $r['gender'] ?? '',
            $r['civil_status'] ?? '',
            $r['occupation'] ?? '',
            ($r['monthly_income'] ?? null) !== null && $r['monthly_income'] !== '' ? 'PHP ' . number_format((float)$r['monthly_income'], 2) : '',
            $r['house_occupancy'] ?? '',
            $r['lot_occupancy'] ?? '',
            implode(', ', $pdfCatMap[(int)$r['id']] ?? [(string)$r['category']]),
            $r['barangay_name'],
            $r['district_name'],
            ucfirst($r['status']),
            date('M j, Y', strtotime($r['created_at'])),
        ]);
    }
    if (!$rows) {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 8, cleanText('No records found for this filter.'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
    }

// ------------------------- Distribution detail -------------------------
} elseif (in_array($type, ['distribution'], true)) {
    $cols = [
        ['t' => 'Date',             'w' => 10],
        ['t' => 'Type',             'w' => 7],
        ['t' => 'Item',             'w' => 11],
        ['t' => 'Beneficiary',      'w' => 14],
        ['t' => 'Qty/Cash',         'w' => 7],
        ['t' => 'Barangay',         'w' => 9],
        ['t' => 'District',         'w' => 8],
        ['t' => 'Distributed By',   'w' => 8],
    ];
    $ds = $pdo->prepare("SELECT dl.*, i.item_name, i.unit, b.full_name beneficiary_name, br.name barangay_name, d.name district_name, u.full_name distributed_by_name
                         $distBase LEFT JOIN inventory i ON i.id=dl.inventory_id
                         LEFT JOIN districts d ON d.id=br.district_id LEFT JOIN users u ON u.id=dl.distributed_by
                         $dWhereSql ORDER BY d.name ASC, br.name ASC, FIELD(dl.assistance_type, 'food_pack','cash','hygiene','relief_goods','sponsored','other'), b.full_name ASC, dl.distributed_at DESC");
    $ds->execute($dParams);
    $rrows = $ds->fetchAll();
    if ($rrows) pdfTableHeader($pdf, $cols);
    $_gd = $_gb = $_gt = null; $first = true;
    foreach ($rrows as $r) {
        $dist = $r['district_name'] ?? 'No District';
        $brgy = shortBarangay($r['barangay_name'] ?? 'No Barangay', $dist);
        $typeLbl = $ayudaTypeLabels[$r['assistance_type']] ?? $r['assistance_type'];
        if ($dist !== $_gd) { pdfFullRow($pdf, $cols, $dist . ' District', true); $_gb = $_gt = null; $first = false; }
        if ($brgy !== $_gb || $dist !== $_gd) { pdfFullRow($pdf, $cols, $brgy); $_gt = null; }
        if ($typeLbl !== $_gt) { pdfFullRow($pdf, $cols, 'Ayuda Type: ' . $typeLbl, false, true); }
        $qty = $r['assistance_type']==='cash' && (float)$r['amount']>0
            ? 'PHP ' . number_format((float)$r['amount'],2)
            : (string)(int)$r['quantity'];
        pdfRow($pdf, $cols, [
            date('M j, Y g:i A', strtotime($r['distributed_at'])),
            $typeLbl,
            $r['item_name'],
            $r['beneficiary_name'] ? (string)$r['beneficiary_name'] : '(Description only)',
            $qty,
            $brgy,
            $dist,
            $r['distributed_by_name'] ?: 'Unknown',
        ]);
        $_gd = $dist; $_gb = $brgy; $_gt = $typeLbl;
    }
    if (!$rrows) {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 8, cleanText('No distribution records.'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
    }

// ------------------------- Barangay summary -------------------------
} elseif (in_array($type, ['barangay_dist','district_dist'], true)) {
    $cols = [];
    $rows = [];
    if ($type === 'barangay_dist') {
        $cols = [
            ['t' => 'Barangay', 'w' => 8], ['t' => 'District', 'w' => 8],
            ['t' => 'Distributions', 'w' => 5], ['t' => 'Cash Value', 'w' => 6],
        ];
        $bs = $pdo->prepare("SELECT br.name barangay_name, d.name district_name, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                             $distBase JOIN districts d ON d.id=br.district_id $dWhereSql GROUP BY br.id, br.name, d.name ORDER BY br.name ASC");
        $bs->execute($dParams);
        $rows = $bs->fetchAll();
    } else {
        $cols = [
            ['t' => 'District', 'w' => 10], ['t' => 'Distributions', 'w' => 5], ['t' => 'Cash Value', 'w' => 6],
        ];
        $ds2 = $pdo->prepare("SELECT d.name district_name, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                              $distBase JOIN districts d ON d.id=br.district_id $dWhereSql GROUP BY d.id, d.name ORDER BY d.name ASC");
        $ds2->execute($dParams);
        $rows = $ds2->fetchAll();
    }
    if ($rows) pdfTableHeader($pdf, $cols);
    foreach ($rows as $r) {
        if ($type === 'barangay_dist') {
            pdfRow($pdf, $cols, [$r['barangay_name'], $r['district_name'], (string)(int)$r['cnt'], (float)$r['amt']>0 ? 'PHP '.number_format((float)$r['amt'],2) : '-']);
        } else {
            pdfRow($pdf, $cols, [$r['district_name'], (string)(int)$r['cnt'], (float)$r['amt']>0 ? 'PHP '.number_format((float)$r['amt'],2) : '-']);
        }
    }
    if (!$rows) {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 8, cleanText('No records.'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
    }

// ------------------------- Inventory -------------------------
} elseif ($type === 'inventory') {
    $cols = [
        ['t' => 'Item', 'w' => 11], ['t' => 'Barangay', 'w' => 8], ['t' => 'District', 'w' => 8],
        ['t' => 'Stock', 'w' => 6], ['t' => 'Unit', 'w' => 4], ['t' => 'Distributed', 'w' => 7], ['t' => 'Status', 'w' => 5],
    ];
    $invWhere = []; $invParams = [];
    if (!$isAdmin) { $invWhere[] = 'i.barangay_id = ?'; $invParams[] = $myBarangayId; }
    if ($barangayId > 0) { $invWhere[] = 'i.barangay_id = ?'; $invParams[] = $barangayId; }
    $invWhereSql = $invWhere ? ('WHERE ' . implode(' AND ', $invWhere)) : '';
    $is = $pdo->prepare("SELECT i.*, br.name barangay_name, d.name district_name,
                         COALESCE((SELECT SUM(CASE WHEN i.assistance_type='cash' THEN dl2.amount ELSE dl2.quantity END) FROM distribution_logs dl2 WHERE dl2.inventory_id=i.id),0) distributed
                         FROM inventory i LEFT JOIN barangays br ON br.id=i.barangay_id LEFT JOIN districts d ON d.id=br.district_id
                         $invWhereSql ORDER BY (br.name IS NULL) DESC, br.name ASC, i.item_name ASC");
    $is->execute($invParams);
    $irows = $is->fetchAll();
    if ($irows) pdfTableHeader($pdf, $cols);
    foreach ($irows as $r) {
        $isPool = ($r['barangay_name'] === null || $r['barangay_name'] === '');
        $low = (float)$r['quantity'] <= (int)$r['low_stock_threshold'];
        pdfRow($pdf, $cols, [
            $r['item_name'],
            $isPool ? 'Citywide' : $r['barangay_name'],
            $isPool ? 'Citywide' : $r['district_name'],
            $isPool ? number_format((float)$r['quantity'], 2) : (string)(float)$r['quantity'],
            $r['unit'],
            $isPool ? number_format((float)$r['distributed'], 2) : (string)(int)$r['distributed'],
            $low ? 'LOW STOCK' : 'In Stock',
        ]);
    }
    if (!$irows) {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 8, cleanText('No inventory records.'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
    }

// ------------------------- Summary / fallback -------------------------
} else {
    $cols = [
        ['t' => 'Ayuda Type', 'w' => 10], ['t' => 'Distributions', 'w' => 5], ['t' => 'Cash Value', 'w' => 6],
    ];
    $byType = $pdo->prepare("SELECT COALESCE(IF(dl.assistance_type='hygiene','relief_goods',dl.assistance_type),'other') type, COUNT(*) cnt, COALESCE(SUM(dl.amount),0) amt
                             $distBase $dWhereSql GROUP BY type ORDER BY FIELD(type, 'food_pack','cash','relief_goods','sponsored','other')");
    $byType->execute($dParams);
    $trows = $byType->fetchAll();
    if ($trows) pdfTableHeader($pdf, $cols);
    foreach ($trows as $bt) {
        pdfRow($pdf, $cols, [
            $ayudaTypeLabels[$bt['type']] ?? ucfirst($bt['type']),
            (string)(int)$bt['cnt'],
            (float)$bt['amt']>0 ? 'PHP '.number_format((float)$bt['amt'],2) : '-',
        ]);
    }
    if (!$trows) {
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 8, cleanText('No distribution records.'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
    }
    $pdf->Ln(4);
    $catCols = [['t' => 'Category', 'w' => 8], ['t' => 'Beneficiaries', 'w' => 5]];
    $byCat = $pdo->prepare("SELECT COALESCE(bc.category, b.category) AS category, COUNT(DISTINCT b.id) AS cnt
                            FROM beneficiaries b
                            LEFT JOIN beneficiary_categories bc ON bc.beneficiary_id = b.id
                            $bWhereSql GROUP BY category ORDER BY category");
    $byCat->execute($bParams);
    $crows = $byCat->fetchAll();
    if ($crows) {
        pdfFullRow($pdf, $catCols, 'Beneficiaries by Category', true);
        pdfTableHeader($pdf, $catCols);
    }
    foreach ($crows as $bc) pdfRow($pdf, $catCols, [$bc['category'], (string)(int)$bc['cnt']]);
}

$name = 'ayuda_report_' . date('Ymd_His') . '.pdf';
$pdf->Output('D', $name);