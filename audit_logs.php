<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff('district_admin'); // Audit trail is read-only and admin-scoped

$pageTitle = 'Audit Log';
$activePage = 'audit';
$isAdmin = true;

// ---- Optional filters: action type + date range ----
$actionFilter = $_GET['action'] ?? 'All';
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$where = [];
$params = [];
if ($actionFilter !== 'All' && $actionFilter !== '') {
    $where[] = 'a.action = ?';
    $params[] = $actionFilter;
}
if ($from !== '' && strtotime($from)) {
    $where[] = 'DATE(a.created_at) >= ?';
    $params[] = $from;
}
if ($to !== '' && strtotime($to)) {
    $where[] = 'DATE(a.created_at) <= ?';
    $params[] = $to;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Distinct action types for the filter chips
$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("
    SELECT a.*, u.full_name AS actor_name, u.username, u.role AS actor_role
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.user_id
    $whereSql
    ORDER BY a.created_at DESC LIMIT 200
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Human-readable labels for each audit action
$actionLabels = [
    'login'                     => 'Sign-in',
    'distribute'                => 'Distribution',
    'beneficiary_approve'       => 'Beneficiary Approved',
    'beneficiary_add'           => 'Beneficiary Added',
    'beneficiary_password_reset'=> 'Beneficiary Password Reset',
    'user_create'               => 'Account Created',
    'user_approve'              => 'Account Approved',
    'user_decline'              => 'Account Declined',
    'user_password_reset'       => 'Account Password Reset',
    'request_submit'            => 'Aid Request Submitted',
    'request_approved'          => 'Aid Request Approved',
    'request_declined'          => 'Aid Request Declined',
    'emergency_report_submit'   => 'Emergency Report Submitted',
    'emergency_report_approved' => 'Emergency Response Dispatched',
    'emergency_report_declined' => 'Emergency Report Dismissed',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Log - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/includes/flash.php'; ?>

        <div class="section-title" style="margin-bottom:10px;">Audit Log
        </div>

        <form method="GET" class="card" style="padding:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                <label>Action</label>
                <select name="action" class="form-control">
                    <option value="All">All actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($actionLabels[$a] ?? $a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;min-width:130px;margin-bottom:0;">
                <label>From</label>
                <input type="date" name="from" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="form-group" style="flex:1;min-width:130px;margin-bottom:0;">
                <label>To</label>
                <input type="date" name="to" class="form-control" value="<?= e($to) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="audit_logs.php" class="btn btn-outline">Reset</a>
        </form>

        <div class="card" style="margin-top:12px;">
            <?php foreach ($logs as $l): ?>
                <div class="list-item">
                    <div>
                        <div class="li-name"><?= e($actionLabels[$l['action']] ?? $l['action']) ?></div>
                        <div class="li-sub">
                            <?= e($l['actor_name'] ?: ($l['username'] ?: 'System/Public')) ?>
                            <span class="text-muted">(<?= e($l['actor_role'] ?: ($l['role'] ?: 'n/a')) ?>)</span>
                            <?php if ($l['details']): ?> &bull; <span><?= e($l['details']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="small text-muted"><?= date('M j, Y g:i A', strtotime($l['created_at'])) ?></span>
                        <?php if ($l['ip_address']): ?><div class="small text-muted mt-2"><?= e($l['ip_address']) ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$logs): ?><div class="empty-state">No audit entries match your filters.</div><?php endif; ?>
        </div>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
</body>
</html>