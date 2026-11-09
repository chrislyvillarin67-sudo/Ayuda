<?php
/**
 * Plain helper for CSV + Excel (SpreadsheetML 2003) import/export.
 * No third-party library required. XLSX is not supported server-side
 * (needs ZipArchive) and produces a clear error instead.
 */

function ssExpectedColumns(): array
{
    return ['full_name','date_of_birth','address','district','barangay','category','contact_number',
            'gender','civil_status','occupation','monthly_income','house_occupancy','lot_occupancy',
            'surname','first_name','middle_initial'];
}

function ssNormalizeHeader(string $h): string
{
    $h = mb_strtolower(trim($h));
    $h = preg_replace('/[\s-]+/', '_', $h);
    $map = [
        'name'            => 'full_name',
        'fullname'        => 'full_name',
        'full_name'       => 'full_name',
        'surname'         => 'surname',
        'last_name'       => 'surname',
        'lastname'        => 'surname',
        'last'            => 'surname',
        'family_name'     => 'surname',
        'first_name'      => 'first_name',
        'firstname'       => 'first_name',
        'given_name'      => 'first_name',
        'given'           => 'first_name',
        'first'           => 'first_name',
        'middle_initial'  => 'middle_initial',
        'middle'          => 'middle_initial',
        'middle_name'     => 'middle_initial',
        'middle_initial.' => 'middle_initial',
        'contact'         => 'contact_number',
        'contact_number'  => 'contact_number',
        'contact_no'      => 'contact_number',
        'contact_no_'     => 'contact_number',
        'phone'           => 'contact_number',
        'dob'             => 'date_of_birth',
        'date_of_birth'   => 'date_of_birth',
        'birthdate'       => 'date_of_birth',
        'birth_date'      => 'date_of_birth',
        'bday'            => 'date_of_birth',
        'type'            => 'category',
        'category'        => 'category',
        'brobamo'         => 'barangay',
        'barangay'        => 'barangay',
        'district'        => 'district',
        'address'         => 'address',
        'gender'          => 'gender',
        'sex'             => 'gender',
        'civil_status'    => 'civil_status',
        'civilstatus'     => 'civil_status',
        'marital_status'  => 'civil_status',
        'civil'           => 'civil_status',
        'occupation'      => 'occupation',
        'job'             => 'occupation',
        'monthly_income'  => 'monthly_income',
        'income'          => 'monthly_income',
        'house_occupancy' => 'house_occupancy',
        'house'           => 'house_occupancy',
        'lot_occupancy'   => 'lot_occupancy',
        'lot'             => 'lot_occupancy',
    ];
    return $map[$h] ?? $h;
}

/**
 * Normalize an imported birthdate cell to Y-m-d. Accepts the usual text dates
 * ("1990-01-01", "1/1/1990"...) and bare Excel serial numbers (e.g. 32874),
 * which is what a real .xls date cell contains. Returns null when unusable.
 */
function importDateToYmd($value): ?string {
    $v = trim((string)$value);
    if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
        return date('Y-m-d', (int)round(((float)$v - 25569) * 86400)); // 1899-12-30 origin
    }
    $ts = strtotime($v);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/** Read a file into a grid of rows (arrays of cells). */
function ssParseFile(string $path, string $ext): array
{
    $ext = strtolower($ext);
    if (in_array($ext, ['xlsx','xlsm'], true)) {
        return ['error' => 'Excel .xlsx/.xlsm files are not supported on this server (the ZipArchive PHP extension is not available). Please save your file as .xls (Excel 97-2003), .xml, or .csv and try again.'];
    }
    if (in_array($ext, ['xls','xml'], true)) {
        return ssParseXml($path);
    }
    return ssParseCsv($path);
}

function ssParseCsv(string $path): array
{
    $handle = @fopen($path, 'r');
    if (!$handle) return ['error' => 'Could not read the uploaded file.'];
    $grid = [];
    while (($data = fgetcsv($handle)) !== false) {
        if ($data === [null]) continue;
        $grid[] = $data;
    }
    fclose($handle);
    // strip UTF-8 BOM from the very first cell
    if (isset($grid[0][0])) $grid[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$grid[0][0]);
    return $grid;
}

