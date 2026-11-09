<?php
/**
 * AJAX endpoint: messaging inbox info for the Messages page and brand badges.
 * Returns the user's total unread count plus, when a contact is given, the
 * newest message id in that thread so the front-end can detect incoming mail.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

if (!isStaffLoggedIn()) {
    echo json_encode(['unread_total' => 0, 'contact_id' => 0, 'last_id' => 0]);
    exit;
}

$meId = (int)$_SESSION['user_id'];
$isAdmin = ($_SESSION['role'] ?? '') === 'district_admin';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id=? AND is_read=0");
$stmt->execute([$meId]);
$unread = (int)$stmt->fetchColumn();

$contactId = 0;
$lastId = 0;
$given = (int)($_GET['contact'] ?? 0);

if ($given > 0) {
    // Verify the contact is allowed for the current user.
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='barangay_staff' AND status='approved'");
        $stmt->execute([$given]);
        $allowed = (int)$stmt->fetchColumn() > 0;
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role='district_admin' ORDER BY id LIMIT 1");
        $stmt->execute();
        $allowed = $given === (int)$stmt->fetchColumn();
    }
    if ($allowed) {
        $contactId = $given;
        $stmt = $pdo->prepare("SELECT MAX(id) FROM messages WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)");
        $stmt->execute([$meId, $given, $given, $meId]);
        $lastId = (int)$stmt->fetchColumn();
    }
}

echo json_encode(['unread_total' => $unread, 'contact_id' => $contactId, 'last_id' => $lastId]);