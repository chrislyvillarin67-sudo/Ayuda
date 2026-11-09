<?php
require_once __DIR__ . '/../includes/functions.php';
requireBeneficiary();

$errors = [];
$csrfOk = validateCSRF();

$stmt = $pdo->prepare("
    SELECT b.*, br.name AS barangay_name, d.name AS district_name
    FROM beneficiaries b
    JOIN barangays br ON br.id = b.barangay_id
    JOIN districts d ON d.id = b.district_id
    WHERE b.id = ?
");
$stmt->execute([$_SESSION['beneficiary_id']]);
$beneficiary = $stmt->fetch();
if (!$beneficiary) { unset($_SESSION['beneficiary_id']); redirect('../login.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_photo']) && $csrfOk) {
    if (!isset($_FILES['profile_photo']) || ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please choose a photo to upload.';
    } elseif (($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
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
            $uploadDir = dirname(__DIR__) . '/assets/uploads/profiles/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = 'ben_' . (int)$beneficiary['id'] . '_' . time() . '.' . $allowed[$mime];
            $destPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destPath)) {
                if (!empty($beneficiary['photo_path'])) {
                    $oldFile = dirname(__DIR__) . '/' . ltrim((string)$beneficiary['photo_path'], '/');
                    if (is_file($oldFile)) @unlink($oldFile);
                }
                $relativePath = 'assets/uploads/profiles/' . $filename;
                $pdo->prepare("UPDATE beneficiaries SET photo_path = ? WHERE id = ?")->execute([$relativePath, (int)$beneficiary['id']]);
                setFlash('success', 'Profile photo updated.');
                redirect('profile.php');
            } else {
                $errors[] = 'Could not save the uploaded photo. Please try again.';
            }
        }
    }
}

$stmt->execute([$_SESSION['beneficiary_id']]);
$beneficiary = $stmt->fetch();

