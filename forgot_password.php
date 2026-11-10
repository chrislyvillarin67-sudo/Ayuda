<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

if (isStaffLoggedIn()) redirect('dashboard.php');
if (isBeneficiaryLoggedIn()) redirect('beneficiary/dashboard.php');

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF()) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
        $error = 'Please enter your username or Gmail address.';
    } else {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

        // Same auto-detect approach as login.php: try Staff/Admin username first, then Beneficiary Gmail.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();

        if ($user) {
            $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $upd->execute([$token, $expires, $user['id']]);
            $resetUrl = BASE_URL . 'reset_password.php?type=user&token=' . $token;
            if (filter_var($user['username'], FILTER_VALIDATE_EMAIL)) {
                mailPasswordReset($user['username'], $user['full_name'], $resetUrl);
            } else {
                // Username isn't an email -- we can't send a reset link automatically.
                // Fail closed with a generic message below (don't reveal account existence either way).
            }
        } else {
            $stmt = $pdo->prepare("SELECT * FROM beneficiaries WHERE beneficiary_code = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $ben = $stmt->fetch();
            if ($ben) {
                $upd = $pdo->prepare("UPDATE beneficiaries SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $upd->execute([$token, $expires, $ben['id']]);
                $resetUrl = BASE_URL . 'reset_password.php?type=beneficiary&token=' . $token;
                mailPasswordReset($ben['beneficiary_code'], $ben['full_name'], $resetUrl);
            }
        }

        // Always show the same success message, whether or not the account
        // exists / has an emailable username -- avoids leaking which
        // accounts are registered.
        $sent = true;
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - Ayuda Monitoring System</title>
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
        <h1 class="auth-title">Forgot Password</h1>
        <p class="auth-subtitle">We'll email you a link to reset it</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($sent): ?>
            <div class="alert alert-success">
                If an account matches that username/Gmail, a password reset link has been sent. Check your inbox (and spam folder) -- the link expires in 30 minutes.
            </div>
            <a href="login.php" class="btn btn-primary btn-block mt-2">Back to Login</a>
        <?php else: ?>
            <form method="POST" novalidate>
                <?= csrfField() ?>
                <div class="form-group">
                    <label>Username or Gmail Address</label>
                    <input type="text" name="identifier" class="form-control" required value="<?= e($_POST['identifier'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
            </form>
            <div style="margin-top:16px;">
                <a href="login.php" class="btn-link">Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
