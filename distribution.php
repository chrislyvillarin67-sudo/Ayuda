<?php
/**
 * DISTRICT ADMIN - Distribution (redesigned).
 *
 * The District Admin (CSWDO) handles ALL distribution. This dedicated
 * "Distribution" navigation (kept separate from Inventory) walks through:
 *
 *   1. DISTRICT dropdown  - one of the 3 Davao districts (Poblacion/Agdao/Talomo)
 *   2. BARANGAY dropdown  - all barangays of the chosen district
 *   3. AID TYPE dropdown  - Cash / Food Packs / Relief Goods / Sponsored / Other
 *
 *   4. CARDS for every type of aid showing the TOTAL remaining stock across
 *      all 3 districts (so the admin sees "ilan nalang ang natira" at a glance).
 *
 *   5. CONFIRM FINAL LIST - the admin (CSWDO) confirms exactly which members of
 *      the selected barangay may receive this aid, by filtering category
 *      (e.g. all PWD / all Senior / whole barangay) or ticking individual names.
 *
 *   6. DISTRIBUTE - the admin enters description + quantity (or cash amount),
 *      reviews in a CONFIRMATION MODAL, then submits. One submission distributes
 *      the aid to EVERY member on the confirmed list. Records appear on the
 *      staff side too.
 */
require_once __DIR__ . '/includes/functions.php';
requireStaff('district_admin');

$pageTitle = 'Distribution';
$activePage = 'distribute';

$districts3 = $pdo->query("SELECT * FROM districts ORDER BY id LIMIT 3")->fetchAll();

// Aid group definitions. These mirror the Inventory overview grouping so every
// type that has stock (including program-created items under Sponsored / Other)
// is available to distribute. Relief Goods and Hygiene Kit are no longer offered
// in the type-of-aid picker.
$aidDefs = [
    'food_pack'   => ['label' => 'Food Packs',   'icon' => 'box',    'color' => 'stat-navy',   'where' => "assistance_type='food_pack'"],
    'cash_general'   => ['label' => 'General Fund',   'icon' => 'dollar', 'color' => 'stat-gold',   'where' => "assistance_type='cash' AND (cash_fund_type='general' OR cash_fund_type IS NULL)"],
    'cash_emergency' => ['label' => 'Emergency Fund', 'icon' => 'dollar', 'color' => 'stat-red',    'where' => "assistance_type='cash' AND cash_fund_type='emergency'"],
    'sponsored'   => ['label' => 'Sponsored',     'icon' => 'gift',   'color' => 'stat-teal',   'where' => "assistance_type='sponsored'"],
    'other'       => ['label' => 'Other',         'icon' => 'box',    'color' => 'stat-slate',  'where' => "assistance_type='other'"],
];

// Only offer aid groups that have stock somewhere across the 3 districts.
// CASH appears once as a single "Cash" pick; the fund (General/Emergency) is
// chosen on a secondary bar right below the dropdown.
$present = [];
$districts3Ids = array_map(fn($d) => (int)$d['id'], $districts3);
$districts3In = implode(',', $districts3Ids ?: [0]);
foreach ($pdo->query("
    SELECT i.assistance_type, i.item_name, i.cash_fund_type
    FROM inventory i
    LEFT JOIN barangays b ON b.id = i.barangay_id
    WHERE b.district_id IN ($districts3In) OR i.barangay_id IS NULL
")->fetchAll() as $row) {
    if ($row['assistance_type'] === 'cash') {
        $present['cash_general'] = true;
        $present['cash_emergency'] = true;
    } else {
        $present[$row['assistance_type']] = true;
    }
}
$assistanceTypes = [];
$aidIcons = [];
$aidColors = [];
foreach ($aidDefs as $k => $def) {
    if (!empty($present[$k])) {
        $assistanceTypes[$k] = $def['label'];
        $aidIcons[$k] = $def['icon'];
        $aidColors[$k] = $def['color'];
    }
}
// 'cash' is the single visible pick; fund is chosen on a secondary bar. Both
// fund keys stay in the map so POST validation + labels resolve correctly.
$cashShown = !empty($present['cash_general']) || !empty($present['cash_emergency']);
if ($cashShown) {
    $assistanceTypes['cash'] = 'Cash';
    $aidIcons['cash'] = 'dollar';
    $aidColors['cash'] = 'stat-gold';
}
$assistanceTypes['cash_general'] = 'Cash - General Fund';
$assistanceTypes['cash_emergency'] = 'Cash - Emergency Fund';
$aidIcons['cash_general'] = 'dollar';
$aidIcons['cash_emergency'] = 'dollar';
$aidColors['cash_general'] = 'stat-gold';
$aidColors['cash_emergency'] = 'stat-red';
// Fallback so the page never breaks even with an empty inventory.
if (!$assistanceTypes) {
    foreach (['food_pack' => 'Food Packs', 'cash' => 'Cash', 'sponsored' => 'Sponsored', 'other' => 'Other'] as $k => $label) {
        $assistanceTypes[$k] = $label;
        $aidIcons[$k] = $aidDefs[$k]['icon'] ?? 'box';
        $aidColors[$k] = $aidDefs[$k]['color'] ?? 'stat-slate';
    }
}
// The internal fund-specific keys resolved from the single 'cash' pick.
$selectedFund = ($_GET['fund'] ?? 'general') === 'emergency' ? 'emergency' : 'general';
$fundKeys = ['general' => 'cash_general', 'emergency' => 'cash_emergency'];
$aidLabelMap = $assistanceTypes;
$aidLabel = function ($k) use ($aidLabelMap) { return $aidLabelMap[$k] ?? (string)$k; };
// Order used ONLY for the type-of-aid dropdown (relief goods removed).
$dropdownAid = ['food_pack' => 'Food Packs', 'cash' => 'Cash', 'sponsored' => 'Sponsored', 'other' => 'Other'];

// ---------------- Resolve dropdown selections ----------------
$districtId = (int)($_GET['district_id'] ?? 0);
$barangayId = (int)($_GET['barangay_id'] ?? 0);
$aidType    = $_GET['aid_type'] ?? '';
if ($districtId <= 0 && $districts3) $districtId = (int)$districts3[0]['id'];
if ($barangayId <= 0) {
    $first = getBarangays($pdo, $districtId);
    $barangayId = $first ? (int)$first[0]['id'] : 0;
}
if (!array_key_exists($aidType, $assistanceTypes)) $aidType = array_key_first($assistanceTypes);
// The dropdown shows one "Cash"; the fund bar below decides General vs Emergency.
if ($aidType === 'cash') $aidType = $fundKeys[$selectedFund];
$barangays = ($districtId > 0) ? getBarangays($pdo, $districtId) : [];
$selectedBarangay = $barangayId > 0 ? getBarangayById($pdo, $barangayId) : null;

// ---------------- Inventory row(s) for the selected barangay + aid group ----
// Non-cash groups (food packs, relief goods) are owned by the barangay. CASH is
// the single shared citywide pool, so it is pulled in for every barangay -- one
// balance, deducted from whichever barangay is chosen.
$invRows = [];
if ($barangayId > 0) {
    if (aidTypeUsesPool($aidType)) {
        // Cash AND Food Packs are shared citywide pools: one balance, deducted
        // from regardless of which barangay is chosen.
        if (aidTypeUsesCashPool($aidType)) {
            $fund = $aidType === 'cash_emergency' ? 'emergency' : 'general';
            $pool = getCashPool($pdo, $fund);
            if ($pool) $invRows[] = $pool;
        } else {
            $fp = $pdo->prepare("SELECT * FROM inventory WHERE assistance_type='food_pack' AND barangay_id IS NULL ORDER BY item_name");
            $fp->execute();
            $invRows = $fp->fetchAll();
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM inventory WHERE barangay_id=? ORDER BY assistance_type, item_name");
        $stmt->execute([$barangayId]);
        $invRows = $stmt->fetchAll();
    }
}
// The selected aid group may have MULTIPLE items (e.g. several program-created
// kinds under Relief Goods). Filter by the group predicate, then let the admin
// pick the exact item to distribute via ?inv=<id>.
$selectedInv = null;
$aidInvRows = [];
foreach ($invRows as $r) {
    $match = $r['assistance_type'] === $aidType;
    if ($aidType === 'cash_general') {
        $match = $r['assistance_type'] === 'cash' && $r['cash_fund_type'] !== 'emergency';
    } elseif ($aidType === 'cash_emergency') {
        $match = $r['assistance_type'] === 'cash' && $r['cash_fund_type'] === 'emergency';
    }
    if ($match) {
        $aidInvRows[] = $r;
    }
}
$invId = (int)($_GET['inv'] ?? 0);
foreach ($aidInvRows as $r) {
    if ((int)$r['id'] === $invId) { $selectedInv = $r; break; }
}
if (!$selectedInv && $aidInvRows) $selectedInv = $aidInvRows[0];

// ---------------- Barangay members (approved) ----
// All approved members are rendered once; the category filter reveals/hides
// them client-side so the page never reloads while picking recipients.
$memberSql = "SELECT * FROM beneficiaries WHERE barangay_id=? AND status='approved' ORDER BY full_name";
$memberParams = [$barangayId];
$mStmt = $pdo->prepare($memberSql);
$mStmt->execute($memberParams);
$members = $mStmt->fetchAll();

// Each approved member may belong to several categories; load the full set so
// the picker shows every badge (not just the primary) and the category filter
// can match ANY of them.
$memberCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($m) => (int)$m['id'], $members));
foreach ($members as &$mm) {
    $mm['categories'] = $memberCatMap[(int)$mm['id']] ?? [(string)$mm['category']];
}
unset($mm);

// ---------------- Existing confirmed eligibility list for (brgy, aid) ----
$eligibilityList = null;
if ($barangayId > 0 && $aidType) {
    $elAid = dbAidType($aidType);
    $elFund = cashFundOf($aidType);
    $elWhere = "barangay_id=? AND assistance_type=? AND status='confirmed'";
    $elParams = [$barangayId, $elAid];
    if ($elFund !== null) { $elWhere .= " AND cash_fund_type=?"; $elParams[] = $elFund; }
    $el = $pdo->prepare("SELECT * FROM eligibility_lists WHERE $elWhere ORDER BY id DESC LIMIT 1");
    $el->execute($elParams);
    $eligibilityList = $el->fetch() ?: null;
}
$confirmedMemberIds = [];
if ($eligibilityList) {
    $ml = $pdo->prepare("SELECT beneficiary_id FROM eligibility_list_members WHERE list_id=?");
    $ml->execute([(int)$eligibilityList['id']]);
    $confirmedMemberIds = array_map('intval', $ml->fetchAll(PDO::FETCH_COLUMN));
}

