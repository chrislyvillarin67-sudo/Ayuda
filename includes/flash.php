<?php $flash = getFlash(); ?>
<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?>"><?= !empty($flash['html']) ? $flash['message'] : e($flash['message']) ?></div>
<?php endif; ?>