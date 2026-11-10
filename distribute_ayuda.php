<?php
require_once __DIR__ . '/includes/functions.php';
// Direct distribution of ayuda is a DISTRICT ADMIN role only. Barangay Staff
// assist (announce, post the final list, report emergencies) but do NOT give
// out the ayuda -- they are sent to their helping screen for that.
requireStaff();
if ($_SESSION['role'] !== 'district_admin') {
    redirect('final_list.php');
}

$pageTitle = 'Distribution';
$activePage = 'distribute';
$isAdmin = true;
$myBarangayId = (int)($_SESSION['barangay_id'] ?? 0);

// Distribution is admin-focused and only surfaces the first three Davao
// districts (Poblacion, Agdao, Talomo) as "District 1, 2, 3".
$districts = $pdo->query("SELECT * FROM districts ORDER BY id LIMIT 3")->fetchAll();

// ---------------- Resolve scope (district / barangay) ----------------
$districtId = (int)($_GET['district_id'] ?? 0);
$barangayId = (int)($_GET['barangay_id'] ?? 0);

if ($isAdmin) {
    // Admin may pick any of the 3 districts, defaulting to District 1.
    if ($districtId <= 0) {
        $districtId = (int)$districts[0]['id'];
    }
    if ($barangayId <= 0) {
        $first = getBarangays($pdo, $districtId);
        $barangayId = $first ? (int)$first[0]['id'] : 0;
    }
} else {
    // Staff are locked to their own barangay.
    $barangayId = $myBarangayId;
    $b = getBarangayById($pdo, $barangayId);
    $districtId = $b ? (int)$b['district_id'] : 0;
}

$barangayName = '';
if ($barangayId > 0) {
    $bb = getBarangayById($pdo, $barangayId);
    if ($bb) { $barangayName = $bb['name']; $districtId = $districtId ?: (int)$bb['district_id']; }
}

