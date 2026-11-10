<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = 'Add Beneficiary';
$activePage = 'beneficiaries';
$isAdmin = $_SESSION['role'] === 'district_admin';
$districts = getDistricts($pdo);
$errors = [];
$duplicateInfo = null;
$old = $_POST;

// Barangay staff can only add to their own barangay
$myBarangay = null;
if (!$isAdmin) {
    $myBarangay = getBarangayById($pdo, (int)$_SESSION['barangay_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF()) $errors[] = 'Invalid security token. Please refresh the page and try again.';

$lastName   = trim($_POST['last_name'] ?? '');
    $firstName  = trim($_POST['first_name'] ?? '');
    $middleInit = mb_strtoupper(trim($_POST['middle_initial'] ?? ''));
    if ($lastName === '' && $firstName === '' && trim($_POST['full_name'] ?? '') !== '') {
        $p = nameParts(null, null, null, $_POST['full_name']);
        $lastName = $p['surname']; $firstName = $p['first_name']; $middleInit = $p['middle_initial'];
    }
    $fullName   = displayName($firstName, $middleInit, $lastName);
    $dob        = trim($_POST['date_of_birth'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $districtId = $isAdmin ? (int)($_POST['district_id'] ?? 0) : (int)$myBarangay['district_id'];
    $barangayId = $isAdmin ? (int)($_POST['barangay_id'] ?? 0) : (int)$myBarangay['id'];
    $categories = normalizeBeneficiaryCategories($_POST['category'] ?? []);
    $contact    = trim($_POST['contact_number'] ?? '');

    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '')  $errors[] = 'Last name is required.';
    if ($dob !== '' && !strtotime($dob)) $errors[] = 'Please enter a valid date of birth.';
    if ($address === '') $errors[] = 'Address is required.';
    if ($districtId <= 0) $errors[] = 'Please select a district.';
    if ($barangayId <= 0) $errors[] = 'Please select a barangay.';
    if (!$categories) $errors[] = 'Please select at least one category (up to ' . MAX_BENEFICIARY_CATEGORIES . ').';

    $isDuplicate = false;
    if (!$errors) $isDuplicate = isDuplicateBeneficiary($pdo, $fullName, $barangayId, null, $dob ?: null, $address);

    if (!$errors && $isDuplicate && !isset($_POST['confirm_duplicate'])) {
        $stmt = $pdo->prepare("SELECT b.*, br.name AS barangay_name FROM beneficiaries b JOIN barangays br ON br.id=b.barangay_id WHERE LOWER(b.full_name)=LOWER(?) AND b.barangay_id=? AND b.status != 'rejected' LIMIT 1");
        $stmt->execute([$fullName, $barangayId]);
        $duplicateInfo = $stmt->fetch();
        if ($duplicateInfo) {
            // Show every category the existing record belongs to.
            $duplicateInfo['categories'] = beneficiaryCategories($pdo, (int)$duplicateInfo['id']);
        }
    } elseif (!$errors) {
        // Staff-added beneficiaries go through barangay initial verification first,
        // then are submitted to CSWDO for final approval (per redesign doc).
        $status = $isAdmin ? 'approved' : 'pending';
        $verifStep = $isAdmin ? 'approved' : 'barangay';
        $stmt = $pdo->prepare("INSERT INTO beneficiaries (full_name, first_name, middle_initial, last_name, date_of_birth, address, district_id, barangay_id, category, contact_number, is_duplicate, status, verification_step, approved_by, approved_at)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$fullName, $firstName, $middleInit, $lastName, $dob ?: null, $address, $districtId, $barangayId, $categories[0], $contact ?: null, $isDuplicate ? 1 : 0, $status, $verifStep, $isAdmin ? $_SESSION['user_id'] : null, $isAdmin ? date('Y-m-d H:i:s') : null]);
        syncBeneficiaryCategories($pdo, (int)$pdo->lastInsertId(), $categories);
        logAudit($pdo, 'beneficiary_add', 'Added beneficiary "' . $fullName . '" (barangay #' . $barangayId . ', ' . implode(', ', $categories) . ')' . ($isDuplicate ? ' [possible duplicate]' : ''));
        setFlash('success', $isAdmin ? 'Successfully registered beneficiary.' : 'Beneficiary added and submitted for CSWDO validation.');
        redirect('beneficiaries.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Beneficiary - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width:560px;">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1;min-width:140px;">
                            <label>Last Name</label>
                            <input type="text" name="last_name" class="form-control" required value="<?= e($old['last_name'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="flex:1;min-width:140px;">
                            <label>First Name</label>
                            <input type="text" name="first_name" class="form-control" required value="<?= e($old['first_name'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="flex:0 0 110px;min-width:90px;">
                            <label>Middle Initial</label>
                            <input type="text" name="middle_initial" class="form-control" maxlength="10" value="<?= e($old['middle_initial'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth <span class="small text-muted">(optional)</span></label>
                        <input type="date" name="date_of_birth" class="form-control" value="<?= e($old['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" class="form-control" required value="<?= e($old['address'] ?? '') ?>">
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="form-group">
                            <label>District</label>
                            <select name="district_id" id="districtSelect" class="form-control" required>
                                <option value="">Select District</option>
                                <?php foreach ($districts as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= (($old['district_id'] ?? '') == $d['id']) ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Barangay</label>
                            <select name="barangay_id" id="barangaySelect" class="form-control" required>
                                <option value="">Select District First</option>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Barangay</label>
                            <input type="text" class="form-control" value="<?= e($myBarangay['name']) ?>" disabled>
                        </div>
                    <?php endif; ?>
                    <?php $oldCats = (array)($old['category'] ?? []); ?>
                    <div class="form-group">
                        <label>Category <span class="small text-muted">(select up to <?= MAX_BENEFICIARY_CATEGORIES ?> &mdash; a person can belong to more than one)</span></label>
                        <div style="display:flex;flex-wrap:wrap;gap:8px 18px;padding:4px 0;">
                            <?php foreach (BENEFICIARY_CATEGORIES as $c): ?>
                                <label style="display:flex;align-items:center;gap:6px;font-weight:500;cursor:pointer;">
                                    <input type="checkbox" name="category[]" value="<?= e($c) ?>" <?= in_array($c, $oldCats, true) ? 'checked' : '' ?>>
                                    <?= e($c) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Contact Number (optional)</label>
                        <input type="text" name="contact_number" class="form-control" data-numeric="1" value="<?= e($old['contact_number'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Save Beneficiary</button>
                    <a href="beneficiaries.php" class="btn btn-outline btn-block mt-2">Cancel</a>
                </form>
            </div>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<div class="modal-overlay <?= $duplicateInfo ? 'show' : '' ?>">
    <div class="modal-box">
        <span class="mb-icon"><?= svgIcon('alert', 26) ?></span>
        <h3 style="text-align:center;color:var(--danger);margin-top:0;">Duplicate Beneficiary Detected</h3>
        <?php if ($duplicateInfo): ?>
        <p style="font-size:13.5px;">A beneficiary with the same name already exists in this barangay:</p>
        <div class="card-flat card" style="margin-bottom:14px;">
            <div class="li-name"><?= e(formatName($duplicateInfo['full_name'])) ?></div>
            <div class="li-sub"><?= e(implode(', ', $duplicateInfo['categories'] ?? [(string)$duplicateInfo['category']])) ?> &bull; <?= e($duplicateInfo['barangay_name']) ?></div>
        </div>
        <form method="POST">
            <?php foreach ($old as $k => $v): ?>
                <?php if (is_array($v)): foreach ($v as $vv): if (is_scalar($vv)): ?><input type="hidden" name="<?= e($k) ?>[]" value="<?= e((string)$vv) ?>"><?php endif; endforeach; ?>
                <?php elseif (is_string($v)): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <input type="hidden" name="confirm_duplicate" value="1">
            <button type="submit" class="btn btn-primary btn-block mb-2">Register Anyway</button>
        </form>
        <a href="add_beneficiary.php" class="btn btn-outline btn-block">Cancel Registration</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAdmin): ?>
<script>
const barangaysByDistrict = <?php $all=[]; foreach ($districts as $d) { $all[$d['id']] = getBarangays($pdo, $d['id']); } echo json_encode($all); ?>;
const districtSelect = document.getElementById('districtSelect');
const barangaySelect = document.getElementById('barangaySelect');
function populateBarangays(selectedId) {
    const id = districtSelect.value;
    barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
    (barangaysByDistrict[id] || []).forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id; opt.textContent = b.name;
        if (selectedId && String(selectedId) === String(b.id)) opt.selected = true;
        barangaySelect.appendChild(opt);
    });
}
districtSelect.addEventListener('change', () => populateBarangays(null));
<?php if (!empty($old['district_id'])): ?>populateBarangays(<?= json_encode($old['barangay_id'] ?? null) ?>);<?php endif; ?>
</script>
<?php endif; ?>
</body>
</html>
