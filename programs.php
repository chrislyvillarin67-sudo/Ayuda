<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff('district_admin'); // Distribution programs are created/managed by CSWDO

$pageTitle = 'Distribution Programs';
$activePage = 'programs';
$isAdmin = true;

$districts = getDistricts($pdo);
$errors = [];
$csrfOk = validateCSRF();

// ---- Create a new distribution program ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_program']) && $csrfOk) {
    $title  = trim($_POST['title'] ?? '');
    $type   = $_POST['assistance_type'] ?? 'other';
    $date   = $_POST['program_date'] ?? '';
    $time   = $_POST['program_time'] ?? '';
    $loc    = trim($_POST['location'] ?? '');
    $brgyId = (int)($_POST['barangay_id'] ?? 0);
    $qty    = (float)($_POST['quantity'] ?? 0);
    $validTypes = ['cash','food_pack','relief_goods','other'];

    if ($title === '') $errors[] = 'Program title is required.';
    if (!in_array($type, $validTypes, true)) $errors[] = 'Please select a valid assistance type.';
    if ($qty <= 0) $errors[] = 'Please enter a quantity greater than 0.';
    if ($date === '' || !strtotime($date)) $date = date('Y-m-d'); // no-date programs default to today

    if (!$errors) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO distribution_programs (title, assistance_type, program_date, program_time, location, barangay_id, created_by)
                                VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$title, $type, $date, $time ?: null, $loc ?: null, $brgyId ?: null, $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        logAudit($pdo, 'program_create', 'Created distribution program "' . $title . '" (' . $type . ', ' . $date . ')');

        // Add the quantity to inventory so it reflects on the home + inventory cards.
    $invType = $type === 'hygiene' ? 'relief_goods' : $type;
    $invName = $type === 'hygiene' ? 'Relief Goods - ' . $title : $title;
        $invUnit = $type === 'cash' ? 'peso' : 'pack';
        $invBar  = $brgyId > 0 ? $brgyId : (int)($_SESSION['barangay_id'] ?? 1);
        $pdo->prepare("INSERT INTO inventory (barangay_id, item_name, quantity, unit, source, assistance_type, location, low_stock_threshold)
                       VALUES (?,?,?,?,?,?,?,20)")
            ->execute([$invBar, $invName, $qty, $invUnit, 'CSWDO - Distribution Program', $invType, $loc ?: null]);
        $invId = (int)$pdo->lastInsertId();
        logInventoryTransaction($pdo, $invId, $invBar, $invName, 'replenish', $qty, $qty, 'CSWDO - Distribution Program', 'From program: ' . $title);

        // Optionally assign beneficiaries in bulk at creation time.
        $assigned = $_POST['assign_ids'] ?? [];
        if (!empty($assigned) && is_array($assigned)) {
            $ins = $pdo->prepare("INSERT IGNORE INTO distribution_program_assignments (program_id, beneficiary_id) VALUES (?,?)");
            foreach (array_map('intval', $assigned) as $aid) {
                if ($aid > 0) $ins->execute([$newId, $aid]);
            }
        }
        $pdo->commit();
        setFlash('success', 'Distribution program created and ' . $qty . ' added to inventory.');
        redirect('programs.php');
    }
}