// ---------------- HANDLERS ----------------
$errors = [];
$csrfOk = validateCSRF();
$ajax = strtoupper($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHTTPREQUEST';
$jsonOut = function (bool $ok, array $extra = []) use ($ajax) {
    if (!$ajax) return;
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok], $extra));
    exit;
};

// CONFIRM FINAL LIST (replace members)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_list']) && $csrfOk) {
    $title  = trim($_POST['title'] ?? 'Ayuda Eligible Members');
    $brgy   = (int)($_POST['barangay_id'] ?? 0);
    $aid    = $_POST['aid_type'] ?? '';
    $invId  = (int)($_POST['inventory_id'] ?? 0);
    $memberIds = $_POST['member_ids'] ?? [];
    if ($title === '') $title = 'Ayuda Eligible Members';
    if (!array_key_exists($aid, $assistanceTypes)) $aid = 'food_pack';
    $memberIds = array_filter(array_map('intval', (array)$memberIds));
    $b = getBarangayById($pdo, $brgy);
    $dist = $b ? (int)$b['district_id'] : 0;
    if (empty($memberIds)) {
        $jsonOut(false, ['message' => 'Please select at least one member for the final list.']);
        $errors[] = 'Please select at least one member for the final list.';
    } else {
        $pdo->beginTransaction();
        // Mark previous confirmed list(s) for this barangay+aid (only one "current").
        $aidDb = dbAidType($aid);
        $fund = cashFundOf($aid);
        $updSql = "UPDATE eligibility_lists SET status='draft' WHERE barangay_id=? AND assistance_type=?";
        $updParams = [$brgy, $aidDb];
        if ($fund !== null) { $updSql .= " AND cash_fund_type=?"; $updParams[] = $fund; }
        $updSql .= " AND status='confirmed'";
        $pdo->prepare($updSql)->execute($updParams);
        $insList = $pdo->prepare("INSERT INTO eligibility_lists (title, barangay_id, district_id, assistance_type, cash_fund_type, inventory_id, status, created_by, confirmed_at) VALUES (?,?,?,?,?,?, 'confirmed', ?, NOW())");
        $insList->execute([$title, $brgy, $dist, $aidDb, $fund, $invId ?: null, $_SESSION['user_id']]);
        $listId = (int)$pdo->lastInsertId();
        $insMem = $pdo->prepare("INSERT INTO eligibility_list_members (list_id, beneficiary_id) VALUES (?,?)");
        foreach ($memberIds as $mid) {
            // ensure the member really belongs to this barangay + approved
            $chk = $pdo->prepare("SELECT id FROM beneficiaries WHERE id=? AND barangay_id=? AND status='approved'");
            $chk->execute([$mid, $brgy]);
            if ($chk->fetch()) $insMem->execute([$listId, $mid]);
        }
        $pdo->commit();
        logAudit($pdo, 'eligibility_confirm', ($_SESSION['full_name'] ?? 'Admin') . " confirmed the final list for $aid in barangay #$brgy (" . count($memberIds) . " members)");
        $jsonOut(true, ['count' => count($memberIds), 'title' => $title]);
        setFlash('success', 'Final list confirmed. You can now distribute the ayuda to these members.');
        redirect('distribution.php?district_id=' . $districtId . '&barangay_id=' . $brgy . '&aid_type=' . urlencode($aid));
    }
}

// MANAGE CONFIRMED LIST MEMBERSHIP (add / remove one member after confirming).
// The confirmed list can be wrong, so the admin must be able to fix a single
// member instead of re-confirming (which REPLACES the entire list).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['list_member_action']) && $csrfOk) {
    $memAction = ($_POST['list_member_action'] ?? '') === 'add' ? 'add' : 'remove';
    $mBrgy = (int)($_POST['barangay_id'] ?? 0);
    $mAid  = $_POST['aid_type'] ?? '';
    $mBid  = (int)($_POST['beneficiary_id'] ?? 0);
    if (!array_key_exists($mAid, $assistanceTypes)) $mAid = 'food_pack';

    // Always resolve the CURRENT confirmed list server-side. A client-supplied
    // list_id is never trusted, so a crafted POST cannot edit another barangay's list.
    $mAidDb = dbAidType($mAid);
    $mFund = cashFundOf($mAid);
    $curLSql = "SELECT * FROM eligibility_lists WHERE barangay_id=? AND assistance_type=? AND status='confirmed'";
    $curLParams = [$mBrgy, $mAidDb];
    if ($mFund !== null) { $curLSql .= " AND cash_fund_type=?"; $curLParams[] = $mFund; }
    $curLSql .= " ORDER BY id DESC LIMIT 1";
    $curLQ = $pdo->prepare($curLSql);
    $curLQ->execute($curLParams);
    $curList = $curLQ->fetch();

    $listFail = function (string $msg) use ($ajax, $mBrgy, $mAid) {
        if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'message' => $msg]); exit; }
        setFlash('error', $msg);
        redirect('distribution.php?barangay_id=' . $mBrgy . '&aid_type=' . urlencode($mAid));
    };
    $listDone = function (array $payload) use ($ajax, $mBrgy, $mAid) {
        if ($ajax) { header('Content-Type: application/json'); echo json_encode(array_merge(['ok' => true], $payload)); exit; }
        setFlash('success', $payload['flash'] ?? 'Confirmed list updated.');
        redirect('distribution.php?barangay_id=' . $mBrgy . '&aid_type=' . urlencode($mAid));
    };

    if (!$curList) {
        // Distributing sets the list to 'completed' (see distribute_now), and that
        // rewrites its members to exactly who received -- so membership is locked then.
        $doneSql = "SELECT id FROM eligibility_lists WHERE barangay_id=? AND assistance_type=? AND status='completed'";
        $doneParams = [$mBrgy, $mAidDb];
        if ($mFund !== null) { $doneSql .= " AND cash_fund_type=?"; $doneParams[] = $mFund; }
        $doneSql .= " ORDER BY id DESC LIMIT 1";
        $doneQ = $pdo->prepare($doneSql);
        $doneQ->execute($doneParams);
        if ($doneQ->fetch()) {
            $listFail('This list was already distributed, so its membership is locked. Confirm a new final list to change who receives the aid.');
        }
        $listFail('There is no confirmed list for this barangay and aid type yet. Confirm the list first.');
    }
    if ($mBid <= 0 && empty($_POST['beneficiary_ids'])) $listFail('Select at least one member to remove.');

    $listId = (int)$curList['id'];
    $countQ = $pdo->prepare("SELECT COUNT(*) FROM eligibility_list_members WHERE list_id=?");

    if ($memAction === 'remove') {
        // Accept one id or many, so the admin can clear the whole list in one go.
        $mBids = [];
        if (isset($_POST['beneficiary_ids']) && is_array($_POST['beneficiary_ids'])) {
            foreach ($_POST['beneficiary_ids'] as $x) { $v = (int)$x; if ($v > 0) $mBids[] = $v; }
        }
        if (!$mBids && $mBid > 0) $mBids[] = $mBid;
        $mBids = array_values(array_unique($mBids));
        if (!$mBids) $listFail('Select at least one member to remove.');

        $ph = implode(',', array_fill(0, count($mBids), '?'));
        $del = $pdo->prepare("DELETE FROM eligibility_list_members WHERE list_id=? AND beneficiary_id IN ($ph)");
        $del->execute(array_merge([$listId], $mBids));
        $removed = $del->rowCount();
        if ($removed === 0) $listFail('None of those members are on the confirmed list.');

        $countQ->execute([$listId]);
        $left = (int)$countQ->fetchColumn();
        // Clearing the list completely is allowed: distribute_now already refuses an
        // empty list, and the admin rebuilds it by ticking members and confirming again.
        logAudit($pdo, 'eligibility_list_member_removed', ($_SESSION['full_name'] ?? 'Admin')
            . " removed $removed member(s) from the confirmed $mAid list #$listId (barangay #$mBrgy); $left member(s) remain");
        $listDone(['action' => 'remove', 'beneficiary_id' => $mBid, 'removed_ids' => $mBids,
                   'removed' => $removed, 'count' => $left,
                   'flash' => $removed . ' member(s) removed from the confirmed list.']);
    }

    // add
    $benQ = $pdo->prepare("SELECT id, full_name FROM beneficiaries WHERE id=? AND barangay_id=? AND status='approved'");
    $benQ->execute([$mBid, $mBrgy]);
    $ben = $benQ->fetch();
    if (!$ben) $listFail('That person is not an approved member of this barangay, so they cannot be added.');

    $ins = $pdo->prepare("INSERT IGNORE INTO eligibility_list_members (list_id, beneficiary_id) VALUES (?,?)");
    $ins->execute([$listId, $mBid]);
    if ($ins->rowCount() === 0) $listFail(formatName($ben['full_name']) . ' is already on the confirmed list.');

    $countQ->execute([$listId]);
    $now = (int)$countQ->fetchColumn();
    logAudit($pdo, 'eligibility_list_member_added', ($_SESSION['full_name'] ?? 'Admin')
        . ' added ' . formatName($ben['full_name']) . " (#$mBid) to the confirmed $mAid list #$listId (barangay #$mBrgy); $now member(s)");
    $listDone(['action' => 'add', 'beneficiary_id' => $mBid, 'name' => formatName($ben['full_name']),
               'count' => $now, 'flash' => formatName($ben['full_name']) . ' added to the confirmed list.']);
}

