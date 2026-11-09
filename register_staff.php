<?php
require_once __DIR__ . '/includes/functions.php';

if (isStaffLoggedIn()) redirect('dashboard.php');
if (isBeneficiaryLoggedIn()) redirect('beneficiary/dashboard.php');

$districts = getDistricts($pdo);
$errors = [];
$old = $_POST;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF()) $errors[] = 'Invalid security token. Please refresh the page and try again.';

    $fullName   = trim($_POST['full_name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $districtId = (int)($_POST['district_id'] ?? 0);
    $barangayId = (int)($_POST['barangay_id'] ?? 0);

    if ($fullName === '') $errors[] = 'Full name is required.';
    if ($username === '' || strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
    if ($districtId <= 0) $errors[] = 'Please select a district.';
    if ($barangayId <= 0) $errors[] = 'Please select a barangay.';
    if (!isPasswordStrong($password)) $errors[] = 'Password must be ' . lcfirst(PASSWORD_RULE_HINT);
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if (!isset($_POST['agree_terms'])) $errors[] = 'You must agree to the Terms and Conditions to register.';

    if (!$errors) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $check->execute([$username]);
        if ((int)$check->fetchColumn() > 0) $errors[] = 'That username is already taken.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, barangay_id, status)
                                VALUES (?,?,?, 'barangay_staff', ?, 'pending')");
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $fullName, $barangayId]);

        // Only fires if the username happens to be a valid email address --
        // ayudaSendMail() silently no-ops otherwise, so this is safe either way.
        require_once __DIR__ . '/includes/mailer.php';
        mailWelcome($username, $fullName, 'barangay_staff');

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register as Barangay Staff - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell">
    <div class="app-header">
        <a href="login.php" class="icon-btn"><?= svgIcon('left', 20) ?></a>
        <div style="text-align:center;">
            <h1>Barangay Staff Registration</h1>
            <div class="header-sub">Ayuda Monitoring System</div>
        </div>
        <span style="width:20px;"></span>
    </div>

    <div class="content">
        <?php if ($success): ?>
            <div class="card text-center">
                <div style="display:flex;justify-content:center;margin-bottom:10px;color:var(--navy);"><?= svgIcon('clock', 40) ?></div>
                <h3 style="color:var(--navy);">Account Submitted</h3>
                <p class="small text-muted">
                    Your Barangay Staff account has been created and is <strong>pending approval</strong>
                    from the District Admin. You'll be able to log in once approved.
                </p>
                <a href="login.php" class="btn btn-primary btn-block">Back to Login</a>
            </div>
        <?php else: ?>
            <div class="alert alert-info small">
                Barangay Staff accounts require District Admin approval before you can log in.
                District Admin accounts are never self-registered â€” they're created directly by
                an existing admin.
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
                </div>
            <?php endif; ?>

            <div class="card">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" class="form-control" required value="<?= e($old['full_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" required value="<?= e($old['username'] ?? '') ?>">
                    </div>
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
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required minlength="8" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" title="<?= e(PASSWORD_RULE_HINT) ?>">
                        <div class="small text-muted mt-2"><?= e(PASSWORD_RULE_HINT) ?></div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="8">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-start;gap:8px;">
                        <input type="checkbox" name="agree_terms" id="agreeTerms" value="1" style="margin-top:3px;" <?= isset($old['agree_terms']) ? 'checked' : '' ?> required>
                        <label for="agreeTerms" style="font-weight:400;font-size:13px;margin-bottom:0;">
                            I have read and agree to the
                            <a href="terms.php?back=register_staff.php" target="_blank" style="color:var(--navy-light);font-weight:700;">Terms and Conditions</a>.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Submit for Approval</button>
                    <a href="login.php" class="btn btn-outline btn-block mt-2">Cancel</a>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const barangaysByDistrict = <?php $all=[]; foreach ($districts as $d) { $all[$d['id']] = getBarangays($pdo, $d['id']); } echo json_encode($all); ?>;
const districtSelect = document.getElementById('districtSelect');
const barangaySelect = document.getElementById('barangaySelect');
function populateBarangays(selectedId) {
    if (!districtSelect) return;
    const id = districtSelect.value;
    barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
    (barangaysByDistrict[id] || []).forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id; opt.textContent = b.name;
        if (selectedId && String(selectedId) === String(b.id)) opt.selected = true;
        barangaySelect.appendChild(opt);
    });
}
if (districtSelect) {
    districtSelect.addEventListener('change', () => populateBarangays(null));
    <?php if (!empty($old['district_id'])): ?>populateBarangays(<?= json_encode($old['barangay_id'] ?? null) ?>);<?php endif; ?>
}
</script>
<?php include __DIR__ . '/includes/chatbot_widget.php'; ?>
</body>
</html>
