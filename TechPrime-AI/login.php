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
        case 'technician':
        case 'inventory_custodian':
            header("Location: ASSOCIATE/associate_dashboard.php");
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
    <title>EasyPC Portal | Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root { --ep-green: #61b337; --ep-green-dark: #4b8b2a; --ep-yellow: #f3c400; --bg: #f3f4f5; }
        body { margin:0; font-family: 'Poppins', sans-serif; background: var(--bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); width: 100%; max-width: 420px; text-align: center; border-top: 5px solid var(--ep-green); }
        .login-logo { height: 36px; width: auto; margin-bottom: 12px; }
        h1 { color: var(--ep-green); font-weight: 800; margin: 0 0 5px; font-size: 28px; }
        p.subtitle { color: #888; font-size: 13px; margin-bottom: 28px; font-weight: 600; letter-spacing: 0.4px; }
        .error-box { background: #fff5f5; color: #ff4757; padding: 12px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 20px; border: 1px solid #ffccd5; }
        .success-box { background: #eef8e6; color: #3d7422; padding: 12px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 20px; border: 1px solid #d4efc4; }
        .form-group { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; margin-bottom: 8px; }
        input { width: 100%; padding: 14px; border: 1.5px solid #e4e8ea; border-radius: 10px; box-sizing: border-box; font-family: inherit; font-size: 15px; }
        input:focus { outline: none; border-color: var(--ep-green); box-shadow: 0 0 0 3px rgba(97,179,55,0.15); }
        .btn-submit { background: var(--ep-green); color: white; border: none; width: 100%; padding: 15px; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; transition: 0.2s; font-family: inherit; }
        .btn-submit:hover { background: var(--ep-green-dark); transform: translateY(-1px); box-shadow: 0 5px 15px rgba(97, 179, 55, 0.3); }
        .footer-link { margin-top: 25px; font-size: 14px; color: #666; }
        .footer-link a { color: var(--ep-green-dark); text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
<div class="login-card">
    <h1>EasyPC</h1>
    <p class="subtitle">SECURE ACCESS PORTAL</p>

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
<?php ias_alert_footer(); ?>
</body>
</html>