// DISTRIBUTE to all confirmed members
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['distribute_now']) && $csrfOk) {
    $brgy   = (int)($_POST['barangay_id'] ?? 0);
    $aid    = $_POST['aid_type'] ?? '';
    $invId  = (int)($_POST['inventory_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $scheduleDate = $_POST['schedule_date'] ?? '';
    $scheduleTime = normalizeTimeInput($_POST['schedule_time'] ?? '');
    if ($scheduleDate !== '' && !strtotime($scheduleDate)) $scheduleDate = '';

    $invRow = null;
    if ($invId > 0) {
        if (aidTypeUsesCashPool($aid)) {
            // Cash is the shared pool -- lock that row, whichever barangay is chosen.
            $iv = $pdo->prepare("SELECT * FROM inventory WHERE id=? AND assistance_type='cash' AND barangay_id IS NULL FOR UPDATE");
            $iv->execute([$invId]);
        } elseif ($aid === 'food_pack') {
            // Food Packs are a shared citywide pool too (like cash).
            $iv = $pdo->prepare("SELECT * FROM inventory WHERE id=? AND assistance_type='food_pack' AND barangay_id IS NULL FOR UPDATE");
            $iv->execute([$invId]);
        } else {
            $iv = $pdo->prepare("SELECT * FROM inventory WHERE id=? AND barangay_id=? FOR UPDATE");
            $iv->execute([$invId, $brgy]);
        }
        $invRow = $iv->fetch();
    }
    if (!$invRow) { $errors[] = 'Inventory item not found for this barangay.'; }
    else {
        $aidDb = dbAidType($aid);
        $fund = cashFundOf($aid);
        $listSql = "SELECT * FROM eligibility_lists WHERE barangay_id=? AND assistance_type=? AND status='confirmed'";
        $listParams = [$brgy, $aidDb];
        if ($fund !== null) { $listSql .= " AND cash_fund_type=?"; $listParams[] = $fund; }
        $listSql .= " ORDER BY id DESC LIMIT 1";
        $listRow = $pdo->prepare($listSql);
        $listRow->execute($listParams);
        $list = $listRow->fetch();
        if (!$list) { $errors[] = 'Please confirm the final list first before distributing.'; }
        else {
            // Recipients = the members actually ticked on this page (kept in sync
            // with the confirmation modal). Falls back to the confirmed final list.
            $ticked = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['member_ids'] ?? [])))));
            $toDistribute = [];
            $chk = $pdo->prepare("SELECT id FROM beneficiaries WHERE id=? AND barangay_id=? AND status='approved'");
            foreach ($ticked as $mid) { $chk->execute([$mid, $brgy]); if ($chk->fetch()) $toDistribute[] = $mid; }
            if (empty($toDistribute)) {
                $memRows = $pdo->prepare("SELECT beneficiary_id FROM eligibility_list_members WHERE list_id=?");
                $memRows->execute([(int)$list['id']]);
                $toDistribute = array_map('intval', $memRows->fetchAll(PDO::FETCH_COLUMN));
            }
            if (empty($toDistribute)) { $errors[] = 'The final list has no members to distribute to.'; }
            else {
                $isCash = $invRow['assistance_type'] === 'cash';
                $perQty = $isCash ? 1 : max(1, $quantity);
                $perAmt = $isCash ? $amount : 0.0;
                $deductPer = $isCash ? $amount : $perQty;
                $totalDeduct = $deductPer * count($toDistribute);
if ($isCash && $amount <= 0) { $errors[] = 'Please enter a valid cash amount per member.'; }
                elseif ((float)$invRow['quantity'] < $totalDeduct) {
                    // Spell out the real numbers. Cash is now ONE shared citywide pool,
                    // so this figure is the same balance every card shows.
                    // NOTE: errors are printed through e(), so keep this plain text.
                    $available = (float)$invRow['quantity'];
                    $short = $totalDeduct - $available;
                    $errors[] = $isCash
                        ? 'Not enough cash budget. The citywide pool has PHP ' . number_format($available, 2) . ' left, but '
                          . count($toDistribute) . ' member(s) x PHP ' . number_format($amount, 2) . ' = '
                          . 'PHP ' . number_format($totalDeduct, 2) . '. '
                          . 'Short by PHP ' . number_format($short, 2) . '.'
                        : 'Not enough ' . ($aid === 'food_pack' ? 'food pack stock' : 'stock in ' . ($selectedBarangay['name'] ?? 'this barangay')) . '. Only '
                          . number_format($available) . ' ' . $invRow['unit'] . ' left, but '
                          . count($toDistribute) . ' member(s) x ' . (int)$quantity . ' = '
                          . number_format($totalDeduct) . '. Short by ' . number_format($short) . ' '
                          . $invRow['unit'] . '.';
                }
                else {
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare("INSERT INTO distribution_logs (beneficiary_id, inventory_id, barangay_id, distributed_by, assistance_type, quantity, amount, location, description) VALUES (?,?,?,?,?,?,?,?,?)");
                    foreach ($toDistribute as $bid) {
                        $ins->execute([(int)$bid, $invRow['id'], $brgy, $_SESSION['user_id'], $invRow['assistance_type'], $perQty, $perAmt, $location ?: null, $description ?: null]);
                    }
                    $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id=?")->execute([$totalDeduct, $invRow['id']]);
                    logInventoryTransaction($pdo, (int)$invRow['id'], $brgy, $invRow['item_name'], 'deduct', $totalDeduct, (float)$invRow['quantity'] - $totalDeduct);
                    $pdo->prepare("UPDATE eligibility_lists SET status='completed' WHERE id=?")->execute([(int)$list['id']]);
                    // Connection: the distribution is sent to the Barangay Staff for
                    // posting. A draft schedule + announcement are created on the staff
                    // side ONLY -- the staff then posts it to beneficiaries (public side)
                    // with the "what to bring" note. Nothing shows publicly until then.
                    $brName = '';
                    $distId = 0;
                    $bn = getBarangayById($pdo, $brgy);
                    if ($bn) { $brName = $bn['name']; $distId = (int)$bn['district_id']; }
                    $schTitle = 'Ayuda Distribution - ' . $aidLabel($aid) . ($brName !== '' ? ' - ' . $brName : '');
                    $pdo->prepare("INSERT INTO distribution_schedules (title, aid_type, barangay_id, district_id, venue, schedule_date, schedule_time, what_to_bring, note, status, created_by)
                                   VALUES (?,?,?,?,?,?,?,?,?, 'draft', ?)")
                        ->execute([$schTitle, $aid, $brgy, $distId ?: $districtId, $location ?: null, $scheduleDate ?: null, $scheduleTime ?: null, 'Valid ID + Barangay Clearance', $description ?: null, $_SESSION['user_id']]);
                    $scheduleId = (int)$pdo->lastInsertId();
                    $insMem = $pdo->prepare("INSERT IGNORE INTO distribution_schedule_members (schedule_id, beneficiary_id, is_receiver) VALUES (?,?,1)");
                    foreach ($toDistribute as $bid) { $insMem->execute([$scheduleId, (int)$bid]); }
                    // Keep the confirmed final list in sync with exactly who received the ayuda.
                    $pdo->prepare("DELETE FROM eligibility_list_members WHERE list_id=?")->execute([(int)$list['id']]);
                    $insMemList = $pdo->prepare("INSERT INTO eligibility_list_members (list_id, beneficiary_id) VALUES (?,?)");
                    foreach ($toDistribute as $bid) { $insMemList->execute([(int)$list['id'], (int)$bid]); }
                    // Auto-post a distribution announcement so it reflects on the Staff side
                    // (linked -- not duplicated -- via source_type/source_id). Public side is
                    // gated: it only shows once the staff posts it to beneficiaries.
                    $schParts = [];
                    if ($scheduleDate !== '') $schParts[] = 'Schedule: ' . date('F j, Y', strtotime($scheduleDate)) . ($scheduleTime !== '' ? ' • ' . $scheduleTime : '');
                    elseif ($scheduleTime !== '') $schParts[] = 'Schedule: ' . $scheduleTime;
                    if ($location !== '') $schParts[] = 'Venue: ' . $location;
                    if ($description !== '') $schParts[] = 'Details: ' . $description;
                    $memberCount = count($toDistribute);
                    $schParts[] = 'Recipients: ' . $memberCount . ' ' . ($memberCount === 1 ? 'resident' : 'residents')
                                 . ' &mdash; each receives ' . ($isCash ? 'PHP ' . number_format($perAmt, 2) : $perQty . ' ' . ($invRow['unit'] ?? 'unit'));
                    $catBreak = recipientCategoryBreakdown($pdo, $toDistribute);
                    if ($catBreak) $schParts[] = 'Recipients by category: ' . categoryBreakdownText($catBreak);
                    ensureAutoAnnouncement($pdo, 'distribution_schedule', $scheduleId, $brgy, $schTitle, implode("\n", $schParts), 'normal', $_SESSION['user_id']);
                    $pdo->commit();
                    logAudit($pdo, 'distribute_list', ($_SESSION['full_name'] ?? 'Admin') . " distributed $aid to $memberCount members in barangay #$brgy (" . ($isCash ? 'PHP ' . number_format($perAmt, 2) : $perQty . ' ' . ($invRow['unit'] ?? 'unit')) . ' each)');
                    setFlash('success', 'Ayuda distributed to ' . count($toDistribute) . ' member(s). The schedule &amp; announcement are now with the Barangay Staff &mdash; they will post it to beneficiaries with the "what to bring" note. <a href="announcements.php" style="font-weight:700;text-decoration:underline;">View Announcements</a>', true);
                    redirect('distribution.php?district_id=' . $districtId . '&barangay_id=' . $brgy . '&aid_type=' . urlencode($aid));
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Distribution - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
.final-list-item { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:7px 4px; border-bottom:1px solid #eef2f7; }
</style>
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/includes/flash.php'; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><ul style="margin:0;padding-left:18px;"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
        <?php endif; ?>

        <!-- STEP 1-3: dropdowns -->
        <div class="card" style="margin-bottom:14px;">
            <div class="section-title" style="margin:0 0 10px;">Select District - Barangay - Aid Type</div>
            <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                    <label>District</label>
                    <select name="district_id" id="districtSelect" class="form-control" onchange="submitWithAid()">
                        <?php foreach ($districts3 as $i => $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= $districtId === (int)$d['id'] ? 'selected' : '' ?>>District <?= (int)$i + 1 ?> (<?= e($d['name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                    <label>Barangay</label>
                    <select name="barangay_id" id="barangaySelect" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $barangayId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:1;min-width:170px;margin-bottom:0;">
                    <label>Type of Aid</label>
                    <select name="aid_type" id="aidSelect" class="form-control" onchange="this.form.submit()">
                        <?php $selIsCash = $aidType === 'cash_general' || $aidType === 'cash_emergency'; foreach ($dropdownAid as $k => $label): if ($k === 'cash' && !$cashShown) continue; ?>
                            <option value="<?= $k ?>" <?= ($k === 'cash' ? $selIsCash : $aidType === $k) ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php if ($selIsCash): ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px;padding-top:12px;border-top:1px dashed var(--line);">
                    <span class="small" style="font-weight:700;color:var(--navy);">Deduct from which fund?</span>
                    <a href="?district_id=<?= (int)$districtId ?>&barangay_id=<?= (int)$barangayId ?>&aid_type=cash&fund=general" class="btn <?= $selectedFund==='general' ? 'btn-gold' : 'btn-outline' ?>" style="gap:6px;"><?= svgIcon('dollar', 14) ?> General Fund</a>
                    <a href="?district_id=<?= (int)$districtId ?>&barangay_id=<?= (int)$barangayId ?>&aid_type=cash&fund=emergency" class="btn <?= $selectedFund==='emergency' ? 'btn-danger' : 'btn-outline' ?>" style="gap:6px;"><?= svgIcon('dollar', 14) ?> Emergency Fund</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($barangayId > 0 && $selectedBarangay): ?>
        <div class="card" style="margin-bottom:14px;display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <div class="li-name" style="margin-bottom:4px;"><?= e($selectedBarangay['name']) ?> &mdash; <?= e($selectedBarangay['district_name']) ?></div>
                <?php if ($selectedInv): ?>
                    <div class="small <?= $selectedInv['assistance_type']==='cash' ? '' : 'text-muted' ?>" style="font-size:13px;">
                        <strong><?= e($selectedInv['item_name']) ?></strong>
                        <?= $selectedInv['assistance_type']==='cash'
                            ? '&mdash; budget <strong>&#8369;' . number_format((float)$selectedInv['quantity'], 2) . '</strong>'
                            : '&mdash; remaining <strong>' . (int)$selectedInv['quantity'] . ' ' . e($selectedInv['unit']) . '</strong>' ?>
                        <span class="small text-muted">(this barangay's <?= e($aidLabel($aidType)) ?>)</span>
                    </div>
                    <?php if (count($aidInvRows) > 1): ?>
                        <form method="GET" action="distribution.php" style="margin-top:8px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                            <input type="hidden" name="district_id" value="<?= (int)$districtId ?>">
                            <input type="hidden" name="barangay_id" value="<?= (int)$barangayId ?>">
                            <input type="hidden" name="aid_type" value="<?= e($aidType) ?>">
                            <div class="form-group" style="flex:1;min-width:180px;margin-bottom:0;">
                                <label>Item / kind to distribute</label>
                                <select name="inv" class="form-control" onchange="this.form.submit()">
                                    <?php foreach ($aidInvRows as $rr): $isSel = $selectedInv && (int)$selectedInv['id'] === (int)$rr['id']; ?>
                                        <option value="<?= (int)$rr['id'] ?>" <?= $isSel ? 'selected' : '' ?>><?= e($rr['item_name']) ?> (<?= $rr['assistance_type']==='cash' ? '&#8369;' . number_format((float)$rr['quantity'], 2) : (int)$rr['quantity'] . ' ' . e($rr['unit']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="small text-muted">No <?= e($aidLabel($aidType)) ?> inventory set for this barangay yet.</div>
                <?php endif; ?>
            </div>
            <a href="distribution_process.php?district_id=<?= (int)$districtId ?>&barangay_id=<?= (int)$barangayId ?>&aid_type=<?= urlencode($aidType) ?>"
               class="btn btn-gold" style="flex:1;min-width:200px;display:inline-flex;align-items:center;justify-content:center;gap:8px;">
                <?= svgIcon('box', 17) ?> Distribution Process
            </a>
        </div>

        <!-- STEP 5: confirm final list -->
        <div class="section-title">Confirm Final List (CSWDO) &mdash; who receives <?= e(strtolower($aidLabel($aidType))) ?></div>
        <div class="card" style="margin-bottom:14px;">
            <form method="POST" id="confirmListForm" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="confirm_list" value="1">
                <input type="hidden" name="barangay_id" value="<?= (int)$barangayId ?>">
                <input type="hidden" name="aid_type" value="<?= e($aidType) ?>">
                <input type="hidden" name="inventory_id" value="<?= $selectedInv ? (int)$selectedInv['id'] : 0 ?>">

                <div class="form-group">
                    <label>List Title</label>
                    <input type="text" name="title" class="form-control" value="Ayuda - <?= e($aidLabel($aidType)) ?> - <?= e($selectedBarangay['name']) ?>">
                </div>

<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;">
                        <div class="form-group" style="flex:1;min-width:180px;margin-bottom:0;">
                            <label>Filter by Category</label>
                            <select id="catSelect" class="form-control" onchange="showMembersBox()">
                                <option value="">All Members</option>
                                <?php foreach (BENEFICIARY_CATEGORIES as $cc): ?>
                                    <option value="<?= $cc ?>"><?= $cc ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small text-muted mt-2">Pick a category to drop the member list down.</div>
                        </div>
                        <span class="small text-muted" style="padding-bottom:10px;" id="selCountLabel"></span>
                    </div>

                    <div id="membersBox" style="display:none;max-height:360px;overflow:auto;border:1px solid #e3e9f0;border-radius:10px;padding:6px 12px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:8px 0 10px;border-bottom:1px solid #eef2f7;">
                            <label style="display:flex;align-items:center;gap:8px;font-weight:700;color:var(--navy);cursor:pointer;">
                                <input type="checkbox" id="selectAll" onchange="toggleAll(this.checked)"> Select all <span id="selAllLabel">members</span> (<span id="selAllCount">0</span>)
                            </label>
                            <span style="display:flex;gap:8px;align-items:center;">
                                <button type="button" id="clearSelBtn" class="btn btn-sm" onclick="clearSelection()" style="padding:4px 12px;font-size:12px;font-weight:600;background:#fff;color:#b91c1c;border:1px solid #f0b4b4;">Clear selection</button>
                                <button type="button" class="btn btn-sm" onclick="closeMembersBox()" style="padding:4px 12px;font-size:12px;font-weight:600;background:#fff;color:#334155;border:1px solid #cbd5e1;">Hide list</button>
                            </span>
                        </div>
                        <?php if (!$members): ?>
                            <div class="empty-state">No approved members in this barangay yet.</div>
                        <?php endif; ?>
                        <?php foreach ($members as $m): $mCats = $m['categories'] ?? [(string)$m['category']]; $mCatsAttr = implode('|', $mCats); ?>
                            <div class="final-list-item" data-cat="<?= e($m['category']) ?>" data-cats="<?= e($mCatsAttr) ?>">
                                <label style="display:flex;align-items:center;gap:10px;flex:1;cursor:pointer;">
                                    <input type="checkbox" name="member_ids[]" value="<?= (int)$m['id'] ?>" class="mem-chk" data-cat="<?= e($m['category']) ?>" data-cats="<?= e($mCatsAttr) ?>" onchange="updateSelCount()">
                                    <div style="font-weight:600;font-size:14px;"><?= e(formatName($m['full_name'])) ?></div>
                                </label>
                                <span class="small text-muted" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:flex-end;"><?php foreach ($mCats as $mc): ?><span class="badge badge-navy" style="font-size:10.5px;"><?= e($mc) ?></span><?php endforeach; ?><span class="conf-tag" style="display:none;"> &bull; <strong>Confirmed</strong></span></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <button type="submit" class="btn btn-primary btn-block"><?= svgIcon('check', 15) ?> Confirm Final List</button>
            </form>

            <?php if ($eligibilityList): ?>
                <div class="alert alert-success mt-3" style="margin-bottom:0;<?= count($confirmedMemberIds)===0 ? 'display:none;' : '' ?>" id="currentListAlert">
                    <strong>Current confirmed list:</strong> <span id="currentListInfo">&ldquo;<?= e($eligibilityList['title']) ?>&rdquo; &bull; <?= count($confirmedMemberIds) ?> member(s) &bull; confirmed on <?= date('M j, Y g:i A', strtotime($eligibilityList['confirmed_at'])) ?></span>
                </div>
            <?php endif; ?>
            <div class="alert alert-success mt-3" style="margin-bottom:0;display:none;" id="confirmSuccess"></div>

            <?php if ($eligibilityList && $eligibilityList['status'] === 'confirmed'):
                $curMemberRows = [];
                if ($confirmedMemberIds) {
                    $inIds = implode(',', array_map('intval', $confirmedMemberIds));
                    $curMemberRows = $pdo->query("SELECT id, full_name, category FROM beneficiaries WHERE id IN ($inIds) ORDER BY full_name")->fetchAll();
                    $curCatMap = beneficiaryCategoriesMap($pdo, array_map(fn($r) => (int)$r['id'], $curMemberRows));
                    foreach ($curMemberRows as &$cr) { $cr['categories'] = $curCatMap[(int)$cr['id']] ?? [(string)$cr['category']]; }
                    unset($cr);
                }
            ?>
            <div class="card" id="manageCard" style="margin-top:12px;background:#f7fafd;border:1px solid var(--line);<?= count($confirmedMemberIds)===0 ? 'display:none;' : '' ?>">
                <div class="d-flex justify-between align-center" style="gap:10px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:220px;">
                        <div class="li-name" style="font-size:14px;"><?= svgIcon('users', 14) ?> Edit Confirmed List</div>
                    </div>
                    <div class="small text-muted">Total on list: <strong data-live-count><?= count($confirmedMemberIds) ?></strong></div>
                    <button type="button" id="manageToggle" class="btn btn-sm" onclick="toggleManageList()" style="padding:4px 12px;font-size:12px;font-weight:600;">Show members</button>
                </div>
                <div id="manageBody" style="display:none;margin-top:12px;">
                    <div class="form-group" style="margin-bottom:10px;">
                        <label>Add a member you missed</label>
                        <input type="text" id="addSearch" class="form-control" placeholder="Type at least 2 letters of a name" oninput="renderAddResults()" autocomplete="off">
                        <div id="addResults" style="margin-top:6px;max-height:170px;overflow:auto;border:1px solid #e3e9f0;border-radius:10px;padding:6px 12px;display:none;"></div>
                    </div>
                    <div class="d-flex justify-between align-center" style="gap:10px;flex-wrap:wrap;margin-bottom:4px;">
                        <label style="display:flex;align-items:center;gap:8px;font-weight:700;color:var(--navy);cursor:pointer;">
                            <input type="checkbox" id="curSelectAll" onchange="toggleAllCur(this.checked)"> Select all
                        </label>
                        <button type="button" id="removeSelBtn" class="btn btn-sm" onclick="removeSelectedMembers()" disabled
                                style="padding:4px 12px;font-size:12px;font-weight:600;background:#fff;color:#b91c1c;border:1px solid #f0b4b4;">
                            Remove selected (<span id="curSelCount">0</span>)
                        </button>
                    </div>
                    <div class="small text-muted" style="margin-bottom:4px;">On the confirmed list now (<span data-live-count><?= count($confirmedMemberIds) ?></span>):</div>
                    <div id="curMemberBox" style="max-height:260px;overflow:auto;border:1px solid #e3e9f0;border-radius:10px;padding:6px 12px;">
                        <?php foreach ($curMemberRows as $cm): ?>
                        <div class="final-list-item" id="curMember-<?= (int)$cm['id'] ?>">
                            <label style="display:flex;align-items:center;gap:10px;flex:1;cursor:pointer;">
                                <input type="checkbox" class="curChk" value="<?= (int)$cm['id'] ?>" onchange="updateCurSelCount()" style="flex-shrink:0;">
                                <div style="font-weight:600;font-size:14px;"><?= e(formatName($cm['full_name'])) ?></div>
                            </label>
                            <span class="small text-muted" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:flex-end;"><?php foreach (($cm['categories'] ?? [(string)$cm['category']]) as $cc): ?><span class="badge badge-navy" style="font-size:10.5px;"><?= e($cc) ?></span><?php endforeach; ?></span>
                            <button type="button" class="btn btn-sm rmMemberBtn" data-id="<?= (int)$cm['id'] ?>" data-name="<?= e(formatName($cm['full_name'])) ?>" style="margin-left:8px;background:#fff;color:#b91c1c;border:1px solid #f0b4b4;">Remove</button>
                        </div>
                        <?php endforeach; ?>
                        <div id="curEmptyMsg" class="small text-muted" style="padding:10px 0;<?= $curMemberRows ? 'display:none;' : '' ?>">
                            No members on the confirmed list. Tick members above and press <strong>Confirm Final List</strong> to rebuild it.
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- STEP 6: distribute -->
        <?php if ($selectedInv): ?>
        <div class="section-title">Distribute to the Confirmed Final List</div>
        <div class="card">
            <div id="distEmpty" class="card empty-state" style="margin-top:0;<?= ($eligibilityList && $confirmedMemberIds) ? 'display:none;' : '' ?>">Please confirm the final list above before distributing.</div>
            <form method="POST" id="distForm" style="<?= ($eligibilityList && $confirmedMemberIds) ? '' : 'display:none;' ?>">
                <?php $isCash = $selectedInv['assistance_type'] === 'cash'; ?>
                    <input type="hidden" name="distribute_now" value="1">
                    <?= csrfField() ?>
                    <input type="hidden" name="barangay_id" value="<?= (int)$barangayId ?>">
                    <input type="hidden" name="aid_type" value="<?= e($aidType) ?>">
                    <input type="hidden" name="inventory_id" value="<?= (int)$selectedInv['id'] ?>">
                    <input type="hidden" id="distMemberCount" value="<?= count($confirmedMemberIds) ?>">
                    <div id="distMemberIds"></div>

                    <div class="form-group">
                        <label>Distribution to <span id="distHeadingNum"><?= count($confirmedMemberIds) ?></span> member(s) &mdash; <?= e($selectedBarangay['name']) ?></label>
                    </div>

                    <?php if ($isCash): ?>
                        <div class="form-group">
                            <label>Cash Amount per Member (&#8369;)</label>
                            <input type="number" name="amount" id="amountInput" class="form-control" min="1" step="0.01" required
                                   placeholder="e.g. 1000">
                            <div class="small text-muted mt-2">Total to deduct: <span id="totalEst">&#8369;0.00</span></div>
                            <div id="budgetHint" class="small mt-2" style="color:#64748b;">
                                Available in <?= e($selectedBarangay['name'] ?? 'this barangay') ?>:
                                <strong>&#8369;<?= number_format((float)$selectedInv['quantity'], 2) ?></strong>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Quantity per Member (<?= e($selectedInv['unit']) ?>)</label>
                            <input type="number" name="quantity" id="quantityInput" class="form-control" min="1" step="1" required value="1">
                            <div class="small text-muted mt-2">Total to deduct: <span id="totalEst">0</span></div>
                            <div id="budgetHint" class="small mt-2" style="color:#64748b;">
                                Available in <?= e($selectedBarangay['name'] ?? 'this barangay') ?>:
                                <strong><?= number_format((float)$selectedInv['quantity']) ?> <?= e($selectedInv['unit']) ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Distribution Location</label>
                        <input type="text" name="location" class="form-control" value="<?= e($selectedInv['location'] ?? '') ?>">
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1;min-width:140px;margin-bottom:0;">
                            <label>Schedule Date <span class="small text-muted">(announcement)</span></label>
                            <input type="date" name="schedule_date" class="form-control">
                        </div>
                        <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0;">
                            <label>Schedule Time <span class="small text-muted">(announcement)</span></label>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <input type="time" step="60" name="schedule_time_from" id="scheduleTimeFrom" class="form-control" placeholder="From">
                                <span style="color:#6b7280;">-</span>
                                <input type="time" step="60" name="schedule_time_to" id="scheduleTimeTo" class="form-control" placeholder="To">
                            </div>
                            <input type="hidden" name="schedule_time" id="scheduleTimeCombined">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description <span class="small text-muted">(shown to staff as the announcement)</span></label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>

                    <button type="submit" id="distBtn" class="btn btn-gold btn-block" onclick="prepareDistTime()"><?= svgIcon('box', 15) ?> Distribute to <span id="distBtnNum"><?= count($confirmedMemberIds) ?></span> Members</button>
                </form>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- CONFIRM & DISTRIBUTE (inline at the bottom of this page) -->
        <div id="confirmModal" style="display:none;max-width:860px;margin:18px auto 0;">
            <div class="section-title" style="text-align:center;">Confirm &amp; Distribute</div>
            <div class="card" style="margin-bottom:14px;">
                <div style="background:#f7f9fc;border:1px solid #e3e9f0;border-radius:12px;padding:20px 24px;">
                    <div class="small text-muted">District / Barangay</div>
                    <div class="li-name" id="cmArea">&mdash;</div>
                    <div class="small text-muted mt-2">Aid Type</div>
                    <div class="li-name" id="cmAid">&mdash;</div>

                    <div class="small text-muted mt-3">Confirmed Recipients <span class="text-muted">(final list)</span></div>
                    <div style="position:relative;margin-top:2px;">
                        <span id="cmRecipToggle" onclick="toggleRecipList()" role="button" tabindex="0"
                              style="cursor:pointer;font-weight:700;color:var(--navy);font-size:15px;text-decoration:underline;text-underline-offset:3px;">
                            <span id="cmRecipSummary">No confirmed members</span>
                        </span>
                        <!-- Drops down as an overlay so the card never grows. -->
                        <div id="cmRecipients"
                             style="display:none;position:absolute;top:100%;left:0;right:0;z-index:20;margin-top:6px;
                                    max-height:240px;overflow:auto;padding:14px 18px;background:#fff;
                                    border:1px solid #d7dfe8;border-radius:12px;box-shadow:0 12px 28px rgba(7,31,51,0.18);">&mdash;</div>
                    </div>

                    <div class="small text-muted mt-3">Amount / Quantity (each)</div>
                    <div class="li-name" id="cmAmt">&mdash;</div>
                    <div class="small text-muted mt-2">Total to Deduct</div>
                    <div class="li-name" id="cmTotal">&mdash;</div>
                    <div class="small text-muted mt-2">Schedule</div>
                    <div class="li-name" id="cmSched">&mdash;</div>
                    <div class="small text-muted mt-2">Location / Description</div>
                    <div class="li-name" id="cmDesc">&mdash;</div>
                </div>
                <div class="small text-muted mt-3" style="text-align:center;">This will distribute to the confirmed recipients above. Are you sure?</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                    <button type="button" class="btn btn-gold" id="cmYes" style="flex:1;min-width:200px;display:inline-flex;align-items:center;justify-content:center;gap:8px;"><?= svgIcon('check', 15) ?> Confirm &amp; Distribute</button>
                    <button type="button" class="btn btn-outline" id="cmNo" style="flex:1;min-width:160px;">Cancel</button>
                </div>
            </div>
        </div>

        <!-- REMOVE MEMBERS (centered popup, not anchored to the bottom of the page) -->
        <div id="removeModal" role="dialog" aria-modal="true"
             style="display:none;position:fixed;inset:0;z-index:60;background:rgba(10,20,35,0.55);align-items:center;justify-content:center;padding:16px;">
            <div class="card" style="max-width:520px;width:100%;margin:0;border-radius:16px;box-shadow:0 18px 40px rgba(7,31,51,0.35);">
                <div class="section-title" style="text-align:center;">Remove from the Confirmed List</div>
                <div style="background:#f7f9fc;border:1px solid #e3e9f0;border-radius:12px;padding:20px 24px;margin-bottom:14px;">
                    <div class="li-name" id="rvHeading" style="font-size:15px;">&mdash;</div>
                    <div class="small text-muted mt-2" id="rvSub"></div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-danger" id="rvYes" style="flex:1;min-width:160px;display:inline-flex;align-items:center;justify-content:center;gap:8px;"><?= svgIcon('trash', 15) ?> Remove</button>
                    <button type="button" class="btn btn-outline" id="rvNo" style="flex:1;min-width:140px;">Cancel</button>
                </div>
            </div>
        </div>

    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>

<script>
const barangaysByDistrict = <?php
    $all = [];
    foreach ($districts3 as $d) { $all[$d['id']] = getBarangays($pdo, $d['id']); }
    echo json_encode($all);
?>;
function submitWithAid() {
    const dist = document.getElementById('districtSelect');
    const aid = document.getElementById('aidSelect').value;
    let brgy = '';
    if (barangaysByDistrict[dist.value] && barangaysByDistrict[dist.value].length) {
        brgy = barangaysByDistrict[dist.value][0].id;
    }
    const url = new URL(window.location.href);
    url.searchParams.set('district_id', dist.value);
    if (brgy) url.searchParams.set('barangay_id', brgy);
    if (aid) url.searchParams.set('aid_type', aid);
    url.searchParams.delete('inv');
    window.location.href = url.toString();
}
function applyCatFilter() {
    const cat = document.getElementById('catSelect').value;
    let shown = 0;
    document.querySelectorAll('#membersBox .final-list-item').forEach(function (row) {
        const rowCats = (row.getAttribute('data-cats') || row.getAttribute('data-cat') || '').split('|');
        const match = cat === '' || rowCats.indexOf(cat) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) shown++;
        // A narrower filter must never carry hidden members along: only the
        // rows that stay visible may stay selected.
        const chk = row.querySelector('.mem-chk');
        if (chk && !match) chk.checked = false;
    });
    const lab = document.getElementById('selAllLabel');
    if (lab) lab.textContent = cat ? cat : 'members';
    const cnt = document.getElementById('selAllCount');
    if (cnt) cnt.textContent = shown;
    // uncheck select all when filter changes
    const sa = document.getElementById('selectAll');
    if (sa) sa.checked = false;
    renderConfirmedTags();
    updateSelCount();
}
// The member list stays collapsed on load -- it only drops down when the
// admin changes the category filter, and it is never auto-opened elsewhere.
function showMembersBox() {
    const box = document.getElementById('membersBox');
    if (box && box.style.display === 'none') box.style.display = 'block';
    if (typeof applyCatFilter === 'function') applyCatFilter();
}
function closeMembersBox() {
    const box = document.getElementById('membersBox');
    if (box) box.style.display = 'none';
}
function toggleAll(checked) {
    // Only tick the rows currently visible under the category filter, so
    // selecting "all" never silently picks hidden members from other groups.
    document.querySelectorAll('#membersBox .final-list-item').forEach(function (row) {
        const chk = row.querySelector('.mem-chk');
        if (chk && row.style.display !== 'none') chk.checked = checked;
    });
    renderConfirmedTags();
    updateSelCount();
}
// Untick everyone currently ticked -- for when the wrong recipients were picked
// and the admin wants to start the selection over instead of submitting it.
function clearSelection() {
    document.querySelectorAll('.mem-chk:checked').forEach(function (chk) {
        chk.checked = false;
    });
    const sa = document.getElementById('selectAll');
    if (sa) sa.checked = false;
    renderConfirmedTags();
    updateSelCount();
    const btn = document.getElementById('clearSelBtn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Selection cleared';
        setTimeout(function () {
            btn.disabled = false;
            btn.textContent = 'Clear selection';
        }, 1500);
    }
}
function updateSelCount() {
    let rows = document.querySelectorAll('#membersBox .final-list-item');
    rows = Array.prototype.filter.call(rows, function (row) { return row.style.display !== 'none'; });
    const total = rows.length;
    const n = rows.filter(function (row) {
        const chk = row.querySelector('.mem-chk');
        return chk && chk.checked;
    }).length;
    const lbl = document.getElementById('selCountLabel');
    if (lbl) lbl.innerHTML = 'Picked: <strong>' + n + '</strong> of ' + total;
    // The heading and the button describe the distribution, so they follow the
    // confirmed final list rather than the raw ticks.
    const confirmed = (window.__onList || []).map(Number).filter(function (v) { return v > 0; }).length;
    const hd = document.getElementById('distHeadingNum');
    if (hd) hd.textContent = confirmed;
    const bn = document.getElementById('distBtnNum');
    if (bn) bn.textContent = confirmed;
    const cb = document.getElementById('clearSelBtn');
    if (cb) cb.disabled = n === 0;
    // Keep the live "will this fit?" budget check in step with the selection.
    if (typeof updateTotals === 'function') updateTotals();
}
function renderConfirmedTags() {
    document.querySelectorAll('#membersBox .final-list-item').forEach(function (row) {
        const tag = row.querySelector('.conf-tag');
        const chk = row.querySelector('.mem-chk');
        if (tag && chk) tag.style.display = chk.checked ? '' : 'none';
    });
}
function escHtml(s) { return String(s || '').replace(/[&<>"']/g, function (ch) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[ch]; }); }
// The recipient list is collapsed by default -- click the count to drop it down.
function setRecipListOpen(open) {
    const box = document.getElementById('cmRecipients');
    const tog = document.getElementById('cmRecipToggle');
    if (box) box.style.display = open ? '' : 'none';
    if (tog) tog.setAttribute('aria-expanded', open ? 'true' : 'false');
}
function toggleRecipList() {
    const box = document.getElementById('cmRecipients');
    if (!box) return;
    setRecipListOpen(box.style.display === 'none');
}
// The confirm step is a card at the bottom of this page, not a popup.
function showConfirmCard() {
    const card = document.getElementById('confirmModal');
    if (!card) return;
    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function hideConfirmCard() {
    const card = document.getElementById('confirmModal');
    if (card) card.style.display = 'none';
}
// Inline confirm for removing members -- replaces the browser native popup.
let __removeCb = null;
function showRemoveConfirm(heading, sub, onYes) {
    const card = document.getElementById('removeModal');
    if (!card) { if (typeof onYes === 'function') onYes(); return; }
    const h = document.getElementById('rvHeading');
    if (h) h.innerHTML = heading;
    const s = document.getElementById('rvSub');
    if (s) s.innerHTML = sub;
    __removeCb = onYes || null;
    card.style.display = 'flex';
    const yes = document.getElementById('rvYes');
    if (yes) yes.focus({ preventScroll: true });
}
function hideRemoveConfirm() {
    const card = document.getElementById('removeModal');
    if (card) card.style.display = 'none';
    __removeCb = null;
}
function removeConfirmYes() {
    const cb = __removeCb;
    hideRemoveConfirm();
    if (typeof cb === 'function') cb();
}
const __rvYes = document.getElementById('rvYes');
const __rvNo = document.getElementById('rvNo');
const __removeModal = document.getElementById('removeModal');
if (__rvYes) __rvYes.addEventListener('click', removeConfirmYes);
if (__rvNo) __rvNo.addEventListener('click', hideRemoveConfirm);
if (__removeModal) {
    __removeModal.addEventListener('click', function (e) { if (e.target === __removeModal) hideRemoveConfirm(); });
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideRemoveConfirm(); });
// The toggle is keyboard reachable, so honour Enter and Space too.
document.addEventListener('keydown', function (e) {
    const tog = document.getElementById('cmRecipToggle');
    if (!tog || document.activeElement !== tog) return;
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
        e.preventDefault();
        toggleRecipList();
    }
});
// Clicking anywhere else closes the dropped-down list, so it never sits on top
// of the Confirm & Distribute button.
document.addEventListener('click', function (e) {
    const tog = document.getElementById('cmRecipToggle');
    const box = document.getElementById('cmRecipients');
    if (!tog || !box || box.style.display === 'none') return;
    if (tog.contains(e.target) || box.contains(e.target)) return;
    setRecipListOpen(false);
});
function confirmListDone(data) {
    renderConfirmedTags();
    updateSelCount();
    const title = escHtml(data.title);
    const ok = document.getElementById('confirmSuccess');
    if (ok) { ok.style.display = '';
        ok.innerHTML = '<strong>Final list confirmed</strong> &mdash; ' + data.count + ' member(s) on &ldquo;' + title + '&rdquo;. You can now distribute below.'; }
    const listAlert = document.getElementById('currentListAlert');
    const manage = document.getElementById('manageCard');
    const info = document.getElementById('currentListInfo');
    const empty = document.getElementById('distEmpty');
    const form = document.getElementById('distForm');
    const membersBox = document.getElementById('membersBox');
    if (info) info.innerHTML = '&ldquo;<strong>' + title + '</strong>&rdquo; &bull; ' + data.count + ' member(s) &bull; confirmed just now';
    if (listAlert) listAlert.style.display = '';
    if (manage) manage.style.display = '';
    if (empty) empty.style.display = 'none';
    if (form) form.style.display = '';
    if (membersBox && membersBox.style.display !== 'none' && typeof applyCatFilter === 'function') applyCatFilter();
    if (typeof updateSelCount === 'function') updateSelCount();
    // Reload so the "Edit Confirmed List" card (with its Show members toggle) is
    // rendered server-side, but restore the exact scroll position so the page
    // never jumps back to the top.
    try {
        sessionStorage.setItem('ayudaDistScroll', String(window.pageYOffset || document.documentElement.scrollTop || 0));
    } catch (err) { /* storage unavailable -- fall through */ }
    setTimeout(function () { window.location.reload(); }, 250);
}
function formatPeso(v) { return Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

// Converts an <input type="time"> value ("09:00") to "9:00 AM" for display so
// the confirmation summary always reads like the rest of the panel.
function time12(v) {
    var s = String(v || '').trim();
    if (!s) return '';
    if (/[-–—]/.test(s)) {
        var parts = s.split(/\s*[-–—]\s*/);
        return parts.map(function(p){ return time12(p); }).filter(Boolean).join(' - ') || s;
    }
    var t = s.split(':');
    if (t.length < 2) return s;
    var h = parseInt(t[0], 10);
    var m = String(t[1] || '00').slice(0, 2);
    if (isNaN(h)) return s;
    var ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) h = 12;
    return h + ':' + m + ' ' + ap;
}
function prepareDistTime() {
    var fromEl = document.getElementById('scheduleTimeFrom');
    var toEl = document.getElementById('scheduleTimeTo');
    var out = document.getElementById('scheduleTimeCombined');
    if (!fromEl || !toEl || !out) return;
    var a = String(fromEl.value || '').trim();
    var b = String(toEl.value || '').trim();
    if (a && b) out.value = a + ' - ' + b;
    else out.value = a || b || '';
}

const distForm = document.getElementById('distForm');

// Confirm Final List without reloading the page.
const confirmForm = document.getElementById('confirmListForm');
if (confirmForm) {
    confirmForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!document.querySelector('.mem-chk:checked')) { alert('Please select at least one member for the final list.'); return; }
        const btn = confirmForm.querySelector('button[type="submit"]');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Confirming&hellip;';
        fetch(window.location.href, {
            method: 'POST',
            body: new FormData(confirmForm),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (data && data.ok) confirmListDone(data);
              else alert((data && data.message) || 'Could not confirm the final list.');
          })
          .catch(function () { alert('Network error while confirming. Please try again.'); })
          .finally(function () { btn.disabled = false; btn.innerHTML = orig; });
    });
}

// Initial UI state: label counts + confirmed tags (no reload ever needed).
(function initDistributionUI() {
    const cnt = document.getElementById('selAllCount');
    if (cnt) cnt.textContent = document.querySelectorAll('#membersBox .final-list-item').length;
    updateSelCount();
    renderConfirmedTags();
})();
// ---- Confirmed final list state (shared by the form totals, the modal and the edit card) ----
window.__approvedMembers = <?= json_encode(array_map(static function ($m) {
    $cats = $m['categories'] ?? [(string)$m['category']];
    return ['id' => (int)$m['id'], 'name' => formatName($m['full_name']), 'cat' => (string)$m['category'], 'cats' => implode(', ', $cats)];
}, $members), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.__onList = <?= json_encode(array_values(array_map('intval', $confirmedMemberIds))) ?>;
window.__listBrgy = <?= (int)$barangayId ?>;
window.__listAid = <?= json_encode($aidType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.__csrf = <?= json_encode(generateCSRFToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

if (distForm) {
    const amtEl = distForm.elements['amount'];
    const qtyEl = distForm.elements['quantity'];
    const isCash = !!amtEl;
    const budgetHint = document.getElementById('budgetHint');
    const distBtn = document.getElementById('distBtn');
    // Budget/stock available in the SELECTED barangay for the selected item.
    // This is what the server checks -- NOT the citywide card total.
    const availQty = <?= (float)$selectedInv['quantity'] ?>;
    // The recipients are the CONFIRMED final list, so the totals and the
    // budget check must count that list -- not the ticks in the picker.
    function selCount() { return (window.__onList || []).map(Number).filter(function (v) { return v > 0; }).length; }

    function updateTotals() {
        const el = document.getElementById('totalEst');
        const n = selCount();
        const per = isCash ? (Number(amtEl.value) || 0) : (Number(qtyEl.value) || 0);
        const total = per * n;
        if (isCash) {
            el.innerHTML = '&#8369;' + formatPeso(total);
        } else {
            el.textContent = total + ' unit(s)';
        }
        // Live "will this fit?" check so the user is not surprised by the server error.
        if (budgetHint) {
            const over = total > availQty + 0.00001;
            if (n === 0 || per <= 0) {
                budgetHint.style.color = '#64748b';
                budgetHint.innerHTML = 'Available in this barangay: <strong>'
                    + (isCash ? '&#8369;' : '') + (isCash ? formatPeso(availQty) : availQty.toLocaleString())
                    + '</strong>';
            } else if (over) {
                const short = total - availQty;
                budgetHint.style.color = '#b91c1c';
                budgetHint.innerHTML = '&#9888; Not enough. Needs <strong>'
                    + (isCash ? '&#8369;' : '') + (isCash ? formatPeso(total) : total.toLocaleString())
                    + '</strong> but only <strong>'
                    + (isCash ? '&#8369;' : '') + (isCash ? formatPeso(availQty) : availQty.toLocaleString())
                    + '</strong> is available here &mdash; short by <strong>'
                    + (isCash ? '&#8369;' : '') + (isCash ? formatPeso(short) : short.toLocaleString())
                    + '</strong>. Tick fewer recipients or lower the amount.';
            } else {
                const after = availQty - total;
                budgetHint.style.color = '#15803d';
                budgetHint.innerHTML = '&#10003; Fits. Left after: <strong>'
                    + (isCash ? '&#8369;' : '') + (isCash ? formatPeso(after) : after.toLocaleString())
                    + '</strong>';
            }
        }
    }
    if (isCash) amtEl.addEventListener('input', updateTotals); else qtyEl.addEventListener('input', updateTotals);
    document.querySelectorAll('.mem-chk').forEach(function(cb) { cb.addEventListener('change', updateTotals); });
    updateTotals();
    updateSelCount();

    distForm.addEventListener('submit', function(e) {
        e.preventDefault();
        // Distribute to the confirmed final list, not to whatever is ticked.
        const n = (window.__onList || []).map(Number).filter(function (v) { return v > 0; }).length;
        if (n === 0) { alert('Confirm the final list first -- there are no confirmed recipients yet.'); return; }
        if (typeof prepareDistTime === 'function') prepareDistTime();
        const d = document.getElementById('districtSelect');
        const b = document.getElementById('barangaySelect');
        const aid = document.getElementById('aidSelect');
        const area = (d.options[d.selectedIndex].textContent.trim()) + ' / ' + (b.options[b.selectedIndex].textContent.trim());
        const amtLabel = isCash ? '&#8369;' + formatPeso(Number(amtEl.value) || 0) : (Number(qtyEl.value) || 0) + ' unit(s)';
        const totalLabel = isCash ? '&#8369;' + formatPeso((Number(amtEl.value) || 0) * n) : ((Number(qtyEl.value) || 0) * n) + ' unit(s)';
        const descEl = distForm.elements['description'];
        const locEl = distForm.elements['location'];
        const schedEl = distForm.elements['schedule_date'];
        const schedTimeEl = distForm.elements['schedule_time'];
        let schedLabel = '&mdash;';
        if (schedEl && schedEl.value) {
            const d = String(schedEl.value).split('-');
            if (d.length === 3) {
                const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][Number(d[1]) - 1] || d[1];
                schedLabel = mo + ' ' + Number(d[2]) + ', ' + d[0];
            } else schedLabel = schedEl.value;
            if (schedTimeEl && schedTimeEl.value) schedLabel += ' • ' + time12(schedTimeEl.value);
        } else if (schedTimeEl && schedTimeEl.value) {
            schedLabel = time12(schedTimeEl.value);
        }
        document.getElementById('cmArea').innerHTML = area;
        document.getElementById('cmAid').innerHTML = aid.options[aid.selectedIndex].textContent.trim();
        // Show ONLY the members on the confirmed final list -- not whatever is
        // currently ticked in the picker above.
        const confirmedIds = (window.__onList || []).map(Number).filter(function (v) { return v > 0; });
        const approved = window.__approvedMembers || [];
        const nameOf = function (id) {
            const hit = approved.find(function (m) { return Number(m.id) === Number(id); });
            if (hit) return { name: hit.name, cat: hit.cats || hit.cat || 'Member' };
            const chk = document.querySelector('.mem-chk[value="' + id + '"]');
            if (chk) {
                const row = chk.closest('.final-list-item');
                const nm = row && row.querySelector('label div') ? row.querySelector('label div').textContent.trim() : 'Member';
                const cs = chk.getAttribute('data-cats') || chk.getAttribute('data-cat') || 'Member';
                return { name: nm, cat: cs.split('|').join(', ') };
            }
            return { name: 'Member #' + id, cat: 'Member' };
        };
        const recipNames = [];
        const catCounts = {};
        confirmedIds.forEach(function (id) {
            const rec = nameOf(id);
            recipNames.push('<div style="font-size:14px;padding:3px 0;">' + escHtml(rec.name) +
                ' <span class="small text-muted">&mdash; ' + escHtml(rec.cat) + '</span></div>');
            catCounts[rec.cat] = (catCounts[rec.cat] || 0) + 1;
        });
        const catSummary = Object.keys(catCounts).map(function (k) {
            return escHtml(k) + ': <strong>' + catCounts[k] + '</strong>';
        }).join(' &bull; ');
        const cmRecip = document.getElementById('cmRecipients');
        const cmSummary = document.getElementById('cmRecipSummary');
        if (recipNames.length) {
            if (cmSummary) cmSummary.textContent = recipNames.length + ' confirmed member(s)';
            cmRecip.innerHTML =
                '<div style="max-height:260px;overflow:auto;padding-right:6px;">' + recipNames.join('') + '</div>' +
                '<div class="small text-muted" style="margin-top:10px;border-top:1px dashed #dde4ec;padding-top:10px;">' + catSummary + '</div>';
        } else {
            if (cmSummary) cmSummary.textContent = 'No confirmed members';
            cmRecip.innerHTML = '<span class="text-muted">No confirmed members on the final list yet.</span>';
        }
        // Always start collapsed so the whole confirmation fits on screen.
        setRecipListOpen(false);
        document.getElementById('cmAmt').innerHTML = amtLabel;
        document.getElementById('cmTotal').innerHTML = totalLabel;
        document.getElementById('cmSched').innerHTML = schedLabel;
        document.getElementById('cmDesc').innerHTML = [locEl.value, descEl.value].filter(v => v && v.trim()).join(' &bull; ') || '&mdash;';
        showConfirmCard();
    });

    document.getElementById('cmNo').addEventListener('click', hideConfirmCard);
    document.getElementById('cmYes').addEventListener('click', function() {
        // Send exactly the confirmed final list that the modal just displayed.
        const holder = document.getElementById('distMemberIds');
        holder.innerHTML = '';
        (window.__onList || []).map(Number).filter(function (v) { return v > 0; }).forEach(function (id) {
            const h = document.createElement('input');
            h.type = 'hidden'; h.name = 'member_ids[]'; h.value = id;
            holder.appendChild(h);
        });
        setTimeout(function() { distForm.submit(); }, 150);
    });
}


function toggleManageList() {
    const b = document.getElementById('manageBody');
    const t = document.getElementById('manageToggle');
    if (!b) return;
    const open = b.style.display === 'none';
    b.style.display = open ? '' : 'none';
    if (t) t.textContent = open ? 'Hide members' : 'Show members';
}
function renderAddResults() {
    const q = document.getElementById('addSearch');
    const box = document.getElementById('addResults');
    if (!q || !box) return;
    const term = q.value.trim().toLowerCase();
    if (term.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
    const on = new Set((window.__onList || []).map(Number));
    const hits = (window.__approvedMembers || [])
        .filter(function (m) { return !on.has(m.id) && String(m.name).toLowerCase().indexOf(term) !== -1; })
        .slice(0, 25);
    box.style.display = '';
    if (!hits.length) {
        box.innerHTML = '<div class="small text-muted" style="padding:6px 0;">No matching approved member'
            + (on.size ? ' who is not already on the list.' : '.') + '</div>';
        return;
    }
    box.innerHTML = hits.map(function (m) {
        const mcat = m.cats || m.cat || 'Member';
        return '<div class="final-list-item">'
            + '<label style="display:flex;align-items:center;gap:10px;flex:1;cursor:default;">'
            + '<div style="font-weight:600;font-size:14px;">' + escHtml(m.name) + '</div></label>'
            + '<span class="small text-muted">' + escHtml(mcat) + '</span>'
            + '<button type="button" class="btn btn-sm addMemberBtn" data-id="' + m.id + '"'
            + ' data-name="' + escHtml(m.name) + '" data-cat="' + escHtml(mcat) + '"'
            + ' style="margin-left:8px;">Add</button></div>';
    }).join('');
}
function listMemberPost(action, beneficiaryId, beneficiaryIds) {
    const fd = new FormData();
    fd.append('list_member_action', action);
    fd.append('barangay_id', window.__listBrgy);
    fd.append('aid_type', window.__listAid);
    if (beneficiaryIds && beneficiaryIds.length) {
        beneficiaryIds.forEach(function (v) { fd.append('beneficiary_ids[]', v); });
    } else {
        fd.append('beneficiary_id', beneficiaryId || 0);
    }
    fd.append('_csrf_token', window.__csrf);
    return fetch(window.location.href, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
}
// ---- bulk selection over the current confirmed members ----
function curChecks() { return Array.prototype.slice.call(document.querySelectorAll('.curChk')); }
function updateCurSelCount() {
    const n = curChecks().filter(function (c) { return c.checked; }).length;
    const lbl = document.getElementById('curSelCount');
    if (lbl) lbl.textContent = n;
    const btn = document.getElementById('removeSelBtn');
    if (btn) btn.disabled = n === 0;
    const all = document.getElementById('curSelectAll');
    const boxes = curChecks();
    if (all && boxes.length) {
        all.checked = n === boxes.length;
        all.indeterminate = n > 0 && n < boxes.length;
    }
}
function toggleAllCur(checked) {
    curChecks().forEach(function (c) { c.checked = checked; });
    updateCurSelCount();
}
function removeSelectedMembers() {
    const picked = curChecks().filter(function (c) { return c.checked; });
    if (!picked.length) return;
    const ids = picked.map(function (c) { return Number(c.value); });
    const total = curChecks().length;
    const removeAll = ids.length >= total;
    const sub = removeAll
        ? 'Remove <strong>ALL ' + total + ' member(s)</strong> from the confirmed list? The list will be empty and you will need to tick members and confirm again before distributing.'
        : 'Remove <strong>' + ids.length + ' selected member(s)</strong> from the confirmed list?';
    showRemoveConfirm('Remove ' + ids.length + ' member(s) from the confirmed list?', sub, function () {
        const btn = document.getElementById('removeSelBtn');
        btn.disabled = true;
        listMemberPost('remove', 0, ids).then(function (d) {
            if (!d || !d.ok) { alert((d && d.message) || 'Could not remove those members.'); updateCurSelCount(); return; }
            (d.removed_ids || []).forEach(function (id) {
                const row = document.getElementById('curMember-' + id);
                if (row) row.remove();
                setMemberChecked(id, false);
            });
            window.__onList = (window.__onList || []).filter(function (v) { return ids.indexOf(Number(v)) === -1; });
            syncListCount(d.count);
            updateCurSelCount();
            const em = document.getElementById('curEmptyMsg');
            if (em && !curChecks().length) em.style.display = '';
            const empty = document.getElementById('distEmpty');
            const form = document.getElementById('distForm');
            if (empty) empty.style.display = d.count === 0 ? '' : 'none';
            if (form) form.style.display = d.count === 0 ? 'none' : '';
        }).catch(function () { alert('Network error while removing. Please try again.'); updateCurSelCount(); });
    });
}
// Keep every count on the page in step with the edited membership, including
// the step-6 distribute button, so the numbers never contradict each other.

function syncListCount(n) {
    const set = function (id, v) { const el = document.getElementById(id); if (el) el.textContent = v; };
    // Every place that shows the live list size (there is more than one).
    document.querySelectorAll('[data-live-count]').forEach(function (el) { el.textContent = n; });
    set('distHeadingNum', n);
    set('distBtnNum', n);
    const hidden = document.getElementById('distMemberCount');
    if (hidden) hidden.value = n;
    const em = document.getElementById('curEmptyMsg');
    if (em) em.style.display = n === 0 ? '' : 'none';
    const empty = document.getElementById('distEmpty');
    const form = document.getElementById('distForm');
    const listAlert = document.getElementById('currentListAlert');
    const manage = document.getElementById('manageCard');
    const info = document.getElementById('currentListInfo');
    if (info) {
        const txt = info.textContent;
        info.innerHTML = txt.replace(/\d[\d,]*\s*member\(s\)/, n + ' member(s)');
    }
    if (empty) empty.style.display = n === 0 ? '' : 'none';
    if (form) form.style.display = n === 0 ? 'none' : '';
    if (listAlert) listAlert.style.display = n === 0 ? 'none' : '';
    if (manage) manage.style.display = n === 0 ? 'none' : '';
    if (typeof updateSelCount === 'function') updateSelCount();
    if (typeof renderConfirmedTags === 'function') renderConfirmedTags();
}

function setMemberChecked(id, checked) {
    const c = document.querySelector('.mem-chk[value="' + id + '"]');
    if (c) { c.checked = checked; if (typeof applyCatFilter === 'function') applyCatFilter(); }
}
function curMemberRowHtml(id, name, cat) {
    return '<div class="final-list-item" id="curMember-' + id + '">'
        + '<label style="display:flex;align-items:center;gap:10px;flex:1;cursor:pointer;">'
        + '<input type="checkbox" class="curChk" value="' + id + '" onchange="updateCurSelCount()" style="flex-shrink:0;">'
        + '<div style="font-weight:600;font-size:14px;">' + escHtml(name) + '</div></label>'
        + '<span class="small text-muted">' + escHtml(cat) + '</span>'
        + '<button type="button" class="btn btn-sm rmMemberBtn" data-id="' + id + '" data-name="' + escHtml(name) + '"'
        + ' style="margin-left:8px;background:#fff;color:#b91c1c;border:1px solid #f0b4b4;">Remove</button></div>';
}
document.addEventListener('click', function (e) {
    const rm = e.target.closest('.rmMemberBtn');
    const add = e.target.closest('.addMemberBtn');
    if (rm) {
        const id = Number(rm.dataset.id), name = rm.dataset.name || 'this member';
        showRemoveConfirm('Remove this member from the confirmed final list?', 'Remove <strong>' + escHtml(name) + '</strong>? They will no longer receive this ayuda unless added back.', function () {
        rm.disabled = true;
        listMemberPost('remove', id).then(function (d) {
            if (!d || !d.ok) { alert((d && d.message) || 'Could not remove that member.'); rm.disabled = false; return; }
            const row = document.getElementById('curMember-' + id);
            if (row) row.remove();
            window.__onList = (window.__onList || []).filter(function (v) { return Number(v) !== id; });
            setMemberChecked(id, false);
            syncListCount(d.count);
            updateCurSelCount();
            const em = document.getElementById('curEmptyMsg');
            if (em && !curChecks().length) em.style.display = '';
            const empty = document.getElementById('distEmpty');
            const form = document.getElementById('distForm');
            if (empty) empty.style.display = d.count === 0 ? '' : 'none';
            if (form) form.style.display = d.count === 0 ? 'none' : '';
        }).catch(function () { alert('Network error while removing. Please try again.'); rm.disabled = false; });
        });
    } else if (add) {
        const id = Number(add.dataset.id);
        add.disabled = true;
        listMemberPost('add', id).then(function (d) {
            if (!d || !d.ok) { alert((d && d.message) || 'Could not add that member.'); add.disabled = false; return; }
            const box = document.getElementById('curMemberBox');
            if (box && !document.getElementById('curMember-' + id)) {
                box.insertAdjacentHTML('beforeend', curMemberRowHtml(id, d.name || add.dataset.name, add.dataset.cat || ''));
            }
            window.__onList = (window.__onList || []).concat([id]);
            setMemberChecked(id, true);
            syncListCount(d.count);
            const q = document.getElementById('addSearch');
            if (q) { q.value = ''; }
            const res = document.getElementById('addResults');
            if (res) { res.style.display = 'none'; res.innerHTML = ''; }
        }).catch(function () { alert('Network error while adding. Please try again.'); add.disabled = false; });
    }
});
// Keep the UI in step with the server-rendered list on load, without any page jump.
document.addEventListener('DOMContentLoaded', function () {
    var dc = document.getElementById('distMemberCount');
    if (typeof syncListCount === 'function' && dc) syncListCount(parseInt(dc.value, 10) || 0);
    if (typeof applyCatFilter === 'function') applyCatFilter();
    // Put the user back exactly where they were before the list was confirmed.
    var saved = null;
    try { saved = sessionStorage.getItem('ayudaDistScroll'); } catch (err) { saved = null; }
    if (saved !== null && saved !== '') {
        try { sessionStorage.removeItem('ayudaDistScroll'); } catch (err) { /* ignore */ }
        var y = parseInt(saved, 10) || 0;
        if (y > 0) window.scrollTo(0, y);
    }
});
</script>
</body>
</html>