function ssParseXml(string $path): array
{
    $prev = libxml_use_internal_errors(true);
    $xml = @simplexml_load_file($path);
    if ($xml === false) {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return ['error' => 'Could not read this Excel (.xls/.xml) file. Please save it as Excel 97-2003 (.xls) or .csv and try again.'];
    }
    $grid = [];
    foreach ($xml->Worksheet as $ws) {
        if (!isset($ws->Table)) continue;
        foreach ($ws->Table->Row as $row) {
            $cells = [];
            foreach ($row->Cell as $cell) {
                $cells[] = trim((string)$cell->Data);
            }
            $grid[] = $cells;
        }
        break; // first sheet only
    }
    if (!$grid) {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return ['error' => 'The Excel file has no readable sheet/data.'];
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $grid;
}

/** Turn a grid into assoc rows keyed by the expected columns (header-aware). */
function ssGridToAssoc(array $grid, array $expected): array
{
    $rows = [];
    $map = null;              // header name -> cell index
    $rowsAreEmpty = true;     // if every row is blank, treat as empty file
    foreach ($grid as $r) {
        $r = array_values($r);
        if ($map === null) {
            $norm = array_map('ssNormalizeHeader', $r);
            $matched = count(array_intersect($norm, array_merge($expected, ['date_of_birth','status','is_duplicate','beneficiary_code','created_at'])));
            $hasData = in_array('', array_map('trim', $r), true) === false || trim(implode('', $r)) !== '';
            if ($matched >= 2 && $hasData) {
                $map = $norm; // header row
                continue;
            }
        }
        $assoc = [];
        foreach ($expected as $col) {
            if ($map !== null) {
                $idx = array_search($col, $map, true);
                $assoc[$col] = ($idx === false) ? '' : ($r[$idx] ?? '');
            } else {
                $assoc[$col] = $r[array_search($col, $expected, true)] ?? '';
            }
        }
        // Accept split-name exports too: if no full_name column exists but the
        // name was given as surname / first name / middle initial, rebuild it
        // as "Surname, First M." so the roster import still works.
        if (trim((string)($assoc['full_name'] ?? '')) === '' && $map !== null) {
            $sn = ($si = array_search('surname', $map, true)) !== false ? trim((string)($r[$si] ?? '')) : '';
            $fn = ($fi = array_search('first_name', $map, true)) !== false ? trim((string)($r[$fi] ?? '')) : '';
            $mi = ($mi = array_search('middle_initial', $map, true)) !== false ? trim((string)($r[$mi] ?? '')) : '';
            if ($sn !== '' && $fn !== '') {
                $assoc['full_name'] = $sn . ', ' . $fn . ($mi !== '' ? ' ' . strtoupper(rtrim($mi, '.')) . '.' : '');
            } elseif ($sn !== '' || $fn !== '') {
                $assoc['full_name'] = trim($sn . ' ' . $fn . ($mi !== '' ? ' ' . strtoupper(rtrim($mi, '.')) . '.' : ''));
            }
        }
        if (trim(implode('', $assoc)) !== '') {
            $rows[] = $assoc;
            $rowsAreEmpty = false;
        }
    }
    if ($rowsAreEmpty) return [];
    return $rows;
}

/** Emit a CSV attachment. */
function ssEmitCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
}

/** Emit an Excel (.xls, SpreadsheetML 2003) attachment. */
function ssEmitExcel(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
    echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
    echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
    echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo ' <Worksheet ss:Name="Beneficiaries">' . "\n";
    echo '  <Table>' . "\n";
    $writeRow = function (array $cells) use (&$writeRow) {
        echo '   <Row>';
        foreach ($cells as $c) {
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$c, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
        }
        echo '</Row>' . "\n";
    };
    $writeRow($headers);
    foreach ($rows as $r) $writeRow($r);
    echo '  </Table>' . "\n";
    echo ' </Worksheet>' . "\n";
    echo '</Workbook>';
}

