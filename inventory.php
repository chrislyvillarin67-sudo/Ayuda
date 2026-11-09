<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = 'Inventory Management';
$activePage = 'inventory';
$isAdmin = $_SESSION['role'] === 'district_admin';
$barangayId = $_SESSION['barangay_id'] ?? 1; // general stock: default bucket when the District Admin has none selected
$selectedItem = trim($_GET['item'] ?? '');
$category = $_GET['category'] ?? 'All';
$q = trim($_GET['q'] ?? '');
$errors = [];
$csrfOk = validateCSRF();

// Admin navigates by tab (Overview / Food Pack / Cash); staff keep
// their per-item flows. Unknown tabs fall back to Overview. Hygiene Kit is no
// longer a tab - those stock lines are reported under Relief Goods / Overview.
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview','food_pack','cash','cash_general','cash_emergency'], true)) $tab = 'overview';

// Staff drilldown state (initialized so the staff detail view never hits an
// undefined variable, even if a stale ?item= link is followed by an admin).
$currentInv = null;
$drilldownRows = [];

// Admin only navigates by aid type: bounce old ?item= links (e.g. bookmarked
// per-barangay pages) to the matching aid-type drill.
if ($isAdmin && $selectedItem !== '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $t = $pdo->prepare("SELECT assistance_type FROM inventory WHERE item_name=? LIMIT 1");
    $t->execute([$selectedItem]);
    $at = $t->fetchColumn();
    redirect($at ? 'inventory.php?aidtype=' . urlencode($at) : 'inventory.php');
}

// ---- Add stock / amount to an existing inventory line (admin, per tab) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock']) && $isAdmin && $csrfOk) {
    $tabKey = preg_replace('/[^a-z_]/', '', $_POST['back_tab'] ?? 'overview');
    $source = trim($_POST['source'] ?? '');
    $sourceOther = trim($_POST['source_other'] ?? '');
    $sourceDetails = trim($_POST['source_details'] ?? '');
    $validSources = ['DSWD - National Government','City SWDO (CSWDO)','City Government of Davao','LGU','Private Company','NGO','Charitable Organization'];
    if ($source !== '' && !in_array($source, $validSources, true)) $source = $sourceOther !== '' ? $sourceOther : 'Other';

    $typeCheck = [
        'food_pack'      => "assistance_type='food_pack'",
        'cash_general'   => "assistance_type='cash' AND (cash_fund_type='general' OR cash_fund_type IS NULL)",
        'cash_emergency' => "assistance_type='cash' AND cash_fund_type='emergency'",
    ];

    $isCashTab = ($tabKey === 'cash_general' || $tabKey === 'cash_emergency');
    $tabErrLabels = ['food_pack' => 'food pack', 'cash_general' => 'general fund', 'cash_emergency' => 'emergency fund'];
    $appliedFunds = [];

    // CASH: one form tops up the General Fund and/or Emergency Fund independently.
    if ($isCashTab && isset($typeCheck[$tabKey])) {
        $genAmt = (float)($_POST['general_amount'] ?? 0);
        $emgAmt = (float)($_POST['emergency_amount'] ?? 0);
        if ($genAmt <= 0 && $emgAmt <= 0) {
            $errors[] = 'Please enter a General Fund or Emergency Fund amount greater than 0.';
        } else {
            foreach (['general' => $genAmt, 'emergency' => $emgAmt] as $fund => $amt) {
                if ($amt <= 0) continue;
                $inv = getCashPool($pdo, $fund);
                if (!$inv) { $errors[] = 'No ' . $fund . ' fund stock to add to yet.'; continue; }
                $invId = (int)$inv['id'];
                $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?")->execute([$amt, $invId]);
                if ($source !== '') {
                    if ($sourceDetails !== '') {
                        $pdo->prepare("UPDATE inventory SET source=?, source_details=? WHERE id=?")->execute([$source, $sourceDetails, $invId]);
                    } else {
                        $pdo->prepare("UPDATE inventory SET source=? WHERE id=?")->execute([$source, $invId]);
                    }
                } elseif ($sourceDetails !== '') {
                    $pdo->prepare("UPDATE inventory SET source_details=? WHERE id=?")->execute([$sourceDetails, $invId]);
                }
                // The barangay stamp is resolved from the inventory row inside
                // logInventoryTransaction(): a citywide pool row logs as NULL
                // ("Citywide") instead of whichever barangay the admin had open.
                logInventoryTransaction($pdo, $invId, (int)$barangayId, $inv['item_name'], 'replenish', $amt, (float)$inv['quantity'] + $amt, $source !== '' ? $source : ($inv['source'] ?? null), $sourceDetails !== '' ? $sourceDetails : null);
                $who = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
                logAudit($pdo, 'inventory_replenish', $who . ' added &#8369;' . number_format($amt, 2) . ' cash to "' . $inv['item_name'] . '"');
                $appliedFunds[] = ucfirst($fund) . ' Fund';
            }
        }
    } elseif (isset($typeCheck[$tabKey])) {
        $qty = (float)($_POST['quantity'] ?? 0);
        if ($qty <= 0) $errors[] = 'Please enter a quantity greater than 0.';
        if (!$errors) {
            // Food Packs live in one shared citywide pool (like cash): stock is
            // always added to that single balance, never to a barangay bucket.
            $inv = null;
            $row = $pdo->prepare("SELECT * FROM inventory WHERE assistance_type='food_pack' AND barangay_id IS NULL ORDER BY id LIMIT 1");
            $row->execute();
            $inv = $row->fetch();
            if (!$inv) {
                $row = $pdo->prepare("SELECT * FROM inventory WHERE " . $typeCheck[$tabKey] . " ORDER BY id LIMIT 1");
                $row->execute();
                $inv = $row->fetch();
            }
            if (!$inv) {
                $errors[] = 'No ' . ($tabErrLabels[$tabKey] ?? $tabKey) . ' stock to add to yet.';
            } else {
                $invId = (int)$inv['id'];
                $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?")->execute([$qty, $invId]);

                if ($source !== '') {
                    if ($sourceDetails !== '') {
                        $pdo->prepare("UPDATE inventory SET source=?, source_details=? WHERE id=?")->execute([$source, $sourceDetails, $invId]);
                    } else {
                        $pdo->prepare("UPDATE inventory SET source=? WHERE id=?")->execute([$source, $invId]);
                    }
                } elseif ($sourceDetails !== '') {
                    $pdo->prepare("UPDATE inventory SET source_details=? WHERE id=?")->execute([$sourceDetails, $invId]);
                }

                logInventoryTransaction($pdo, $invId, (int)$barangayId, $inv['item_name'], 'replenish', $qty, (float)$inv['quantity'] + $qty, $source !== '' ? $source : ($inv['source'] ?? null), $sourceDetails !== '' ? $sourceDetails : null);
                $who = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
                logAudit($pdo, 'inventory_replenish', $who . ' added ' . ($inv['assistance_type'] === 'cash'
                    ? '&#8369;' . number_format($qty, 2) . ' cash to "' . $inv['item_name'] . '"'
                    : $qty . ' ' . ($inv['unit'] ?? 'unit') . ' to "' . $inv['item_name'] . '"'));
                $errorMsg = $inv['assistance_type'] === 'cash' ? 'Cash amount added.' : 'Stock added.';
                setFlash('success', $errorMsg);
                redirect('inventory.php?tab=' . $tabKey);
            }
        }
    }

    if ($appliedFunds) {
        setFlash('success', count($appliedFunds) > 1
            ? 'Cash added to ' . implode(' & ', $appliedFunds) . '.'
            : 'Cash added to ' . $appliedFunds[0] . '.');
        redirect('inventory.php?tab=' . $tabKey);
    }
}

