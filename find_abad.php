<?php
require_once __DIR__.'/config/db.php';
$s = $pdo->query("SELECT id, full_name, date_of_birth, barangay_id, status, beneficiary_code FROM beneficiaries WHERE LOWER(full_name) LIKE '%abad%' ORDER BY id DESC LIMIT 20");
while($r = $s->fetch(PDO::FETCH_ASSOC)){
    echo $r['id'].'|'.$r['full_name'].'|'.$r['date_of_birth'].'|'.$r['barangay_id'].'|'.$r['status'].'|'.$r['beneficiary_code']."\n";
}