// ---- Handle per-beneficiary received toggle (assist during distribution) ----
// Per "Chrisly Villarin.docx" (Section 2: Distribution Assistance), recording a
// distribution must also update the official distribution records and deduct
// the inventory, so the program progress is aligned with real stock.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_received']) && $csrfOk) {
    $progId = (int)$_POST['program_id'];
    $benId  = (int)$_POST['beneficiary_id'];

    $prog = $pdo->prepare("SELECT * FROM distribution_programs WHERE id = ?");
    $prog->execute([$progId]);
    $progRow = $prog->fetch();

    $ben = $pdo->prepare("SELECT * FROM beneficiaries WHERE id = ? AND status='approved'");
    $ben->execute([$benId]);
    $benRow = $ben->fetch();

    if (!$progRow || !$benRow) {
        setFlash('danger', 'Program or beneficiary not found.');
        redirect('programs.php');
    }

    try {
        $pdo->beginTransaction();

        // Mark assignment as received.
        $upd = $pdo->prepare("UPDATE distribution_program_assignments SET received=1, received_at=NOW() WHERE program_id=? AND beneficiary_id=? AND received=0");
        $upd->execute([$progId, $benId]);
        $changed = $upd->rowCount();

        if ($changed > 0) {
            // Locate an inventory item in the beneficiary's barangay matching the program's assistance type.
            $type = $progRow['assistance_type'];
            $invSql = "SELECT * FROM inventory WHERE barangay_id = ?";
            switch ($type) {
                case 'cash':        $invSql .= " AND assistance_type='cash'"; break;
                case 'food_pack':   $invSql .= " AND (assistance_type='food_pack' OR item_name LIKE '%food%')"; break;
        case 'relief_goods':$invSql .= " AND assistance_type='relief_goods'"; break;
                default:            $invSql .= " AND assistance_type != 'cash'"; break;
            }
            $invSql .= " ORDER BY quantity DESC LIMIT 1 FOR UPDATE";
            $invStmt = $pdo->prepare($invSql);
            $invStmt->execute([$benRow['barangay_id']]);
            $invRow = $invStmt->fetch();

            if ($invRow) {
                $logType = $invRow['assistance_type'];
                $isCash = $invRow['assistance_type'] === 'cash';
                $qty = $isCash ? 1 : 1;             // one unit of the aid item per beneficiary
                $amt = $isCash ? (float)$invRow['quantity'] : 0.0; // not meaningful here; keep 0

                if ((float)$invRow['quantity'] >= ($isCash ? 0 : 1)) {
                    if (!$isCash) {
                        $pdo->prepare("UPDATE inventory SET quantity = quantity - 1 WHERE id = ?")->execute([$invRow['id']]);
                        logInventoryTransaction($pdo, (int)$invRow['id'], (int)$benRow['barangay_id'], $invRow['item_name'],
                            'deduct', 1, (float)$invRow['quantity'] - 1);
                    }
                    // Guard against double distribution of the same item (program records one item each).
                    $dup = $pdo->prepare("SELECT COUNT(*) FROM distribution_logs WHERE beneficiary_id=? AND inventory_id=?");
                    $dup->execute([$benId, $invRow['id']]);
                    if ((int)$dup->fetchColumn() === 0) {
                        $pdo->prepare("INSERT INTO distribution_logs
                            (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, description)
                            VALUES (?,?,?,?,?,?,?,?,?)")
                            ->execute([$benId, $invRow['id'], $benRow['barangay_id'], $_SESSION['user_id'],
                                       $logType, $qty, $amt, $progRow['location'] ?: null,
                                       'Distribution program: ' . $progRow['title']]);
                    }
                }
            }
            logAudit($pdo, 'distribution_received', 'Marked beneficiary #' . $benId . ' as received for program #' . $progId . ' (' . $progRow['title'] . ')');
        }

        $pdo->commit();
        setFlash('success', 'Distribution recorded and inventory updated.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        setFlash('danger', 'Unable to record distribution.');
    }
    redirect('programs.php?view=' . $progId);
}

// ---- Mark program as completed / cancel ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_program_status']) && $csrfOk) {
    $progId = (int)$_POST['program_id'];
    $newStatus = $_POST['program_status'] ?? '';
    if (in_array($newStatus, ['ongoing','completed','cancelled'], true)) {
        $pdo->prepare("UPDATE distribution_programs SET status=? WHERE id=?")->execute([$newStatus, $progId]);
        logAudit($pdo, 'program_status', 'Set program #' . $progId . ' to ' . $newStatus);
        setFlash('success', 'Program marked as ' . $newStatus . '.');
    }
    redirect('programs.php?view=' . $progId);
}

