<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = 'Beneficiaries';
$activePage = 'beneficiaries';
$isAdmin = ($_SESSION['role'] ?? '') === 'district_admin';

// District Admin browses ALL members per barangay, separated by category.
// Barangay Staff (if they reach this page) are locked to their own barangay.
$districtId = (int)($_GET['district_id'] ?? 0);
$barangayId = (int)($_GET['barangay_id'] ?? 0);
        $catFilter = $_GET['category'] ?? 'All';
        $q = trim($_GET['q'] ?? '');
// Filter by aid/assistance type based on distribution_schedules/published final lists
$aidFilter = $_GET['aid_type'] ?? 'all';
$aidOptions = ['all' => 'All Aid Types', 'cash' => 'Cash', 'food_pack' => 'Food Packs', 'relief_goods' => 'Relief Goods', 'hygiene' => 'Hygiene'];

// Eligibility status filter. The official/distributable list is the set of
// APPROVED members, so that is the default view (it matches the members that
// can receive ayuda on the Distribution page). Other statuses stay reachable
// so pending records can still be opened and processed.
$statusTabs = ['approved' => 'Approved', 'pending' => 'Pending', 'returned' => 'Returned', 'rejected' => 'Rejected', 'all' => 'All'];
$statusFilter = strtolower(trim($_GET['status'] ?? 'approved'));
if (!array_key_exists($statusFilter, $statusTabs)) $statusFilter = 'approved';

$districts = $pdo->query("SELECT * FROM districts ORDER BY id LIMIT 3")->fetchAll();

if (!$isAdmin && ($_SESSION['barangay_id'] ?? null)) {
    $barangayId = (int)$_SESSION['barangay_id'];
}
if ($districtId <= 0 && $districts) $districtId = (int)$districts[0]['id'];
$barangays = ($districtId > 0) ? getBarangays($pdo, $districtId) : [];
// ensure the selected barangay belongs to the selected district
if ($barangayId > 0 && !in_array($barangayId, array_map(fn($b) => (int)$b['id'], $barangays), true)) {
    $barangayId = 0;
}
if ($barangayId <= 0 && $barangays) $barangayId = (int)$barangays[0]['id'];
$selectedBarangay = $barangayId > 0 ? getBarangayById($pdo, $barangayId) : null;

$categories = BENEFICIARY_CATEGORIES;

$barangayIdInt = (int)$barangayId;
$memberSql = "SELECT b.*, br.name AS barangay_name, d.name AS district_name
              FROM beneficiaries b
              JOIN barangays br ON br.id = b.barangay_id
              JOIN districts d ON d.id = b.district_id
              WHERE b.barangay_id = ?";
$mParams = [$barangayIdInt];
if ($statusFilter !== 'all') {
    $memberSql .= " AND b.status = ?";
    $mParams[] = $statusFilter;
}
if ($aidFilter !== 'all') {
    // Find beneficiaries who are receivers in schedules/final lists of this aid type for this barangay
    $memberSql .= " AND EXISTS (
        SELECT 1 FROM distribution_schedule_members m
        JOIN distribution_schedules ds ON ds.id = m.schedule_id
        WHERE m.beneficiary_id = b.id AND m.is_receiver = 1
          AND ds.barangay_id = b.barangay_id
          AND (
            LOWER(COALESCE(ds.aid_type,'')) = LOWER(?)
            OR LOWER(COALESCE(ds.title,'')) LIKE ?
            OR EXISTS (SELECT 1 FROM distribution_logs dl WHERE dl.beneficiary_id = b.id AND dl.barangay_id = b.barangay_id AND LOWER(COALESCE(dl.assistance_type,'')) = LOWER(?))
          )
    )";
    $mParams[] = $aidFilter;
    $mParams[] = '%' . str_replace('_', '%', strtolower($aidFilter)) . '%';
    $mParams[] = $aidFilter;
}
$filter = null;
if ($q !== '') {
    $filter = nameSearchFilter($q, 'b.full_name', 'b.category');
    if ($filter !== null) {
        $memberSql .= " AND (" . $filter[0] . ")";
        foreach ($filter[1] as $p) $mParams[] = $p;
    }
}
$memberSql .= " ORDER BY b.full_name ASC, b.id ASC";
$mStmt = $pdo->prepare($memberSql);
$mStmt->execute($mParams);
$members = $mStmt->fetchAll();

// Each person can carry up to 3 categories, so load the whole set per member.
$catMap = beneficiaryCategoriesMap($pdo, array_map(fn($m) => (int)$m['id'], $members));
foreach ($members as &$mm) {
    $mm['categories'] = $catMap[(int)$mm['id']] ?? [(string)$mm['category']];
}
unset($mm);

