<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = 'Profile / Settings';
$activePage = 'profile';
$isAdmin = $_SESSION['role'] === 'district_admin';
$errors = [];

$stmt = $pdo->prepare("SELECT u.*, b.name AS barangay_name, d.name AS district_name
                        FROM users u
                        LEFT JOIN barangays b ON b.id = u.barangay_id
                        LEFT JOIN districts d ON d.id = b.district_id
                        WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$csrfOk = validateCSRF();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfOk) {
    $errors[] = 'Invalid security token. Please refresh the page and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password']) && $csrfOk) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 4) {
        $errors[] = 'New password must be at least 4 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    } else {
        $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]);
        setFlash('success', 'Password updated successfully.');
        redirect('profile.php');
    }
}

// Profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_photo']) && $csrfOk) {
    if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please choose a photo to upload.';
    } elseif ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed. Please try again.';
    } else {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['profile_photo']['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            $errors[] = 'Only JPG, PNG, or WEBP images are allowed.';
        } elseif ($_FILES['profile_photo']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Photo must be smaller than 5MB.';
        } else {
            $uploadDir = __DIR__ . '/assets/uploads/profiles/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $filename = 'user_' . $_SESSION['user_id'] . '_' . time() . '.' . $allowed[$mime];
            $destPath = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destPath)) {
                // Remove old photo file, if any
                if (!empty($user['photo_path'])) {
                    $oldFile = __DIR__ . '/' . $user['photo_path'];
                    if (is_file($oldFile)) @unlink($oldFile);
                }
                $relativePath = 'assets/uploads/profiles/' . $filename;
                $pdo->prepare("UPDATE users SET photo_path = ? WHERE id = ?")->execute([$relativePath, $_SESSION['user_id']]);
                $_SESSION['photo_path'] = $relativePath;
                setFlash('success', 'Profile photo updated successfully.');
                redirect('profile.php');
            } else {
                $errors[] = 'Could not save the uploaded photo. Please try again.';
            }
        }
    }
}

// Chatbot icon upload (District Admin only Ã¢â‚¬â€ applies system-wide)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_chatbot_icon']) && $csrfOk && $isAdmin) {
    if (!isset($_FILES['chatbot_icon']) || $_FILES['chatbot_icon']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please choose an icon image to upload.';
    } elseif ($_FILES['chatbot_icon']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed. Please try again.';
    } else {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['chatbot_icon']['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            $errors[] = 'Only JPG, PNG, or WEBP images are allowed.';
        } elseif ($_FILES['chatbot_icon']['size'] > 3 * 1024 * 1024) {
            $errors[] = 'Icon image must be smaller than 3MB.';
        } else {
            $uploadDir = __DIR__ . '/assets/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // Remove any existing assistant.* / chatbot_icon.* first.
            // Stored name is "assistant.<ext>" (neutral) so hosts that block
            // URLs containing "chatbot_icon" still serve it.
            foreach (['assistant', 'chatbot_icon'] as $base) {
                foreach (['png','jpg','jpeg','webp'] as $ext) {
                    $old = $uploadDir . $base . '.' . $ext;
                    if (is_file($old)) @unlink($old);
                }
            }

            $destPath = $uploadDir . 'assistant.' . $allowed[$mime];
            if (move_uploaded_file($_FILES['chatbot_icon']['tmp_name'], $destPath)) {
                setFlash('success', 'Chatbot icon updated successfully.');
                redirect('profile.php');
            } else {
                $errors[] = 'Could not save the uploaded icon. Please try again.';
            }
        }
    }
}