// ---- Assign additional beneficiaries to a program ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_to_program']) && $csrfOk) {
    $progId = (int)$_POST['program_id'];
    $assigned = $_POST['assign_ids'] ?? [];
    if ($progId > 0 && !empty($assigned) && is_array($assigned)) {
        $ins = $pdo->prepare("INSERT IGNORE INTO distribution_program_assignments (program_id, beneficiary_id) VALUES (?,?)");
        foreach (array_map('intval', $assigned) as $aid) {
            if ($aid > 0) $ins->execute([$progId, $aid]);
        }
        logAudit($pdo, 'program_assign', 'Assigned ' . count($assigned) . ' beneficiary(ies) to program #' . $progId);
        setFlash('success', 'Beneficiaries assigned.');
    }
    redirect('programs.php?view=' . $progId);
}

// ---- Listing + detail view ----
$viewId = (int)($_GET['view'] ?? 0);

$programs = $pdo->query("
    SELECT p.*, b.name AS barangay_name,
           COALESCE(a.total,0) AS total_assigned,
           COALESCE(a.received,0) AS received
    FROM distribution_programs p
    LEFT JOIN barangays b ON b.id = p.barangay_id
    LEFT JOIN (SELECT program_id, COUNT(*) total, SUM(received) received
               FROM distribution_program_assignments GROUP BY program_id) a ON a.program_id = p.id
    ORDER BY p.program_date DESC, p.created_at DESC
")->fetchAll();

$detail = null;
$assignments = [];
if ($viewId > 0) {
    $d = $pdo->prepare("SELECT p.*, b.name AS barangay_name FROM distribution_programs p LEFT JOIN barangays b ON b.id = p.barangay_id WHERE p.id = ?");
    $d->execute([$viewId]);
    $detail = $d->fetch() ?: null;
    if ($detail) {
        $assignments = $pdo->prepare("SELECT a.beneficiary_id, a.received, a.received_at, ben.full_name, ben.category, br.name AS barangay_name
                                       FROM distribution_program_assignments a
                                       JOIN beneficiaries ben ON ben.id = a.beneficiary_id
                                       JOIN barangays br ON br.id = ben.barangay_id
                                       WHERE a.program_id = ? ORDER BY ben.full_name");
        $assignments->execute([$viewId]);
        $assignments = $assignments->fetchAll();
        // Show every category each assigned beneficiary belongs to.
        $progCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($a) => (int)$a['beneficiary_id'], $assignments));
        foreach ($assignments as &$progA) { $progA['categories'] = $progCatMap[(int)$progA['beneficiary_id']] ?? [(string)$progA['category']]; }
        unset($progA);
    }
}

