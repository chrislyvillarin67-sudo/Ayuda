<?php
require_once __DIR__ . '/includes/functions.php';
requireStaff();

$pageTitle = 'Distribution Calendar';
$activePage = 'calendar';
$isAdmin = $_SESSION['role'] === 'district_admin';
$myBarangay = (int)($_SESSION['barangay_id'] ?? 0);

// ---- Month navigation ------------------------------------------------------
$raw = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $raw)) $raw = date('Y-m');
[$yy, $mm] = explode('-', $raw);
$yy = (int)$yy; $mm = (int)$mm;
$ym = sprintf('%04d-%02d', $yy, $mm);
$firstOfMonth = $ym . '-01';
$daysInMonth = (int)date('t', strtotime($firstOfMonth));
$firstDow = (int)date('w', strtotime($firstOfMonth));       // 0 = Sunday
$monthLabel = date('F Y', strtotime($firstOfMonth));
$prevM = date('Y-m', strtotime($firstOfMonth . ' -1 day'));
$nextM = date('Y-m', strtotime($firstOfMonth . ' +1 month'));

// ---- Events in the displayed month ------------------------------------------
// Staff only see their own barangay's schedule (plus citywide programs that
// include them); the District Admin sees every barangay.
$events = $pdo->prepare("
    SELECT p.id, p.title, p.assistance_type, p.program_date, p.program_time, p.location, p.status,
           p.barangay_id, b.name AS barangay_name
    FROM distribution_programs p
    LEFT JOIN barangays b ON b.id = p.barangay_id
    WHERE p.program_date BETWEEN ? AND ? AND p.status <> 'cancelled'
    " . ($isAdmin ? "" : "AND (p.barangay_id IS NULL OR p.barangay_id = ?)") . "
    ORDER BY p.program_date ASC, p.program_time ASC
");
$lastDay = $ym . '-' . $daysInMonth;
if ($isAdmin) {
    $events->execute([$firstOfMonth, $lastDay]);
} else {
    $events->execute([$firstOfMonth, $lastDay, $myBarangay]);
}

$byDate = [];
$eventsByDate = [];
foreach ($events as $ev) {
    $ev['mine'] = !$isAdmin && ($ev['barangay_id'] === null || (int)$ev['barangay_id'] === $myBarangay);
    $byDate[$ev['program_date']][] = $ev;
    $eventsByDate[$ev['program_date']] = true;
}

// ---- Selected day ------------------------------------------------------------
$selDay = $_GET['d'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDay)) $selDay = date('Y-m-d');
if (substr($selDay, 0, 7) !== $ym) {
    $selDay = date('Y-m-d') >= $firstOfMonth && date('Y-m-d') <= $lastDay ? date('Y-m-d') : null;
}
if ($selDay) {
    $dts = strtotime($selDay);
    if ($dts < strtotime($firstOfMonth) || $dts > strtotime($lastDay)) $selDay = null;
}
if (!$selDay && $byDate) $selDay = (string)array_key_first($byDate);

