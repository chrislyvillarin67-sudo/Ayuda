<?php
require_once __DIR__ . '/../includes/functions.php';
requireBeneficiary();

$stmt = $pdo->prepare("SELECT b.*, br.name AS barangay_name, d.name AS district_name
                        FROM beneficiaries b
                        JOIN barangays br ON br.id = b.barangay_id
                        JOIN districts d ON d.id = b.district_id
                        WHERE b.id = ?");
$stmt->execute([$_SESSION['beneficiary_id']]);
$beneficiary = $stmt->fetch();
if (!$beneficiary) { unset($_SESSION['beneficiary_id']); redirect('../login.php'); }

$isApproved = $beneficiary['status'] === 'approved';

$announcements = [];
if ($isApproved) {
    $annStmt = $pdo->prepare("
        SELECT a.*, br.name AS barangay_name, u.full_name AS created_by_name,
               ds.status AS sched_status
        FROM announcements a
        LEFT JOIN barangays br ON br.id = a.barangay_id
        LEFT JOIN users u ON u.id = a.created_by
        LEFT JOIN distribution_schedules ds ON ds.id = a.source_id
            AND a.source_type IN ('distribution_schedule','schedule_final_list')
        WHERE a.barangay_id IS NULL OR a.barangay_id = ?
        ORDER BY a.created_at DESC, a.id DESC
    ");
    $annStmt->execute([$beneficiary['barangay_id']]);
    $announcements = $annStmt->fetchAll();
}

$categories = ['distribution' => [], 'emergency' => [], 'general' => []];
foreach ($announcements as $a) {
    $st = $a['source_type'] ?? '';
    $prio = $a['priority'] ?? '';
    if ($st === 'emergency_response' || $prio === 'high') {
        $categories['emergency'][] = $a;
    } elseif (in_array($st, ['distribution_schedule', 'schedule_final_list', 'distribution'], true) || stripos($a['title'] ?? '', 'distribution') !== false || stripos($a['title'] ?? '', 'ayuda') !== false) {
        $categories['distribution'][] = $a;
    } else {
        $categories['general'][] = $a;
    }
}
$benAnnFinished = function ($a) { return (($a['sched_status'] ?? '') === 'completed'); };
$benAnnSort = function (&$arr) use ($benAnnFinished) {
    usort($arr, function ($x, $y) use ($benAnnFinished) {
        $fx = $benAnnFinished($x) ? 1 : 0; $fy = $benAnnFinished($y) ? 1 : 0;
        if ($fx !== $fy) return $fx <=> $fy;
        return strtotime((string)($y['created_at'] ?? '0')) <=> strtotime((string)($x['created_at'] ?? '0'));
    });
};
$benAnnSort($categories['distribution']);
$benAnnSort($categories['emergency']);
$benAnnSort($categories['general']);

$hasUnseenUpdates = beneficiaryHasUnseenUpdates($pdo, $beneficiary);
markBeneficiaryUpdatesSeen($pdo, $beneficiary['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Announcements - Ayuda Monitoring System</title>
<link rel="stylesheet" href="../assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell" style="padding-bottom:80px;">
    <header class="app-header" style="justify-content:center;position:relative;top:auto;flex-wrap:wrap;height:auto;min-height:0;overflow:visible;padding:12px 10px 10px;box-sizing:border-box;width:100%;">
        <div class="app-header-title" style="position:static;transform:none;max-width:100%;width:100%;text-align:center;flex:1 1 100%;pointer-events:auto;min-width:0;">
            <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:2px;flex-wrap:wrap;min-width:0;width:100%;">
                <span style="width:30px;height:30px;border-radius:10px;background:var(--gold);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="20" height="20" aria-hidden="true">
                        <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--navy-dark)"/>
                        <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--gold)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <h1 style="font-size:clamp(15px,4.8vw,18px);margin:0;color:#fff;line-height:1.15;white-space:normal;">Ayuda Monitoring System</h1>
            </div>
            <div class="header-sub" style="white-space:normal;overflow:visible;text-overflow:clip;line-height:1.2;"><?= e($beneficiary['barangay_name']) ?>, <?= e($beneficiary['district_name']) ?></div>
        </div>
    </header>

    <main class="main-content">
        <div style="margin:14px 16px 10px;">
            <div style="font-size:18px;font-weight:800;color:var(--navy);">Announcements</div>
        </div>

        <?php if (!$isApproved): ?>
            <div class="card" style="margin:0 16px 12px;"><div class="empty-state"><div class="es-icon"><?= svgIcon('clock', 26) ?></div><div class="es-title">Not available yet</div><div class="es-sub">Announcements will appear here once your registration is approved.</div></div></div>
        <?php else: ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 16px 12px;">
                <button type="button" class="btn btn-sm btn-gold ben-cat-btn" data-cat="dist" onclick="switchBenAnn('dist',this)"><?= svgIcon('gift', 15) ?> Distribution</button>
                <button type="button" class="btn btn-sm btn-outline ben-cat-btn" data-cat="emerg" onclick="switchBenAnn('emerg',this)"><?= svgIcon('alert', 15) ?> Emergency</button>
                <button type="button" class="btn btn-sm btn-outline ben-cat-btn" data-cat="gen" onclick="switchBenAnn('gen',this)"><?= svgIcon('bell', 15) ?> General</button>
            </div>

            <div id="ben-ann-dist" class="ben-ann-pane" style="display:block;margin:0 16px 14px;">
                <div class="section-title" style="margin:0 0 8px;">Distribution</div>
                <?php if ($categories['distribution']): ?>
                    <div class="ann-stack">
                        <?php foreach ($categories['distribution'] as $a): ?>
                            <?php $isFinishedAnn = (($a['sched_status'] ?? '') === 'completed'); ?>
                            <article class="ann<?= $a['priority'] === 'high' ? ' ann-urgent' : '' ?>" style="<?= $isFinishedAnn ? 'opacity:.72;' : '' ?>">
                                <div class="ann-head">
                                    <div class="ann-ic"><?= svgIcon($a['priority'] === 'high' ? 'alert' : 'gift', 16) ?></div>
                                    <div class="ann-main">
                                        <div class="ann-title"><?= e($a['title']) ?></div>
                                        <div class="ann-tags">
                                            <?php foreach (announcementChips($a) as [$chipText, $chipClass]): ?><span class="badge <?= $chipClass ?>"><?= $chipText ?></span><?php endforeach; ?>
                                            <?php if (($a['sched_status'] ?? '') === 'completed'): ?><span class="badge badge-success">Finished</span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php $rows = announcementBodyRows($pdo, $a); if ($rows): ?><div class="ann-body"><?php $first=true; foreach($rows as $r): if($r['label']===null): ?><p class="ann-note"<?= $first?' style="margin-top:0"':'' ?>><?= nl2br($r['text']) ?></p><?php else: ?><div class="ann-row"><div class="ann-row-k"><?= e($r['label']) ?></div><div class="ann-row-v"><?= nl2br($r['text']) ?></div></div><?php endif; $first=false; endforeach; ?></div><?php endif; ?>
                                <div class="ann-foot"><?= announcementFoot($a) ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card"><div class="empty-state"><div class="es-icon"><?= svgIcon('gift', 26) ?></div><div class="es-title">No distribution announcements</div><div class="es-sub">Updates will appear here when available.</div></div></div>
                <?php endif; ?>
            </div>

            <div id="ben-ann-emerg" class="ben-ann-pane" style="display:none;margin:0 16px 14px;">
                <div class="section-title" style="margin:0 0 8px;">Emergency</div>
                <?php if ($categories['emergency']): ?>
                    <div class="ann-stack">
                        <?php foreach ($categories['emergency'] as $a): ?>
                            <?php $isFinishedAnn = (($a['sched_status'] ?? '') === 'completed'); ?>
                            <article class="ann<?= $a['priority'] === 'high' ? ' ann-urgent' : '' ?>" style="<?= $isFinishedAnn ? 'opacity:.72;' : '' ?>">
                                <div class="ann-head">
                                    <div class="ann-ic"><?= svgIcon('alert', 16) ?></div>
                                    <div class="ann-main"><div class="ann-title"><?= e($a['title']) ?></div><div class="ann-tags"><?php foreach (announcementChips($a) as [$chipText, $chipClass]): ?><span class="badge <?= $chipClass ?>"><?= $chipText ?></span><?php endforeach; ?></div></div>
                                </div>
                                <?php $rows = announcementBodyRows($pdo, $a); if ($rows): ?><div class="ann-body"><?php $first=true; foreach($rows as $r): if($r['label']===null): ?><p class="ann-note"<?= $first?' style="margin-top:0"':'' ?>><?= nl2br($r['text']) ?></p><?php else: ?><div class="ann-row"><div class="ann-row-k"><?= e($r['label']) ?></div><div class="ann-row-v"><?= nl2br($r['text']) ?></div></div><?php endif; $first=false; endforeach; ?></div><?php endif; ?>
                                <div class="ann-foot"><?= announcementFoot($a) ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card"><div class="empty-state"><div class="es-icon"><?= svgIcon('alert', 26) ?></div><div class="es-title">No emergency announcements</div><div class="es-sub">Emergency notices will appear here when posted.</div></div></div>
                <?php endif; ?>
            </div>

            <div id="ben-ann-gen" class="ben-ann-pane" style="display:none;margin:0 16px 14px;">
                <div class="section-title" style="margin:0 0 8px;">General</div>
                <?php if ($categories['general']): ?>
                    <div class="ann-stack">
                        <?php foreach ($categories['general'] as $a): ?>
                            <?php $isFinishedAnn = (($a['sched_status'] ?? '') === 'completed'); ?>
                            <article class="ann<?= $a['priority'] === 'high' ? ' ann-urgent' : '' ?>" style="<?= $isFinishedAnn ? 'opacity:.72;' : '' ?>">
                                <div class="ann-head">
                                    <div class="ann-ic"><?= svgIcon('bell', 16) ?></div>
                                    <div class="ann-main"><div class="ann-title"><?= e($a['title']) ?></div><div class="ann-tags"><?php foreach (announcementChips($a) as [$chipText, $chipClass]): ?><span class="badge <?= $chipClass ?>"><?= $chipText ?></span><?php endforeach; ?></div></div>
                                </div>
                                <?php $rows = announcementBodyRows($pdo, $a); if ($rows): ?><div class="ann-body"><?php $first=true; foreach($rows as $r): if($r['label']===null): ?><p class="ann-note"<?= $first?' style="margin-top:0"':'' ?>><?= nl2br($r['text']) ?></p><?php else: ?><div class="ann-row"><div class="ann-row-k"><?= e($r['label']) ?></div><div class="ann-row-v"><?= nl2br($r['text']) ?></div></div><?php endif; $first=false; endforeach; ?></div><?php endif; ?>
                                <div class="ann-foot"><?= announcementFoot($a) ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card"><div class="empty-state"><div class="es-icon"><?= svgIcon('bell', 26) ?></div><div class="es-title">No general announcements</div><div class="es-sub">General notices will appear here when posted.</div></div></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php
$benNavActive = 'announcements';
$benHasUnread = $hasUnseenUpdates;
include __DIR__ . '/_bottomnav.php';
?>

<script>
function switchBenAnn(k, btn){
    document.querySelectorAll('.ben-ann-pane').forEach(function(p){ p.style.display='none'; });
    document.querySelectorAll('.ben-cat-btn').forEach(function(b){ b.classList.remove('btn-gold'); b.classList.add('btn-outline'); });
    if(btn){ btn.classList.remove('btn-outline'); btn.classList.add('btn-gold'); }
    var el = document.getElementById('ben-ann-'+k);
    if(el) el.style.display='block';
}
</script>