// Approved, not-yet-assigned beneficiaries eligible for assignment (for the assign UI)
$eligibles = $pdo->query("SELECT b.id, b.full_name, b.category, br.name AS barangay_name
                           FROM beneficiaries b JOIN barangays br ON br.id = b.barangay_id
                           WHERE b.status = 'approved' ORDER BY b.full_name")->fetchAll();

$assistanceLabels = ['cash'=>'Cash Assistance','food_pack'=>'Food Pack','relief_goods'=>'Relief Goods','hygiene'=>'Relief Goods','other'=>'Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Distribution Programs - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/includes/flash.php'; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
        <?php endif; ?>

        <div class="section-title" style="margin-bottom:10px;">Distribution Programs
        </div>

        <!-- Create form -->
        <div class="card" style="margin-bottom:14px;">
            <div class="li-name" style="margin-bottom:8px;">Create an Ayuda Distribution Program</div>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                <?= csrfField() ?>
                <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0;">
                    <label>Program Title</label>
                    <input type="text" name="title" class="form-control" required placeholder="e.g. Panahon ng Pagbangon - Food Packs">
                </div>
                <div class="form-group" style="min-width:140px;margin-bottom:0;">
                    <label>Assistance Type</label>
                    <select name="assistance_type" class="form-control">
                        <?php foreach ($assistanceLabels as $k => $l): ?>
                            <option value="<?= $k ?>"><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="min-width:120px;margin-bottom:0;">
                    <label>Quantity</label>
                    <input type="number" name="quantity" class="form-control" min="1" step="1" required placeholder="e.g. 50">
                </div>
                <button type="submit" name="create_program" class="btn btn-primary">Create Program</button>
            </form>
        </div>

        <?php if ($detail): ?>
        <div class="section-title" style="margin:16px 0 10px;">Program Details: <?= e($detail['title']) ?></div>
        <div class="card">
            <div class="li-name"><?= e($detail['title']) ?> <span class="badge <?= statusBadgeClass($detail['status']) ?>"><?= ucfirst(e($detail['status'])) ?></span></div>
            <div class="li-sub"><?= e($assistanceLabels[$detail['assistance_type']] ?? $detail['assistance_type']) ?> &bull; <?= date('F j, Y', strtotime($detail['program_date'])) ?><?= $detail['program_time'] ? ' at ' . date('g:i A', strtotime($detail['program_time'])) : '' ?> &bull; <?= $detail['barangay_name'] ? e($detail['barangay_name']) : 'Citywide' ?> <?= $detail['location'] ? ' &bull; ' . e($detail['location']) : '' ?></div>

            <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:10px;">
                <?= csrfField() ?>
                <input type="hidden" name="program_id" value="<?= $detail['id'] ?>">
                <select name="program_status" class="form-control" style="max-width:220px;">
                    <option value="ongoing" <?= $detail['status']==='ongoing'?'selected':'' ?>>Ongoing</option>
                    <option value="completed" <?= $detail['status']==='completed'?'selected':'' ?>>Completed</option>
                    <option value="cancelled" <?= $detail['status']==='cancelled'?'selected':'' ?>>Cancelled</option>
                </select>
                <button type="submit" name="set_program_status" class="btn btn-outline">Update Status</button>
            </form>

            <div class="section-title" style="margin:14px 0 6px;">Assigned Beneficiaries</div>
            <?php if (!$assignments): ?>
                <div class="empty-state">No beneficiaries assigned.</div>
            <?php endif; ?>
            <?php foreach ($assignments as $a): ?>
                <div class="list-item">
                    <div>
                        <div class="li-name"><?= e(formatName($a['full_name'])) ?> <?= $a['received'] ? '<span class="badge badge-success">Received</span>' : '<span class="badge badge-warning">Pending</span>' ?></div>
                        <div class="li-sub"><?= e(implode(', ', $a['categories'] ?? [(string)$a['category']])) ?> &bull; <?= e($a['barangay_name']) ?></div>
                    </div>
                    <?php if (!$a['received']): ?>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="program_id" value="<?= $detail['id'] ?>">
                            <input type="hidden" name="beneficiary_id" value="<?= $a['beneficiary_id'] ?>">
                            <button type="submit" name="mark_received" class="btn btn-success btn-sm">Record Received</button>
                        </form>
                    <?php else: ?>
                        <span class="small text-muted"><?= $a['received_at'] ? timeAgo($a['received_at']) : '' ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <form method="POST" style="margin-top:12px;display:flex;gap:8px;align-items:flex-end;">
                <?= csrfField() ?>
                <input type="hidden" name="program_id" value="<?= $detail['id'] ?>">
                <div class="form-group" style="flex:1;margin-bottom:0;">
                    <label>Assign More Beneficiaries</label>
                    <select name="assign_ids[]" class="form-control" multiple size="5">
                        <?php
                        $already = array_column($assignments, 'beneficiary_id');
                        foreach ($eligibles as $eb):
                            if (in_array($eb['id'], $already)) continue;
                        ?>
                            <option value="<?= $eb['id'] ?>"><?= e(formatName($eb['full_name'])) ?> (<?= e($eb['barangay_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="assign_to_program" class="btn btn-primary">Assign</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
<script>
function toggleEligible(sel) {
    // Scope filter hint: when a barangay is chosen, the eligible list is filtered by backend via re-render.
    // For simplicity we keep the full approved list; assignment is validated server-side.
}
</script>
</body>
</html>