<?php
require_once __DIR__ . '/includes/functions.php';

// Decide where the splash screen should send the user next.
// Beneficiaries no longer log in -- they use the public viewer (reachable
// from the login screen's "View Ayuda Updates" button). It's a perks of the
// current design that there are no beneficiary sessions anymore.
if (isStaffLoggedIn()) {
    $next = 'dashboard.php';
} else {
    $next = 'login.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<style>
.splash-wrap {
    min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
    background: linear-gradient(160deg, var(--navy) 0%, var(--navy-dark) 55%, #051624 100%);
    text-align: center; padding: 24px;
}
.splash-logo {
    width: 96px; height: 96px; border-radius: 26px;
    background: linear-gradient(145deg, #2a6fa8, var(--navy) 60%, var(--navy-dark));
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 12px 30px rgba(0,0,0,0.35), inset 0 1px 1px rgba(255,255,255,0.35);
    margin-bottom: 20px;
    
}
.splash-logo svg { width: 52px; height: 52px; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.35)); }
.splash-title { color: #fff; font-size: 20px; font-weight: 800; margin: 0; letter-spacing: 0.3px;  }
.splash-sub { color: #b9c9da; font-size: 13px; margin-top: 6px;  }
.splash-loader { margin-top: 26px; width: 120px; height: 4px; border-radius: 4px; background: rgba(255,255,255,0.15); overflow: hidden;  }
.splash-loader::after { content: ''; display: block; height: 100%; width: 40%; border-radius: 4px; background: var(--gold);  }
 100% { transform: scale(1); opacity: 1; } }
 100% { opacity: 1; transform: translateY(0); } }
 100% { transform: translateX(340%); } }
</style>
</head>
<body>
<div class="splash-wrap">
    <div class="splash-logo">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2L3 7v6c0 5 4 8.5 9 9 5-0.5 9-4 9-9V7l-9-5z" fill="var(--gold)"/>
            <path d="M8.5 12.2l2.4 2.4 4.6-5" stroke="var(--navy-dark)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <h1 class="splash-title">Ayuda Monitoring System</h1>
    <p class="splash-sub">Davao City &bull; Relief &amp; Aid Distribution</p>
    <div class="splash-loader"></div>
</div>
<script>
setTimeout(function () {
    window.location.href = <?= json_encode($next) ?>;
}, 1100);
</script>
</body>
</html>
