<?php
require_once __DIR__.'/config/db.php';
$s = $pdo->query("SELECT id, full_name, barangay_id, date_of_birth, status FROM beneficiaries WHERE id=4363");
print_r($s->fetch(PDO::FETCH_ASSOC));
