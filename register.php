<?php
require_once __DIR__ . '/includes/functions.php';

if (isStaffLoggedIn()) redirect('dashboard.php');
if (isBeneficiaryLoggedIn()) redirect('beneficiary/dashboard.php');

$pageTitle = 'Beneficiary Registration';
$districts = getDistricts($pdo);
$errors = [];
$success = '';
$step = 'search'; // search | setpass
$found = null;

$genderOpts = ['Female', 'Male', 'Prefer not to say'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF()) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? 'search';

        if ($action === 'search') {
            $step = 'search';
            $lastName = trim($_POST['last_name'] ?? '');
            $firstName = trim($_POST['first_name'] ?? '');
            $middleInit = mb_strtoupper(trim($_POST['middle_initial'] ?? ''));
            $districtId = (int)($_POST['district_id'] ?? 0);
            $barangayId = (int)($_POST['barangay_id'] ?? 0);
            $dob = trim($_POST['date_of_birth'] ?? '');

            if ($firstName === '' || $lastName === '') {
                $errors[] = 'Please enter your first and last name.';
            }
            if ($dob === '') {
                $errors[] = 'Please enter your date of birth.';
            }
            if ($districtId <= 0 || $barangayId <= 0) {
                $errors[] = 'Please select your district and barangay.';
            }

            if (!$errors) {
                // Look for matching record in master list (beneficiaries) - be flexible with formatting
                $stmt = $pdo->prepare("SELECT b.*, br.name AS barangay_name, d.name AS district_name
                                       FROM beneficiaries b
                                       JOIN barangays br ON br.id = b.barangay_id
                                       JOIN districts d ON d.id = b.district_id
                                       WHERE b.barangay_id = ? AND b.date_of_birth = ?
                                       ORDER BY b.id DESC");
                $stmt->execute([$barangayId, $dob]);
                $all = $stmt->fetchAll();
                $cands = [];
                $ln = strtolower($lastName);
                $fn = strtolower($firstName);
                $mi = strtolower(preg_replace('/[^a-z]/i', '', $middleInit));
                foreach ($all as $a) {
                    $nf = strtolower(str_replace(['.', ','], ' ', $a['full_name']));
                    $nf = preg_replace('/\s+/', ' ', trim($nf));
                    if (strpos($nf, $ln) === false || strpos($nf, $fn) === false) {
                        continue;
                    }
                    if ($mi !== '' && strpos($nf, $mi) === false) {
                        continue;
                    }
                    $cands[] = $a;
                }
                if (empty($cands)) {
                    $stmt2 = $pdo->prepare("SELECT b.*, br.name AS barangay_name, d.name AS district_name
                                            FROM beneficiaries b
                                            JOIN barangays br ON br.id = b.barangay_id
                                            JOIN districts d ON d.id = b.district_id
                                            WHERE b.barangay_id = ? AND b.date_of_birth = ? AND LOWER(b.full_name) LIKE LOWER(?) AND LOWER(b.full_name) LIKE LOWER(?)
                                            ORDER BY b.id DESC");
                    $likeLast = '%' . str_replace(['%','_'], ['\\%','\\_'], $lastName) . '%';
                    $likeFirst = '%' . str_replace(['%','_'], ['\\%','\\_'], $firstName) . '%';
                    $stmt2->execute([$barangayId, $dob, $likeLast, $likeFirst]);
                    $cands = $stmt2->fetchAll();
                }

                if (empty($cands)) {
                    $errors[] = 'No record found in the master list for that name, barangay, and date of birth. Please check your details or visit your Barangay Hall.';
                } else {
                    $found = $cands[0];
                    if (($found['status'] ?? '') === 'approved') {
                        session_regenerate_id(true);
                        $_SESSION['beneficiary_id']   = (int)$found['id'];
                        $_SESSION['beneficiary_name'] = $found['full_name'];
                        logAudit($pdo, 'login', "Beneficiary auto-logged in via master list check: " . $found['full_name']);
                        redirect('beneficiary/dashboard.php');
                    } elseif (!empty($found['password_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION['beneficiary_id']   = (int)$found['id'];
                        $_SESSION['beneficiary_name'] = $found['full_name'];
                        logAudit($pdo, 'login', "Beneficiary auto-logged in via registration check: " . $found['full_name']);
                        redirect('beneficiary/dashboard.php');
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['beneficiary_id']   = (int)$found['id'];
                        $_SESSION['beneficiary_name'] = $found['full_name'];
                        logAudit($pdo, 'login', "Beneficiary auto-logged in from master list: " . $found['full_name']);
                        redirect('beneficiary/dashboard.php');
                    }
                }
            }
        } elseif ($action === 'setpass') {
            $step = 'setpass';
            $bid = (int)($_SESSION['reg_beneficiary_id'] ?? 0);
            if ($bid <= 0) {
                $errors[] = 'Session expired. Please search for your record again.';
                $step = 'search';
            } else {
                $stmt = $pdo->prepare("SELECT * FROM beneficiaries WHERE id = ?");
                $stmt->execute([$bid]);
                $found = $stmt->fetch();
                if (!$found || !empty($found['password_hash'])) {
                    $errors[] = 'This record is not eligible for registration.';
                    $step = 'search';
                    unset($_SESSION['reg_beneficiary_id']);
                } else {
                    $password = $_POST['password'] ?? '';
                    $confirm = $_POST['confirm_password'] ?? '';
                    if (strlen($password) < 6) {
                        $errors[] = 'Password must be at least 6 characters.';
                    }
                    if ($password !== $confirm) {
                        $errors[] = 'Passwords do not match.';
                    }
                    if (!$errors) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $pdo->prepare("UPDATE beneficiaries SET password_hash = ? WHERE id = ? AND password_hash IS NULL");
                        $upd->execute([$hash, $bid]);
                        unset($_SESSION['reg_beneficiary_id']);
                        session_regenerate_id(true);
                        $_SESSION['beneficiary_id']   = (int)$bid;
                        if ($found['full_name'] ?? null) { $_SESSION['beneficiary_name'] = $found['full_name']; }
                        logAudit($pdo, 'login', "Beneficiary logged in after setting password: " . ($found['full_name'] ?? $bid));
                        redirect('beneficiary/dashboard.php');
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Beneficiary Registration - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body class="login-body">
<div class="auth-wrap">
    <div class="auth-card" style="max-width:480px;">
        <div class="auth-logo">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="34" height="34" aria-hidden="true">
                <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--gold)"/>
                <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--navy-dark)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <h1 class="auth-title">Ayuda Monitoring System</h1>
        <p class="auth-subtitle" style="font-weight:700;color:var(--navy);">Login as Beneficiary</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($step === 'search'): ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="search">
            <div style="display:flex;gap:10px;">
                <div class="form-group" style="flex:1">
                    <label>Last Name</label>
                    <input type="text" name="last_name" class="form-control" required value="<?= e($_POST['last_name'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:1">
                    <label>First Name</label>
                    <input type="text" name="first_name" class="form-control" required value="<?= e($_POST['first_name'] ?? '') ?>">
                </div>
                <div class="form-group" style="width:80px">
                    <label>M.I.</label>
                    <input type="text" name="middle_initial" class="form-control" maxlength="5" value="<?= e($_POST['middle_initial'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Date of Birth</label>
                <input type="date" name="date_of_birth" class="form-control" required value="<?= e($_POST['date_of_birth'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>District</label>
                <select name="district_id" id="regDistrict" class="form-control" onchange="loadRegBarangays()" required>
                    <option value="">-- Select District --</option>
                    <?php foreach ($districts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= (($_POST['district_id'] ?? 0)==$d['id'])?'selected':'' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Barangay</label>
                <select name="barangay_id" id="regBarangay" class="form-control" required>
                    <option value="">-- Select Barangay --</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
        <?php elseif ($step === 'setpass'): ?>
        <div class="alert alert-info">
            <?= svgIcon('info', 14) ?> Record found. Set your password to continue.
            <?php if ($found): ?><br><strong><?= e($found['full_name']) ?></strong> - <?= e($found['barangay_name']) ?><?php endif; ?>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="setpass">
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" minlength="6" required>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" minlength="6" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Continue</button>
            <a href="register.php" class="btn btn-outline btn-block mt-2">Back</a>
        </form>
        <?php endif; ?>

        <hr style="border:none;border-top:1px solid #eef1f5;margin:16px 0;">
        <div class="text-center">
            <a href="login.php" class="btn-link">Back to Login</a>
        </div>
    </div>
</div>
<script>
const regBarangaysByDistrict = <?php $all=[]; foreach ($districts as $d) { $all[$d['id']] = getBarangays($pdo, $d['id']); } echo json_encode($all); ?>;
function loadRegBarangays(selectedId) {
    const dist = document.getElementById('regDistrict');
    const brgy = document.getElementById('regBarangay');
    if (!dist || !brgy) return;
    brgy.innerHTML = '<option value="">-- Select Barangay --</option>';
    (regBarangaysByDistrict[dist.value] || []).forEach(function(b) {
        const opt = document.createElement('option');
        opt.value = b.id; opt.textContent = b.name;
        if (selectedId && String(selectedId) === String(b.id)) opt.selected = true;
        brgy.appendChild(opt);
    });
}
<?php if (!empty($_POST['barangay_id'])): ?>loadRegBarangays(<?= json_encode($_POST['barangay_id']) ?>);<?php endif; ?>
</script>
</body>
</html>