$benCategories = beneficiaryCategories($pdo, (int)$beneficiary['id']);
$status = $beneficiary['status'] ?: 'pending';
$hasUnseenUpdates = beneficiaryHasUnseenUpdates($pdo, $beneficiary);
$activePage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Profile - Ayuda Monitoring System</title>
<link rel="stylesheet" href="../assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell" style="padding-bottom:80px;">
    <header class="app-header" style="justify-content:center;position:relative;top:auto;flex-wrap:wrap;height:auto;min-height:0;overflow:visible;padding:12px 10px 10px;box-sizing:border-box;width:100%;">
        <div class="app-header-title" style="position:static;transform:none;max-width:100%;width:100%;text-align:center;flex:1 1 100%;pointer-events:auto;min-width:0;">
            <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:2px;flex-wrap:wrap;min-width:0;width:100%;">
                <span style="width:30px;height:30px;border-radius:10px;background:var(--gold);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" aria-hidden="true">
                        <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--navy-dark)"/>
                        <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--gold)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <h1 style="font-size:clamp(15px,4.8vw,18px);margin:0;color:#fff;line-height:1.15;white-space:normal;">Ayuda Monitoring System</h1>
            </div>
            <div class="header-sub" style="white-space:normal;overflow:visible;text-overflow:clip;line-height:1.2;"><?= e($beneficiary['barangay_name']) ?>, <?= e($beneficiary['district_name']) ?></div>
        </div>
    </header>

    <main class="main-content">
        <div style="margin:14px 16px 10px;">
            <div style="font-size:18px;font-weight:800;color:var(--navy);">Profile</div>
        </div>
        <?php include __DIR__ . '/../includes/flash.php'; ?>
        <?php if ($errors): ?><div class="alert alert-danger" style="margin:0 16px 12px;"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

        <div class="card" style="margin:0 16px 12px;background:linear-gradient(135deg, var(--navy), var(--navy-light));color:#fff;">
            <div style="display:flex;align-items:center;gap:12px;">
                <button type="button" onclick="openPhotoModal()" title="Change profile photo" style="width:62px;height:62px;border-radius:50%;overflow:hidden;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;border:2px solid rgba(255,255,255,.25);padding:0;cursor:pointer;">
                    <?php $photo = $beneficiary['photo_path'] ?? ''; $photoFile = $photo ? (dirname(__DIR__) . '/' . ltrim($photo,'/')) : ''; if ($photo && is_file($photoFile)): ?>
                        <img src="<?= e('../' . ltrim($photo,'/')) ?>?v=<?= @filemtime($photoFile) ?: time() ?>" alt="Profile photo" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <span style="font-weight:800;font-size:18px;color:#fff;"><?= e(mb_substr($beneficiary['first_name'] ?: $beneficiary['full_name'], 0, 1)) ?></span>
                    <?php endif; ?>
                </button>
                <div>
                    <div style="font-weight:700;"><?= e($beneficiary['full_name']) ?></div>
                    <div class="small" style="opacity:0.9;">Beneficiary ID: <?= e($beneficiary['beneficiary_code'] ?: $beneficiary['id']) ?></div>
                    <div class="small" style="opacity:0.9;margin-top:4px;">Status: <?= ucfirst(e(statusLabel($status))) ?></div>
                </div>
            </div>
        </div>

        <div class="modal-overlay" id="photoModal">
            <div class="modal-box" style="max-width:420px;">
                <h3 style="margin-top:0;font-size:17px;color:var(--navy);">Change Profile Photo</h3>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div style="display:flex;justify-content:center;margin:4px 0 12px;">
                        <div style="width:84px;height:84px;border-radius:50%;overflow:hidden;background:#eef2f7;border:2px solid #d7e2ee;display:flex;align-items:center;justify-content:center;color:var(--navy);font-weight:800;font-size:22px;">
                            <?php if ($photo && is_file($photoFile)): ?>
                                <img src="<?= e('../' . ltrim($photo,'/')) ?>?v=<?= @filemtime($photoFile) ?: time() ?>" alt="Profile photo" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <?= e(mb_substr($beneficiary['first_name'] ?: $beneficiary['full_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Choose file</label>
                        <input type="file" id="benPhotoInput" name="profile_photo" accept="image/png,image/jpeg,image/webp" class="form-control" onchange="showPhotoFileInfo(this)">
                        <div id="benPhotoInfo" class="small text-muted" style="margin-top:8px;padding:8px 10px;border:1px dashed #cfd8e3;border-radius:8px;background:#fafcff;">Allowed files: JPG, JPEG, PNG, WEBP (max 5MB)</div>
                    </div>
                    <button type="submit" name="change_photo" class="btn btn-gold btn-block">Upload Photo</button>
                    <button type="button" class="btn btn-outline btn-block mt-2" onclick="closePhotoModal()">Cancel</button>
                </form>
            </div>
        </div>

        <div class="section-title" style="margin:6px 16px 8px;">Personal Information</div>
        <div class="card" style="margin:0 16px 12px;">
            <div class="list-item"><div><div class="li-sub">Full Name</div><div class="li-name"><?= e($beneficiary['full_name']) ?></div></div></div>
            <div class="list-item"><div><div class="li-sub">Address</div><div class="li-name"><?= e($beneficiary['address']) ?></div></div></div>
            <?php if ($beneficiary['contact_number']): ?><div class="list-item"><div><div class="li-sub">Contact Number</div><div class="li-name"><?= e($beneficiary['contact_number']) ?></div></div></div><?php endif; ?>
            <?php if ($beneficiary['date_of_birth']): ?><div class="list-item"><div><div class="li-sub">Date of Birth</div><div class="li-name"><?= e($beneficiary['date_of_birth']) ?></div></div></div><?php endif; ?>
            <?php if ($beneficiary['beneficiary_code']): ?><div class="list-item"><div><div class="li-sub">Gmail / Beneficiary Code</div><div class="li-name" style="word-break:break-all;"><?= e($beneficiary['beneficiary_code']) ?></div></div></div><?php endif; ?>
        </div>

        <div class="section-title" style="margin:6px 16px 8px;">Assistance Category</div>
        <div class="card" style="margin:0 16px 14px;">
            <div class="list-item"><div><div class="li-name"><?= e(implode(', ', $benCategories ?: [(string)$beneficiary['category']])) ?></div></div></div>
        </div>
    </main>
</div>

<?php
$benNavActive = 'profile';
$benHasUnread = $hasUnseenUpdates;
include __DIR__ . '/_bottomnav.php';
?>
<script>
function openPhotoModal(){ var m=document.getElementById('photoModal'); if(m) m.classList.add('show'); }
function closePhotoModal(){ var m=document.getElementById('photoModal'); var i=document.getElementById('benPhotoInput'); var info=document.getElementById('benPhotoInfo'); if(m) m.classList.remove('show'); if(i) i.value=''; if(info) info.textContent='Allowed files: JPG, JPEG, PNG, WEBP (max 5MB)'; }
function showPhotoFileInfo(input){ var info=document.getElementById('benPhotoInfo'); if(!info||!input||!input.files||!input.files[0]) return; var f=input.files[0]; var size=f.size<1048576?(f.size/1024).toFixed(1)+' KB':(f.size/1048576).toFixed(2)+' MB'; info.innerHTML='<strong>Selected:</strong> '+f.name+'<br><strong>Type:</strong> '+(f.type||'unknown')+'<br><strong>Size:</strong> '+size; }
var pm=document.getElementById('photoModal'); if(pm){ pm.addEventListener('click', function(e){ if(e.target===pm) closePhotoModal(); }); }
</script>
</body>
</html>
