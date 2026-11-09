<?php
require_once __DIR__ . '/includes/functions.php';

$type  = $_GET['type']  ?? $_POST['type']  ?? '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$errors = [];
$success = false;
$account = null;

if (!in_array($type, ['user', 'beneficiary'], true) || $token === '') {
    $errors[] = 'This reset link is invalid.';
} else {
    $table = $type === 'user' ? 'users' : 'beneficiaries';
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE reset_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $account = $stmt->fetch();

    if (!$account) {
        $errors[] = 'This reset link is invalid or has already been used.';
    } elseif (strtotime($account['reset_expires']) < time()) {
        $errors[] = 'This reset link has expired. Please request a new one.';
    }
}

if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF()) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!isPasswordStrong($password)) $errors[] = 'Password must be ' . lcfirst(PASSWORD_RULE_HINT);
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $table = $type === 'user' ? 'users' : 'beneficiaries';
        $upd = $pdo->prepare("UPDATE $table SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $upd->execute([password_hash($password, PASSWORD_BCRYPT), $account['id']]);
        $success = true;
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="34" height="34" aria-hidden="true">
                <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--gold)"/>
                <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--navy-dark)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <h1 class="auth-title">Reset Password</h1>
        <p class="auth-subtitle">Choose a new password for your account</p>

        <?php if ($success): ?>
            <div class="alert alert-success">Your password has been updated. You can now log in.</div>
            <a href="login.php" class="btn btn-primary btn-block mt-2">Go to Login</a>
        <?php elseif ($account): ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger"><?= e($err) ?></div>
            <?php endforeach; ?>
            <form method="POST" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" class="form-control" required minlength="8" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}" title="<?= e(PASSWORD_RULE_HINT) ?>">
                    <div class="small text-muted mt-2"><?= e(PASSWORD_RULE_HINT) ?></div>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
            </form>
        <?php else: ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger"><?= e($err) ?></div>
            <?php endforeach; ?>
            <a href="forgot_password.php" class="btn btn-primary btn-block mt-2">Request a New Link</a>
        <?php endif; ?>

        <div style="margin-top:16px;">
            <a href="login.php" class="btn-link">Back to Login</a>
        </div>
    </div>
</div>
</body>
</html>
