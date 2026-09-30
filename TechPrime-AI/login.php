<?php
session_start();
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/includes/security.php';

date_default_timezone_set('Asia/Manila');
$connection = getDbConnection();

$error   = $_GET['error']   ?? '';
$success = $_GET['success'] ?? '';

// One-shot details left by checkSessionTimeout(); shown on its ?expired=1 redirect.
$sessionExpired = $_SESSION['session_expired'] ?? [];
unset($_SESSION['session_expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $q = $connection->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $q->execute([$email]);
    $user = $q->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: login.php?error=" . urlencode("Invalid email or password."));
        exit;
    }

    if ((int)$user['is_locked'] === 1) {
        header("Location: login.php?error=" . urlencode("Account locked. Contact support."));
        exit;
    }

    if ((int)$user['is_verified'] === 0) {
        header("Location: login.php?error=" . urlencode("Account not activated. Please check your Gmail."));
        exit;
    }

    if (!password_verify($password, $user['password'])) {
        $failed = (int)$user['failed_attempts'] + 1;
        $locked = $failed >= 3 ? 1 : 0;
        $up = $connection->prepare('UPDATE users SET failed_attempts = ?, is_locked = ? WHERE id = ?');
        $up->execute([$failed, $locked, $user['id']]);

        if ($locked === 1) {
            $s = $connection->prepare("INSERT INTO locked_accounts (user_id, reason) VALUES (?, '3 failed login attempts')");
            $s->execute([$user['id']]);
            logActivity($connection, $user['id'], 'account_locked', 'Account locked after 3 failed attempts');
            header("Location: login.php?error=" . urlencode("Account locked after 3 failed attempts. Contact support."));
        } else {
            $remaining = 3 - $failed;
            header("Location: login.php?error=" . urlencode("Invalid email or password."));
        }
        exit;
    }

    $up = $connection->prepare('UPDATE users SET failed_attempts = 0 WHERE id = ?');
    $up->execute([$user['id']]);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['name']          = $user['name'];
    $_SESSION['surname']       = $user['surname'];
    $_SESSION['login_at']      = time();
    $_SESSION['last_activity'] = time();

    // Persist cart across logout/login for clients (DB-backed cart → session).
    $role = (string)($user['role'] ?? '');
    if ($role === 'client' || $role === '' || $role === 'customer') {
        require_once __DIR__ . '/includes/client_helpers.php';
        ep_sync_cart_on_login($connection, (int)$user['id']);
    }

    logActivity($connection, $user['id'], 'login_success', 'User logged in');
    redirectByRole($user['role']);
}