// ---------------- Assistance items ----------------
// District Admin: the assistance list is FIXED to District 1's items so the
// same options appear no matter which district/barangay is selected.
// Barangay Staff: keep their own barangay's inventory.
// CASH is the one shared citywide pool (barangay_id IS NULL), so it is added to
// whichever barangay is in view -- same balance the admin sees.
$items = [];
$scopeBrgy = $isAdmin ? 0 : $barangayId;
if ($isAdmin) {
    $district1Brgy = (int)$pdo->query("SELECT id FROM barangays WHERE district_id = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $scopeBrgy = $district1Brgy ?: 0;
}
if ($scopeBrgy > 0) {
    $stmt = $pdo->prepare("SELECT * FROM inventory
                           WHERE barangay_id = ? OR (assistance_type='cash' AND barangay_id IS NULL)
                           ORDER BY assistance_type, item_name");
    $stmt->execute([$scopeBrgy]);
    $items = $stmt->fetchAll();
}

$selectedItemId = (int)($_GET['item_id'] ?? 0);
$selectedItem = null;
foreach ($items as $it) {
    if ((int)$it['id'] === $selectedItemId) { $selectedItem = $it; break; }
}
if (!$selectedItem && $items) $selectedItem = $items[0];

// ---------------- Approved beneficiaries (Barangay Staff only) ----------------
$beneficiaries = [];
if (!$isAdmin && $barangayId > 0) {
    $stmt = $pdo->prepare("SELECT b.*, (SELECT COUNT(*) FROM distribution_logs dl WHERE dl.beneficiary_id = b.id AND dl.inventory_id = ?) AS already_received
                           FROM beneficiaries b WHERE b.barangay_id = ? AND b.status='approved' ORDER BY b.full_name");
    $stmt->execute([$selectedItem ? (int)$selectedItem['id'] : 0, $barangayId]);
    $beneficiaries = $stmt->fetchAll();
    // Load every category each beneficiary belongs to (not just the primary).
    $daCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($b) => (int)$b['id'], $beneficiaries));
    foreach ($beneficiaries as &$daB) { $daB['categories'] = $daCatMap[(int)$daB['id']] ?? [(string)$daB['category']]; }
    unset($daB);
}

$assistanceTypes = [
    'cash'        => 'Cash',
    'food_pack'   => 'Food Packs',
    'relief_goods'=> 'Relief Goods',
    'sponsored'   => 'Sponsored',
    'other'       => 'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Distribution - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
            <?php include __DIR__ . '/includes/flash.php'; ?>

            <div class="card">
                <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <?php if ($isAdmin): ?>
                        <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                            <label>District</label>
                            <select name="district_id" id="districtSelect" class="form-control" onchange="loadBarangays()">
                                <?php foreach ($districts as $i => $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $districtId === (int)$d['id'] ? 'selected' : '' ?>>
                                        District <?= (int)$i + 1 ?> (<?= e($d['name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                            <label>Barangay</label>
                            <select name="barangay_id" id="barangaySelect" class="form-control" onchange="this.form.submit()">
                                <?php foreach (($districtId ? getBarangays($pdo, $districtId) : []) as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= $barangayId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                            <label>Barangay</label>
                            <input type="text" id="staffBarangay" class="form-control" value="<?= e($barangayName) ?>" disabled>
                        </div>
                    <?php endif; ?>
                    <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                        <label>Assistance Type / Item</label>
                        <select name="item_id" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($items as $it): ?>
                                <option value="<?= $it['id'] ?>" <?= $selectedItem && (int)$selectedItem['id'] === (int)$it['id'] ? 'selected' : '' ?>>
                                    <?= e($it['item_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>

            <?php if (!$selectedItem): ?>
                <div class="card empty-state">No aid items/inventory for this barangay.</div>
            <?php else: ?>
                <?php $isCash = $selectedItem['assistance_type'] === 'cash'; ?>
                <div class="grid-2">
                    <div class="stat-card stat-navy">
                        <span class="stat-icon"><?= svgIcon('box', 24) ?></span>
                        <div class="stat-label">Remaining Balance</div>
                        <div class="stat-num" id="remainingBalance"><?= $isCash ? '&#8369;' . number_format((float)$selectedItem['quantity'], 2) : (int)$selectedItem['quantity'] . ' ' . e($selectedItem['unit']) ?></div>
                    </div>
                    <div class="stat-card stat-gold">
                        <span class="stat-icon"><?= svgIcon('pin', 22) ?></span>
                        <div class="stat-label">Ayuda Type</div>
                        <div class="stat-num" style="font-size:19px;"><?= e($assistanceTypes[$selectedItem['assistance_type']] ?? $selectedItem['assistance_type']) ?></div>
                    </div>
                </div>

                <?php if (!empty($selectedItem['location'])): ?>
                    <div class="card small text-muted">
                        Distribution location: <strong><?= e($selectedItem['location']) ?></strong>
                    </div>
                <?php endif; ?>

                <div class="section-title"><?= $isAdmin ? 'Record &amp; Confirm Distribution' : 'Select Beneficiary &amp; Confirm Distribution' ?></div>
                <form method="POST" action="distribute_action.php" id="distForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="inventory_id" value="<?= (int)$selectedItem['id'] ?>">
                    <input type="hidden" name="barangay_id" value="<?= (int)$barangayId ?>">

                    <?php if ($isAdmin): ?>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Beneficiary</label>
                            <select name="beneficiary_id" class="form-control" required>
                                <option value="">Select approved beneficiary...</option>
                                <?php foreach ($beneficiaries as $b): ?>
                                    <option value="<?= $b['id'] ?>" <?= (int)$b['already_received'] > 0 ? 'disabled' : '' ?>>
                                        <?= e(formatName($b['full_name'])) ?> &mdash; <?= e(implode(', ', $b['categories'] ?? [(string)$b['category']])) ?>
                                        <?= (int)$b['already_received'] > 0 ? ' [Already received]' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$beneficiaries): ?><div class="small text-muted mt-2">No approved beneficiaries yet in this barangay.</div><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isCash): ?>
                        <div class="form-group">
                            <label>Cash Amount (<?= $isCash ? '&#8369;' : '' ?>peso)</label>
                            <input type="number" name="amount" id="amountInput" class="form-control" min="1"
                                   max="<?= (float)$selectedItem['quantity'] ?>" step="0.01" required
                                   value="">
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Quantity (<?= e($selectedItem['unit']) ?>)</label>
                            <input type="number" name="quantity" id="quantityInput" class="form-control" min="1"
                                   max="<?= (int)$selectedItem['quantity'] ?>" step="1" required value="1">
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Distribution Location</label>
                        <input type="text" name="location" class="form-control" value="<?= e($selectedItem['location'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-gold btn-block" id="distBtn"><?= svgIcon('box', 15) ?> Distribute</button>
                    <a href="inventory.php" class="btn btn-outline btn-block mt-2">Cancel</a>
                </form>
            <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<div class="modal-overlay" id="confirmModal">
    <div class="modal-box" style="max-width:420px;">
        <h3 style="margin-top:0;color:var(--navy);text-align:center;">Confirm Distribution</h3>
        <div style="background:#f7f9fc;border:1px solid #e3e9f0;border-radius:10px;padding:14px;">
            <div class="small text-muted">District</div>
            <div class="li-name" id="cmDistrict">&mdash;</div>
            <div class="small text-muted mt-2">Barangay</div>
            <div class="li-name" id="cmBrgy">&mdash;</div>
            <div class="small text-muted mt-2">Assistance Item</div>
            <div class="li-name" id="cmItem">&mdash;</div>
            <div class="small text-muted mt-2">Amount / Quantity</div>
            <div class="li-name" id="cmAmt">&mdash;</div>
            <div class="small text-muted mt-2">Description</div>
            <div class="li-name" id="cmDesc">&mdash;</div>
        </div>
        <div class="small text-muted mt-3" style="text-align:center;">Are you sure you want to distribute this ayuda?</div>
        <button type="button" class="btn btn-gold btn-block mt-3" id="cmYes"><?= svgIcon('check', 15) ?> Confirm Distribute</button>
        <button type="button" class="btn btn-outline btn-block mt-2" id="cmNo">Cancel</button>
    </div>
</div>

<script>
const barangaysByDistrict = <?php
    $all = [];
    foreach ($districts as $d) { $all[$d['id']] = getBarangays($pdo, $d['id']); }
    echo json_encode($all);
?>;

// Map every barangay id to its district name so the confirm dialog can always
// show the correct district (for both the admin's dropdown and staff's lock).
const districtByBarangay = <?php
    $dmap = [];
    foreach ($districts as $d) {
        foreach (getBarangays($pdo, $d['id']) as $b) {
            $dmap[(int)$b['id']] = $d['name'];
        }
    }
    echo json_encode($dmap);
?>;
function loadBarangays() {
    const dist = document.getElementById('districtSelect');
    const brgy = document.getElementById('barangaySelect');
    const current = brgy.value;
    brgy.innerHTML = '';
    (barangaysByDistrict[dist.value] || []).forEach(function(b) {
        const opt = document.createElement('option');
        opt.value = b.id; opt.textContent = b.name;
        if (String(b.id) === String(current)) opt.selected = true;
        brgy.appendChild(opt);
    });
    if (brgy.options.length) {
        window.location.href = 'distribute_ayuda.php?district_id=' + dist.value + '&barangay_id=' + brgy.value + '&item_id=' + (<?= json_encode($selectedItem ? (int)$selectedItem['id'] : 0) ?>);
    }
}

function formatPeso(v) {
    return Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const distForm = document.getElementById('distForm');
const confirmModal = document.getElementById('confirmModal');
const amountEl = distForm ? distForm.elements['amount'] : null;
const qtyEl = distForm ? distForm.elements['quantity'] : null;

function openConfirmDialog() {
    const itemSel = document.querySelector('select[name="item_id"]');
    const itemText = itemSel ? itemSel.options[itemSel.selectedIndex].textContent.trim() : '&mdash;';
    const brgySel = document.getElementById('barangaySelect');
    const staffBrgy = document.getElementById('staffBarangay');
    const barangay = brgySel ? brgySel.options[brgySel.selectedIndex].textContent.trim()
                             : (staffBrgy ? staffBrgy.value.trim() : '&mdash;');
    const brgyId = brgySel && brgySel.value ? brgySel.value : '<?= (int)$barangayId ?>';
    const district = brgyId ? (districtByBarangay[brgyId] || '&mdash;') : '&mdash;';
    const amtLabel = amountEl && amountEl.value !== ''
        ? '&#8369;' + formatPeso(amountEl.value)
        : (qtyEl ? qtyEl.value + ' unit(s)' : '&mdash;');
    const descEl = distForm.elements['description'];
    document.getElementById('cmDistrict').innerHTML = district;
    document.getElementById('cmBrgy').innerHTML = barangay;
    document.getElementById('cmItem').innerHTML = itemText;
    document.getElementById('cmAmt').innerHTML = amtLabel;
    document.getElementById('cmDesc').innerHTML = (descEl && descEl.value.trim() !== '') ? descEl.value : '&mdash;';
    confirmModal.classList.add('show');
}

function closeConfirmDialog() {
    confirmModal.classList.remove('show');
}

if (distForm) {
    distForm.addEventListener('submit', function(e) {
        e.preventDefault();
        openConfirmDialog();
    });

    document.getElementById('cmNo').addEventListener('click', closeConfirmDialog);
    document.getElementById('cmYes').addEventListener('click', function() {
        const btn = this;
        const original = btn.innerText;
        btn.disabled = true; btn.innerText = 'Processing...';
        fetch(distForm.action, { method: 'POST', body: new FormData(distForm) })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false; btn.innerText = original;
                if (d.success) {
                    closeConfirmDialog();
                    const el = document.getElementById('remainingBalance');
                    if (el && typeof d.new_balance !== 'undefined') {
                        el.innerHTML = d.is_cash
                            ? '&#8369;' + formatPeso(d.new_balance)
                            : Math.floor(d.new_balance) + ' ' + (d.unit || 'unit');
                    }
                    if (amountEl) { amountEl.max = d.new_balance; amountEl.value = ''; }
                    if (qtyEl)     { qtyEl.max = Math.floor(d.new_balance); qtyEl.value = '1'; }
                    alert('Distribution recorded. The remaining balance has been updated.');
                    <?php if (!$isAdmin): ?>window.location.reload();<?php endif; ?>
                } else {
                    alert(d.message || 'Unable to record distribution.');
                }
            })
            .catch(() => { btn.disabled = false; btn.innerText = original; alert('Network error.'); });
    });
}
</script>
</body>
</html>
