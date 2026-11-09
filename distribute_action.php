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

$isAdmin = $_SESSION['role'] === 'district_admin';

// Barangay scope: staff are locked to their own barangay; the District Admin
// may target any barangay selected on the Distribution screen.
$barangayId = $isAdmin
    ? (int)($_POST['barangay_id'] ?? 0)
    : (int)$_SESSION['barangay_id'];

$beneficiaryId = (int)($_POST['beneficiary_id'] ?? 0);

// Accept either inventory_id (distribution screen) or item_name (inventory.php)
$inventoryId = (int)($_POST['inventory_id'] ?? 0);
$itemName = trim($_POST['item_name'] ?? '');

if ($barangayId <= 0 || ($inventoryId <= 0 && $itemName === '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$quantity = (int)($_POST['quantity'] ?? 1);
$amount   = (float)($_POST['amount'] ?? 0);
$location = trim($_POST['location'] ?? '');
$description = trim($_POST['description'] ?? '');

try {
    $pdo->beginTransaction();

    // If a beneficiary is named, confirm they belong to the target barangay
    // and are approved (District Admin may instead record a description).
    if ($beneficiaryId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM beneficiaries WHERE id=? AND barangay_id=? AND status='approved'");
        $stmt->execute([$beneficiaryId, $barangayId]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Beneficiary not eligible.']);
            exit;
        }
    }

    // Lock and fetch inventory row (by id, else fall back to item_name).
    // Staff must stay within their barangay; admin targets the fixed item.
    // CASH is the one shared citywide pool (barangay_id IS NULL), so it is
    // reachable from any barangay -- otherwise staff could never release cash.
    if ($inventoryId > 0) {
        $inv = $pdo->prepare("SELECT * FROM inventory WHERE id=?" . ($isAdmin ? '' : ' AND (barangay_id=? OR (assistance_type=\'cash\' AND barangay_id IS NULL))') . " FOR UPDATE");
        $params = $isAdmin ? [$inventoryId] : [$inventoryId, $barangayId];
        $inv->execute($params);
    } else {
        $inv = $pdo->prepare("SELECT * FROM inventory WHERE (barangay_id=? OR (assistance_type='cash' AND barangay_id IS NULL)) AND item_name=? ORDER BY (barangay_id IS NULL) DESC, id LIMIT 1 FOR UPDATE");
        $inv->execute([$barangayId, $itemName]);
    }
    $invRow = $inv->fetch();
    if (!$invRow) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Inventory item not found for the target barangay.']);
        exit;
    }

    $isCash = $invRow['assistance_type'] === 'cash';

    // Determine the amount to deduct: cash uses a peso amount, goods use quantity.
    $deduct = $isCash ? $amount : max(1, $quantity);
    $logQty = $isCash ? 1 : $deduct;
    $logAmt = $isCash ? $deduct : 0.0;

    if ($deduct <= 0 && !$isCash) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Invalid quantity.']);
        exit;
    }
    if ($isCash && $amount <= 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Please enter a valid cash amount.']);
        exit;
    }

    // Reserved stock is held for scheduled distributions and cannot be given out.
    $reserved = getReservedQuantity($pdo, (int)$invRow['id']);
    $available = getAvailableQuantity((float)$invRow['quantity'], $reserved);

    if ($available < $deduct) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $isCash
            ? 'Insufficient cash budget (reserved amount already set aside for a scheduled distribution).'
            : 'Insufficient available stock. Some units are reserved for a scheduled distribution.']);
        exit;
    }

    // Prevent double distribution of the same item to the same beneficiary
    if ($beneficiaryId > 0) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM distribution_logs WHERE beneficiary_id=? AND inventory_id=?");
        $dup->execute([$beneficiaryId, $invRow['id']]);
        if ((int)$dup->fetchColumn() > 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'This beneficiary already received this item.']);
            exit;
        }
    }

    $log = $pdo->prepare("INSERT INTO distribution_logs
        (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, description)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $log->execute([$beneficiaryId > 0 ? $beneficiaryId : null, $invRow['id'], $barangayId, $_SESSION['user_id'], $invRow['assistance_type'], $logQty, $logAmt, $location ?: null, $description ?: null]);

    $upd = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ?");
    $upd->execute([$deduct, $invRow['id']]);

    logInventoryTransaction($pdo, (int)$invRow['id'], $barangayId, $invRow['item_name'], 'deduct',
        $deduct, (float)$invRow['quantity'] - $deduct);

    $who = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';
    logAudit($pdo, 'distribute',
        $who . ' distributed "' . $invRow['item_name'] . '" (' .
        ($isCash ? '&#8369;' . number_format($logAmt, 2) . ' cash' : $logQty . ' ' . ($invRow['unit'] ?? 'unit')) .
        ') to barangay #' . $barangayId .
        ($beneficiaryId > 0 ? ' / beneficiary #' . $beneficiaryId : ' / description only'));

    $pdo->commit();
    echo json_encode([
        'success'     => true,
        'new_balance' => (float)$invRow['quantity'] - $deduct,
        'is_cash'     => $isCash,
        'unit'        => $invRow['unit'] ?? 'unit',
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}