// ---- Add stock directly to a specific item kind (admin, aid-type drill page) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stock_item']) && $isAdmin && $csrfOk) {
    $qty = (float)($_POST['quantity'] ?? 0);
    $itemName = trim($_POST['item_name'] ?? '');
    $backAt = preg_replace('/[^a-z_]/', '', $_POST['back_aidtype'] ?? '');
    $source = trim($_POST['source'] ?? '');
    $sourceOther = trim($_POST['source_other'] ?? '');
    $sourceDetails = trim($_POST['source_details'] ?? '');

    if ($qty <= 0) $errors[] = 'Please enter a quantity greater than 0.';
    $inv = null;
    if ($itemName !== '') {
        // Barangay-owned row first; the shared citywide cash pool is the fallback.
        $q = $pdo->prepare("SELECT * FROM inventory
                            WHERE item_name=? AND (barangay_id=? OR (assistance_type='cash' AND barangay_id IS NULL))
                            ORDER BY (barangay_id IS NULL) DESC, id LIMIT 1");
        $q->execute([$itemName, $barangayId]);
        $inv = $q->fetch();
        if (!$inv) {
            $q = $pdo->prepare("SELECT * FROM inventory WHERE item_name=? ORDER BY id LIMIT 1");
            $q->execute([$itemName]);
            $inv = $q->fetch();
        }
    }
    if ($inv) {
        $validSources = ['DSWD - National Government','City SWDO (CSWDO)','City Government of Davao','LGU','Private Company','NGO','Charitable Organization'];
        if ($source !== '' && !in_array($source, $validSources, true)) $source = $sourceOther !== '' ? $sourceOther : 'Other';
        $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id=?")->execute([$qty, (int)$inv['id']]);
        if ($source !== '' || $sourceDetails !== '') {
            $pdo->prepare("UPDATE inventory SET source=COALESCE(?,source), source_details=COALESCE(NULLIF(?,''),source_details) WHERE id=?")
                ->execute([$source !== '' ? $source : null, $sourceDetails, (int)$inv['id']]);
        }
        $newQty = (float)$inv['quantity'] + $qty;
        // Barangay stamp resolved from the inventory row inside
        // logInventoryTransaction(): a citywide pool row logs as NULL ("Citywide").
        logInventoryTransaction($pdo, (int)$inv['id'], (int)$barangayId, $inv['item_name'], 'replenish', $qty, $newQty,
            $source !== '' ? $source : ($inv['source'] ?? null), $sourceDetails !== '' ? $sourceDetails : null);
        logAudit($pdo, 'inventory_replenish', ($_SESSION['full_name'] ?? 'Admin') . ' added ' . $qty . ' to "' . $inv['item_name'] . '"');
        setFlash('success', 'Stock added to "' . $inv['item_name'] . '".');
        redirect($backAt !== '' ? 'inventory.php?aidtype=' . urlencode($backAt) : 'inventory.php');
    } elseif (!$errors) {
        $errors[] = 'Item not found.';
    }
}

// ---- Reserve stock for a scheduled distribution (admin) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reserve_stock']) && $isAdmin && $csrfOk) {
    $resQty = (float)($_POST['reserve_quantity'] ?? 0);
    $ref = trim($_POST['reference'] ?? '');
    $backTab = preg_replace('/[^a-z_]/', '', $_POST['back_tab'] ?? 'overview');
    $resTypeCheck = [
        'food_pack'      => "assistance_type='food_pack'",
        'cash_general'   => "assistance_type='cash' AND (cash_fund_type='general' OR cash_fund_type IS NULL)",
        'cash_emergency' => "assistance_type='cash' AND cash_fund_type='emergency'",
    ];
    if ($resQty > 0 && isset($resTypeCheck[$backTab])) {
        // Food Packs are a shared citywide pool; food pack reservations always
        // draw from that single balance.
        $inv = null;
        if ($backTab === 'food_pack') {
            $row = $pdo->prepare("SELECT * FROM inventory WHERE assistance_type='food_pack' AND barangay_id IS NULL ORDER BY id LIMIT 1");
            $row->execute();
            $inv = $row->fetch();
        }
        if (!$inv) {
            $row = $pdo->prepare("SELECT * FROM inventory WHERE barangay_id=? AND " . $resTypeCheck[$backTab] . " ORDER BY id LIMIT 1");
            $row->execute([$barangayId]);
            $inv = $row->fetch();
        }
        if (!$inv) {
            $row = $pdo->prepare("SELECT * FROM inventory WHERE " . $resTypeCheck[$backTab] . " ORDER BY id LIMIT 1");
            $row->execute();
            $inv = $row->fetch();
        }
        if ($inv) {
            $invId = (int)$inv['id'];
            $reserved = getReservedQuantity($pdo, $invId);
            $available = getAvailableQuantity((float)$inv['quantity'], $reserved);
            if ($resQty > $available) {
                setFlash('danger', 'Cannot reserve more than the available stock (' . number_format($available, $inv['assistance_type'] === 'cash' ? 2 : 0) . ').');
            } else {
                $pdo->prepare("INSERT INTO inventory_reservations (inventory_id, barangay_id, quantity, reference, status, done_by) VALUES (?,?,?,?,'reserved',?)")
                    ->execute([$invId, (int)$inv['barangay_id'], $resQty, $ref ?: null, $_SESSION['user_id']]);
                logInventoryTransaction($pdo, $invId, (int)$inv['barangay_id'], $inv['item_name'], 'reserve', $resQty,
                    getAvailableQuantity((float)$inv['quantity'], $reserved + $resQty));
                logAudit($pdo, 'inventory_reserve', 'Reserved ' . $resQty . ' of "' . $inv['item_name'] . '"' . ($ref ? ' for ' . $ref : ''));
                setFlash('success', 'Stock reserved.');
            }
        }
    }
    redirect('inventory.php?tab=' . $backTab);
}

// ---- Release or cancel a reservation back to available stock (admin) ----
foreach (['release' => 'release_reserve', 'cancel' => 'cancel_reserve'] as $ttype => $postKey) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST[$postKey]) && $isAdmin && $csrfOk) {
        $resId = (int)$_POST['reservation_id'];
        $backTab = preg_replace('/[^a-z_]/', '', $_POST['back_tab'] ?? 'overview');
        $r = $pdo->prepare("SELECT * FROM inventory_reservations WHERE id=? AND status='reserved'");
        $r->execute([$resId]);
        $res = $r->fetch();
        if ($res) {
            $reservedBefore = getReservedQuantity($pdo, (int)$res['inventory_id']);
            $invRow = $pdo->prepare("SELECT * FROM inventory WHERE id=?");
            $invRow->execute([$res['inventory_id']]);
            $inv = $invRow->fetch();
            $pdo->prepare("UPDATE inventory_reservations SET status=?, released_at=NOW() WHERE id=?")
                ->execute([$ttype === 'release' ? 'released' : 'cancelled', $resId]);
            if ($inv) {
                logInventoryTransaction($pdo, (int)$inv['id'], (int)$inv['barangay_id'], $inv['item_name'], $ttype,
                    (float)$res['quantity'],
                    getAvailableQuantity((float)$inv['quantity'], max(0.0, $reservedBefore - (float)$res['quantity'])));
                logAudit($pdo, 'inventory_' . $ttype, ucfirst($ttype) . ' reservation #' . $resId . ' of "' . $inv['item_name'] . '"');
            }
            setFlash('success', $ttype === 'release' ? 'Reservation released back to stock.' : 'Reservation cancelled.');
        }
        redirect('inventory.php?tab=' . $backTab);
    }
}

