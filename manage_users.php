<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff('district_admin'); // Only District Admin can manage staff/admin accounts

$pageTitle = 'Manage Users';
$activePage = 'users';
$errors = [];
$districts = getDistricts($pdo);
$csrfOk = validateCSRF();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfOk) {
    $errors[] = 'Invalid security token. Please refresh the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user']) && $csrfOk) {
    $username  = trim($_POST['username'] ?? '');
    $fullName  = trim($_POST['full_name'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $role      = $_POST['role'] ?? '';
    $barangayId = (int)($_POST['barangay_id'] ?? 0);

    if ($username === '' || strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
    if ($fullName === '') $errors[] = 'Full name is required.';
    if (!in_array($role, ['barangay_staff','district_admin'], true)) $errors[] = 'Please select a valid role.';
    if (strlen($password) < 4 || !isPasswordStrong($password)) $errors[] = 'Password must meet the strong-password policy: ' . PASSWORD_RULE_HINT;
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if ($role === 'barangay_staff' && $barangayId <= 0) $errors[] = 'Please assign a barangay for Barangay Staff accounts.';

    if (!$errors) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $check->execute([$username]);
        if ((int)$check->fetchColumn() > 0) $errors[] = 'That username is already taken.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, barangay_id) VALUES (?,?,?,?,?)");
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_BCRYPT),
            $fullName,
            $role,
            $role === 'barangay_staff' ? $barangayId : null
        ]);
        logAudit($pdo, 'user_create', 'Created ' . $role . ' account "' . $username . '" (' . $fullName . ')');
        setFlash('success', 'Account created successfully for ' . $fullName . '.');
        redirect('manage_users.php');
    }
}

// Approve a self-registered pending staff account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_staff_id']) && $csrfOk) {
    $uid = (int)$_POST['approve_staff_id'];
    $name = $pdo->query("SELECT full_name FROM users WHERE id = $uid")->fetchColumn();
    $pdo->prepare("UPDATE users SET status='approved' WHERE id=? AND status='pending'")->execute([$uid]);
    logAudit($pdo, 'user_approve', 'Approved staff account "' . ($name ?: "#$uid") . '"');
    setFlash('success', 'Staff account approved. They can now log in.');
    redirect('manage_users.php');
}

// Decline (delete) a self-registered pending staff account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decline_staff_id']) && $csrfOk) {
    $uid = (int)$_POST['decline_staff_id'];
    $name = $pdo->query("SELECT full_name FROM users WHERE id = $uid")->fetchColumn();
    $pdo->prepare("DELETE FROM users WHERE id=? AND status='pending'")->execute([$uid]);
    logAudit($pdo, 'user_decline', 'Declined staff registration "' . ($name ?: "#$uid") . '"');
    setFlash('info', 'Staff registration declined and removed.');
    redirect('manage_users.php');
}

// Password reset (in place of delete, to avoid orphaning distribution history tied to a user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password_id']) && $csrfOk) {
    $uid = (int)$_POST['reset_password_id'];
    $name = $pdo->query("SELECT full_name FROM users WHERE id = $uid")->fetchColumn();
    $newPass = $_POST['new_temp_password'] ?? '';
    if (strlen($newPass) < 4 || !isPasswordStrong($newPass)) {
        setFlash('danger', 'Temporary password must meet the strong-password policy: ' . PASSWORD_RULE_HINT);
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($newPass, PASSWORD_BCRYPT), $uid]);
        logAudit($pdo, 'user_password_reset', 'Reset password for account "' . ($name ?: "#$uid") . '"');
        setFlash('success', 'Password reset successfully.');
    }
    redirect('manage_users.php');
}

