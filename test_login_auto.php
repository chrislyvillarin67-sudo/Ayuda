<?php
require_once __DIR__.'/config/db.php';
require_once __DIR__.'/includes/functions.php';
$lastName='Abad'; $firstName='Maria'; $middleInit='B'; $barangayId=11; $dob='2007-04-27';
$fullName = displayName($firstName, $middleInit, $lastName);
$altName = $lastName . ', ' . $firstName . ($middleInit !== '' ? ' ' . $middleInit . '.' : '');
echo $fullName."\n".$altName."\n";
$stmt = $pdo->prepare("SELECT b.*, br.name AS barangay_name, d.name AS district_name
                       FROM beneficiaries b
                       JOIN barangays br ON br.id = b.barangay_id
                       JOIN districts d ON d.id = b.district_id
                       WHERE b.barangay_id = ? AND (LOWER(b.full_name) = LOWER(?) OR LOWER(b.full_name) = LOWER(?)) AND b.date_of_birth = ?
                       ORDER BY b.id DESC");
$stmt->execute([$barangayId, $fullName, $altName, $dob]);
$cands = $stmt->fetchAll();
var_dump(count($cands));
if($cands){ print_r($cands[0]['full_name']); echo "\n".$cands[0]['status']."\n"; }
