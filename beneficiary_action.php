<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json');

if (!isStaffLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

if (!validateCSRF()) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'approve' && $id > 0) {
    $isAdmin = $_SESSION['role'] === 'district_admin';
    if (!$isAdmin && $_SESSION['role'] !== 'barangay_staff') {
        echo json_encode(['success' => false, 'message' => 'Not authorized.']);
        exit;
    }

    // Barangay staff may only process beneficiaries within their own barangay
    if ($_SESSION['role'] === 'barangay_staff') {
        $check = $pdo->prepare("SELECT barangay_id FROM beneficiaries WHERE id = ?");
        $check->execute([$id]);
        $row = $check->fetch();
        if (!$row || (int)$row['barangay_id'] !== (int)$_SESSION['barangay_id']) {
            echo json_encode(['success' => false, 'message' => 'You can only process beneficiaries in your barangay.']);
            exit;
        }
    }

    // DEVIATION per "Chrisly Villarin.docx" (Section 2, Rule of hierarchy):
    // Barangay Staff does NOT hold final approval authority. Staff approval
    // only performs the initial verification and submits the record to CSWDO.
    // Only the District Admin performs the final approval/validation.
    if ($isAdmin) {
        $stmt = $pdo->prepare("UPDATE beneficiaries SET status='approved', verification_step='approved', approved_by=?, approved_at=NOW(), rejected_at=NULL, rejected_reason=NULL, return_reason=NULL WHERE id=? AND status IN ('pending','returned')");
        $stmt->execute([$_SESSION['user_id'], $id]);

        if ($stmt->rowCount() > 0) {
            require_once __DIR__ . '/includes/mailer.php';
            $ben = $pdo->prepare("SELECT beneficiary_code, full_name FROM beneficiaries WHERE id = ?");
            $ben->execute([$id]);
            if ($b = $ben->fetch()) {
                mailBeneficiaryApproved($b['beneficiary_code'], $b['full_name']);
                logAudit($pdo, 'beneficiary_approve', 'Approved beneficiary #' . $id . ' (' . $b['full_name'] . ')');
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // Barangay staff: initial verification -> submit to CSWDO for validation
    $res = $pdo->prepare("UPDATE beneficiaries SET status='pending', verification_step='cswdo', rejected_at=NULL, rejected_reason=NULL, return_reason=NULL WHERE id=? AND status IN ('pending','returned')");
    $res->execute([$id]);

    if ($res->rowCount() > 0) {
        $ben = $pdo->prepare("SELECT beneficiary_code, full_name FROM beneficiaries WHERE id = ?");
        $ben->execute([$id]);
        $bname = ($b = $ben->fetch()) ? $b['full_name'] : ('#' . $id);
        logAudit($pdo, 'beneficiary_submit_cswdo', 'Barangay Staff submitted beneficiary #' . $id . ' (' . $bname . ') to CSWDO for validation');
    }

    echo json_encode(['success' => true, 'submitted' => true]);
    exit;
}

// Reject an application (CSWDO/admin only) with a reason recorded.
if ($action === 'reject' && $id > 0 && $_SESSION['role'] === 'district_admin') {
    $reason = trim($_POST['reason'] ?? 'No reason provided.');
    $stmt = $pdo->prepare("UPDATE beneficiaries SET status='rejected', rejected_at=NOW(), rejected_reason=?, return_reason=NULL WHERE id=? AND status IN ('pending','returned')");
    $stmt->execute([$reason, $id]);
    logAudit($pdo, 'beneficiary_reject', 'Rejected beneficiary #' . $id . ' (' . $reason . ')');
    echo json_encode(['success' => true]);
    exit;
}

// Return an application for correction (CSWDO/admin only) with an explanation.
if ($action === 'return' && $id > 0 && $_SESSION['role'] === 'district_admin') {
    $reason = trim($_POST['reason'] ?? 'Please correct and resubmit your application.');
    $stmt = $pdo->prepare("UPDATE beneficiaries SET status='returned', return_reason=?, rejected_at=NULL, rejected_reason=NULL WHERE id=? AND status='pending'");
    $stmt->execute([$reason, $id]);
    logAudit($pdo, 'beneficiary_return', 'Returned beneficiary #' . $id . ' for correction (' . $reason . ')');
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
