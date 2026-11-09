<?php
// Lightweight endpoint used by the login form to detect whether a username
// belongs to a Barangay Staff account (so the login page can show the
// District + Barangay scope picker). Returns JSON only; no sensitive data.
require_once __DIR__ . '/includes/functions.php';

function loginCheckJson(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$username = trim($_GET['username'] ?? '');
if ($username === '') {
    loginCheckJson(['found' => false]);
}

$stmt = $pdo->prepare("SELECT role, barangay_id FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) {
    loginCheckJson(['found' => false]);
}

loginCheckJson([
    'found'      => true,
    'role'       => $user['role'],
    'barangay_id'=> $user['barangay_id'],
]);
