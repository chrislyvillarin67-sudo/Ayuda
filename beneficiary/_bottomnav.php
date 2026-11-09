<?php
$benNavActive = $benNavActive ?? '';
$benHasUnread = $benHasUnread ?? false;
?>
<nav class="bottom-nav" style="left:0;right:0;transform:none;max-width:100%;margin:0;">
    <a href="dashboard.php" class="<?= $benNavActive === 'dashboard' ? 'active' : '' ?>">
        <span class="bn-icwrap"><span class="bn-icon"><?= svgIcon('home', 22) ?></span></span>Home
    </a>
    <a href="map.php" class="<?= $benNavActive === 'announcements' ? 'active' : '' ?>">
        <span class="bn-icwrap"><span class="bn-icon"><?= svgIcon('bell', 22) ?></span>
            <?php if ($benHasUnread): ?><span class="bn-badge" style="display:inline-block;"></span><?php endif; ?>
        </span>Announcements
    </a>
    <a href="profile.php" class="<?= $benNavActive === 'profile' ? 'active' : '' ?>">
        <span class="bn-icwrap"><span class="bn-icon"><?= svgIcon('user', 22) ?></span></span>Profile
    </a>
    <a href="../logout.php">
        <span class="bn-icwrap"><span class="bn-icon"><?= svgIcon('logout', 22) ?></span></span>Logout
    </a>
</nav>
<script src="../assets/js/input-guards.js"></script>
<?php include __DIR__ . '/../includes/chatbot_widget.php'; ?>
