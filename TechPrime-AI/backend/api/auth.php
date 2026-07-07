<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../vendor/totp/TOTP.php';

function json_exit(int $httpCode, array $payload): void
{
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

$connection = getDbConnection();
$action = $_POST['action'] ?? '';

// Check CSRF for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'login' && $action !== 'register') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        json_exit(403, ['success' => false, 'message' => 'Invalid CSRF token.']);
    }
}

// ── Register ────────────────────────────────────────────────────────────────
if ($action === 'register') {
    $name     = trim($_POST['name'] ?? '');
    $surname  = trim($_POST['surname'] ?? '');
    $age      = (int)($_POST['age'] ?? 0);
    $address  = trim($_POST['address'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role     = strtolower(trim($_POST['role'] ?? 'client'));
    $allowedRoles = ['client', 'seller', 'courier', 'admin'];

    if ($name === '' || $surname === '' || $age < 13 || $address === '' || $email === '' || $password === '') {
        json_exit(422, ['success' => false, 'message' => 'Please complete all required fields.']);
    }

    if (!isPasswordComplex($password, $connection)) {
        $rules = getPasswordRules($connection);
        $msg = 'Password must be at least ' . $rules['min_length'] . ' characters';
        $parts = [];
        if ($rules['require_upper'])   $parts[] = 'uppercase letter';
        if ($rules['require_lower'])   $parts[] = 'lowercase letter';
        if ($rules['require_number'])  $parts[] = 'number';
        if ($rules['require_special']) $parts[] = 'special character';
        if (!empty($parts)) $msg .= ' and include: ' . implode(', ', $parts);
        $msg .= '.';
        json_exit(422, ['success' => false, 'message' => $msg]);
    }

    if (!in_array($role, $allowedRoles, true)) {
        json_exit(422, ['success' => false, 'message' => 'Invalid account type.']);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_exit(422, ['success' => false, 'message' => 'Invalid email address.']);
    }

    $chk = $connection->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
    $chk->bind_param('s', $email);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        json_exit(409, ['success' => false, 'message' => 'Email already registered.']);
    }
    $chk->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $activationToken = bin2hex(random_bytes(32));

    $ins = $connection->prepare(
        'INSERT INTO users (name, surname, age, address, email, password, role, is_verified, is_locked, failed_attempts, activation_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
    );
    $ins->bind_param('ssisssss', $name, $surname, $age, $address, $email, $hash, $role, $activationToken);
    if (!$ins->execute()) {
        $ins->close();
        json_exit(500, ['success' => false, 'message' => 'Registration failed.']);
    }
    $userId = $ins->insert_id;
    $ins->close();

    // Send activation email via PHPMailer
    require_once __DIR__ . '/../../includes/mailer.php';
    $activationLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'] . '/activitate.php?token=' . $activationToken;
    sendActivationEmail($email, $name, $activationLink);

    logActivity($connection, $userId, 'registration', 'User registered. Activation email sent to: ' . $email);

    json_exit(200, [
        'success' => true,
        'message' => 'Registration successful! Please check your Gmail to activate your account.'
    ]);
}

// ── Login ────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        json_exit(422, ['success' => false, 'message' => 'Please enter email and password.']);
    }

    $q = $connection->prepare(
        'SELECT id, name, surname, email, password, role, is_verified, is_locked, failed_attempts, totp_secret, totp_enabled
         FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1'
    );
    $q->bind_param('s', $email);
    $q->execute();
    $user = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$user) {
        json_exit(401, ['success' => false, 'message' => 'Invalid email or password.']);
    }

    if ((int)$user['is_locked'] === 1) {
        json_exit(403, ['success' => false, 'message' => 'Your account has been locked due to too many failed login attempts. Please contact an administrator to unlock it.']);
    }

    if ((int)$user['is_verified'] !== 1) {
        json_exit(403, ['success' => false, 'message' => 'Please activate your account first. Check your Gmail.']);
    }

    if (!password_verify($password, $user['password'])) {
        $failed = ((int)$user['failed_attempts']) + 1;
        $locked = $failed >= 3 ? 1 : 0;
        $up = $connection->prepare('UPDATE users SET failed_attempts = ?, is_locked = ? WHERE id = ?');
        $up->bind_param('iii', $failed, $locked, $user['id']);
        $up->execute();
        $up->close();

        if ($locked === 1) {
            $stmt = $connection->prepare("INSERT INTO locked_accounts (user_id, reason) VALUES (?, '3 failed login attempts')");
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $stmt->close();
            logActivity($connection, $user['id'], 'account_locked', 'Account locked after 3 failed login attempts');
            json_exit(403, ['success' => false, 'message' => 'Your account has been locked after 3 failed login attempts. Please contact an administrator to unlock it.']);
        }

        $remaining = 3 - $failed;
        json_exit(401, ['success' => false, 'message' => 'Invalid email or password. ' . $remaining . ' attempt(s) remaining before your account is locked.']);
    }

    // Credentials OK — store partial session
    $_SESSION['partial_user_id'] = (int)$user['id'];

    // First login: no TOTP secret yet
    if (empty($user['totp_secret'])) {
        $secret = TOTP::generateSecret();
        $_SESSION['totp_setup_secret'] = $secret;
        logActivity($connection, $user['id'], 'login_attempt', 'Credentials OK — TOTP setup required');

        json_exit(200, [
            'success'      => true,
            'require_totp' => true,
            'totp_setup'   => true,
            'message'      => 'Please set up Google Authenticator.',
            'qr_url'       => TOTP::getQRCodeUrl($secret, $user['email']),
            'totp_secret'  => $secret
        ]);
    }

    logActivity($connection, $user['id'], 'login_attempt', 'Credentials OK — TOTP verification required');

    json_exit(200, [
        'success'      => true,
        'require_totp' => true,
        'totp_setup'   => false,
        'message'      => 'Enter the code from Google Authenticator.'
    ]);
}

