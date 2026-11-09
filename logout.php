<?php
require_once __DIR__ . '/includes/functions.php';
$who = $_SESSION['username'] ?? ($_SESSION['beneficiary_name'] ?? 'Unknown');
$role = $_SESSION['role'] ?? 'beneficiary';
logAudit($pdo, 'logout', "Signed out: $who ($role)");
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
session_start();
setFlash('info', 'You have been logged out.');
redirect('login.php');
