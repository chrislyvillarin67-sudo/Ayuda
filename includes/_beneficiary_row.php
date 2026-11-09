<?php
?>
<div class="final-list-item" id="ben-<?= (int)$m['id'] ?>">
    <div>
        <div class="li-name">
            <a href="beneficiary_detail.php?id=<?= (int)$m['id'] ?>" style="color:inherit;text-decoration:none;border-bottom:1px dotted #9aa7b5;">
                <?= e(formatName($m['full_name'])) ?>
            </a>
            <?php if ($m['is_duplicate']): ?><span title="Possible duplicate"><?= svgIcon('alert', 16) ?></span><?php endif; ?>
            <?php if (($m['status'] ?? '') === 'pending' && empty($m['verification_step'])): ?>
                <span class="badge badge-gold" style="font-size:10px;">In Master List</span>
            <?php elseif (($m['verification_step'] ?? '') === 'barangay'): ?>
                <span class="badge badge-gold" style="font-size:10px;">For Review</span>
            <?php elseif (($m['verification_step'] ?? '') === 'cswdo'): ?>
                <span class="badge badge-navy" style="font-size:10px;">For CSWDO Validation</span>
            <?php endif; ?>
        </div>
        <div class="li-sub">
            <?php
            $rowCats = $m['categories'] ?? [(string)($m['category'] ?? '')];
            $rowCats = array_values(array_filter($rowCats, fn($c) => $c !== ''));
            ?>
            <?php foreach ($rowCats as $rc): ?><span class="badge badge-navy" style="font-size:10.5px;"><?= e($rc) ?></span> <?php endforeach; ?>
            <span style="opacity:.7;"><?= e($m['barangay_name']) ?></span>
        </div>
    </div>
    <div class="d-flex align-center gap-2">
        <?php if (($_SESSION['role'] ?? '') === 'district_admin' && ($m['status'] ?? '') === 'pending' && ($m['verification_step'] ?? '') === 'cswdo'): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="quickApprove(<?= (int)$m['id'] ?>)">Approve</button>
        <?php elseif (($_SESSION['role'] ?? '') === 'barangay_staff' && ($m['status'] ?? '') === 'pending' && ($m['verification_step'] ?? '') === 'barangay'): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="quickSubmitCSWDO(<?= (int)$m['id'] ?>)">Submit to CSWDO</button>
        <?php endif; ?>
        <a href="beneficiary_detail.php?id=<?= (int)$m['id'] ?>" class="btn btn-outline btn-sm">View</a>
    </div>
</div>
<script>
function quickApprove(id){
    if(!confirm('Approve this beneficiary?')) return;
    const fd = new FormData();
    fd.append('action','approve');
    fd.append('id', id);
    fd.append('csrf_token','<?= function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? '') ?>');
    fetch('beneficiary_action.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
        if(j.success){ alert('Approved'); location.reload(); } else { alert(j.message||'Error'); }
    });
}
function quickSubmitCSWDO(id){
    if(!confirm('Submit this beneficiary to CSWDO for validation?')) return;
    const fd = new FormData();
    fd.append('action','approve');
    fd.append('id', id);
    fd.append('csrf_token','<?= function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? '') ?>');
    fetch('beneficiary_action.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
        if(j.success){ alert('Submitted to CSWDO'); location.reload(); } else { alert(j.message||'Error'); }
    });
}
</script>