// One bucket per category a person belongs to -> used by the chips + filter,
// so a PWD/Senior/Solo-Parent entry shows up in all three lists. The "All" view
// lists everyone exactly once, in alphabetical order (the overall count is the
// number of people, not the sum of memberships).
$membersByCategory = [];
foreach ($members as $m) {
    foreach ($m['categories'] as $cc) { $membersByCategory[$cc][] = $m; }
}
$totalMembers = count($members);
$selectedCat = ($catFilter !== 'All' && in_array($catFilter, $categories, true)) ? $catFilter : 'All';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Beneficiaries - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
.final-list-item { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:7px 4px; border-bottom:1px solid #eef2f7; }
.cat-group { margin:6px 0; }
.cat-header { background:var(--navy); color:#fff; border-radius:8px; padding:8px 10px; margin:8px 0 2px; display:flex; align-items:center; justify-content:space-between; }
.cat-tag { font-weight:800; font-size:13px; text-transform:uppercase; letter-spacing:.4px; }
.cat-header .small { color:#cfd8e6 !important; }
.picker-bar { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.dropdown { position:relative; display:inline-block; }
.dropdown-menu { position:absolute; z-index:50; top:calc(100% + 4px); left:0; min-width:220px; background:#fff; border:1px solid #e3e9f0; border-radius:10px; box-shadow:0 8px 24px rgba(15,42,70,.12); overflow:hidden; }
.dropdown-item { display:block; padding:10px 14px; font-size:13px; color:var(--navy); text-decoration:none; }
.dropdown-item:hover { background:#f3f8fd; }
</style>
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/includes/flash.php'; ?>

        <!-- STEP 1-2: district -> barangay -->
        <div class="card" style="margin-bottom:14px;">
            <div class="section-title" style="margin:0 0 10px;">Select District - Barangay</div>
            <form method="GET" class="picker-bar">
                <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                    <label>District</label>
                    <select name="district_id" class="form-control" onchange="location.href='beneficiaries.php?district_id='+this.value+'&status=<?= e($statusFilter) ?>&aid_type=<?= e($aidFilter) ?>'">
                        <?php foreach ($districts as $i => $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $districtId === (int)$d['id'] ? 'selected' : '' ?>>District <?= (int)$i + 1 ?> (<?= e($d['name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                    <label>Barangay</label>
                    <select name="barangay_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $barangayId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                    <label>Ayuda Type</label>
                    <select name="aid_type" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($aidOptions as $ak => $al): ?>
                            <option value="<?= e($ak) ?>" <?= $aidFilter === $ak ? 'selected' : '' ?>><?= e($al) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="category" value="<?= e($selectedCat) ?>">
                <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            </form>
        </div>

        <!-- Import / Export -->
        <div class="d-flex gap-2 mb-3" style="flex-wrap:wrap;justify-content:space-between;align-items:center;">
            <div class="d-flex gap-2" style="flex-wrap:wrap;align-items:center;">
                <a href="import.php" class="btn btn-outline btn-sm">&#8595; Import (CSV / Excel)</a>
                <span style="opacity:.35;">|</span>
                <div class="dropdown">
                    <button type="button" class="btn btn-outline btn-sm" onclick="toggleExportMenu(this)">&#8593; Export (CSV / Excel)</button>
                    <div class="dropdown-menu" style="display:none;">
                        <a class="dropdown-item" href="export.php?district_id=<?= $districtId ?>&barangay_id=<?= $barangayId ?>&category=<?= urlencode($selectedCat) ?>&status=<?= e($statusFilter) ?>&format=csv">Download as CSV (.csv)</a>
                        <a class="dropdown-item" href="export.php?district_id=<?= $districtId ?>&barangay_id=<?= $barangayId ?>&category=<?= urlencode($selectedCat) ?>&status=<?= e($statusFilter) ?>&format=xls">Download as Excel (.xls)</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <?php if ($selectedBarangay): ?>
        <form method="GET" class="search-bar">
            <input type="hidden" name="district_id" value="<?= $districtId ?>">
            <input type="hidden" name="barangay_id" value="<?= $barangayId ?>">
            <input type="hidden" name="category" value="<?= e($selectedCat) ?>">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <input type="hidden" name="aid_type" value="<?= e($aidFilter) ?>">
            <input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Search beneficiary name or category...">
            <button class="btn btn-outline">Search</button>
        </form>
        <?php endif; ?>

        <!-- Status tabs: Approved (eligibility default) / Pending / etc -->
        <?php
        // Separate count per status (within the selected barangay + search),
        // so the tabs are accurate regardless of the current status filter.
        $statusCounts = ['approved' => 0, 'pending' => 0, 'returned' => 0, 'rejected' => 0];
        $scSql = "SELECT b.status, COUNT(*) n FROM beneficiaries b WHERE b.barangay_id = ?";
        $scParams = [$barangayIdInt];
        if ($q !== '' && $filter !== null) { $scSql .= " AND (" . $filter[0] . ")"; foreach ($filter[1] as $p) $scParams[] = $p; }
        $scSql .= " GROUP BY b.status";
        $scStmt = $pdo->prepare($scSql);
        $scStmt->execute($scParams);
        foreach ($scStmt->fetchAll() as $scr) { if (isset($statusCounts[$scr['status']])) $statusCounts[$scr['status']] = (int)$scr['n']; }
        $statusTotal = array_sum($statusCounts);
        ?>
        <div class="d-flex gap-2 mb-2" style="flex-wrap:wrap;align-items:center;">
            <span class="small text-muted" style="font-weight:700;">Status:</span>
            <?php foreach ($statusTabs as $sk => $sl):
                $scnt = ($sk === 'all') ? $statusTotal : ($statusCounts[$sk] ?? 0); ?>
                <a href="?district_id=<?= $districtId ?>&barangay_id=<?= $barangayId ?>&category=<?= urlencode($selectedCat) ?>&status=<?= urlencode($sk) ?>&aid_type=<?= urlencode($aidFilter) ?>&q=<?= urlencode($q) ?>"
                   class="chip <?= $statusFilter === $sk ? 'active' : '' ?>"><?= e($sl) ?> (<?= $scnt ?>)</a>
            <?php endforeach; ?>
        </div>

        <!-- Category tabs: All / PWD / Solo Parent / etc -->
        <div class="section-title">Beneficiaries of <?= $selectedBarangay ? e($selectedBarangay['name']) : 'â€”' ?> (<?= $totalMembers ?>)</div>
        <?php
        $membershipSum = 0;
        foreach ($categories as $c) $membershipSum += count($membersByCategory[$c] ?? []);
        ?>
        <div class="d-flex gap-2 mb-3" style="flex-wrap:wrap;">
            <a href="?district_id=<?= $districtId ?>&barangay_id=<?= $barangayId ?>&category=All&status=<?= urlencode($statusFilter) ?>&aid_type=<?= urlencode($aidFilter) ?>&q=<?= urlencode($q) ?>" class="chip <?= $selectedCat==='All' ? 'active' : '' ?>">All (<?= $totalMembers ?>)</a>
            <?php foreach ($categories as $c): $cnt = count($membersByCategory[$c] ?? []); ?>
                <a href="?district_id=<?= $districtId ?>&barangay_id=<?= $barangayId ?>&category=<?= urlencode($c) ?>&status=<?= urlencode($statusFilter) ?>&aid_type=<?= urlencode($aidFilter) ?>&q=<?= urlencode($q) ?>"
                   class="chip <?= $selectedCat===$c ? 'active' : '' ?>"><?= e($c) ?> (<?= $cnt ?>)</a>
            <?php endforeach; ?>
        </div>
        <?php if ($membershipSum > $totalMembers): ?>
            <div class="small text-muted" style="margin-top:-8px;margin-bottom:12px;">
                <?= svgIcon('info', 13) ?> Some residents belong to more than one category, so the category numbers add up to more than the <?= $totalMembers ?> people listed (each person is counted once in the total).
            </div>
        <?php endif; ?>

        <?php
        // One alphabetical list for "All"; a single alphabetical list for a
        // chosen category. Each row already shows its category badge(s).
        $listMembers = $selectedCat === 'All' ? $members : ($membersByCategory[$selectedCat] ?? []);
        ?>
        <div class="card" style="margin-bottom:14px;">
            <?php if (!$listMembers): ?>
                <div class="empty-state">
                    <div class="es-icon"><?= svgIcon('users', 30) ?></div>
                    <?= $selectedCat === 'All' ? 'No beneficiaries in this barangay.' : 'No ' . e($selectedCat) . ' members in this barangay.' ?>
                </div>
            <?php else: ?>
                <div class="cat-group">
                    <div class="cat-header">
                        <span class="cat-tag"><?= $selectedCat === 'All' ? 'All Beneficiaries' : e($selectedCat) ?></span>
                        <span class="small"><?= count($listMembers) ?> member(s)<?= $aidFilter !== 'all' ? ' • Filtered by: ' . e($aidOptions[$aidFilter] ?? $aidFilter) : '' ?></span>
                    </div>
                    <?php foreach ($listMembers as $m): ?>
                        <?php include __DIR__ . '/includes/_beneficiary_row.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
<script>
function toggleExportMenu(btn) {
    const menu = btn.nextElementSibling;
    const isOpen = menu.style.display === 'block';
    document.querySelectorAll('.dropdown-menu').forEach(function(m) { m.style.display = 'none'; });
    menu.style.display = isOpen ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu').forEach(function(m) { m.style.display = 'none'; });
    }
});
</script>
</body>
</html>