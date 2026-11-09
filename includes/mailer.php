<?php
/**
 * mailer.php — Ayuda Monitoring System Email Notifications
 * PHPMailer-powered · Navy/Gold theme · Gmail SMTP
 *
 * ── SETUP (one time) ─────────────────────────────────────────────
 *  1. Sign in to Gmail → myaccount.google.com/security
 *  2. Enable "2-Step Verification"
 *  3. Go to: myaccount.google.com/apppasswords
 *  4. App: "Mail" → Device: "Windows Computer" → Generate
 *  5. Copy the 16-char App Password and paste below
 * ─────────────────────────────────────────────────────────────────
 * SECURITY NOTE: never commit a real Gmail address/App Password to a
 * shared repo or hand it to anyone else. The placeholders below are
 * intentionally blank -- fill them in locally only.
 * ─────────────────────────────────────────────────────────────────
 */

if (defined('AYUDA_MAILER_LOADED')) return;
define('AYUDA_MAILER_LOADED', true);

// ── CONFIGURE ────────────────────────────────────────────────────────
// Credentials are read from environment variables (or a local, non-committed
// config file) so no real Gmail address / App Password is stored in source.
//
//   AYUDA_MAIL_USERNAME : your Gmail address
//   AYUDA_MAIL_APP_PASS : 16-char Gmail App Password
//   AYUDA_MAIL_FROM     : display name for the From header (default below)
//   AYUDA_MAIL_ENABLED  : "1" to enable, anything else disables (default "0")
//
// On XAMPP you can also place a local (NOT version-controlled) file at
//   <project>/config/mail.local.php
// that returns an array. Example:
//   <?php return ['username'=>'you@gmail.com','app_pass'=>'xxxx xxxx xxxx xxxx','from_name'=>'Ayuda','enabled'=>true];
$__mail_local = [];
if (is_file(__DIR__ . '/../config/mail.local.php')) {
    $__mail_local = (array)require __DIR__ . '/../config/mail.local.php';
}

define('MAIL_USERNAME',  getenv('AYUDA_MAIL_USERNAME') ?: ($__mail_local['username'] ?? ''));
define('MAIL_APP_PASS',  getenv('AYUDA_MAIL_APP_PASS') ?: ($__mail_local['app_pass'] ?? ''));
define('MAIL_FROM_NAME', getenv('AYUDA_MAIL_FROM') ?: ($__mail_local['from_name'] ?? 'Ayuda Monitoring System'));
define('MAIL_ENABLED',   getenv('AYUDA_MAIL_ENABLED') === '1' ? true : (bool)($__mail_local['enabled'] ?? false));
unset($__mail_local);
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ════════════════════════════════════════════════════════════════
   CORE SEND FUNCTION
   ════════════════════════════════════════════════════════════════ */
