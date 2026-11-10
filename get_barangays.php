<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json');

if (!isStaffLoggedIn()) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$districtId = (int)($_GET['district_id'] ?? 0);
echo json_encode($districtId > 0 ? getBarangays($pdo, $districtId) : []);