function redirectByRole($role) {
    switch ($role) {
        case 'admin':
            header("Location: ADMIN/admin_dashboard.php");
            break;
        case 'seller':
            header("Location: SELLER/seller_dashboard.php");
            break;
        case 'courier':
            header("Location: courier/courier_dashboard.php");
            break;
        case 'retail_officer':
            header("Location: RETAIL/retail_dashboard.php");
            break;
        case 'inventory_custodian':
            header("Location: INVENTORY/inventory_dashboard.php");
            break;
        case 'cashier':
            header("Location: CASHIER/cashier_dashboard.php");
            break;
        case 'technician':
            header("Location: login.php?error=" . urlencode("The Technician role has been removed. Contact an administrator."));
            break;
        default:
            header("Location: CLIENT/index.php");
            break;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EasyPC Portal | Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --ep-green: #61b337;
            --ep-green-dark: #4b8b2a;
            --ep-yellow: #f3c400;
            --bg: #f3f4f5;
            --ink: #1c2b16;
            --muted: #7c8577;
            --line: #e4e8ea;
            --danger: #e5484d;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: white;
        }

        .auth-shell {
            width: 100%;
            min-height: 100vh;
            background: white;
            display: flex;
        }

        /* Left panel: hero photo + animated effects (assets/js/login-hero.js) */
        .auth-visual {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            flex: 1 1 50%;
            background: #0a0f08 url('assets/EasyPC.jpg') center 45% / cover no-repeat;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 56px;
            color: white;
        }
        .auth-visual::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 1;
            /* darken top (logo) and bottom (tagline) so the text stays readable */
            background:
                linear-gradient(to bottom, rgba(0,0,0,0.55) 0%, rgba(0,0,0,0) 22%),
                linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.35) 30%, rgba(0,0,0,0) 55%);
        }
        .auth-visual::after {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; height: 6px;
        }
        #heroCanvas {
            position: absolute;
            inset: 0;
            z-index: 0;
            display: block;
            opacity: 0;
            transition: opacity .6s ease;
        }
        .visual-brand, .visual-copy { z-index: 2; }
        .visual-brand {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: auto;
        }
        .visual-brand .mark {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--ep-green);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 16px; color: #0e1a08;
        }
        .visual-brand span {
            font-weight: 800; font-size: 18px; letter-spacing: 0.3px;
        }
        .visual-copy {
            position: relative;
            max-width: 340px;
        }
        .visual-copy h2 {
            font-size: 26px;
            font-weight: 700;
            line-height: 1.3;
            margin: 0 0 10px;
        }
        .visual-copy p {
            font-size: 14px;
            color: #d8ecc9;
            line-height: 1.6;
            margin: 0;
        }

        /* Right panel: form (same look as register.php) */
        .auth-form { flex: 1 1 50%; display: flex; justify-content: center; align-items: center; padding: 48px 40px; }
        .auth-form-inner { width: 100%; max-width: 480px; }

        .reveal { opacity: 0; transform: translateY(12px); animation: fadeUp .55s ease forwards; animation-delay: calc(var(--d, 0) * 0.07s); }

        .form-eyebrow { font-size: 12px; font-weight: 700; letter-spacing: 1.2px; color: var(--ep-green-dark); text-transform: uppercase; margin: 0 0 8px; }
        h1 { font-size: 34px; font-weight: 800; margin: 0 0 4px; color: var(--ink); }
        p.subtitle { color: var(--muted); font-size: 14px; font-weight: 500; margin: 0 0 26px; }

        .error-box, .success-box {
            display: flex; gap: 10px; align-items: flex-start;
            padding: 12px 14px; border-radius: 12px; font-size: 13px; font-weight: 600; margin-bottom: 20px;
        }
        .error-box { background: #fff5f5; color: var(--danger); border: 1px solid #ffd3d6; animation: shake .45s ease; }
        .success-box { background: #eef8e6; color: var(--ep-green-dark); border: 1px solid #d4efc4; }

        .field { margin-bottom: 16px; }
        label { display: block; font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 7px; transition: color .2s; }
        .field:focus-within label { color: var(--ep-green-dark); }

        .input-wrap { position: relative; }
        .input-wrap > i.lead {
            position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
            color: #a9b1a5; font-size: 14px; pointer-events: none; transition: color .2s, transform .2s;
        }
        .input-wrap input {
            width: 100%;
            padding: 13px 44px 13px 42px;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            background: #fafbfa;
            font-family: inherit; font-size: 13px; color: var(--ink);
            transition: border-color .2s, box-shadow .2s, background-color .2s;
        }
        .input-wrap input::placeholder { color: #b3b9b0; }
        .input-wrap input:hover { border-color: #d3dbd0; }
        .input-wrap input:focus { outline: none; border-color: var(--ep-green); background-color: #fff; box-shadow: 0 0 0 4px rgba(97,179,55,0.14); }
        .input-wrap:focus-within > i.lead { color: var(--ep-green); transform: translateY(-50%) scale(1.1); }

        .pw-toggle {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            width: 32px; height: 32px; border: 0; border-radius: 8px;
            background: transparent; color: #9aa296; cursor: pointer; transition: color .2s, background .2s;
        }
        .pw-toggle:hover { color: var(--ep-green-dark); background: #eef6e8; }

        .btn-submit {
            position: relative; overflow: hidden;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            width: 100%; margin-top: 22px; padding: 15px;
            background: var(--ep-green); color: #fff;
            border: 0; border-radius: 12px;
            font-family: inherit; font-size: 14px; font-weight: 700;
            cursor: pointer; transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-submit::after {
            content: ""; position: absolute; top: 0; left: -60%; width: 40%; height: 100%;
            background: linear-gradient(100deg, transparent, rgba(255,255,255,0.35), transparent);
            transform: skewX(-20deg);
            animation: sweep 3.2s ease-in-out infinite;
        }
        .btn-submit:hover { background: var(--ep-green-dark); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(97,179,55,0.3); }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit .spinner { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
        .btn-submit.loading { pointer-events: none; opacity: .85; }
        .btn-submit.loading .spinner { display: inline-block; }
        .btn-submit.loading .arrow { display: none; }
        .btn-submit .arrow { transition: transform .2s; }
        .btn-submit:hover .arrow { transform: translateX(4px); }

        .footer-link { margin-top: 22px; font-size: 14px; color: #666; text-align: center; }
        .footer-link a { color: var(--ep-green-dark); text-decoration: none; font-weight: 700; }
        .footer-link a:hover { text-decoration: underline; }

        @keyframes fadeUp { to { opacity: 1; transform: none; } }
        @keyframes sweep { 0%, 55% { left: -60%; } 100% { left: 130%; } }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes shake { 0%, 100% { transform: none; } 20% { transform: translateX(-6px); } 40% { transform: translateX(6px); } 60% { transform: translateX(-4px); } 80% { transform: translateX(3px); } }

        @media (max-width: 820px) {
            .auth-shell { flex-direction: column; }
            .auth-visual { min-height: 260px; padding: 28px; }
            .auth-form { padding: 32px 20px 40px; }
        }
        @media (max-width: 520px) {
            h1 { font-size: 28px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .reveal, .error-box, .btn-submit::after, .btn-submit .spinner { animation-duration: .01ms !important; animation-iteration-count: 1 !important; }
        }
    </style>
</head>
<body>
<div class="auth-shell">
    <div class="auth-visual">
        <canvas id="heroCanvas" data-src="assets/EasyPC.jpg" data-scene="pc" aria-hidden="true"></canvas>
        <div class="visual-brand">
            <img src="assets/logo.png" alt="EasyPC Logo" style="width: auto; height: 45px;">
            <span>One Oasis</span>
        </div>
        <div class="visual-copy">
            <h2>Built to power what you build.</h2>
        </div>
    </div>

    <div class="auth-form">
        <div class="auth-form-inner">
            <p class="form-eyebrow reveal">Secure Access Portal</p>
            <h1 class="reveal" style="--d:1">Welcome!</h1>
            <p class="subtitle reveal" style="--d:2">Enter your details below</p>

            <?php if ($error): ?>
                <div class="error-box"><i class="fas fa-exclamation-triangle"></i><span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-box"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div>
            <?php endif; ?>

            <form action="login.php" method="POST" id="loginForm">
                <div class="field reveal" style="--d:3">
                    <label for="loginEmail">Email Address</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope lead"></i>
                        <input type="email" id="loginEmail" name="email" autocomplete="email" placeholder="you@gmail.com" required>
                    </div>
                </div>
                <div class="field reveal" style="--d:4">
                    <label for="loginPassword">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock lead"></i>
                        <input type="password" id="loginPassword" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                        <button type="button" class="pw-toggle" data-target="loginPassword" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn-submit reveal" style="--d:5" id="loginSubmit">
                    <span class="spinner"></span>
                    <span>Sign In</span>
                    <i class="fas fa-arrow-right arrow"></i>
                </button>
            </form>

            <div class="footer-link reveal" style="--d:6">
                Need an account? <a href="register.php">Register here</a>
            </div>
        </div>
    </div>
</div>
<?php ias_alert_footer(); ?>
<?php if (isset($_GET['expired'])) echo ias_session_expired_notice(is_array($sessionExpired) ? $sessionExpired : []); ?>
<script>
(function () {
    var form = document.getElementById('loginForm');
    if (!form) return;
    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.target);
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = '<i class="fas fa-eye' + (show ? '-slash' : '') + '"></i>';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });
    // Spinner, and block double submits (each failed attempt counts toward the 3-try lock).
    form.addEventListener('submit', function (e) {
        var btn = document.getElementById('loginSubmit');
        if (btn.classList.contains('loading')) { e.preventDefault(); return; }
        btn.classList.add('loading');
    });
})();
</script>
<script src="assets/js/login-hero.js"></script>
</body>
</html>