<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/spreadsheet.php';
requireStaff();

$format = ($_GET['format'] ?? 'csv') === 'xls' ? 'xls' : 'csv';
$err = ssRunExport($pdo, $format);
if ($err !== null) {
    http_response_code(400);
    echo $err;
}
exit;