// ── Verify TOTP (API route, used by JS-based login) ──────────────────────────
if ($action === 'verify_totp') {
    $code   = trim($_POST['totp_code'] ?? '');
    $userId = $_SESSION['partial_user_id'] ?? 0;

    if ($userId === 0 || $code === '') {
        json_exit(422, ['success' => false, 'message' => 'Session expired or invalid code.']);
    }

    $q = $connection->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $q->bind_param('i', $userId);
    $q->execute();
    $user = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$user) {
        json_exit(401, ['success' => false, 'message' => 'User not found.']);
    }

    if (!TOTP::verify($user['totp_secret'], $code)) {
        logActivity($connection, $userId, 'totp_failed', 'Invalid TOTP code submitted via API');
        json_exit(401, ['success' => false, 'message' => 'Invalid or expired authenticator code.']);
    }

    // Finalize login
    $up = $connection->prepare('UPDATE users SET failed_attempts = 0 WHERE id = ?');
    $up->bind_param('i', $user['id']);
    $up->execute();
    $up->close();

    unset($_SESSION['partial_user_id']);
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['name']          = $user['name'];
    $_SESSION['surname']       = $user['surname'];
    $_SESSION['last_activity'] = time();

    logActivity($connection, $user['id'], 'login_success', 'User logged in via Google Authenticator TOTP');

    json_exit(200, [
        'success' => true,
        'message' => 'Login successful.',
        'user'    => [
            'id'      => (int)$user['id'],
            'name'    => $user['name'],
            'surname' => $user['surname'],
            'email'   => $user['email'],
            'role'    => $user['role']
        ]
    ]);
}

// ── Confirm TOTP Setup (API route) ───────────────────────────────────────────
if ($action === 'confirm_totp_setup') {
    $code   = trim($_POST['totp_code'] ?? '');
    $userId = $_SESSION['partial_user_id'] ?? 0;
    $secret = $_SESSION['totp_setup_secret'] ?? '';

    if (!$userId || !$secret) {
        json_exit(422, ['success' => false, 'message' => 'Session expired. Please log in again.']);
    }

    if (!TOTP::verify($secret, $code)) {
        json_exit(401, ['success' => false, 'message' => 'Invalid code. Please try again.']);
    }

    // Save secret
    $up = $connection->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?');
    $up->bind_param('si', $secret, $userId);
    $up->execute();
    $up->close();

    unset($_SESSION['totp_setup_secret']);

    $q = $connection->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $q->bind_param('i', $userId);
    $q->execute();
    $user = $q->get_result()->fetch_assoc();
    $q->close();

    unset($_SESSION['partial_user_id']);
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['name']          = $user['name'];
    $_SESSION['surname']       = $user['surname'];
    $_SESSION['last_activity'] = time();

    logActivity($connection, $user['id'], 'totp_setup_complete', 'Google Authenticator configured via API');
    logActivity($connection, $user['id'], 'login_success', 'User logged in after TOTP setup via API');

    json_exit(200, [
        'success' => true,
        'message' => 'Google Authenticator set up successfully! Logging in...',
        'user'    => [
            'id'      => (int)$user['id'],
            'name'    => $user['name'],
            'surname' => $user['surname'],
            'email'   => $user['email'],
            'role'    => $user['role']
        ]
    ]);
}

// ── Activate account (GET) ───────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'activate') {
    $token = $_GET['token'] ?? '';
    if ($token !== '') {
        $up = $connection->prepare('UPDATE users SET is_verified = 1, activation_token = NULL WHERE activation_token = ?');
        $up->bind_param('s', $token);
        if ($up->execute() && $up->affected_rows > 0) {
            $up->close();
            header("Location: /login.php?success=" . urlencode("Account activated! You can now sign in."));
            exit;
        }
        $up->close();
    }
    header("Location: /login.php?error=" . urlencode("Invalid or expired activation link."));
    exit;
}

// ── Logout ───────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    if (isset($_SESSION['user_id'])) {
        logActivity($connection, $_SESSION['user_id'], 'logout', 'User logged out');
    }
    $_SESSION = [];
    if (session_id() !== '' && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    json_exit(200, ['success' => true, 'message' => 'Logged out.']);
}

json_exit(400, ['success' => false, 'message' => 'Invalid action.']);
