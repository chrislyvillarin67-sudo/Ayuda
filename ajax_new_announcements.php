<?php
/**
 * AJAX endpoint: current "new" announcement count for the bottom-nav
 * Announcements badge. Called periodically by the front-end so the red
 * count appears/clears without a page reload. Staff-scoped only.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

if (!isStaffLoggedIn() || ($_SESSION['role'] ?? '') === 'district_admin') {
    echo json_encode(['count' => 0]);
    exit;
}

$count = getNewAnnouncementCount($pdo, (int)$_SESSION['user_id'], (int)($_SESSION['barangay_id'] ?? 0));

echo json_encode(['count' => $count]);