$pendingStaff = $pdo->query("
    SELECT u.*, b.name AS barangay_name, d.name AS district_name
    FROM users u
    LEFT JOIN barangays b ON b.id = u.barangay_id
    LEFT JOIN districts d ON d.id = b.district_id
    WHERE u.status = 'pending'
    ORDER BY u.created_at
")->fetchAll();

$users = $pdo->query("
    SELECT u.*, b.name AS barangay_name, d.name AS district_name
    FROM users u
    LEFT JOIN barangays b ON b.id = u.barangay_id
    LEFT JOIN districts d ON d.id = b.district_id
    WHERE u.status = 'approved'
    ORDER BY u.role, u.full_name
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
            <?php include __DIR__ . '/includes/flash.php'; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
            <?php endif; ?>

            <?php if ($pendingStaff): ?>
            <div class="section-title">Pending Staff Approvals</div>
            <?php foreach ($pendingStaff as $p): ?>
                <div class="card">
                    <div class="d-flex justify-between align-center flex-wrap gap-2">
                        <div>
                            <div class="li-name"><?= e($p['full_name']) ?> <span class="badge badge-warning">Pending</span></div>
                            <div class="li-sub">
                                @<?= e($p['username']) ?>
                                <?php if ($p['barangay_name']): ?> &bull; <?= e($p['barangay_name']) ?>, <?= e($p['district_name']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="approve_staff_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm">Approve</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Decline and remove this registration?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="decline_staff_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm">Decline</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <div class="section-title" style="justify-content:center;">Create Staff / Admin Account Directly</div>
            <div class="card">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" class="form-control" required value="<?= e($_POST['full_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" required value="<?= e($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="roleSelect" class="form-control" required onchange="toggleBarangay()">
                            <option value="">Select Role</option>
                            <option value="barangay_staff" <?= (($_POST['role'] ?? '')==='barangay_staff')?'selected':'' ?>>Barangay Staff</option>
                            <option value="district_admin" <?= (($_POST['role'] ?? '')==='district_admin')?'selected':'' ?>>District Admin</option>
                        </select>
                    </div>
                    <div class="form-group" id="barangayGroup">
                        <label>Assigned Barangay</label>
                        <select name="district_id_helper" id="districtHelper" class="form-control mb-2" onchange="loadBarangays()">
                            <option value="">Select District First</option>
                            <?php foreach ($districts as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="barangay_id" id="barangaySelect" class="form-control">
                            <option value="">Select Barangay</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" name="create_user" class="btn btn-primary btn-block">Create Account</button>
                </form>
            </div>

            <div class="section-title">Existing Accounts</div>
            <?php foreach ($users as $u): ?>
                <div class="list-item">
                    <div>
                        <div class="li-name"><?= e($u['full_name']) ?> <span class="badge badge-navy"><?= $u['role']==='district_admin'?'District Admin':'Barangay Staff' ?></span></div>
                        <div class="li-sub">
                            @<?= e($u['username']) ?>
                            <?php if ($u['barangay_name']): ?> &bull; <?= e($u['barangay_name']) ?>, <?= e($u['district_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <button class="btn btn-outline btn-sm" onclick="document.getElementById('resetModal<?= $u['id'] ?>').classList.add('show')">Reset Password</button>
                </div>

                <div class="modal-overlay" id="resetModal<?= $u['id'] ?>">
                    <div class="modal-box">
                        <h3 style="margin-top:0;color:var(--navy);">Reset Password</h3>
                        <p class="small text-muted">Set a new temporary password for <strong><?= e($u['full_name']) ?></strong>.</p>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="reset_password_id" value="<?= $u['id'] ?>">
                            <div class="form-group">
                                <input type="text" name="new_temp_password" class="form-control" placeholder="New temporary password" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block mb-2">Reset</button>
                        </form>
                        <button class="btn btn-outline btn-block" onclick="document.getElementById('resetModal<?= $u['id'] ?>').classList.remove('show')">Cancel</button>
                    </div>
                </div>
            <?php endforeach; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<script>
const barangaysByDistrict = <?php $all=[]; foreach ($districts as $d) { $all[$d['id']] = getBarangays($pdo, $d['id']); } echo json_encode($all); ?>;
function loadBarangays() {
    const districtId = document.getElementById('districtHelper').value;
    const sel = document.getElementById('barangaySelect');
    sel.innerHTML = '<option value="">Select Barangay</option>';
    (barangaysByDistrict[districtId] || []).forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id; opt.textContent = b.name;
        sel.appendChild(opt);
    });
}
function toggleBarangay() {
    const role = document.getElementById('roleSelect').value;
    document.getElementById('barangayGroup').style.display = role === 'barangay_staff' ? 'block' : 'none';
}
toggleBarangay();
</script>
</body>
</html>
