<?php
/**
 * AJAX endpoint: current pending emergency report count for the bottom-nav
 * notification badge. Called every 30 seconds by the front-end; the badge
 * vanishes automatically when there are no pending reports left.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

if (!isStaffLoggedIn()) {
    echo json_encode(['count' => 0]);
    exit;
}

if (($_SESSION['role'] ?? '') === 'district_admin') {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM barangay_requests WHERE status='pending'")->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM barangay_requests WHERE status='pending' AND barangay_id=?");
    $stmt->execute([(int)($_SESSION['barangay_id'] ?? 0)]);
    $count = (int)$stmt->fetchColumn();
}

echo json_encode(['count' => $count]);