// Current profile info for the photo/role/system cards.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile - Ayuda Monitoring System</title>
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

            <div class="card" style="">
                <div class="d-flex align-center gap-3 mb-3">
                    <div class="user-avatar" style="width:56px;height:56px;font-size:20px;overflow:hidden;">
                        <?php if (!empty($user['photo_path']) && is_file(__DIR__ . '/' . $user['photo_path'])): ?>
                            <img src="<?= e($user['photo_path']) ?>?v=<?= time() ?>" alt="Profile photo" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                            <?php $ini=''; foreach (explode(' ', $user['full_name']) as $p) if($p!=='') $ini.=strtoupper($p[0]); echo e(substr($ini,0,2)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:16px;color:var(--navy);"><?= e($user['full_name']) ?></div>
                        <div class="small text-muted">@<?= e($user['username']) ?></div>
                    </div>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label>Change Profile Photo</label>
                        <input type="file" name="profile_photo" accept="image/png,image/jpeg,image/webp" class="form-control">
                        <div class="small text-muted mt-2">JPG, PNG, or WEBP. Max 5MB.</div>
                    </div>
                    <button type="submit" name="change_photo" class="btn btn-outline btn-block">Upload Photo</button>
                </form>
                <div class="list-item" style="margin-top:14px;"><div><div class="li-sub">Role</div><div class="li-name"><?= $isAdmin ? 'District Admin' : 'Barangay Staff' ?></div></div></div>
                <?php if (!$isAdmin): ?>
                <div class="list-item"><div><div class="li-sub">Barangay Assignment</div><div class="li-name"><?= e($user['barangay_name']) ?> (<?= e($user['district_name']) ?>)</div></div></div>
                <?php endif; ?>
                <div class="list-item"><div><div class="li-sub">Account Created</div><div class="li-name"><?= date('F j, Y', strtotime($user['created_at'])) ?></div></div></div>
            </div>

            <div class="card" style="">
                <h3 style="margin-top:0;font-size:14.5px;color:var(--navy);">Change Password</h3>
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" name="change_password" class="btn btn-primary btn-block">Update Password</button>
                </form>
            </div>

            <?php if ($isAdmin): ?>
            <div class="card" style="">
                <h3 style="margin-top:0;font-size:14.5px;color:var(--navy);">Chatbot Icon</h3>
                <p class="small text-muted">Upload a custom icon for the help chat button shown throughout the app (JPG, PNG, or WEBP, max 3MB).</p>
                <?php
                    $__currentIcon = null;
                    foreach (['assistant', 'chatbot_icon'] as $__base) {
                        foreach (['png','jpg','jpeg','webp'] as $__ext) {
                            if (is_file(__DIR__ . '/assets/uploads/' . $__base . '.' . $__ext)) { $__currentIcon = 'assets/uploads/' . $__base . '.' . $__ext . '?v=' . @filemtime(__DIR__ . '/assets/uploads/' . $__base . '.' . $__ext); break 2; }
                        }
                    }
                    if (!$__currentIcon && is_file(__DIR__ . '/assets/uploads/profiles/gem.jpg')) { $__currentIcon = 'assets/uploads/profiles/gem.jpg'; }
                ?>
                <?php if ($__currentIcon): ?>
                    <div class="d-flex align-center gap-3 mb-3">
                        <div class="user-avatar" style="width:48px;height:48px;overflow:hidden;">
                            <img src="<?= e($__currentIcon) ?>" alt="Current chatbot icon" style="width:100%;height:100%;object-fit:cover;">
                        </div>
                        <div class="small text-muted">Current icon</div>
                    </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <input type="file" name="chatbot_icon" accept="image/png,image/jpeg,image/webp" class="form-control">
                    </div>
                    <button type="submit" name="change_chatbot_icon" class="btn btn-outline btn-block">Upload Chatbot Icon</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="card" style="">
                <h3 style="margin-top:0;font-size:14.5px;color:var(--navy);">System Info</h3>
                <p class="small text-muted mb-0">Ayuda Distribution and Monitoring System &bull; Davao City<br>Version 1.0</p>
            </div>

            <a href="logout.php" class="btn btn-danger btn-block" style="">Logout</a>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
</body>
</html>