$typeLabel = ['cash' => 'Cash', 'food_pack' => 'Food Pack', 'relief_goods' => 'Relief Goods', 'hygiene' => 'Relief Goods', 'other' => 'Aid'];
$statusLabel = ['scheduled' => 'Scheduled', 'ongoing' => 'Ongoing', 'completed' => 'Completed'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
.cal-card { background:#fff; border:1px solid var(--line); border-radius:16px; overflow:hidden; box-shadow:var(--shadow); margin-bottom:14px; }
.cal-head { display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid var(--line); }
.cal-title { font-size:16px; font-weight:700; color:var(--navy); margin:0; }
.cal-sub { font-size:12px; color:var(--text-muted); margin-top:1px; }
.cal-nav { display:flex; gap:8px; }
.cal-nav a { width:32px; height:32px; border-radius:50%; background:#eef2f6; color:var(--navy);
    display:inline-flex; align-items:center; justify-content:center; text-decoration:none; }
.cal-nav a:active { background:var(--navy); color:#fff; }
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); background:var(--line); gap:1px; }
.cal-dow { background:#f7f9fb; text-align:center; font-size:11px; font-weight:700; color:#7d8a98; padding:8px 0; }
.cal-cell { background:#fff; min-height:62px; padding:4px; display:flex; flex-direction:column; gap:2px;
    text-decoration:none; color:inherit; }
.cal-cell.off { background:#fbfcfd; }
.cal-cell.off .cal-num { color:#cfd7e0; }
.cal-num { font-size:12px; color:#5f6b7a; font-weight:600; }
.cal-cell.today .cal-num { color:var(--navy); font-weight:800; }
.cal-cell.sel { background:#eef4fa; }
.cal-cell.sel .cal-num { background:var(--navy); color:#fff; width:16px; height:16px; line-height:16px; text-align:center; border-radius:50%; }
.cal-ev { font-size:9.5px; line-height:1.2; padding:2px 3px; border-radius:4px; background:#eef2f6; color:#55606b;
    overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
.cal-ev.mine { background:var(--navy); color:#fff; }
.cal-ev.ongoing { background:#e9f4ec; color:#1e7d3c; }
.cal-ev.completed { background:#e9eef3; color:#8a97a5; text-decoration:line-through; }
.cal-more { font-size:9px; color:#8a97a5; text-align:center; padding-top:1px; }
.cal-legend { display:flex; gap:14px; padding:8px 14px; font-size:11px; color:var(--text-muted); flex-wrap:wrap; }
.cal-legend i { display:inline-block; width:9px; height:9px; border-radius:2px; margin-right:4px; vertical-align:-1px; }
.cal-minehead { background:var(--navy); color:#fff; font-size:12px; font-weight:700; padding:8px 14px; }
.cal-lbl { font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:var(--navy);
    padding:10px 14px 6px; border-bottom:1px solid var(--line); }
.cal-empty { padding:26px 16px; text-align:center; color:var(--text-muted); font-size:13.5px; }
.tag-mine { font-size:10px; font-weight:700; color:#fff; background:var(--navy); border-radius:8px; padding:2px 7px; margin-left:6px; }
</style>
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
        <?php include __DIR__ . '/includes/flash.php'; ?>

        <div class="cal-card">
            <div class="cal-head">
                <div>
                    <div class="cal-title"><?= e($monthLabel) ?></div>
                    <div class="cal-sub"><?= $isAdmin ? 'Schedule of Aid Distributions' : 'Scheduled aid distributions for your barangay' ?></div>
                </div>
                <div class="cal-nav">
                    <a href="calendar.php?m=<?= $prevM ?>" aria-label="Previous month"><?= svgIcon('left', 15) ?></a>
                    <a href="calendar.php" aria-label="Jump to current month"><?= svgIcon('home', 15) ?></a>
                    <a href="calendar.php?m=<?= $nextM ?>" aria-label="Next month"><?= svgIcon('right', 15) ?></a>
                </div>
            </div>
            <div class="cal-grid">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dw): ?>
                    <div class="cal-dow"><?= $dw ?></div>
                <?php endforeach; ?>
                <?php for ($pad = 0; $pad < $firstDow; $pad++): ?>
                    <div class="cal-cell off"></div>
                <?php endfor; ?>
                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                    <?php $ds = sprintf('%s-%02d', $ym, $d); $dayEvents = $byDate[$ds] ?? []; shuffle($dayEvents); ?>
                    <a class="cal-cell <?= $ds === date('Y-m-d') ? 'today' : '' ?> <?= $ds === $selDay ? 'sel' : '' ?>"
                       href="calendar.php?m=<?= $ym ?>&d=<?= $ds ?>">
                        <span class="cal-num"><?= $d ?></span>
                        <?php foreach (array_slice($dayEvents, 0, 3) as $ev): ?>
                            <span class="cal-ev <?= $ev['status'] ?> <?= !empty($ev['mine']) ? 'mine' : '' ?>"
                                  title="<?= e($ev['title']) ?> <?= !empty($ev['program_time']) ? date('g:i A', strtotime($ev['program_time'])) : '' ?>">
                                <?= !empty($ev['mine']) ? '&#8226; ' : '' ?><?= e($ev['title']) ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (count($dayEvents) > 3): ?><span class="cal-more">+<?= count($dayEvents) - 3 ?> more</span><?php endif; ?>
                    </a>
                <?php endfor; ?>
                <?php $used = $firstDow + $daysInMonth; ?>
                <?php for ($pad = $used % 7; $pad < 7 && $pad > 0; $pad++): ?>
                    <div class="cal-cell off"></div>
                <?php endfor; ?>
            </div>
            <div class="cal-legend">
                <?php if (!$isAdmin): ?><span><i style="background:var(--navy);"></i>Your barangay</span><?php endif; ?>
                <?php if ($isAdmin): ?><span><i style="background:#eef2f6;"></i>Barangay schedule</span><?php endif; ?>
                <span><i style="background:#e9f4ec;"></i>Ongoing</span>
                <span><i style="background:#e9eef3;"></i>Completed</span>
            </div>
        </div>

        <div class="cal-card">
            <div class="cal-lbl"><?= $selDay ? date('l, F j, Y', strtotime($selDay)) : 'Select a date above' ?></div>
            <?php if (!$selDay || empty($byDate[$selDay])): ?>
                <div class="cal-empty">
                    <div class="es-icon"><?= svgIcon('calendar', 28) ?></div>
                    No distributions scheduled for this date.
                </div>
            <?php else: ?>
                <?php foreach ($byDate[$selDay] as $ev): ?>
                    <div class="list-item">
                        <div style="flex:1;min-width:0;">
                            <div class="li-name">
                                <?= e($ev['title']) ?>
                                <?php if (!empty($ev['mine'])): ?><span class="tag-mine">Includes your barangay</span><?php endif; ?>
                                <span class="badge badge-navy" style="margin-left:6px;"><?= e($statusLabel[$ev['status']] ?? $ev['status']) ?></span>
                            </div>
                            <div class="li-sub">
                                <?= e($typeLabel[$ev['assistance_type']] ?? 'Aid') ?>
                                &bull; <?= $ev['barangay_id'] ? e('Barangay ' . $ev['barangay_name']) : 'Citywide' ?>
                                <?php if (!empty($ev['location'])): ?> &bull; <?= e($ev['location']) ?><?php endif; ?>
                            </div>
                        </div>
                        <span class="small text-muted" style="white-space:nowrap;">
                            <?php if (!empty($ev['program_time'])): echo date('g:i A', strtotime($ev['program_time'])); endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
</body>
</html>