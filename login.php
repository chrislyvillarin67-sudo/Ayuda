<?php
require_once __DIR__ . '/includes/functions.php';

if (isStaffLoggedIn()) redirect('dashboard.php');
if (isBeneficiaryLoggedIn()) redirect('beneficiary/dashboard.php');

$error = '';
$districts = getDistricts($pdo);

// DEVIATION (per doc feedback: remove the "select role" step -- it was
// repetitive/annoying on every login). Instead of asking the person to pick
// a role first, we auto-detect the account type from the identifier they
// type in: usernames (Staff/Admin) are globally unique in the `users`
// table, so we try that first, then fall back to checking it as a
// registered Gmail address (Beneficiary).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection on the login form
    if (!validateCSRF()) {
        $error = 'Invalid security token. Please try again.';
    } else {
    $identifier = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';

    // Rate limiting: lock out after 5 failed attempts within 15 minutes
    if (!empty($identifier) && !checkLoginRateLimit($identifier)) {
        $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
        } elseif ($identifier === '' || $password === '') {
            $error = 'Please enter your username/Gmail and password.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();

            // DEVIATION (per user request): Barangay Staff log in with their
            // password and pick whichever District + Barangay they are currently
            // in. The password works in ANY district/barangay; the pick simply
            // locks the data they see to that barangay.
            $loginDistrictId = (int)($_POST['district_id'] ?? 0);
            $loginBarangayId = (int)($_POST['barangay_id'] ?? 0);

            // Validate that the picked barangay exists in the picked district.
            $scopeOk = true;
            if ($user && $user['role'] === 'barangay_staff') {
                if (!$loginBarangayId || !$loginDistrictId) {
                    $scopeOk = false;
                } else {
                    $sc = $pdo->prepare("SELECT COUNT(*) FROM barangays WHERE id=? AND district_id=?");
                    $sc->execute([$loginBarangayId, $loginDistrictId]);
                    $scopeOk = (int)$sc->fetchColumn() > 0;
                }
            }

            if ($user) {
                if (!password_verify($password, $user['password_hash'])) {
                    recordLoginFailure($identifier);
                    $error = 'Incorrect password.';
                } elseif ($user['status'] === 'pending') {
                    $error = 'Your account is awaiting District Admin approval. Please check back later.';
                } elseif ($user['role'] === 'barangay_staff' && !$scopeOk) {
                    $error = 'Please select the District and Barangay you are in.';
                } else {
                    clearLoginAttempts($identifier);
                    session_regenerate_id(true); // Prevent session fixation
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['username']   = $user['username'];
                    $_SESSION['full_name']  = $user['full_name'];
                    $_SESSION['role']       = $user['role'];
                    // Staff scope = whatever district/barangay they picked.
                    $_SESSION['barangay_id']= $user['role'] === 'barangay_staff'
                                              ? ($loginBarangayId ?: $user['barangay_id'])
                                              : $user['barangay_id'];
                    $_SESSION['district_id']= $loginDistrictId;
                    $_SESSION['photo_path'] = $user['photo_path'] ?? null;
                    logAudit($pdo, 'login', "Staff/admin signed in: " . $user['username'] . " (" . $user['role'] . ")");
                    redirect('dashboard.php');
                }
            } else {
            // Not a Staff/Admin username -- try it as a beneficiary's Gmail address.
            $stmt = $pdo->prepare("SELECT * FROM beneficiaries WHERE beneficiary_code = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $ben = $stmt->fetch();
            if ($ben && $ben['password_hash'] && password_verify($password, $ben['password_hash'])) {
                session_regenerate_id(true); // Prevent session fixation
                $_SESSION['beneficiary_id']   = $ben['id'];
                $_SESSION['beneficiary_name'] = $ben['full_name'];
                logAudit($pdo, 'login', "Beneficiary signed in: " . $ben['full_name']);
                redirect('beneficiary/dashboard.php');
            } else {
                recordLoginFailure($identifier);
                $error = 'We could not find an account matching that username or Gmail address.';
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Login - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body class="login-body">
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="34" height="34" aria-hidden="true">
                <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--gold)"/>
                <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--navy-dark)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <h1 class="auth-title">Ayuda Monitoring System</h1>
        <p class="auth-subtitle">Sign in to your account</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <?php $f = getFlash(); if ($f): ?>
            <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrfField() ?>
            <div class="form-group">
                <label>Username or Gmail Address</label>
                <input type="text" name="username" id="usernameField" class="form-control" required value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="pw-wrap">
                    <input type="password" name="password" id="loginPassword" class="form-control" required autocomplete="current-password">
                    <button type="button" class="pw-toggle" onclick="togglePassword('loginPassword', this)" aria-label="Show password" title="Show password"><?= svgIcon('eyeOff', 17) ?></button>
                </div>
            </div>

            <div class="staff-scope" id="staffScope">
                <div style="display:flex;flex-direction:column;align-items:center;gap:2px;margin-bottom:6px;">
                        <div class="small" style="color:var(--navy);font-weight:700;"><?= svgIcon('user', 15) ?> Select Your District &amp; Barangay</div>
                        <div class="small" style="color:#6b7d90;">(required for Barangay Staff)</div>
                    </div>
                <div class="form-group">
                    <label>District</label>
                    <select name="district_id" id="loginDistrict" class="form-control" onchange="loginLoadBarangays()">
                        <option value="">-- Select District --</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= (($_POST['district_id'] ?? 0) == $d['id']) ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Barangay</label>
                    <select name="barangay_id" id="loginBarangay" class="form-control">
                        <option value="">-- Select Barangay --</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div style="margin:16px 0 4px;">
            <a href="forgot_password.php" class="btn-link">Forgot Password?</a>
        </div>

        <hr style="border:none;border-top:1px solid #eef1f5;margin:20px 0;">

        <p class="small text-muted" style="margin-bottom:10px;">Login as Beneficiary</p>
        <a href="register.php" class="btn btn-gold btn-block" style="margin-bottom:8px;">Login</a>

        <hr style="border:none;border-top:1px solid #eef1f5;margin:20px 0;">

        <p class="small" style="margin-top:18px;">
            <a href="terms.php?back=login.php" style="color:var(--navy-light);">Terms &amp; Conditions</a>
        </p>
    </div>
</div>
<script>
function togglePassword(id, btn) {
    const inp = document.getElementById(id);
    const nowShown = inp.type === 'text';
    inp.type = nowShown ? 'password' : 'text';
    btn.innerHTML = nowShown ? '<?= svgIcon('eyeOff', 17) ?>' : '<?= svgIcon('eye', 17) ?>';
    btn.title = nowShown ? 'Show password' : 'Hide password';
    btn.setAttribute('aria-label', btn.title);
}

// District -> Barangay picker for staff login. Always visible; populated on
// load so a previously selected district/barangay persists after a failed try.
const loginBarangaysByDistrict = <?php $all=[]; foreach ($districts as $d) { $all[$d['id']] = getBarangays($pdo, $d['id']); } echo json_encode($all); ?>;
function loginLoadBarangays(selectedId) {
    const dist = document.getElementById('loginDistrict');
    const brgy = document.getElementById('loginBarangay');
    if (!dist || !brgy) return;
    brgy.innerHTML = '<option value="">-- Select Barangay --</option>';
    (loginBarangaysByDistrict[dist.value] || []).forEach(function(b) {
        const opt = document.createElement('option');
        opt.value = b.id; opt.textContent = b.name;
        if (selectedId && String(selectedId) === String(b.id)) opt.selected = true;
        brgy.appendChild(opt);
    });
}
<?php
$persistedBarangay = (int)($_POST['barangay_id'] ?? 0);
$persistedDistrict = (int)($_POST['district_id'] ?? 0);
?>
loginLoadBarangays(<?= json_encode($persistedDistrict ? $persistedBarangay : null) ?>);
</script>
<?php include __DIR__ . '/includes/chatbot_widget.php'; ?>
</body>
</html>
<?php include __DIR__ . '/includes/chatbot_widget.php'; ?>
</body>
</html>
