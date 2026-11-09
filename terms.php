<?php
require_once __DIR__ . '/includes/functions.php';
$backLink = $_GET['back'] ?? 'login.php';
$allowedBack = ['login.php', 'register_staff.php'];
if (!in_array($backLink, $allowedBack, true)) $backLink = 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Terms and Conditions - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
</head>
<body>
<div class="app-shell">
    <div class="app-header">
        <a href="<?= e($backLink) ?>" class="icon-btn"><?= svgIcon('left', 20) ?></a>
        <div style="text-align:center;">
            <h1>Terms &amp; Conditions</h1>
            <div class="header-sub">Ayuda Monitoring System</div>
        </div>
        <span style="width:20px;"></span>
    </div>

    <div class="content">
        <div class="alert alert-info small">
            This is a general template provided for the barangay's convenience. It should be
            reviewed and adapted by your LGU's legal office before official use.
        </div>

        <div class="card">
            <h3 style="color:var(--navy);margin-top:0;">1. Purpose of the System</h3>
            <p class="small">
                The Ayuda Distribution and Monitoring System is used by Davao City barangays and
                district offices to register residents seeking government assistance ("ayuda"),
                track eligibility, and record the distribution of aid such as food packs, cash
                assistance, and relief goods. It is a record-keeping and coordination tool and
                does not itself guarantee approval or release of any aid.
            </p>

            <h3 style="color:var(--navy);">2. Accuracy of Information</h3>
            <p class="small">
                By registering, you confirm that the personal information you provide (name,
                address, household details, and category) is true and accurate to the best of your
                knowledge. Providing false information, or registering more than once to receive
                duplicate aid, may result in your application being declined, your record being
                flagged, and may be reported to barangay or city authorities as appropriate.
            </p>

            <h3 style="color:var(--navy);">3. Your Beneficiary ID and Password</h3>
            <p class="small">
                You are responsible for keeping your Beneficiary ID and password confidential.
                Do not share your password with anyone claiming to distribute aid on your behalf.
                If you forget your password, ask your Barangay Staff for help resetting it in
                person â€” the system never asks for your password by phone, text, or email.
            </p>

            <h3 style="color:var(--navy);">4. Data Privacy</h3>
            <p class="small">
                Information you submit is collected for the purpose of verifying eligibility and
                coordinating aid distribution, and is accessible only to authorized Barangay Staff
                and District Admin accounts. It will not be sold or shared with third parties
                outside of legitimate government aid-coordination purposes, consistent with the
                Philippine Data Privacy Act of 2012 (Republic Act No. 10173).
            </p>

            <h3 style="color:var(--navy);">5. Approval and Distribution</h3>
            <p class="small">
                Registration does not guarantee approval. Barangay Staff review each registration
                and may approve, decline, or request additional verification (such as a valid ID
                or barangay clearance) before aid is released. Approved beneficiaries may still be
                subject to further verification at the time of claiming aid.
            </p>

            <h3 style="color:var(--navy);">6. Account Suspension</h3>
            <p class="small">
                Barangay Staff or District Admin may flag, suspend, or decline any record found to
                be fraudulent, duplicated, or in violation of these terms.
            </p>

            <h3 style="color:var(--navy);">7. Questions or Concerns</h3>
            <p class="small mb-0">
                For questions about your registration or an ongoing distribution, please visit or
                contact your Barangay Hall directly.
            </p>
        </div>

        <a href="<?= e($backLink) ?>" class="btn btn-primary btn-block">Back</a>
    </div>
</div>
<?php include __DIR__ . '/includes/chatbot_widget.php'; ?>
</body>
</html>