$typeLabels = ['cash'=>'Cash Assistance','food_pack'=>'Food Packs','relief_goods'=>'Relief Goods','sponsored'=>'Sponsored','other'=>'Other'];

// ----- Overview: MAIN AID CARDS (Food Pack / Cash) + Other items -----
if ($isAdmin) {
    $mainCards = [];
    $mainDefs = [
        ['aidtype' => 'food_pack', 'label' => 'Food Pack', 'w' => "i.assistance_type='food_pack'", 'dw' => "i3.assistance_type='food_pack'"],
        ['aidtype' => 'cash', 'label' => 'Cash Assistance', 'w' => "i.assistance_type='cash' AND i.barangay_id IS NULL", 'dw' => "i3.assistance_type='cash' AND i3.barangay_id IS NULL"],
    ];
    foreach ($mainDefs as $md) {
        $stmt = $pdo->prepare("SELECT GROUP_CONCAT(DISTINCT i.item_name ORDER BY i.item_name SEPARATOR '||') AS kinds,
                                      GROUP_CONCAT(DISTINCT i.source ORDER BY i.source SEPARATOR '||') AS sources,
                                      COUNT(DISTINCT i.item_name) AS kind_count,
                                      SUM(i.quantity) AS total_qty,
                                      SUM(CASE WHEN i.quantity<=i.low_stock_threshold THEN 1 ELSE 0 END) AS low_count,
                                      COALESCE((SELECT SUM(CASE WHEN i3.assistance_type='cash' THEN dl.amount ELSE dl.quantity END)
                                                FROM distribution_logs dl JOIN inventory i3 ON i3.id=dl.inventory_id
                                                WHERE {$md['dw']}),0) AS total_distributed
                               FROM inventory i WHERE {$md['w']}");
        $stmt->execute();
        $row = $stmt->fetch();
        $row = $row ?: ['kinds' => '', 'kind_count' => 0, 'total_qty' => 0, 'low_count' => 1, 'total_distributed' => 0];
        $mainCards[] = ['aidtype' => $md['aidtype'], 'label' => $md['label']] + $row;
    }
    // Home only shows the two main aid types (Food Pack, Cash); sponsored /
    // other / relief lines stay out of the overview so it stays clean.
    $others = [];

    // ---- At-a-Glance metrics (moved from the dashboard) ----
    $glanceDistributed = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM distribution_logs")->fetchColumn();
    $glanceRequests = (int)$pdo->query("SELECT COUNT(*) FROM barangay_requests WHERE status='pending'")->fetchColumn();
    $glancePendingProgs = (int)$pdo->query("SELECT COUNT(*) FROM distribution_programs WHERE status IN ('scheduled','ongoing')")->fetchColumn();
    $gProg = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(received),0) received FROM distribution_program_assignments")->fetch();
    $glanceCompletion = $gProg && (int)$gProg['total'] > 0 ? round(((float)$gProg['received'] / (float)$gProg['total']) * 100, 1) : 0;
} else {
    // Barangay Staff see their own stock, plus the shared citywide cash pool so
    // the number they release against is the same one the admin sees.
    $stmt = $pdo->prepare("
        SELECT i.*,
               COALESCE((SELECT SUM(CASE WHEN i.assistance_type='cash' THEN dl.amount ELSE dl.quantity END)
                         FROM distribution_logs dl WHERE dl.inventory_id=i.id),0) AS distributed
        FROM inventory i
        WHERE i.barangay_id=? OR (i.assistance_type='cash' AND i.barangay_id IS NULL)
        ORDER BY i.item_name
    ");
    $stmt->execute([$barangayId]);
    $overview = array_values(array_filter($stmt->fetchAll(), function ($r) {
        return !(strcasecmp(trim((string)$r['assistance_type']), 'cash') === 0 && strcasecmp(trim((string)$r['item_name']), 'cash') === 0);
    }));
}

// ----- Stock tabs (admin): per-type items, reservations, filtered history -----
$reservedTotals = ['food_pack'=>0.0,'cash_general'=>0.0,'cash_emergency'=>0.0];
// The visible tab row keeps just Overview / Food Pack / Cash; the General &
// Emergency fund drilldowns are reached only from the small fund buttons that
// live INSIDE the Cash Assistance card (not as separate tabs).
$tabLabels = ['overview'=>'Overview','food_pack'=>'Food Pack','cash_general'=>'General Fund','cash_emergency'=>'Emergency Fund'];
$tabRowLabels = ['overview'=>'Overview','food_pack'=>'Food Pack','cash'=>'Cash'];
$tabWhere = [
    'food_pack'      => "inv.assistance_type='food_pack'",
    'cash_general'   => "inv.assistance_type='cash' AND (inv.cash_fund_type='general' OR inv.cash_fund_type IS NULL)",
    'cash_emergency' => "inv.assistance_type='cash' AND inv.cash_fund_type='emergency'",
];

$tabItems = [];
$tabReservations = [];
$tabHistory = [];
$txnTypeLabel = ['add'=>'Added','replenish'=>'Stock Added','reserve'=>'Reserved','release'=>'Released','cancel'=>'Cancelled','deduct'=>'Distributed'];

if ($isAdmin) {
    foreach ($pdo->query("SELECT i.assistance_type AS at, i.item_name AS nm, i.cash_fund_type AS fund, r.quantity AS q
                          FROM inventory_reservations r JOIN inventory i ON i.id=r.inventory_id
                          WHERE r.status='reserved'")->fetchAll() as $rs) {
        if ($rs['at'] === 'cash' && $rs['fund'] === 'emergency') $reservedTotals['cash_emergency'] += (float)$rs['q'];
        elseif ($rs['at'] === 'cash') $reservedTotals['cash_general'] += (float)$rs['q'];
        elseif ($rs['at'] === 'food_pack')$reservedTotals['food_pack'] += (float)$rs['q'];
    }

    if (array_key_exists($tab, $tabWhere)) {
        $tW = $tabWhere[$tab];
        $sti = $pdo->prepare("SELECT inv.*,
            COALESCE((SELECT COALESCE(SUM(r.quantity),0) FROM inventory_reservations r WHERE r.inventory_id=inv.id AND r.status='reserved'),0) AS reserved,
            COALESCE((SELECT SUM(CASE WHEN inv.assistance_type='cash' THEN dl.amount ELSE dl.quantity END) FROM distribution_logs dl WHERE dl.inventory_id=inv.id),0) AS distributed
            FROM inventory inv WHERE $tW ORDER BY inv.item_name");
        $sti->execute();
        $tabItems = $sti->fetchAll();

        $srs = $pdo->prepare("SELECT r.*, inv.item_name, inv.unit, u.full_name AS done_by_name
                              FROM inventory_reservations r
                              JOIN inventory inv ON inv.id=r.inventory_id
                              LEFT JOIN users u ON u.id=r.done_by
                              WHERE r.status='reserved' AND $tW ORDER BY r.created_at DESC");
        $srs->execute();
        $tabReservations = $srs->fetchAll();

        $histTypeWhere = str_replace('inv.', 'hi.', $tW);
        $histSql = "SELECT it.*, hi.unit AS unit, hi.source AS item_source, hi.source_details AS item_notes, u.full_name AS done_by_name, br.name AS barangay_name
                    FROM inventory_transactions it
                    JOIN inventory hi ON hi.id = it.inventory_id
                    LEFT JOIN users u ON u.id = it.done_by
                    LEFT JOIN barangays br ON br.id = it.barangay_id
                    WHERE $histTypeWhere
                    ORDER BY it.created_at DESC LIMIT 150";
        $hStmt = $pdo->prepare($histSql);
        $hStmt->execute();
        $tabHistory = array_values(array_filter($hStmt->fetchAll(), function ($h) use ($tab) {
            return !($tab === 'cash' && isset($h['item_name']) && strcasecmp(trim((string)$h['item_name']), 'cash') === 0);
        }));
    }

    $recentTxns = array_values(array_filter($pdo->query("SELECT it.*, u.full_name AS done_by_name, br.name AS barangay_name
                               FROM inventory_transactions it
                               LEFT JOIN users u ON u.id=it.done_by
                               LEFT JOIN barangays br ON br.id=it.barangay_id
                               ORDER BY it.created_at DESC LIMIT 10")->fetchAll(), function ($tx) {
        return !(isset($tx['item_name']) && strcasecmp(trim((string)$tx['item_name']), 'cash') === 0);
    }));
} else {
    $staffTxns = $pdo->prepare("SELECT it.*, u.full_name AS done_by_name, br.name AS barangay_name
                                FROM inventory_transactions it
                                LEFT JOIN users u ON u.id = it.done_by
                                LEFT JOIN barangays br ON br.id = it.barangay_id
                                WHERE it.barangay_id = ?
                                ORDER BY it.created_at DESC LIMIT 20");
    $staffTxns->execute([$barangayId]);
    $staffTxns = array_values(array_filter($stmt->fetchAll(), function ($tx) {
        return !(isset($tx['item_name']) && strcasecmp(trim((string)$tx['item_name']), 'cash') === 0);
    }));
}

// ----- Admin drilldown: ALL KINDS of the chosen aid type, where it came from -----
$aidType = $_GET['aidtype'] ?? '';
$drillLabels = $typeLabels;
$varieties = [];
$itemInfo = null;
if ($isAdmin && $aidType !== '' && array_key_exists($aidType, $drillLabels)) {
    $outerWhere = 'inv.assistance_type = :at';
    $distWhereItem = 'i3.assistance_type = :at2';
    $drillParams = [':at' => $aidType, ':at2' => $aidType];

    $meta = $pdo->prepare("SELECT inv.assistance_type,
                            SUM(inv.quantity) AS total_qty,
                            COUNT(DISTINCT inv.barangay_id) AS brgy_count,
                            COUNT(DISTINCT inv.item_name) AS kind_count,
                            GROUP_CONCAT(DISTINCT inv.source SEPARATOR ' || ') AS source,
                            COALESCE((SELECT SUM(CASE WHEN i3.assistance_type='cash' THEN dl.amount ELSE dl.quantity END)
                                      FROM distribution_logs dl JOIN inventory i3 ON i3.id=dl.inventory_id
                                      WHERE $distWhereItem),0) AS total_distributed
                            FROM inventory inv WHERE $outerWhere
                            GROUP BY inv.assistance_type");
    $meta->execute($drillParams);
    $itemInfo = $meta->fetch() ?: null;

    $v = $pdo->prepare("SELECT i.item_name,
                         MAX(i.source) AS source,
                         MAX(i.source_details) AS source_details,
                         SUM(i.quantity) AS total_qty,
                         COUNT(DISTINCT i.barangay_id) AS brgy_count,
                         COALESCE((SELECT SUM(CASE WHEN i3.assistance_type='cash' THEN dl.amount ELSE dl.quantity END)
                                   FROM distribution_logs dl JOIN inventory i3 ON i3.id=dl.inventory_id
                                   WHERE i3.item_name=i.item_name AND $distWhereItem),0) AS total_distributed
                         FROM inventory i WHERE " . str_replace('inv.', 'i.', $outerWhere) . "
                         GROUP BY i.item_name
                         ORDER BY i.item_name");
    $v->execute($drillParams);
    $varieties = array_values(array_filter($v->fetchAll(), function ($k) use ($aidType) {
    return !($aidType === 'cash' && isset($k['item_name']) && strcasecmp(trim((string)$k['item_name']), 'cash') === 0);
}));
}

// ----- Staff drilldown: beneficiaries for the selected item -----
$drilldownRows = [];
if (!$isAdmin && $selectedItem !== '') {
    $sql = "SELECT b.*, 
            (SELECT COUNT(*) FROM distribution_logs dl JOIN inventory i2 ON i2.id=dl.inventory_id WHERE dl.beneficiary_id=b.id AND i2.item_name=? AND dl.barangay_id=b.barangay_id) AS already_received
            FROM beneficiaries b WHERE b.barangay_id = ? AND b.status='approved'";
    $params = [$selectedItem, $barangayId];
    if ($category !== 'All' && $category !== '') { [$cs, $cp] = categoryMembershipSql($category); $sql .= ' AND ' . $cs; foreach ($cp as $p) $params[] = $p; }
    if ($q !== '') { $sql .= ' AND b.full_name LIKE ?'; $params[] = "%$q%"; }
    $sql .= ' ORDER BY b.full_name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $drilldownRows = $stmt->fetchAll();
    // Show every category each beneficiary belongs to, not only the primary.
    $invCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($r) => (int)$r['id'], $drilldownRows));
    foreach ($drilldownRows as &$invR) { $invR['categories'] = $invCatMap[(int)$invR['id']] ?? [(string)$invR['category']]; }
    unset($invR);

    // Prefer the barangay's own row, but fall back to the shared citywide cash
    // pool so drilling into Cash Assistance always resolves to one balance.
    $invStmt = $pdo->prepare("SELECT i.*,
                               COALESCE((SELECT SUM(CASE WHEN i.assistance_type='cash' THEN dl.amount ELSE dl.quantity END)
                                         FROM distribution_logs dl WHERE dl.inventory_id=i.id),0) AS distributed
                               FROM inventory i
                               WHERE (i.barangay_id=? OR (i.assistance_type='cash' AND i.barangay_id IS NULL))
                                 AND i.item_name=?
                               ORDER BY (i.barangay_id IS NULL) DESC, i.id LIMIT 1");
    $invStmt->execute([$barangayId, $selectedItem]);
    $currentInv = $invStmt->fetch();
}
$isAidDrill = $isAdmin && $aidType !== '' && array_key_exists($aidType, $drillLabels);
$categories = categoryFilterOptions();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
            <?php include __DIR__ . '/includes/flash.php'; ?>

            <?php if ($selectedItem === '' && !$isAidDrill): ?>
                <div class="section-title">Aid Items</div>

                <?php if ($isAdmin): ?>
                <div class="tab-row">
                    <?php foreach ($tabRowLabels as $tk => $tv): $tHref = $tk === 'cash' ? '?tab=cash_general' : '?tab=' . urlencode($tk); $tActive = $tk === 'cash' ? ($tab === 'cash_general' || $tab === 'cash_emergency') : $tab === $tk; ?>
                        <a href="<?= e($tHref) ?>" class="tab <?= $tActive ? 'active' : '' ?>"><?= e($tv) ?></a>
                    <?php endforeach; ?>
                </div>

                <?php if ($tab === 'overview'): ?>
                    <div class="glance-grid">
                        <div class="glance-item"><div class="glance-label">Distributed Ayuda</div><div class="glance-num"><?= number_format($glanceDistributed) ?></div></div>
                        <div class="glance-item"><div class="glance-label">Barangay Requests</div><div class="glance-num"><?= $glanceRequests ?></div></div>
                        <div class="glance-item"><div class="glance-label">Pending Distributions</div><div class="glance-num"><?= $glancePendingProgs ?></div></div>
                        <div class="glance-item"><div class="glance-label">Distribution Completion</div><div class="glance-num"><?= $glanceCompletion ?>%</div></div>
                    </div>

                    <div class="section-title" style="margin-top:18px;">Main Aid</div>
                    <div class="grid-2">
                    <?php foreach ($mainCards as $mc): $mcCash = $mc['aidtype'] === 'cash'; $mcRes = $mcCash ? ((float)($reservedTotals['cash_general'] ?? 0) + (float)($reservedTotals['cash_emergency'] ?? 0)) : (float)($reservedTotals['food_pack'] ?? 0); $mcAvail = getAvailableQuantity((float)$mc['total_qty'], $mcRes); $mcTheme = $mcCash ? 'gold' : 'green'; ?>
                        <a href="?tab=<?= urlencode($mcCash ? 'cash_general' : 'food_pack') ?>" class="stat-card stat-<?= $mcTheme ?>" style="display:block;text-decoration:none;">
                            <span class="stat-icon"><?= svgIcon($mcCash ? 'dollar' : 'box', 24) ?></span>
                            <div class="stat-label"><?= e(strtoupper($mc['label'])) ?></div>
                            <div class="stat-num"><?= $mcCash ? '&#8369;' . number_format($mcAvail, 2) : number_format($mcAvail, 0) . ' units' ?></div>
                            <div class="stat-sub">Distributed: <?= $mcCash ? '&#8369;' . number_format((float)$mc['total_distributed'], 2) : number_format((float)$mc['total_distributed'], 0) . ' units' ?></div>
                            <?php if ($mcCash): $cashPools = getCashPools($pdo); ?>
                                <div style="position:relative;z-index:1;margin-top:12px;border-top:1px solid rgba(255,255,255,0.32);padding-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                                    <?php foreach ($cashPools as $cp): $cpFund = $cp['cash_fund_type'] === 'emergency' ? 'emergency' : 'general'; $cpAvail = getAvailableQuantity((float)$cp['quantity'], (float)($reservedTotals['cash_' . $cpFund] ?? 0)); ?>
                                        <div style="flex:1;min-width:110px;text-align:center;background:rgba(255,255,255,0.18);border-radius:10px;padding:8px 6px;">
                                            <div style="font-size:10px;font-weight:650;letter-spacing:0.04em;opacity:0.95;"><?= $cpFund === 'emergency' ? 'EMERGENCY FUND' : 'GENERAL FUND' ?></div>
                                            <div style="font-size:15px;font-weight:750;font-variant-numeric:tabular-nums;">&#8369;<?= number_format($cpAvail, 2) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                    </div>

                    <?php if ($others): ?>
                    <div class="section-title" style="margin-top:18px;">Other Items</div>
                    <div class="grid-2">
                    <?php foreach ($others as $ot): $otLow = (bool)$ot['low_count']; ?>
                        <a href="?aidtype=<?= urlencode($ot['assistance_type']) ?>" class="card" style="text-decoration:none;color:inherit;display:block;">
                            <div class="d-flex justify-between align-center">
                                <strong style="font-size:14px;"><?= e($ot['label']) ?></strong>
                                <span class="badge <?= $otLow ? 'badge-danger' : 'badge-success' ?>"><?= $otLow ? 'Low / Empty' : 'Available' ?></span>
                            </div>
                            <div class="small" style="color:var(--text-muted);margin-top:2px;">Distributed: <?= number_format((float)$ot['total_distributed'], 0) . ' units' ?></div>
                            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px;">
                                <?php foreach (array_filter(array_map('trim', explode('||', (string)$ot['kinds']))) as $k): ?><span class="chip chip-sm"><?= e($k) ?></span><?php endforeach; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!$mainCards && !$others): ?><div class="card empty-state">No inventory items yet.</div><?php endif; ?>

                    <div class="section-title" style="margin-top:18px;">Recent Inventory Activity</div>
                    <div class="card" style="padding:0;">
                        <div class="table-responsive">
                        <table class="data-table data-table-wide">
                            <thead><tr><th>Date</th><th>Item</th><th>Movements</th><th>Qty</th><th>Balance After</th><th>Barangay</th><th>By</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentTxns as $tx): $cls = $tx['type']==='deduct' ? 'badge-danger' : (in_array($tx['type'], ['add','replenish','release','cancel'], true) ? 'badge-success' : 'badge-warning'); ?>
                                <tr>
                                    <td><?= date('M j, Y g:i A', strtotime($tx['created_at'])) ?></td>
                                    <td><?= e($tx['item_name']) ?></td>
                                    <td><span class="badge <?= $cls ?>"><?= e($txnTypeLabel[$tx['type']] ?? $tx['type']) ?></span></td>
                                    <td><?= (in_array($tx['type'], ['deduct','reserve'], true) ? '&minus;' : '+') . (float)$tx['quantity'] ?></td>
                                    <td><?= (float)$tx['balance_after'] ?></td>
                                    <td><?= !empty($tx['barangay_name']) ? e($tx['barangay_name']) : '<span class="text-muted">Citywide</span>' ?></td>
                                    <td><?= e($tx['done_by_name'] ?: 'System') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recentTxns): ?><tr><td colspan="7" class="text-center text-muted">No inventory activity yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>

                <?php elseif (array_key_exists($tab, $tabWhere)): ?>
                    <?php $tCash = ($tab === 'cash_general' || $tab === 'cash_emergency'); ?>
                    <?php
                    $tTotQty = 0; $tTotRes = 0; $tTotDist = 0;
                    foreach ($tabItems as $ti) { $tTotQty += (float)$ti['quantity']; $tTotRes += (float)$ti['reserved']; $tTotDist += (float)$ti['distributed']; }
                    $tAvail = getAvailableQuantity($tTotQty, $tTotRes);
                    $tFmt = static function ($v) use ($tCash) { return $tCash ? '&#8369;' . number_format((float)$v, 2) : number_format((float)$v, 0); };
                    ?>
                    <div class="card mb-3" style="background:#f4f9fd;border:1px solid var(--line);">
                        <div class="d-flex justify-between align-center" style="gap:10px;flex-wrap:wrap;">
                            <div class="li-name" style="font-size:15px;"><?= e($tabLabels[$tab]) ?> Stock</div>
                            <span class="badge <?= $tAvail <= 0 ? 'badge-danger' : 'badge-success' ?>"><?= $tAvail <= 0 ? 'Out of Stock' : 'In Stock' ?></span>
                        </div>
                        <div style="display:flex;gap:28px;flex-wrap:wrap;margin-top:12px;">
                            <div>
                                <div class="small text-muted">Available</div>
                                <strong style="font-size:18px;color:var(--navy);"><?= $tFmt($tAvail) ?><?= $tCash ? '' : ' units' ?></strong>
                            </div>
                            <div>
                                <div class="small text-muted">Reserved</div>
                                <strong style="font-size:18px;color:#b7791f;"><?= $tFmt($tTotRes) ?><?= $tCash ? '' : ' units' ?></strong>
                            </div>
                            <div>
                                <div class="small text-muted">Distributed</div>
                                <strong style="font-size:18px;color:#0f7b37;"><?= $tFmt($tTotDist) ?><?= $tCash ? '' : ' units' ?></strong>
                            </div>
                            <div>
                                <div class="small text-muted">Stock on hand</div>
                                <strong style="font-size:18px;color:var(--navy);"><?= $tFmt($tTotQty) ?><?= $tCash ? '' : ' units' ?></strong>
                            </div>
                        </div>
                        <?php if ($tCash): $tCashPools = getCashPools($pdo); ?>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;border-top:1px solid var(--line);padding-top:10px;">
                                <?php foreach ($tCashPools as $tcp): $tcpFund = $tcp['cash_fund_type'] === 'emergency' ? 'emergency' : 'general'; $tcpAvail = getAvailableQuantity((float)$tcp['quantity'], (float)($reservedTotals['cash_' . $tcpFund] ?? 0)); ?>
                                    <div style="flex:1;min-width:150px;text-align:center;padding:8px 10px;border-radius:10px;border:1px solid var(--line);background:#fff;">
                                        <div style="font-size:11px;font-weight:650;letter-spacing:0.04em;color:var(--text-muted);text-transform:uppercase;"><?= $tcpFund === 'emergency' ? 'Emergency Fund' : 'General Fund' ?></div>
                                        <div style="font-weight:750;font-size:16px;color:var(--navy);margin-top:2px;font-variant-numeric:tabular-nums;">&#8369;<?= number_format($tcpAvail, 2) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($tabItems): ?>
                    <div class="card" style="margin-bottom:14px;">
                        <div class="li-name" style="margin-bottom:8px;"><?= $tCash ? 'Add Cash Amount' : e($tabLabels[$tab]) . ' Stock' ?></div>
                        <?php if ($errors): ?>
                            <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
                        <?php endif; ?>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="back_tab" value="<?= $tab ?>">
                            <?php if ($tCash): ?>
                            <div class="grid-2" style="gap:10px;">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>General Fund &mdash; Amount to add (peso)</label>
                                    <input type="number" name="general_amount" class="form-control" min="1" step="0.01" placeholder="e.g. 5,000">
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>Emergency Fund &mdash; Amount to add (peso)</label>
                                    <input type="number" name="emergency_amount" class="form-control" min="1" step="0.01" placeholder="e.g. 5,000">
                                </div>
                            </div>
                            <?php else: ?>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                                <div class="form-group" style="width:220px;margin-bottom:0;">
                                    <label>Quantity to add</label>
                                    <input type="number" name="quantity" class="form-control" min="1" step="1" required placeholder="e.g. 5">
                                </div>
                                <button type="submit" name="add_stock" class="btn btn-primary">Add Stock</button>
                            </div>
                            <?php endif; ?>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:flex-end;">
                                <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0;">
                                    <label>Source / Sponsor <span class="text-muted small">(optional)</span></label>
                                    <select name="source" class="form-control" onchange="this.closest('form').querySelector('.src-other').style.display = (this.value==='Other' ? 'inline-block' : 'none');">
                                        <option value="">Keep existing source</option>
                                        <option>DSWD - National Government</option>
                                        <option>City SWDO (CSWDO)</option>
                                        <option>City Government of Davao</option>
                                        <option>LGU</option>
                                        <option>Private Company</option>
                                        <option>NGO</option>
                                        <option>Charitable Organization</option>
                                        <option>Other</option>
                                    </select>
                                </div>
                                <input type="text" name="source_other" class="form-control src-other" placeholder="Specify source/sponsor..." style="display:none;flex:1;min-width:180px;">
                                <?php if ($tCash): ?>
                                <button type="submit" name="add_stock" class="btn btn-primary">Add Amount</button>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php $recentAdded = array_values(array_filter($tabHistory, function ($h) { return in_array($h['type'], ['replenish','add'], true); })); ?>
                        <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:10px;">
                            <div class="small text-muted" style="margin-bottom:6px;">Recently added stock</div>
                            <?php if (!$recentAdded): ?>
                                <div class="small text-muted">No stock added yet.</div>
                            <?php else: ?>
                                <?php foreach (array_slice($recentAdded, 0, 5) as $ra): ?>
                                    <div class="d-flex justify-between" style="gap:8px;padding:4px 0;border-bottom:1px dashed var(--line);">
                                        <span class="small">+<?= number_format((float)$ra['quantity'], $tCash ? 2 : 0) ?> <?= $tCash ? '' : e($ra['unit'] ?? 'unit') ?> &#8594; <strong><?= e($ra['item_name']) ?></strong></span>
                                        <span class="small text-muted"><?= $tCash ? '&#8369;' . number_format((float)$ra['balance_after'], 2) : number_format((float)$ra['balance_after'], 0) . ' ' . e($ra['unit'] ?? 'unit') ?> remaining</span>
                                    </div>
                                    <?php $raSource = ($ra['source'] ?? '') !== '' ? $ra['source'] : ($ra['item_source'] ?? ''); ?>
                                    <?php if ($raSource !== ''): ?>
                                        <div class="small text-muted" style="padding:2px 0 0 2px;"><?= svgIcon('gift', 13) ?> from <?= e($raSource) ?></div>
                                    <?php endif; ?>
                                    <?php $raNote = ($ra['notes'] ?? '') !== '' ? $ra['notes'] : ($ra['item_notes'] ?? ''); ?>
                                    <?php if ($raNote !== ''): ?>
                                        <div class="small" style="color:#3a4752;padding:2px 0 6px 2px;"><?= svgIcon('file', 13) ?> <?= e($raNote) ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="section-title" style="margin-top:18px;">Scheduled / Reserved</div>
                    <?php if (!$tabReservations): ?>
                        <div class="card empty-state">No active reservations for <?= e(strtolower($tabLabels[$tab])) ?> stock.</div>
                    <?php endif; ?>
                    <?php foreach ($tabReservations as $rs): ?>
                        <div class="card" style="margin-bottom:12px;">
                            <div class="d-flex justify-between align-center" style="gap:10px;flex-wrap:wrap;">
                                <div>
                                    <div class="li-name"><?= e($rs['item_name']) ?> &#8212; <?= $tFmt($rs['quantity']) ?><?= $tCash ? '' : ' ' . e($rs['unit'] ?? 'unit') ?></div>
                                    <?php if ($rs['reference']): ?><div class="li-sub">For: <?= e($rs['reference']) ?></div><?php endif; ?>
                                    <div class="li-sub small text-muted">Reserved <?= date('M j, Y g:i A', strtotime($rs['created_at'])) ?> by <?= e($rs['done_by_name'] ?: 'Admin') ?></div>
                                </div>
                                <div class="d-flex gap-2" style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <form method="POST">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="reservation_id" value="<?= (int)$rs['id'] ?>">
                                        <input type="hidden" name="back_tab" value="<?= $tab ?>">
                                        <button type="submit" name="release_reserve" class="btn btn-outline btn-sm">&#8635; Release</button>
                                    </form>
                                    <form method="POST">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="reservation_id" value="<?= (int)$rs['id'] ?>">
                                        <input type="hidden" name="back_tab" value="<?= $tab ?>">
                                        <button type="submit" name="cancel_reserve" class="btn btn-danger btn-sm"><?= svgIcon('x', 14) ?> Cancel</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($tabItems): ?>
                    <div class="card">
                        <div class="li-name" style="margin-bottom:8px;">Reserve Stock</div>
                        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                            <?= csrfField() ?>
                            <input type="hidden" name="back_tab" value="<?= $tab ?>">
                            <div class="form-group" style="width:170px;margin-bottom:0;">
                                <label><?= $tCash ? 'Amount (peso)' : 'Quantity' ?></label>
                                <input type="number" name="reserve_quantity" class="form-control" min="1" step="<?= $tCash ? '0.01' : '1' ?>" required>
                            </div>
                            <div class="form-group" style="flex:2;min-width:200px;margin-bottom:0;">
                                <label>For what schedule / program?</label>
                                <input type="text" name="reference" class="form-control" placeholder="e.g. Christmas Ayuda - Batch 1">
                            </div>
                            <button type="submit" name="reserve_stock" class="btn btn-primary">Reserve</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="section-title" style="margin-top:18px;">Inventory History (<?= e($tabLabels[$tab]) ?>)</div>
                    <div class="card" style="padding:0;">
                        <div class="table-responsive">
                        <table class="data-table data-table-wide">
                            <thead><tr><th>Date</th><th>Item</th><th>Activity</th><th>Quantity</th><th>Remaining</th><th>Barangay</th><th>By</th></tr></thead>
                            <tbody>
                            <?php foreach ($tabHistory as $txh): $hCls = $txh['type'] === 'deduct' ? 'badge-danger' : (in_array($txh['type'], ['add','replenish','release','cancel'], true) ? 'badge-success' : 'badge-warning'); $hUnit = $tCash ? '' : ' ' . e($txh['unit'] ?? 'unit'); ?>
                                <tr>
                                    <td><?= date('M j, Y g:i A', strtotime($txh['created_at'])) ?></td>
                                    <td><?= e($txh['item_name']) ?></td>
                                    <td><span class="badge <?= $hCls ?>"><?= e($txnTypeLabel[$txh['type']] ?? $txh['type']) ?></span></td>
                                    <td><?= (in_array($txh['type'], ['deduct','reserve'], true) ? '&minus;' : '+') . ($tCash ? '&#8369;' . number_format((float)$txh['quantity'], 2) : (float)$txh['quantity'] . $hUnit) ?></td>
                                    <td><?= $tCash ? '&#8369;' . number_format((float)$txh['balance_after'], 2) : (float)$txh['balance_after'] . $hUnit ?></td>
                                    <td><?= !empty($txh['barangay_name']) ? e($txh['barangay_name']) : '<span class="text-muted">Citywide</span>' ?></td>
                                    <td><?= e($txh['done_by_name'] ?: 'System') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$tabHistory): ?><tr><td colspan="7" class="text-center text-muted">No activity matches these filters.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>
                <?php else: ?>
                    <div class="grid-2">
                    <?php foreach ($overview as $item): $low = (float)$item['quantity'] <= (float)$item['low_stock_threshold']; $isCash = $item['assistance_type'] === 'cash'; $suffix = $isCash ? '&#8369;' . number_format((float)$item['quantity'], 2) : (int)$item['quantity'] . ' ' . e($item['unit']); $distQty = (float)($item['distributed'] ?? 0); $distStr = $isCash ? '&#8369;' . number_format($distQty, 2) : (int)$distQty . ' ' . e($item['unit']); ?>
                        <a href="?item=<?= urlencode($item['item_name']) ?>" class="card" style="text-decoration:none;color:inherit;display:block;">
                            <div class="d-flex justify-between align-center">
                                <strong style="font-size:15px;"><?= e($item['item_name']) ?></strong>
                                <span class="badge <?= $low ? 'badge-danger' : 'badge-success' ?>"><?= $low ? 'Low / Empty' : 'Available' ?></span>
                            </div>
                            <div class="stat-num" style="color:var(--navy);font-size:24px;margin-top:8px;"><?= $suffix ?></div>
                            <div class="small" style="color:var(--text-muted);margin-top:2px;">Distributed: <?= $distStr ?></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$overview): ?><div class="card empty-state">No inventory items yet.</div><?php endif; ?>
                    </div>

                    <div class="section-title" style="margin-top:18px;">Inventory Activity Log</div>
                    <div class="card" style="padding:0;">
                        <div class="table-responsive">
                        <table class="data-table data-table-wide">
                            <thead><tr><th>Date</th><th>Item</th><th>Movement</th><th>Qty</th><th>Balance</th><th>Barangay</th><th>By</th></tr></thead>
                            <tbody>
                            <?php foreach ($staffTxns as $tx): $cls = $tx['type']==='deduct' ? 'badge-danger' : ($tx['type']==='add' ? 'badge-success' : 'badge-warning'); ?>
                                <tr>
                                    <td><?= date('M j, Y g:i A', strtotime($tx['created_at'])) ?></td>
                                    <td><?= e($tx['item_name']) ?></td>
                                    <td><span class="badge <?= $cls ?>"><?= e($txnTypeLabel[$tx['type']] ?? $tx['type']) ?></span></td>
                                    <td><?= in_array($tx['type'], ['deduct','reserve'], true) ? '&minus;' : '+' ?><?= (float)$tx['quantity'] ?></td>
                                    <td><?= (float)$tx['balance_after'] ?></td>
                                    <td><?= !empty($tx['barangay_name']) ? e($tx['barangay_name']) : '<span class="text-muted">Citywide</span>' ?></td>
                                    <td><?= e($tx['done_by_name'] ?: 'System') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$staffTxns): ?><tr><td colspan="7" class="text-center text-muted">No inventory activity yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php elseif ($isAidDrill): ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
                <?php endif; ?>
                <a href="inventory.php" class="btn-link">Back to all items</a>
                <div class="section-title"><?= e($drillLabels[$aidType]) ?></div>

                <?php if ($itemInfo): $aidCash = $aidType === 'cash'; ?>
                    <div class="card mb-3" style="background:#f4f9fd;border:1px solid var(--line);">
                        <div class="d-flex justify-between align-center" style="gap:10px;flex-wrap:wrap;">
                            <div class="li-name" style="font-size:15px;">About this aid</div>
                            <?php if (!empty($itemInfo['source'])): ?><span class="badge badge-gold" style="font-size:11px;"><?= svgIcon('gift', 12) ?> From: <?= e(implode(' / ', array_unique(array_map('trim', explode('||', (string)$itemInfo['source']))))) ?></span><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:28px;flex-wrap:wrap;margin-top:12px;">
                            <div>
                                <div class="small text-muted">Remaining stock</div>
                                <strong style="font-size:18px;color:var(--navy);"><?= $aidCash ? '&#8369;' . number_format((float)$itemInfo['total_qty'], 2) : number_format((float)$itemInfo['total_qty'], 0) . ' units' ?></strong>
                            </div>
                            <div>
                                <div class="small text-muted">Distributed</div>
                                <strong style="font-size:18px;color:#0f7b37;"><?= $aidCash ? '&#8369;' . number_format((float)$itemInfo['total_distributed'], 2) : number_format((float)$itemInfo['total_distributed'], 0) . ' units' ?></strong>
                            </div>
                            <div>
                                <div class="small text-muted">Kinds / varieties</div>
                                <strong style="font-size:18px;color:var(--navy);"><?= (int)$itemInfo['kind_count'] ?></strong>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="section-title" style="margin-top:18px;">All kinds of <?= e($drillLabels[$aidType]) ?></div>
                <?php foreach ($varieties as $v): $vCash = $aidType === 'cash'; ?>
                    <div class="card" style="margin-bottom:12px;">
                        <div class="d-flex justify-between align-center" style="gap:10px;flex-wrap:wrap;">
                            <strong style="font-size:15px;"><?= svgIcon('box', 13) ?> <?= e($v['item_name']) ?></strong>
                            <?php if (!empty($v['source'])): ?><span class="badge badge-gold" style="font-size:11px;"><?= svgIcon('gift', 12) ?> <?= e($v['source']) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($v['source_details'])): ?>
                            <div class="small" style="color:#3a4752;margin-top:6px;"><?= e($v['source_details']) ?></div>
                        <?php else: ?>
                            <div class="small text-muted" style="margin-top:6px;">No description provided for this kind yet.</div>
                        <?php endif; ?>
                        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:12px;">
                            <div>
                                <div class="small text-muted">Remaining</div>
                                <strong style="font-size:17px;color:var(--navy);"><?= $vCash ? '&#8369;' . number_format((float)$v['total_qty'], 2) : number_format((float)$v['total_qty'], 0) . ' units' ?></strong>
                            </div>
                            <div>
                                <div class="small text-muted">Distributed</div>
                                <strong style="font-size:17px;color:#0f7b37;"><?= $vCash ? '&#8369;' . number_format((float)$v['total_distributed'], 2) : number_format((float)$v['total_distributed'], 0) . ' units' ?></strong>
                            </div>
                        </div>
                        <details style="margin-top:12px;background:#f7f9fc;border:1px solid var(--line);border-radius:10px;padding:0 12px;">
                            <summary style="cursor:pointer;list-style:none;padding:10px 0;font-weight:700;font-size:13px;color:var(--navy);"><?= svgIcon('plus', 14) ?> Add Stock to this item</summary>
                            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding-bottom:12px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="add_stock_item" value="1">
                                <input type="hidden" name="item_name" value="<?= e($v['item_name']) ?>">
                                <input type="hidden" name="back_aidtype" value="<?= e($aidType) ?>">
                                <div class="form-group" style="width:180px;margin-bottom:0;">
                                    <label><?= $vCash ? 'Amount to add (peso)' : 'Quantity to add' ?></label>
                                    <input type="number" name="quantity" class="form-control" min="1" step="<?= $vCash ? '0.01' : '1' ?>" required placeholder="e.g. 5">
                                </div>
                                <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0;">
                                    <label>Source / Sponsor <span class="text-muted small">(optional)</span></label>
                                    <select name="source" class="form-control">
                                        <option value="">Keep existing source</option>
                                        <option>DSWD - National Government</option>
                                        <option>City SWDO (CSWDO)</option>
                                        <option>City Government of Davao</option>
                                        <option>LGU</option>
                                        <option>Private Company</option>
                                        <option>NGO</option>
                                        <option>Charitable Organization</option>
                                        <option>Other</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary"><?= $vCash ? 'Add Amount' : 'Add Stock' ?></button>
                            </form>
                        </details>
                    </div>
                <?php endforeach; ?>
                <?php if (!$varieties): ?><div class="card empty-state">No kinds recorded for this aid type.</div><?php endif; ?>

            <?php else: ?>
                <a href="inventory.php" class="btn-link">Back to all items</a>
                <div class="section-title"><?= e($selectedItem) ?></div>

                <?php if ($currentInv): $aboutCash = ($currentInv['assistance_type'] ?? '') === 'cash';
                      $aboutRemaining = (float)$currentInv['quantity'];
                      $aboutDist = (float)($currentInv['distributed'] ?? 0);
                      $aboutUnit = e($currentInv['unit'] ?? 'unit'); ?>
                    <div class="card mb-3" style="background:#f4f9fd;border:1px solid var(--line);">
                        <div class="d-flex justify-between align-center" style="gap:10px;flex-wrap:wrap;">
                            <div class="li-name" style="font-size:15px;">About this aid</div>
                            <?php if (!empty($currentInv['source'])): ?><span class="badge badge-gold" style="font-size:11px;"><?= svgIcon('gift', 12) ?> From: <?= e($currentInv['source']) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($currentInv['source_details'])): ?>
                            <div class="small" style="color:#3a4752;margin-top:6px;"><?= e($currentInv['source_details']) ?></div>
                        <?php endif; ?>
                        <div style="display:flex;gap:28px;flex-wrap:wrap;margin-top:12px;">
                            <div>
                                <div class="small text-muted">Remaining stock</div>
                                <strong style="font-size:18px;color:var(--navy);"><?= $aboutCash ? '&#8369;' . number_format($aboutRemaining, 2) : number_format($aboutRemaining, 0) ?></strong>
                                <span class="small text-muted"><?= $aboutUnit ?></span>
                            </div>
                            <div>
                                <div class="small text-muted">Distributed</div>
                                <strong style="font-size:18px;color:#0f7b37;"><?= $aboutCash ? '&#8369;' . number_format($aboutDist, 2) : number_format($aboutDist, 0) ?></strong>
                                <span class="small text-muted"><?= $aboutUnit ?></span>
                            </div>
                            <?php if (!empty($currentInv['location'])): ?>
                                <div>
                                    <div class="small text-muted">Site</div>
                                    <strong style="font-size:14px;color:var(--navy);"><?= e($currentInv['location']) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="GET" class="search-bar">
                    <input type="hidden" name="item" value="<?= e($selectedItem) ?>">
                    <input type="hidden" name="category" value="<?= e($category) ?>">
                    <input type="text" name="q" class="form-control" value="<?= e($q) ?>">
                    <button class="btn btn-outline">Search</button>
                </form>
                <div class="chip-row">
                    <?php foreach ($categories as $c): ?>
                        <a href="?item=<?= urlencode($selectedItem) ?>&category=<?= urlencode($c) ?>&q=<?= urlencode($q) ?>"
                           class="chip <?= $category===$c ? 'active' : '' ?>"><?= e($c) ?></a>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($drilldownRows as $r): $done = (int)$r['already_received'] > 0; ?>
                    <div class="list-item" id="dist-<?= $r['id'] ?>">
                        <div>
                            <div class="li-name"><?= e(formatName($r['full_name'])) ?></div>
                            <div class="li-sub"><?= e(implode(', ', $r['categories'] ?? [(string)$r['category']])) ?></div>
                        </div>
                        <?php if ($done): ?>
                            <button class="btn btn-sm disabled" disabled>Done</button>
                        <?php else: ?>
                            <button class="btn btn-success btn-sm" onclick="distribute(<?= $r['id'] ?>, '<?= e(addslashes($selectedItem)) ?>', this)">Distribute</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$drilldownRows): ?><div class="card empty-state">No approved beneficiaries match this filter.</div><?php endif; ?>
            <?php endif; ?>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
<script>
function distribute(beneficiaryId, itemName, btn) {
    btn.disabled = true; btn.textContent = 'Processing...';
    fetch('distribute_action.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: '_csrf_token=' + encodeURIComponent('<?= generateCSRFToken() ?>') + '&beneficiary_id=' + encodeURIComponent(beneficiaryId) + '&item_name=' + encodeURIComponent(itemName)
    }).then(r => r.json()).then(data => {
        if (data.success) {
            btn.textContent = 'Done';
            btn.className = 'btn btn-sm disabled';
        } else {
            alert(data.message || 'Unable to record distribution.');
            btn.disabled = false; btn.textContent = 'Distribute';
        }
    }).catch(() => { btn.disabled = false; btn.textContent = 'Distribute'; alert('Network error.'); });
}
</script>
</body>
</html>
