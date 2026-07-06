<?php
session_start();
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/backend/vendor/totp/TOTP.php';

date_default_timezone_set('Asia/Manila');
$connection = getDbConnection();

$error   = $_GET['error']   ?? '';
$success = $_GET['success'] ?? '';
$showMfa = isset($_GET['mfa']) && $_GET['mfa'] == '1';
$showSetup = isset($_GET['totp_setup']) && $_GET['totp_setup'] == '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── TOTP Setup: user scanned QR and submits first code to confirm ──────────
    if ($action === 'confirm_totp_setup') {
        $code   = trim($_POST['totp_code'] ?? '');
        $userId = $_SESSION['partial_user_id'] ?? 0;

        if ($userId && TOTP::verify($_SESSION['totp_setup_secret'] ?? '', $code)) {
            // Save the secret permanently
            $up = $connection->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?');
            $up->bind_param('si', $_SESSION['totp_setup_secret'], $userId);
            $up->execute();

            unset($_SESSION['totp_setup_secret']);

            // Now fully log the user in
            $q = $connection->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $q->bind_param('i', $userId);
            $q->execute();
            $user = $q->get_result()->fetch_assoc();

            unset($_SESSION['partial_user_id']);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['email']         = $user['email'];
            $_SESSION['name']          = $user['name'];
            $_SESSION['surname']       = $user['surname'];
            $_SESSION['last_activity'] = time();

            logActivity($connection, $user['id'], 'totp_setup_complete', 'Google Authenticator configured');
            logActivity($connection, $user['id'], 'login_success', 'User logged in after TOTP setup');
            redirectByRole($user['role']);
        } else {
            header("Location: login.php?totp_setup=1&error=" . urlencode("Invalid code. Please try again."));
            exit;
        }
    }

    // ── TOTP Verify: returning user enters code from Google Authenticator ──────
    if ($action === 'verify_totp') {
        $code   = trim($_POST['totp_code'] ?? '');
        $userId = $_SESSION['partial_user_id'] ?? 0;

        if (!$userId) {
            header("Location: login.php?error=" . urlencode("Session expired. Please log in again."));
            exit;
        }

        $q = $connection->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $q->bind_param('i', $userId);
        $q->execute();
        $user = $q->get_result()->fetch_assoc();

        if ($user && TOTP::verify($user['totp_secret'], $code)) {
            unset($_SESSION['partial_user_id']);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['email']         = $user['email'];
            $_SESSION['name']          = $user['name'];
            $_SESSION['surname']       = $user['surname'];
            $_SESSION['last_activity'] = time();

            $up = $connection->prepare('UPDATE users SET failed_attempts = 0 WHERE id = ?');
            $up->bind_param('i', $user['id']);
            $up->execute();

            logActivity($connection, $user['id'], 'login_success', 'User logged in via Google Authenticator TOTP');
            redirectByRole($user['role']);
        } else {
            logActivity($connection, $userId, 'totp_failed', 'Invalid TOTP code entered');
            header("Location: login.php?mfa=1&error=" . urlencode("Invalid code. Please try again."));
            exit;
        }
    }

    // ── Normal Login ────────────────────────────────────────────────────────────
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $q = $connection->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $q->bind_param('s', $email);
    $q->execute();
    $user = $q->get_result()->fetch_assoc();

if (!$user) {
    header("Location: login.php?error=" . urlencode("Invalid email or password."));
    exit;
}

// 1. Check lock FIRST before verifying password
if ((int)$user['is_locked'] === 1) {
    header("Location: login.php?error=" . urlencode("Account locked. Contact support."));
    exit;
}

// 2. Check activation
if ((int)$user['is_verified'] === 0) {
    header("Location: login.php?error=" . urlencode("Account not activated. Please check your Gmail."));
    exit;
}

// 3. Wrong password — increment counter, lock if needed
if (!password_verify($password, $user['password'])) {
    $failed = (int)$user['failed_attempts'] + 1;
    $locked = $failed >= 3 ? 1 : 0;
    $up = $connection->prepare('UPDATE users SET failed_attempts = ?, is_locked = ? WHERE id = ?');
    $up->bind_param('iii', $failed, $locked, $user['id']);
    $up->execute();

    if ($locked === 1) {
        $s = $connection->prepare("INSERT INTO locked_accounts (user_id, reason) VALUES (?, '3 failed login attempts')");
        $s->bind_param('i', $user['id']);
        $s->execute();
        logActivity($connection, $user['id'], 'account_locked', 'Account locked after 3 failed attempts');
        header("Location: login.php?error=" . urlencode("Account locked after 3 failed attempts. Contact support."));
    } else {
        $remaining = 3 - $failed;
        header("Location: login.php?error=" . urlencode("Invalid email or password. $remaining attempt(s) remaining."));
    }
    exit;
}