/** Body used by the export page. Returns null on success, error string otherwise. */
function ssRunExport(PDO $pdo, string $format): ?string
{
    $districtId = (int)($_GET['district_id'] ?? 0);
    $barangayId = (int)($_GET['barangay_id'] ?? 0);
    $catFilter = $_GET['category'] ?? 'All';
    $isAdmin = ($_SESSION['role'] ?? '') === 'district_admin';
    if (!$isAdmin && ($_SESSION['barangay_id'] ?? null)) $barangayId = (int)$_SESSION['barangay_id'];

    $validCats = BENEFICIARY_CATEGORIES;
    $where = [];
    $params = [];
    if ($barangayId > 0) { $where[] = 'b.barangay_id = ?'; $params[] = $barangayId; }
    if (empty($where) && $districtId > 0) { $where[] = 'b.district_id = ?'; $params[] = $districtId; }
    if ($catFilter !== 'All' && in_array($catFilter, $validCats, true)) {
        [$cs, $cp] = categoryMembershipSql($catFilter);
        $where[] = $cs;
        foreach ($cp as $p) $params[] = $p;
    }

    // Match the Beneficiaries page default: the official list is APPROVED members.
    $statusFilter = strtolower(trim($_GET['status'] ?? 'approved'));
    if (!in_array($statusFilter, ['approved', 'pending', 'returned', 'rejected', 'all'], true)) $statusFilter = 'approved';
    if ($statusFilter !== 'all') { $where[] = 'b.status = ?'; $params[] = $statusFilter; }

    $sql = "SELECT b.id, b.beneficiary_code, b.full_name, b.first_name, b.middle_initial, b.last_name, b.date_of_birth, b.address,
            d.name AS district, br.name AS barangay, b.category, b.contact_number, b.status,
            b.gender, b.civil_status, b.occupation, b.monthly_income, b.house_occupancy, b.lot_occupancy,
            b.is_duplicate, b.created_at
            FROM beneficiaries b
            JOIN barangays br ON br.id = b.barangay_id
            JOIN districts d ON d.id = b.district_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY d.id, br.id, b.last_name, b.first_name, b.id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $headers = ['beneficiary_code','surname','first_name','middle_initial','date_of_birth','address','district','barangay','category','contact_number','status','gender','civil_status','occupation','monthly_income','house_occupancy','lot_occupancy','is_duplicate','created_at'];
    $nice = ['Beneficiary Code','Surname','First Name','Middle Initial','Date of Birth','Address','District','Barangay','Category','Contact Number','Status','Gender','Civil Status','Occupation','Monthly Income','House Occupancy','Lot Occupancy','Possible Duplicate','Created At'];
    $catMap = beneficiaryCategoriesMap($pdo, array_column($rows, 'id'));
    $data = [];
    foreach ($rows as $r) {
        $np = nameParts($r['last_name'] ?? null, $r['first_name'] ?? null, $r['middle_initial'] ?? null, $r['full_name']);
        $cats = $catMap[(int)$r['id']] ?? [(string)$r['category']];
        $data[] = [
            $r['beneficiary_code'], $np['surname'], $np['first_name'], $np['middle_initial'], $r['date_of_birth'], $r['address'],
            $r['district'], $r['barangay'], implode(', ', $cats), $r['contact_number'], $r['status'],
            $r['gender'], $r['civil_status'], $r['occupation'], $r['monthly_income'],
            $r['house_occupancy'], $r['lot_occupancy'],
            $r['is_duplicate'], $r['created_at']
        ];
    }
    $ts = date('Ymd_His');
    $tag = $barangayId > 0 ? 'barangay' . $barangayId : ($districtId > 0 ? 'district' . $districtId : 'all');
    if ($format === 'xls') {
        ssEmitExcel('beneficiaries_' . $tag . '_' . $ts . '.xls', $nice, $data);
    } else {
        ssEmitCsv('beneficiaries_' . $tag . '_' . $ts . '.csv', $headers, $data);
    }
    return null;
}