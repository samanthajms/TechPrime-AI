<?php
session_start();
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/includes/security.php';

date_default_timezone_set('Asia/Manila');
$connection = getDbConnection();

$error   = $_GET['error']   ?? '';
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            header("Location: login.php?error=" . urlencode("Invalid email or password."));
        }
        exit;
    }

    $up = $connection->prepare('UPDATE users SET failed_attempts = 0 WHERE id = ?');
    $up->bind_param('i', $user['id']);
    $up->execute();

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['name']          = $user['name'];
    $_SESSION['surname']       = $user['surname'];
    $_SESSION['last_activity'] = time();

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

        /* Left panel: product image */
        .auth-visual {
            position: relative;
            flex: 1 1 50%;
            background: #0e1a08 url('assets/easypc.png') center / cover no-repeat;
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
        }
        .auth-visual::after {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; height: 6px;
        }
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

        /* Right panel: form */
        .auth-form {
            flex: 1 1 50%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .auth-form-inner {
            width: 100%;
            max-width: 480px;
        }
        .form-eyebrow {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.2px;
            color: var(--ep-green-dark);
            text-transform: uppercase;
            margin: 0 0 10px;
        }
        h1 {
            color: var(--ink);
            font-weight: 800;
            margin: 0 0 6px;
            font-size: 35px;
        }
        p.subtitle {
            color: var(--muted);
            font-size: 15px;
            margin: 0 0 28px;
            font-weight: 500;
        }
        .error-box, .success-box {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .error-box { background: #fff5f5; color: #ff4757; border: 1px solid #ffccd5; }
        .success-box { background: #eef8e6; color: #3d7422; border: 1px solid #d4efc4; }
        .form-group { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.3px; }
        input {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #e4e8ea;
            border-radius: 10px;
            font-family: inherit;
            font-size: 12px;
            background: #fafbfa;
        }
        input:focus { outline: none; border-color: var(--ep-green); box-shadow: 0 0 0 3px rgba(97,179,55,0.15); background: white; }
        .btn-submit {
            background: var(--ep-green);
            color: white;
            border: none;
            width: 100%;
            padding: 15px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            font-family: inherit;
            margin-top: 6px;
        }
        .btn-submit:hover { background: var(--ep-green-dark); transform: translateY(-1px); box-shadow: 0 5px 15px rgba(97, 179, 55, 0.3); }
        .footer-link { margin-top: 26px; font-size: 14px; color: #666; text-align: center; }
        .footer-link a { color: var(--ep-green-dark); text-decoration: none; font-weight: 700; }

        @media (max-width: 820px) {
            .auth-shell { flex-direction: column; }
            .auth-visual { min-height: 260px; padding: 28px; }
            .auth-form { padding: 40px 28px; }
        }
    </style>
</head>
<body>
<div class="auth-shell">
    <div class="auth-visual">
        <div class="visual-brand">
            <img src="assets/logo.png" alt="EasyPC Logo" style="width: auto; height: 45px;">
            <span>One Oasis</span>
        </div>
        <div class="visual-copy">
            <h2>Built to power what you build.</h2>
            <p>Sign in to manage orders, inventory, and service tickets across the EasyPC network.</p>
        </div>
    </div>

    <div class="auth-form">
        <div class="auth-form-inner">
            <p class="form-eyebrow">Secure Access Portal</p>
            <h1>Welcome!</h1>
            <p class="subtitle">Enter your details below</p>

            <?php if ($error): ?>
                <div class="error-box">⚠️ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-box">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

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

            <div class="footer-link">
                Need an account? <a href="register.php">Register here</a>
            </div>
        </div>
    </div>
</div>
<?php ias_alert_footer(); ?>
</body>
</html>