// 4. Password correct — proceed to TOTP
$_SESSION['partial_user_id'] = $user['id'];

if (empty($user['totp_secret'])) {
    $secret = TOTP::generateSecret();
    $_SESSION['totp_setup_secret'] = $secret;
    logActivity($connection, $user['id'], 'totp_setup_initiated', 'First login — TOTP setup started');
    header("Location: login.php?totp_setup=1");
    exit;
}

logActivity($connection, $user['id'], 'login_attempt', 'Credentials verified, awaiting TOTP');
header("Location: login.php?mfa=1");
exit;
}

function redirectByRole($role) {
    switch ($role) {
        case 'admin':   header("Location: ADMIN/admin_dashboard.php");       break;
        case 'seller':  header("Location: SELLER/seller_dashboard.php");     break;
        case 'courier': header("Location: courier/courier_dashboard.php");   break;
        default:        header("Location: CLIENT/index.php");                break;
    }
    exit;
}

// For TOTP setup screen — get QR code URL from session secret
$qrUrl = '';
$totpSecret = '';
if ($showSetup && isset($_SESSION['totp_setup_secret']) && isset($_SESSION['partial_user_id'])) {
    $q = $connection->prepare('SELECT email, name FROM users WHERE id = ? LIMIT 1');
    $q->bind_param('i', $_SESSION['partial_user_id']);
    $q->execute();
    $setupUser = $q->get_result()->fetch_assoc();
    if ($setupUser) {
        $totpSecret = $_SESSION['totp_setup_secret'];
        $qrUrl = TOTP::getQRCodeUrl($totpSecret, $setupUser['email']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IAS Portal | Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ias-teal: #0998a8; --bg: #f4f7f6; }
        body { margin:0; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-card { background: white; padding: 40px; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); width: 100%; max-width: 420px; text-align: center; border-top: 6px solid var(--ias-teal); }
        h1 { color: var(--ias-teal); font-weight: 900; margin-bottom: 5px; }
        p.subtitle { color: #888; font-size: 14px; margin-bottom: 30px; font-weight: 600; }
        .error-box { background: #fff5f5; color: #ff4757; padding: 12px; border-radius: 10px; font-size: 13px; font-weight: 700; margin-bottom: 20px; border: 1px solid #ffccd5; }
        .success-box { background: #f0fff4; color: #2f855a; padding: 12px; border-radius: 10px; font-size: 13px; font-weight: 700; margin-bottom: 20px; border: 1px solid #c6f6d5; }
        .totp-notice { background: #e7f5f7; color: var(--ias-teal); padding: 14px; border-radius: 10px; font-size: 13px; font-weight: 700; margin-bottom: 20px; }
        .form-group { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 11px; font-weight: 800; color: #666; text-transform: uppercase; margin-bottom: 8px; }
        input { width: 100%; padding: 14px; border: 2px solid #eee; border-radius: 12px; box-sizing: border-box; font-family: inherit; font-size: 15px; }
        input:focus { outline: none; border-color: var(--ias-teal); background: #f0fbfc; }
        .btn-submit { background: var(--ias-teal); color: white; border: none; width: 100%; padding: 16px; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.3s; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(9, 152, 168, 0.3); }
        .footer-link { margin-top: 25px; font-size: 14px; color: #666; }
        .footer-link a { color: var(--ias-teal); text-decoration: none; font-weight: 700; }
        /* TOTP Setup styles */
        .setup-step { background: #f9f9f9; border-radius: 14px; padding: 16px; margin-bottom: 18px; text-align: left; }
        .setup-step .step-num { background: var(--ias-teal); color: white; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; margin-right: 8px; }
        .setup-step p { margin: 8px 0 0 34px; font-size: 13px; color: #555; line-height: 1.5; }
        .qr-wrap { text-align: center; margin: 18px 0; }
        .qr-wrap img { border: 3px solid var(--ias-teal); border-radius: 12px; padding: 6px; background: white; }
        .secret-box { font-family: monospace; font-size: 15px; font-weight: 800; background: #e7f5f7; color: var(--ias-teal); padding: 10px 14px; border-radius: 10px; letter-spacing: 2px; word-break: break-all; text-align: center; margin: 10px 0; cursor: pointer; border: 2px dashed var(--ias-teal); }
        .secret-box:hover { background: #d0eef2; }
        .copy-hint { font-size: 11px; color: #999; margin-top: 4px; }
        .ga-badge { display: inline-flex; align-items: center; gap: 8px; background: #f0f0f0; padding: 8px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #333; margin-bottom: 14px; }
    </style>
</head>
<body>
<div class="login-card">
    <h1>IAS</h1>
    <p class="subtitle">SECURE ACCESS PORTAL</p>

    <?php if ($error): ?>
        <div class="error-box">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success-box">✅ <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($showSetup && $qrUrl): ?>
        <!-- ── TOTP First-Time Setup ── -->
        <div class="ga-badge">🔐 Google Authenticator Setup</div>
        <div class="setup-step">
            <span class="step-num">1</span><strong>Install Google Authenticator</strong>
            <p>Download <strong>Google Authenticator</strong> on your phone from the App Store or Google Play.</p>
        </div>
        <div class="setup-step">
            <span class="step-num">2</span><strong>Scan the QR Code</strong>
            <p>Open the app, tap <strong>+</strong>, then tap <em>Scan a QR code</em>.</p>
        </div>
        <div class="qr-wrap">
            <img src="<?php echo htmlspecialchars($qrUrl); ?>" width="200" height="200" alt="TOTP QR Code">
        </div>
        <div class="setup-step">
            <span class="step-num">3</span><strong>Or enter the key manually</strong>
            <p>In the app, tap <em>Enter a setup key</em> and type:</p>
            <div class="secret-box" onclick="copySecret(this)" title="Click to copy"><?php echo htmlspecialchars($totpSecret); ?></div>
            <p class="copy-hint">Click the key above to copy it.</p>
        </div>
        <div class="setup-step">
            <span class="step-num">4</span><strong>Enter the 6-digit code to confirm</strong>
            <p>Once added, enter the code shown in the app below to complete setup.</p>
        </div>
        <form action="login.php" method="POST">
            <input type="hidden" name="action" value="confirm_totp_setup">
            <div class="form-group">
                <label>Google Authenticator Code</label>
                <input type="text" name="totp_code" placeholder="000000" maxlength="6" required
                       style="text-align:center; font-size:26px; letter-spacing:6px;" autocomplete="one-time-code">
            </div>
            <button type="submit" class="btn-submit">Confirm & Sign In</button>
        </form>
        <p style="margin-top:15px;"><a href="login.php" style="font-size:12px;color:#999;">← Back to Login</a></p>

    <?php elseif ($showMfa): ?>
        <!-- ── TOTP Verify (returning user) ── -->
        <div class="totp-notice">
            🔐 Open <strong>Google Authenticator</strong> on your phone and enter the 6-digit code for <strong>IAS Marketplace</strong>.
        </div>
        <form action="login.php" method="POST">
            <input type="hidden" name="action" value="verify_totp">
            <div class="form-group">
                <label>Authenticator Code</label>
                <input type="text" name="totp_code" placeholder="000000" maxlength="6" required
                       style="text-align:center; font-size:26px; letter-spacing:6px;" autocomplete="one-time-code">
            </div>
            <button type="submit" class="btn-submit">Verify & Sign In</button>
            <p style="margin-top:15px;"><a href="login.php" style="font-size:12px;color:#999;">← Back to Login</a></p>
        </form>

    <?php else: ?>
        <!-- ── Normal Login Form ── -->
        <form action="login.php" method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter your email" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-submit">Sign In</button>
        </form>
    <?php endif; ?>

    <div class="footer-link">
        Need an account? <a href="register.php">Register here</a>
    </div>
</div>

<script>
function copySecret(el) {
    const text = el.innerText;
    navigator.clipboard.writeText(text).then(() => {
        el.style.background = '#c6f6d5';
        el.style.color = '#276749';
        el.innerText = '✔ Copied!';
        setTimeout(() => {
            el.style.background = '';
            el.style.color = '';
            el.innerText = text;
        }, 2000);
    });
}
</script>
<?php ias_alert_footer(); ?>
</body>
</html>
