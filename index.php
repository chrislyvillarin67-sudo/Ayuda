<?php
require_once __DIR__ . '/includes/functions.php';
// DEVIATION (per doc feedback: "logo nga mogawas dapat after pindot sa app
// and then after mog awas and login page") -- the app should show its logo
// splash screen first, then move on to login (or straight to the dashboard
// if already logged in). Actual routing decision still happens here;
// splash.php just displays the logo briefly before continuing.
redirect('splash.php');