function ayudaSendMail(string $to, string $toName, string $subject, string $html): bool {
    if (!MAIL_ENABLED || MAIL_USERNAME === '' || MAIL_APP_PASS === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("[Ayuda Mail] Skipped (mailer not configured or invalid address) — to: $to");
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = str_replace(' ', '', MAIL_APP_PASS);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPDebug  = 0;
        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress(trim($to), $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','</p>','</tr>'], "\n", $html));
        $mail->send();
        error_log("[Ayuda Mail] Sent to $to | $subject");
        return true;
    } catch (Exception $e) {
        error_log("[Ayuda Mail] FAILED to $to | $subject | " . $mail->ErrorInfo);
        return false;
    }
}

/* ════════════════════════════════════════════════════════════════
   HTML TEMPLATE — Navy / Gold theme (matches the app's UI)
   ════════════════════════════════════════════════════════════════ */
function ayudaMailTemplate(string $title, string $body, string $accent = '#0B2E4A'): string {
    $year = date('Y');
    $gold = '#F4B400';
    return "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#EEF3F8;font-family:Arial,Helvetica,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#EEF3F8;padding:32px 16px;'>
  <tr><td align='center'>
    <table width='600' cellpadding='0' cellspacing='0'
           style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(11,46,74,.15);'>

      <!-- HEADER -->
      <tr>
        <td style='background:linear-gradient(135deg,{$accent},#051624);padding:30px 32px;text-align:center;'>
          <div style='width:52px;height:52px;margin:0 auto 10px;border-radius:14px;background:{$gold};color:{$accent};font-weight:800;font-size:26px;line-height:52px;'>A</div>
          <h1 style='color:#ffffff;margin:0;font-size:22px;font-weight:bold;letter-spacing:.5px;'>
            Ayuda Monitoring System
          </h1>
          <div style='color:{$gold};font-size:12px;margin-top:4px;text-transform:uppercase;letter-spacing:1.5px;'>
            Davao City &bull; Relief &amp; Aid Distribution
          </div>
        </td>
      </tr>

      <!-- TITLE STRIP -->
      <tr>
        <td style='background:#0B2E4A12;padding:16px 32px;border-bottom:2px solid #0B2E4A22;'>
          <h2 style='margin:0;font-size:18px;color:{$accent};font-weight:bold;'>{$title}</h2>
        </td>
      </tr>

      <!-- BODY -->
      <tr>
        <td style='padding:28px 32px;color:#334155;font-size:15px;line-height:1.75;'>
          {$body}
        </td>
      </tr>

      <!-- FOOTER -->
      <tr>
        <td style='background:#F4F8FB;padding:18px 32px;text-align:center;
                   border-top:1px solid #DCE6F0;color:#94A3B8;font-size:12px;line-height:1.7;'>
          &copy; {$year} Ayuda Monitoring System &mdash; Davao City<br>
          <span style='color:#CBD5E1;'>This is an automated message — please do not reply directly.</span>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>";
}

/* ── Detail row helper ── */
function _ayudaRow(string $label, string $value, bool $shade = false): string {
    $bg = $shade ? "background:#F4F8FB;" : "background:#ffffff;";
    return "<tr style='{$bg}'>
      <td style='color:#64748B;width:42%;padding:10px 14px;font-size:14px;border-bottom:1px solid #E2E8F0;'>{$label}</td>
      <td style='padding:10px 14px;font-size:14px;font-weight:600;color:#0F172A;border-bottom:1px solid #E2E8F0;'>{$value}</td>
    </tr>";
}

function _ayudaTable(string $rows): string {
    return "<table width='100%' cellpadding='0' cellspacing='0'
             style='border-collapse:collapse;margin:16px 0;border:1.5px solid #DCE6F0;border-radius:8px;overflow:hidden;'>
      {$rows}
    </table>";
}

function _ayudaBtn(string $text, string $url, string $color = '#0B2E4A'): string {
    return "<p style='margin-top:24px;text-align:center;'>
      <a href='" . htmlspecialchars($url) . "'
         style='display:inline-block;background:{$color};color:#ffffff;padding:13px 32px;
                border-radius:8px;text-decoration:none;font-size:15px;font-weight:bold;
                box-shadow:0 4px 14px rgba(11,46,74,.35);'>
        {$text}
      </a>
    </p>";
}

function _ayudaAlert(string $message, string $type = 'info'): string {
    $styles = [
        'info'    => ['#EFF6FF','#0B2E4A','#0B2E4A','Info'],
        'success' => ['#F0FDF4','#22C55E','#15803D','Done'],
        'warning' => ['#FFFBEB','#F59E0B','#B45309','Caution'],
        'danger'  => ['#FFF1F2','#F43F5E','#BE123C','Alert'],
    ];
    [$bg,$border,$text,$label] = $styles[$type] ?? $styles['info'];
    return "<div style='background:{$bg};border-left:4px solid {$border};padding:14px 18px;
                border-radius:0 8px 8px 0;margin:16px 0;font-size:14px;color:{$text};'>
      <strong style='text-transform:uppercase;letter-spacing:.5px;'>{$label}:</strong> {$message}
    </div>";
}

/* ════════════════════════════════════════════════════════════════
   NOTIFICATION FUNCTIONS
   ════════════════════════════════════════════════════════════════ */

/**
 * 1. Welcome — after registration (Beneficiary, or Barangay Staff/Admin)
 */
function mailWelcome(string $to, string $name, string $role): bool {
    $n = htmlspecialchars($name);
    $labels = [
        'district_admin' => 'District Admin',
        'barangay_staff' => 'Barangay Staff',
        'beneficiary'    => 'Beneficiary',
    ];
    $r = $labels[$role] ?? ucfirst($role);
    $descs = [
        'district_admin' => 'You have full oversight access to manage barangays, review aid requests, monitor inventory, and generate citywide reports.',
        'barangay_staff'  => 'You can manage your barangay\'s records, post distribution notices, and report emergencies (fire, landslide, flood, etc.) so the District responds immediately.',
        'beneficiary'     => 'You can track your registration status and view announcements about relief aid distribution in your area.',
    ];
    $desc = $descs[$role] ?? 'Your account is now active.';
    $statusNote = $role === 'beneficiary'
        ? _ayudaAlert('Your registration is <strong>pending review</strong> by your Barangay Staff. You\'ll be notified once approved.', 'warning')
        : _ayudaAlert('Your account is registered as: <strong>' . $r . '</strong>', 'info');

    $body = "<p>Hi <strong>{$n}</strong>,</p>
      <p>Welcome to the <strong>Ayuda Monitoring System</strong>! Your account has been successfully created.</p>
      {$statusNote}
      <p style='color:#475569;'>{$desc}</p>
      " . _ayudaBtn('Go to Login →', BASE_URL . 'login.php');
    return ayudaSendMail($to, $name, "Welcome to Ayuda Monitoring System, {$n}!", ayudaMailTemplate('Welcome!', $body));
}

/**
 * 2. Forgot password — sends a secure, time-limited reset link.
 *    Works for both Staff/Admin (users table) and Beneficiary accounts.
 */
function mailPasswordReset(string $to, string $name, string $resetUrl): bool {
    $n = htmlspecialchars($name);
    $body = "<p>Hi <strong>{$n}</strong>,</p>
      <p>We received a request to reset the password for your Ayuda Monitoring System account.</p>
      " . _ayudaAlert('This link will expire in <strong>30 minutes</strong> and can only be used once.', 'warning') . "
      " . _ayudaBtn('Reset My Password →', $resetUrl) . "
      <p style='color:#94A3B8;font-size:12.5px;margin-top:20px;'>If you didn't request this, you can safely ignore this email — your password will remain unchanged.</p>";
    return ayudaSendMail($to, $name, "Reset your Ayuda Monitoring System password", ayudaMailTemplate('Password Reset Requested', $body));
}

/**
 * 3. Registration approved — notify a beneficiary once Barangay Staff approves them
 */
function mailBeneficiaryApproved(string $to, string $name): bool {
    $n = htmlspecialchars($name);
    $body = "<p>Hi <strong>{$n}</strong>,</p>
      <p>Good news — your registration with the <strong>Ayuda Monitoring System</strong> has been <strong>approved</strong>!</p>
      " . _ayudaAlert('You can now view announcements and updates about relief aid in your area.', 'success') . "
      " . _ayudaBtn('View My Updates →', BASE_URL . 'beneficiary/dashboard.php');
    return ayudaSendMail($to, $name, "Your Ayuda registration has been approved", ayudaMailTemplate('Registration Approved', $body));
}
