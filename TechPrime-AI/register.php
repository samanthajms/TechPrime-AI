<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/address_helpers.php';

$error = $_GET['error'] ?? '';
$registered = false;
$registeredEmail = '';
// On a failed submit the form is re-rendered with what the user typed ($old);
// only the fields listed in $fieldErrors are cleared.
$old = [];
$fieldErrors = [];

// Load DB connection and current password rules for frontend display
$connection = getDbConnection();
$pwRules = getPasswordRules($connection);

/** Detect optional users.phone column (added by migration_users_phone.sql). */
function register_users_has_phone(PDO $db): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'phone'"
    );
    $stmt->execute();
    $has = (bool)$stmt->fetchColumn();
    return $has;
}

function register_email_taken(PDO $db, string $email): bool
{
    $stmt = $db->prepare('SELECT 1 FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Phones are stored as typed, so compare digits only and, for 10+ digits, just the
 * last 10 — 09171234567, +63 917 123 4567 and 917-123-4567 are the same number.
 */
function register_phone_taken(PDO $db, string $phone): bool
{
    if (!register_users_has_phone($db)) {
        return false;
    }
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return false;
    }
    if (strlen($digits) >= 10) {
        $stmt = $db->prepare("SELECT 1 FROM users WHERE RIGHT(regexp_replace(phone, '\\D', '', 'g'), 10) = ? LIMIT 1");
        $stmt->execute([substr($digits, -10)]);
    } else {
        $stmt = $db->prepare("SELECT 1 FROM users WHERE regexp_replace(phone, '\\D', '', 'g') = ? LIMIT 1");
        $stmt->execute([$digits]);
    }
    return (bool)$stmt->fetchColumn();
}

// Live "already in use" check for the email / phone fields (fetched by the form below).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $value = trim((string)($_GET['value'] ?? ''));
    if ($_GET['check'] === 'email') {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'invalid']);
            exit;
        }
        $taken = register_email_taken($connection, $value);
        echo json_encode(['ok' => true, 'available' => !$taken,
            'message' => $taken ? 'Email already in use.' : 'Email is available.']);
        exit;
    }
    if ($_GET['check'] === 'phone') {
        $len = strlen(preg_replace('/\D+/', '', $value));
        if ($len < 7 || $len > 15) {
            echo json_encode(['ok' => false, 'error' => 'invalid']);
            exit;
        }
        $taken = register_phone_taken($connection, $value);
        echo json_encode(['ok' => true, 'available' => !$taken,
            'message' => $taken ? 'Phone number already in use.' : 'Phone number is available.']);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
}

// ── Activation email + resend ────────────────────────────────────────────────
// After signing up (or a correct-password login to an unactivated account, see
// login.php) $_SESSION['activation_pending'] = ['user_id', 'email'] lets this
// browser — and only this browser — request a new activation email.
const REG_RESEND_COOLDOWN = 60;      // seconds between emails to one account
const REG_RESEND_MAX_PER_HOUR = 5;   // emails per account per hour

function register_activation_link(string $token): string
{
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/activitate.php?token=' . urlencode($token);
}

/** Seconds until another activation email may go to this user (0 = now). Sends are counted from `logs`. */
function register_resend_wait(PDO $db, int $userId): int
{
    $stmt = $db->prepare(
        "SELECT EXTRACT(EPOCH FROM (NOW() AT TIME ZONE 'UTC') - created_at)::int
         FROM logs
         WHERE user_id = ? AND action IN ('activation_email_sent', 'activation_email_failed')
           AND created_at > (NOW() AT TIME ZONE 'UTC') - INTERVAL '1 hour'
         ORDER BY created_at DESC"
    );
    $stmt->execute([$userId]);
    $ages = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($ages) >= REG_RESEND_MAX_PER_HOUR) {
        return max(1, 3600 - max($ages));
    }
    if ($ages && $ages[0] < REG_RESEND_COOLDOWN) {
        return REG_RESEND_COOLDOWN - $ages[0];
    }
    return 0;
}

/** Email the activation link and record the attempt (it feeds register_resend_wait). */
function register_send_activation(PDO $db, int $userId, string $email, string $name, string $token): bool
{
    $sent = (bool)sendActivationEmail($email, $name, register_activation_link($token));
    logActivity($db, $userId, $sent ? 'activation_email_sent' : 'activation_email_failed',
        ($sent ? 'Activation email sent to ' : 'Activation email could not be sent to ') . $email);
    return $sent;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_activation') {
    $pending = $_SESSION['activation_pending'] ?? null;
    if (!$pending) {
        header('Location: login.php?error=' . urlencode('Log in with your email and password to resend the activation email.'));
        exit;
    }
    if (!verifyCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['activation_flash'] = ['type' => 'bad', 'text' => 'Your session expired. Please try again.'];
        header('Location: register.php?activate=1');
        exit;
    }

    $stmt = $connection->prepare('SELECT id, name, email, is_verified, activation_token FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$pending['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || strtolower((string)$user['email']) !== strtolower((string)$pending['email'])) {
        unset($_SESSION['activation_pending']);
        header('Location: login.php?error=' . urlencode('Account not found. Please register again.'));
        exit;
    }
    if ((int)$user['is_verified'] === 1) {
        unset($_SESSION['activation_pending']);
        header('Location: login.php?success=' . urlencode('Your account is already activated. You can now log in.'));
        exit;
    }

    $wait = register_resend_wait($connection, (int)$user['id']);
    if ($wait > 0) {
        $_SESSION['activation_flash'] = ['type' => 'bad',
            'text' => 'Please wait ' . ($wait >= 120 ? ceil($wait / 60) . ' minutes' : $wait . ' seconds') . ' before requesting another email.'];
    } else {
        // Keep the current token so a late-arriving first email still works — unless its 24 h are up.
        $token = (string)($user['activation_token'] ?? '');
        if ($token === '' || ias_activation_token_expired($token)) {
            $token = ias_new_activation_token();
            $connection->prepare('UPDATE users SET activation_token = ? WHERE id = ? AND is_verified = 0')
                ->execute([$token, (int)$user['id']]);
        }
        $sent = register_send_activation($connection, (int)$user['id'], (string)$user['email'], (string)$user['name'], $token);
        $_SESSION['activation_flash'] = $sent
            ? ['type' => 'ok', 'text' => 'A new activation link was sent. It can take a minute to arrive.']
            : ['type' => 'bad', 'text' => "We couldn't send the email right now. Please try again in a minute."];
    }
    header('Location: register.php?activate=1');
    exit;
}

// "Check your Gmail" screen, shown after signing up and after each resend.
$activationFlash = null;
$resendWait = 0;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['activate']) && !empty($_SESSION['activation_pending'])) {
    $stmt = $connection->prepare('SELECT is_verified FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['activation_pending']['user_id']]);
    $verified = $stmt->fetchColumn();
    if ($verified === false || (int)$verified === 1) {
        unset($_SESSION['activation_pending'], $_SESSION['activation_flash']);
        header('Location: login.php' . ($verified === false ? '' : '?success=' . urlencode('Your account is already activated. You can now log in.')));
        exit;
    }
    $registered = true;
    $registeredEmail = (string)$_SESSION['activation_pending']['email'];
    $activationFlash = $_SESSION['activation_flash'] ?? null;
    unset($_SESSION['activation_flash']);
    $resendWait = register_resend_wait($connection, (int)$_SESSION['activation_pending']['user_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The re-rendered form may carry the typed password back; keep it out of caches.
    header('Cache-Control: no-store');

    // Public registration is limited to customer accounts. Never trust a
    // role submitted by the browser, as it could be modified outside the form.
    $role            = 'client';
    $name            = trim($_POST['name'] ?? '');
    $surname         = trim($_POST['surname'] ?? '');
    $age             = intval($_POST['age'] ?? 0);
    $addressInput    = ep_address_from_input($_POST);
    $phone           = trim($_POST['phone'] ?? '');
    $email           = strtolower(trim($_POST['email'] ?? ''));
    $password        = $_POST['password'] ?? '';
    $confirm         = $_POST['confirm_password'] ?? '';

    $old = [
        'name' => $name,
        'surname' => $surname,
        'age' => trim((string)($_POST['age'] ?? '')),
        'phone' => $phone,
        'email' => $email,
        'password' => $password,
        'confirm_password' => $confirm,
    ];
    foreach ($addressInput['fields'] as $k => $v) {
        $old['addr_' . $k] = $v;
    }

    if ($name === '') {
        $fieldErrors['name'] = 'First name is required.';
    }
    if ($surname === '') {
        $fieldErrors['surname'] = 'Surname is required.';
    }
    if ($age < 13) {
        $fieldErrors['age'] = 'Age must be 13 or older.';
    }

    // Keep phone reasonably sane without being overly strict by country.
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    if ($phone === '') {
        $fieldErrors['phone'] = 'Phone number is required.';
    } elseif (strlen($phone) > 30) {
        $fieldErrors['phone'] = 'Phone number is too long.';
    } elseif (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
        $fieldErrors['phone'] = 'Please enter a valid phone number.';
    } elseif (register_phone_taken($connection, $phone)) {
        $fieldErrors['phone'] = 'Phone number already in use.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Please enter a valid email address.';
    } elseif (register_email_taken($connection, $email)) {
        $fieldErrors['email'] = 'Email already in use.';
    }

    if ($addressInput['error'] !== '') {
        $fieldErrors['addr_' . ($addressInput['field'] ?: 'street')] = $addressInput['error'];
    }

    // ── Enforce admin-configured password complexity rules ──────────────────
    if (!isPasswordComplex($password, $connection)) {
        $msg = 'Password must be at least ' . $pwRules['min_length'] . ' characters';
        $parts = [];
        if ($pwRules['require_upper'])   $parts[] = 'uppercase letter';
        if ($pwRules['require_lower'])   $parts[] = 'lowercase letter';
        if ($pwRules['require_number'])  $parts[] = 'number';
        if ($pwRules['require_special']) $parts[] = 'special character';
        if (!empty($parts)) $msg .= ' and include: ' . implode(', ', $parts);
        $msg .= '.';
        $fieldErrors['password'] = $msg;
        $fieldErrors['confirm_password'] = 'Re-enter your new password.';
    } elseif ($password !== $confirm) {
        $fieldErrors['confirm_password'] = 'Passwords do not match!';
    }

    if ($fieldErrors) {
        // Clear only what needs re-entering. A rejected province/city also
        // invalidates the dropdowns that depend on it.
        if (isset($fieldErrors['addr_province'])) {
            $old['addr_city'] = $old['addr_barangay'] = '';
        } elseif (isset($fieldErrors['addr_city'])) {
            $old['addr_barangay'] = '';
        }
        foreach (array_keys($fieldErrors) as $k) {
            $old[$k] = '';
        }
        $error = count($fieldErrors) === 1
            ? reset($fieldErrors)
            : 'Please fix the ' . count($fieldErrors) . ' highlighted fields.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $activationToken = ias_new_activation_token();
        $hasPhone = register_users_has_phone($connection);

        // users.address gets the formatted one-line address; structured address_* columns
        // and phone are included when migration_users_address_phone.sql has been applied.
        $values = [
            'name' => $name,
            'surname' => $surname,
            'age' => $age,
        ] + ep_address_columns($connection, $addressInput['fields']);
        if ($hasPhone) {
            $values['phone'] = $phone;
        }
        $values += [
            'email' => $email,
            'password' => $hashedPassword,
            'role' => $role,
            'is_verified' => 0,
            'activation_token' => $activationToken,
        ];
        $stmt = $connection->prepare(
            'INSERT INTO users (' . implode(', ', array_keys($values)) . ')
             VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')
             RETURNING id'
        );
        $ok = $stmt->execute(array_values($values));
        $newUserId = $ok ? (int)$stmt->fetchColumn() : 0;

        if ($newUserId > 0) {
            $sent = register_send_activation($connection, $newUserId, $email, $name, $activationToken);

            $_SESSION['activation_pending'] = ['user_id' => $newUserId, 'email' => $email];
            $_SESSION['activation_flash'] = $sent
                ? ['type' => 'ok', 'text' => '', 'fresh' => true]
                : ['type' => 'bad', 'text' => "Your account was created, but we couldn't send the activation email. Use Resend below to try again."];
            // Redirect so a refresh doesn't re-submit the registration.
            header('Location: register.php?activate=1');
            exit;
        } else {
            $error = 'Registration failed. Try again.';
        }
    }
}

/** Previously typed value for a field (escaped), for re-rendering after a failed submit. */
function reg_old(array $old, string $key): string
{
    return h($old[$key] ?? '');
}

/** ' is-invalid' when the server rejected this field. */
function reg_invalid(array $fieldErrors, string $key): string
{
    return isset($fieldErrors[$key]) ? ' is-invalid' : '';
}

/** Inline hint under a field, holding the server's message when that field was rejected. */
function reg_hint(array $fieldErrors, string $key, string $id = ''): string
{
    $idAttr = $id !== '' ? ' id="' . h($id) . '"' : '';
    if (!isset($fieldErrors[$key])) {
        return '<div class="field-hint"' . $idAttr . ' aria-live="polite"></div>';
    }
    return '<div class="field-hint bad" data-server' . $idAttr . ' aria-live="polite"><i class="fas fa-times"></i> '
        . h($fieldErrors[$key]) . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | EasyPC</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --ep-green: #61b337;
            --ep-green-dark: #4b8b2a;
            --ink: #1c2b16;
            --muted: #7c8577;
            --line: #e4e8ea;
            --danger: #e5484d;
        }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body { margin: 0; font-family: 'Poppins', sans-serif; background: #fff; color: var(--ink); }

        .auth-shell { display: flex; min-height: 100vh; }

        /* ── Left panel: animated store photo (assets/js/login-hero.js, scene "store") ── */
        .auth-visual {
            position: sticky;
            top: 0;
            height: 100vh;
            flex: 0 0 44%;
            isolation: isolate;
            overflow: hidden;
            background: #0a0f08 url('assets/login-hero.jpg') center 45% / cover no-repeat;
            display: flex;
            flex-direction: column;
            padding: 48px 52px;
            color: #fff;
        }
        .auth-visual::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 1;
            background:
                linear-gradient(to bottom, rgba(0,0,0,0.6) 0%, rgba(0,0,0,0) 24%),
                linear-gradient(to top, rgba(0,0,0,0.88) 0%, rgba(0,0,0,0.55) 32%, rgba(0,0,0,0) 62%);
        }
        #heroCanvas { position: absolute; inset: 0; z-index: 0; display: block; opacity: 0; transition: opacity .6s ease; }
        .visual-brand, .visual-copy { position: relative; z-index: 2; }
        .visual-brand { display: flex; align-items: center; gap: 10px; margin-bottom: auto; }
        .visual-brand img { height: 42px; width: auto; }
        .visual-brand span { font-weight: 800; font-size: 17px; letter-spacing: .3px; }
        .visual-eyebrow {
            display: inline-block;
            font-size: 11px; font-weight: 700; letter-spacing: 1.4px; text-transform: uppercase;
            color: #b9f09a; margin-bottom: 10px;
        }
        .visual-copy h2 { font-size: 30px; line-height: 1.25; font-weight: 800; margin: 0 0 22px; max-width: 380px; }
        .visual-copy h2 em { font-style: normal; color: #8fdc5c; }
        .perks { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; max-width: 400px; }
        .perks li {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px;
            border-radius: 12px;
            background: rgba(10, 20, 8, 0.45);
            border: 1px solid rgba(143, 220, 92, 0.22);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            font-size: 13px; font-weight: 500; color: #e6f5dc;
            opacity: 0; transform: translateX(-14px);
            animation: slideIn .6s ease forwards;
            animation-delay: calc(0.5s + var(--d, 0) * 0.15s);
        }
        .perks li i {
            flex: 0 0 32px; height: 32px; border-radius: 9px;
            display: grid; place-items: center;
            background: rgba(97, 179, 55, 0.22); color: #9be46b; font-size: 14px;
        }

        /* ── Right panel: form ── */
        .auth-form { flex: 1 1 56%; display: flex; justify-content: center; padding: 48px 40px; }
        .auth-form-inner { width: 100%; max-width: 560px; margin: auto 0; }

        .reveal { opacity: 0; transform: translateY(12px); animation: fadeUp .55s ease forwards; animation-delay: calc(var(--d, 0) * 0.07s); }

        .form-eyebrow { font-size: 12px; font-weight: 700; letter-spacing: 1.2px; color: var(--ep-green-dark); text-transform: uppercase; margin: 0 0 8px; }
        h1 { font-size: 34px; font-weight: 800; margin: 0 0 4px; color: var(--ink); }
        p.sub { color: var(--muted); font-size: 14px; font-weight: 500; margin: 0 0 18px; }

        .progress { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
        .progress-track { flex: 1; height: 6px; border-radius: 99px; background: #eef1ec; overflow: hidden; }
        .progress-fill {
            height: 100%; width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--ep-green-dark), var(--ep-green), #9be46b);
            background-size: 200% 100%;
            animation: shimmer 2.4s linear infinite;
            transition: width .35s ease;
        }
        .progress-label { font-size: 11px; font-weight: 700; color: var(--muted); min-width: 64px; text-align: right; }

        .error-box {
            display: flex; gap: 10px; align-items: flex-start;
            background: #fff5f5; color: var(--danger); border: 1px solid #ffd3d6;
            padding: 12px 14px; border-radius: 12px; font-size: 13px; font-weight: 600; margin-bottom: 20px;
            animation: shake .45s ease;
        }

        .section-title {
            display: flex; align-items: center; gap: 10px;
            font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
            color: #9aa296; margin: 22px 0 12px;
        }
        .section-title::after { content: ""; flex: 1; height: 1px; background: #eef1ec; }
        .section-title:first-of-type { margin-top: 0; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-grid.three { grid-template-columns: 1fr 1fr 110px; }
        .form-grid.zip { grid-template-columns: 1fr 130px; }
        .field { margin-bottom: 14px; }
        .form-grid .field { margin-bottom: 0; }
        .form-grid + .field, .form-grid + .form-grid { margin-top: 14px; }

        label { display: block; font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 7px; transition: color .2s; }
        label .optional { text-transform: none; font-weight: 500; color: #a9b1a5; letter-spacing: 0; }
        .field:focus-within label { color: var(--ep-green-dark); }

        .input-wrap { position: relative; }
        .input-wrap > i.lead {
            position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
            color: #a9b1a5; font-size: 14px; pointer-events: none; transition: color .2s, transform .2s;
        }
        .input-wrap input, .input-wrap select {
            width: 100%;
            padding: 13px 40px 13px 42px;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            background: #fafbfa;
            font-family: inherit; font-size: 13px; color: var(--ink);
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .input-wrap input::placeholder { color: #b3b9b0; }
        .input-wrap input:hover, .input-wrap select:hover:not(:disabled) { border-color: #d3dbd0; }
        .input-wrap input:focus, .input-wrap select:focus { outline: none; border-color: var(--ep-green); background-color: #fff; box-shadow: 0 0 0 4px rgba(97,179,55,0.14); }
        .input-wrap:focus-within > i.lead { color: var(--ep-green); transform: translateY(-50%) scale(1.1); }
        .input-wrap .ok {
            position: absolute; right: 14px; top: 50%;
            transform: translateY(-50%) scale(0.4);
            color: var(--ep-green); font-size: 15px; opacity: 0; pointer-events: none;
            transition: opacity .2s, transform .25s cubic-bezier(.3,1.6,.6,1);
        }
        .input-wrap.is-valid .ok { opacity: 1; transform: translateY(-50%) scale(1); }
        .input-wrap.is-invalid input, .input-wrap.is-invalid select { border-color: #f2b8bb; background-color: #fffafa; }
        .input-wrap.is-invalid > i.lead { color: var(--danger); }
        /* hide number spinners on Age */
        .input-wrap input[type=number]::-webkit-inner-spin-button,
        .input-wrap input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        .input-wrap input[type=number] { -moz-appearance: textfield; appearance: textfield; }
        .input-wrap select {
            appearance: none; -webkit-appearance: none; cursor: pointer;
            padding-right: 58px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath d=%27M2 4l4 4 4-4%27 fill=%27none%27 stroke=%27%239aa296%27 stroke-width=%271.8%27 stroke-linecap=%27round%27/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 14px center;
            text-overflow: ellipsis;
        }
        .input-wrap select:disabled { cursor: not-allowed; color: #a9b1a5; background-color: #f4f6f3; }
        .input-wrap select:invalid { color: #a9b1a5; }
        .input-wrap select option { color: var(--ink); }
        .input-wrap select ~ .ok { right: 34px; }

        .pw-toggle {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            width: 32px; height: 32px; border: 0; border-radius: 8px;
            background: transparent; color: #9aa296; cursor: pointer; transition: color .2s, background .2s;
        }
        .pw-toggle:hover { color: var(--ep-green-dark); background: #eef6e8; }

        /* password strength + rules */
        .strength { display: flex; align-items: center; gap: 10px; margin: 10px 0 10px; }
        .strength-bars { flex: 1; display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; }
        .strength-bars span { height: 5px; border-radius: 99px; background: #eceeea; transition: background .3s; }
        .strength-text { font-size: 11px; font-weight: 700; min-width: 52px; text-align: right; color: #a9b1a5; transition: color .3s; }
        .strength[data-level="1"] .strength-bars span:nth-child(-n+1) { background: var(--danger); }
        .strength[data-level="2"] .strength-bars span:nth-child(-n+2) { background: #f59f00; }
        .strength[data-level="3"] .strength-bars span:nth-child(-n+3) { background: #9bc53d; }
        .strength[data-level="4"] .strength-bars span:nth-child(-n+4) { background: var(--ep-green); }
        .strength[data-level="1"] .strength-text { color: var(--danger); }
        .strength[data-level="2"] .strength-text { color: #d98900; }
        .strength[data-level="3"] .strength-text { color: #6f9a1f; }
        .strength[data-level="4"] .strength-text { color: var(--ep-green-dark); }

        #passwordComplexity { list-style: none; padding: 0; margin: 0 0 14px; display: flex; flex-wrap: wrap; gap: 6px; }
        .rule {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 10px 5px 6px; border-radius: 99px;
            background: #f4f6f3; color: #8a9386;
            font-size: 11px; font-weight: 600;
            transition: background .25s, color .25s;
        }
        .rule .tick {
            width: 16px; height: 16px; border-radius: 50%;
            display: grid; place-items: center;
            border: 1.5px solid #cfd5cc; font-size: 8px; color: transparent;
            transition: background .25s, border-color .25s, color .25s, transform .3s cubic-bezier(.3,1.6,.6,1);
        }
        .rule.met { background: #eaf6e2; color: var(--ep-green-dark); }
        .rule.met .tick { background: var(--ep-green); border-color: var(--ep-green); color: #fff; transform: scale(1.12); }

        .match-hint { font-size: 11px; font-weight: 600; margin-top: 7px; min-height: 16px; transition: color .2s; }
        .match-hint.ok { color: var(--ep-green-dark); }
        .match-hint.bad { color: var(--danger); }
        /* inline per-field message (server errors, email/phone availability) */
        .field-hint { font-size: 11px; font-weight: 600; margin-top: 6px; color: var(--muted); }
        .field-hint:empty { display: none; }
        .field-hint.ok { color: var(--ep-green-dark); }
        .field-hint.bad { color: var(--danger); }

        .btn-reg {
            position: relative; overflow: hidden;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            width: 100%; margin-top: 22px; padding: 15px;
            background: var(--ep-green); color: #fff;
            border: 0; border-radius: 12px;
            font-family: inherit; font-size: 14px; font-weight: 700;
            cursor: pointer; transition: background .2s, transform .2s, box-shadow .2s;
        }
        .btn-reg::after {
            content: ""; position: absolute; top: 0; left: -60%; width: 40%; height: 100%;
            background: linear-gradient(100deg, transparent, rgba(255,255,255,0.35), transparent);
            transform: skewX(-20deg);
            animation: sweep 3.2s ease-in-out infinite;
        }
        .btn-reg:hover { background: var(--ep-green-dark); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(97,179,55,0.3); }
        .btn-reg:active { transform: translateY(0); }
        .btn-reg .spinner { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
        .btn-reg.loading { pointer-events: none; opacity: .85; }
        .btn-reg.loading .spinner { display: inline-block; }
        .btn-reg.loading .arrow { display: none; }
        .btn-reg .arrow { transition: transform .2s; }
        .btn-reg:hover .arrow { transform: translateX(4px); }

        .footer-link { margin-top: 22px; font-size: 14px; color: #666; text-align: center; }
        .footer-link a { color: var(--ep-green-dark); text-decoration: none; font-weight: 700; }
        .footer-link a:hover { text-decoration: underline; }

        /* success state */
        .success-card { text-align: center; padding: 10px 0 4px; }
        .success-icon {
            position: relative; width: 92px; height: 92px; margin: 0 auto 20px;
            border-radius: 50%; display: grid; place-items: center;
            background: #eaf6e2; color: var(--ep-green-dark); font-size: 36px;
        }
        .success-icon::before, .success-icon::after {
            content: ""; position: absolute; inset: 0; border-radius: 50%;
            border: 2px solid var(--ep-green); opacity: 0;
            animation: ripple 2.4s ease-out infinite;
        }
        .success-icon::after { animation-delay: 1.2s; }
        .success-title { color: var(--ink); font-size: 24px; font-weight: 800; margin-bottom: 8px; }
        .success-msg { color: #555; font-size: 14px; line-height: 1.7; margin-bottom: 18px; }
        .email-highlight { background: #eef8e6; color: var(--ep-green-dark); padding: 8px 14px; border-radius: 10px; font-weight: 700; display: inline-block; margin: 8px 0; word-break: break-all; }
        .gmail-btn { display: inline-flex; align-items: center; gap: 8px; background: #EA4335; color: #fff; padding: 12px 24px; border-radius: 12px; text-decoration: none; font-weight: 700; font-size: 14px; transition: .2s; }
        .gmail-btn:hover { background: #c5221f; transform: translateY(-1px); box-shadow: 0 8px 18px rgba(234,67,53,0.25); }
        .note { color: #999; font-size: 12px; margin-top: 16px; line-height: 1.6; }
        .resend-notice {
            display: flex; gap: 10px; align-items: flex-start; text-align: left;
            padding: 12px 14px; border-radius: 12px; font-size: 13px; font-weight: 600; margin: 0 0 18px;
        }
        .resend-notice.ok { background: #eef8e6; color: var(--ep-green-dark); border: 1px solid #d4efc4; }
        .resend-notice.bad { background: #fff5f5; color: var(--danger); border: 1px solid #ffd3d6; }
        .resend-form { margin-top: 22px; padding-top: 18px; border-top: 1px solid #eef1ec; }
        .resend-label { color: #999; font-size: 12px; margin: 0 0 10px; }
        .resend-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 12px;
            border: 1.5px solid var(--ep-green); background: #fff; color: var(--ep-green-dark);
            font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
            transition: background .2s, color .2s, border-color .2s;
        }
        .resend-btn:hover:not(:disabled) { background: var(--ep-green); color: #fff; }
        .resend-btn:disabled { cursor: not-allowed; border-color: var(--line); color: #a9b1a5; background: #f4f6f3; }
        .resend-btn .spinner { display: none; width: 14px; height: 14px; border: 2px solid rgba(75,139,42,.3); border-top-color: var(--ep-green-dark); border-radius: 50%; animation: spin .7s linear infinite; }
        .resend-btn.loading { pointer-events: none; }
        .resend-btn.loading .spinner { display: inline-block; }
        .resend-btn.loading .fa-redo-alt { display: none; }

        @keyframes fadeUp { to { opacity: 1; transform: none; } }
        @keyframes slideIn { to { opacity: 1; transform: none; } }
        @keyframes shimmer { to { background-position: -200% 0; } }
        @keyframes sweep { 0%, 55% { left: -60%; } 100% { left: 130%; } }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes ripple { 0% { transform: scale(1); opacity: .6; } 100% { transform: scale(1.6); opacity: 0; } }
        @keyframes shake { 0%, 100% { transform: none; } 20% { transform: translateX(-6px); } 40% { transform: translateX(6px); } 60% { transform: translateX(-4px); } 80% { transform: translateX(3px); } }

        @media (max-width: 900px) {
            .auth-shell { flex-direction: column; }
            .auth-visual { position: relative; height: auto; min-height: 240px; flex: 0 0 auto; padding: 26px 24px; }
            .visual-copy h2 { font-size: 22px; margin-bottom: 0; }
            .perks { display: none; }
            .auth-form { padding: 32px 20px 40px; }
        }
        @media (max-width: 520px) {
            .form-grid, .form-grid.three, .form-grid.zip { grid-template-columns: 1fr; }
            h1 { font-size: 28px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
        }
    </style>
</head>
<body>
<div class="auth-shell">
    <aside class="auth-visual">
        <canvas id="heroCanvas" data-src="assets/login-hero.jpg" data-scene="store" aria-hidden="true"></canvas>
        <div class="visual-brand">
            <img src="assets/logo.png" alt="EasyPC Logo">
            <span>One Oasis</span>
        </div>
        <div class="visual-copy">
            <span class="visual-eyebrow">EasyPC Marketplace</span>
            <h2>Your next build <em>starts here.</em></h2>
            <ul class="perks">
                <li style="--d:0"><i class="fas fa-microchip"></i> Genuine PC parts from EasyPC One Oasis</li>
                <li style="--d:2"><i class="fas fa-robot"></i> Ask Primo, our AI assistant, for build advice</li>
            </ul>
        </div>
    </aside>

    <main class="auth-form">
        <div class="auth-form-inner">
        <?php if ($registered): ?>
            <!-- Registration success: email activation notice -->
            <div class="success-card reveal">
                <div class="success-icon"><i class="fas fa-envelope-open-text"></i></div>
                <div class="success-title">Check Your Gmail!</div>
                <div class="success-msg">
                    We've sent an activation link to:<br>
                    <span class="email-highlight"><?php echo htmlspecialchars($registeredEmail); ?></span><br><br>
                    Please open your Gmail and click the activation link to verify your account before logging in.
                </div>
                <?php if ($activationFlash && $activationFlash['text'] !== ''): ?>
                    <div class="resend-notice <?php echo $activationFlash['type'] === 'ok' ? 'ok' : 'bad'; ?>" role="status">
                        <i class="fas <?php echo $activationFlash['type'] === 'ok' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                        <span><?php echo h($activationFlash['text']); ?></span>
                    </div>
                <?php endif; ?>
                <a href="https://mail.google.com" target="_blank" rel="noopener" class="gmail-btn"><i class="fas fa-inbox"></i> Open Gmail</a>

                <form method="POST" action="register.php" class="resend-form" id="resendForm">
                    <input type="hidden" name="action" value="resend_activation">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generateCsrfToken()); ?>">
                    <p class="resend-label">Didn't get the email? Check your spam folder, or</p>
                    <button type="submit" class="resend-btn" id="resendBtn" data-wait="<?php echo (int)$resendWait; ?>"<?php echo $resendWait > 0 ? ' disabled' : ''; ?>>
                        <span class="spinner"></span>
                        <i class="fas fa-redo-alt"></i>
                        <span id="resendText"><?php echo $resendWait > 0 ? 'Resend link in ' . (int)$resendWait . 's' : 'Resend activation link'; ?></span>
                    </button>
                </form>
            </div>
            <div class="footer-link reveal" style="--d:2">
                Already activated? <a href="login.php">Sign In</a>
            </div>
        <?php else: ?>
            <p class="form-eyebrow reveal">Create your account</p>
            <h1 class="reveal" style="--d:1">Create Account</h1>
            <p class="sub reveal" style="--d:2">Join the EasyPC Marketplace</p>

            <div class="progress reveal" style="--d:3" aria-hidden="true">
                <div class="progress-track"><div class="progress-fill" id="formProgress"></div></div>
                <span class="progress-label" id="formProgressLabel">0% done</span>
            </div>

            <?php if ($error): ?>
                <div class="error-box"><i class="fas fa-exclamation-triangle"></i><span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>

            <form action="register.php" method="POST" id="regForm" novalidate>
                <div class="section-title reveal" style="--d:4">Personal details</div>
                <div class="form-grid three reveal" style="--d:5">
                    <div class="field">
                        <label for="f-name">First Name</label>
                        <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'name'); ?>"><i class="fas fa-user lead"></i><input type="text" id="f-name" name="name" autocomplete="given-name" placeholder="Juan" value="<?php echo reg_old($old, 'name'); ?>" required><i class="fas fa-check-circle ok"></i></div>
                        <?php echo reg_hint($fieldErrors, 'name'); ?>
                    </div>
                    <div class="field">
                        <label for="f-surname">Surname</label>
                        <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'surname'); ?>"><i class="fas fa-user lead"></i><input type="text" id="f-surname" name="surname" autocomplete="family-name" placeholder="Dela Cruz" value="<?php echo reg_old($old, 'surname'); ?>" required><i class="fas fa-check-circle ok"></i></div>
                        <?php echo reg_hint($fieldErrors, 'surname'); ?>
                    </div>
                    <div class="field">
                        <label for="f-age">Age</label>
                        <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'age'); ?>"><i class="fas fa-birthday-cake lead"></i><input type="number" id="f-age" name="age" min="13" max="120" inputmode="numeric" placeholder="18" value="<?php echo reg_old($old, 'age'); ?>" required><i class="fas fa-check-circle ok"></i></div>
                        <?php echo reg_hint($fieldErrors, 'age'); ?>
                    </div>
                </div>

                <div class="section-title reveal" style="--d:6">Contact</div>
                <div class="form-grid reveal" style="--d:7">
                    <div class="field">
                        <label for="f-phone">Phone Number</label>
                        <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'phone'); ?>"><i class="fas fa-phone-alt lead"></i><input type="tel" id="f-phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="30" pattern="[0-9+\(\)\-\s]{7,30}" placeholder="e.g. 09171234567" value="<?php echo reg_old($old, 'phone'); ?>" data-check="phone" required><i class="fas fa-check-circle ok"></i></div>
                        <?php echo reg_hint($fieldErrors, 'phone', 'phoneHint'); ?>
                    </div>
                    <div class="field">
                        <label for="f-email">Email Address</label>
                        <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'email'); ?>"><i class="fas fa-envelope lead"></i><input type="email" id="f-email" name="email" autocomplete="email" placeholder="you@gmail.com" value="<?php echo reg_old($old, 'email'); ?>" data-check="email" required><i class="fas fa-check-circle ok"></i></div>
                        <?php echo reg_hint($fieldErrors, 'email', 'emailHint'); ?>
                    </div>
                </div>

                <div class="section-title reveal" style="--d:8">Delivery address</div>
                <div class="field reveal" style="--d:8">
                    <label for="f-street">House / Lot No. &amp; Street</label>
                    <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'addr_street'); ?>"><i class="fas fa-home lead"></i><input type="text" id="f-street" name="addr_street" autocomplete="address-line1" minlength="5" maxlength="150" pattern=".*\S\s+\S.*" title="Enter your house / lot number and street name, e.g. 123 Rizal St." placeholder="e.g. 123 Rizal St." value="<?php echo reg_old($old, 'addr_street'); ?>" required><i class="fas fa-check-circle ok"></i></div>
                    <?php echo reg_hint($fieldErrors, 'addr_street'); ?>
                </div>
                <div class="field reveal" style="--d:9">
                    <label for="f-unit">Unit / Floor / Building / Subdivision <span class="optional">(optional)</span></label>
                    <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'addr_unit'); ?>"><i class="fas fa-building lead"></i><input type="text" id="f-unit" name="addr_unit" autocomplete="address-line2" maxlength="100" placeholder="e.g. Unit 4B, Oasis Tower" value="<?php echo reg_old($old, 'addr_unit'); ?>"><i class="fas fa-check-circle ok"></i></div>
                    <?php echo reg_hint($fieldErrors, 'addr_unit'); ?>
                </div>
                <div class="ph-address reveal" style="--d:10" data-psgc="assets/data/psgc" data-province="<?php echo reg_old($old, 'addr_province'); ?>" data-city="<?php echo reg_old($old, 'addr_city'); ?>" data-barangay="<?php echo reg_old($old, 'addr_barangay'); ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="f-province">Province</label>
                            <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'addr_province'); ?>"><i class="fas fa-map lead"></i><select id="f-province" name="addr_province" data-ph="province" autocomplete="address-level1" required></select><i class="fas fa-check-circle ok"></i></div>
                            <?php echo reg_hint($fieldErrors, 'addr_province'); ?>
                        </div>
                        <div class="field">
                            <label for="f-city">City / Municipality</label>
                            <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'addr_city'); ?>"><i class="fas fa-city lead"></i><select id="f-city" name="addr_city" data-ph="city" autocomplete="address-level2" required></select><i class="fas fa-check-circle ok"></i></div>
                            <?php echo reg_hint($fieldErrors, 'addr_city'); ?>
                        </div>
                    </div>
                    <div class="form-grid zip">
                        <div class="field">
                            <label for="f-barangay">Barangay</label>
                            <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'addr_barangay'); ?>"><i class="fas fa-map-marker-alt lead"></i><select id="f-barangay" name="addr_barangay" data-ph="barangay" autocomplete="address-level3" required></select><i class="fas fa-check-circle ok"></i></div>
                            <?php echo reg_hint($fieldErrors, 'addr_barangay'); ?>
                        </div>
                        <div class="field">
                            <label for="f-zip">ZIP Code</label>
                            <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'addr_zip'); ?>"><i class="fas fa-mail-bulk lead"></i><input type="text" id="f-zip" name="addr_zip" autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{4}" placeholder="1600" value="<?php echo reg_old($old, 'addr_zip'); ?>" required><i class="fas fa-check-circle ok"></i></div>
                            <?php echo reg_hint($fieldErrors, 'addr_zip'); ?>
                        </div>
                    </div>
                </div>

                <div class="section-title reveal" style="--d:9">Security</div>
                <div class="field reveal" style="--d:10">
                    <label for="regPassword">Password</label>
                    <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'password'); ?>">
                        <i class="fas fa-lock lead"></i>
                        <input type="password" name="password" id="regPassword" autocomplete="new-password" placeholder="Create a strong password" value="<?php echo reg_old($old, 'password'); ?>" oninput="checkPasswordComplexity(this.value)" required>
                        <button type="button" class="pw-toggle" data-target="regPassword" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="strength" id="pwStrength" data-level="0">
                        <div class="strength-bars"><span></span><span></span><span></span><span></span></div>
                        <span class="strength-text" id="pwStrengthText">&nbsp;</span>
                    </div>
                    <ul id="passwordComplexity">
                        <li id="pw-len" class="rule"><span class="tick"><i class="fas fa-check"></i></span><?php echo (int)$pwRules['min_length']; ?>+ characters</li>
                        <?php if ($pwRules['require_upper']): ?>
                        <li id="pw-upper" class="rule"><span class="tick"><i class="fas fa-check"></i></span>Uppercase (A–Z)</li>
                        <?php endif; ?>
                        <?php if ($pwRules['require_lower']): ?>
                        <li id="pw-lower" class="rule"><span class="tick"><i class="fas fa-check"></i></span>Lowercase (a–z)</li>
                        <?php endif; ?>
                        <?php if ($pwRules['require_number']): ?>
                        <li id="pw-num" class="rule"><span class="tick"><i class="fas fa-check"></i></span>Number (0–9)</li>
                        <?php endif; ?>
                        <?php if ($pwRules['require_special']): ?>
                        <li id="pw-special" class="rule"><span class="tick"><i class="fas fa-check"></i></span>Special (!@#$%…)</li>
                        <?php endif; ?>
                    </ul>
                    <?php if (isset($fieldErrors['password'])) echo reg_hint($fieldErrors, 'password'); ?>
                </div>

                <div class="field reveal" style="--d:11">
                    <label for="f-confirm">Confirm Password</label>
                    <div class="input-wrap<?php echo reg_invalid($fieldErrors, 'confirm_password'); ?>">
                        <i class="fas fa-shield-alt lead"></i>
                        <input type="password" id="f-confirm" name="confirm_password" autocomplete="new-password" placeholder="Re-enter your password" value="<?php echo reg_old($old, 'confirm_password'); ?>" required>
                        <button type="button" class="pw-toggle" data-target="f-confirm" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
                    <?php if (isset($fieldErrors['confirm_password'])) echo reg_hint($fieldErrors, 'confirm_password'); ?>
                    <div class="match-hint" id="matchHint" aria-live="polite"></div>
                </div>

                <button type="submit" class="btn-reg reveal" style="--d:12" id="regSubmit">
                    <span class="spinner"></span>
                    <span>Register Account</span>
                    <i class="fas fa-arrow-right arrow"></i>
                </button>
            </form>

            <div class="footer-link reveal" style="--d:13">
                Already have an account? <a href="login.php">Sign In</a>
            </div>
        <?php endif; ?>
        </div>
    </main>
</div>

<!-- Password rules injected from PHP so JS matches the DB config exactly -->
<script>
const PW_RULES = {
    minLen:       <?php echo (int)$pwRules['min_length']; ?>,
    reqUpper:     <?php echo $pwRules['require_upper']   ? 'true' : 'false'; ?>,
    reqLower:     <?php echo $pwRules['require_lower']   ? 'true' : 'false'; ?>,
    reqNumber:    <?php echo $pwRules['require_number']  ? 'true' : 'false'; ?>,
    reqSpecial:   <?php echo $pwRules['require_special'] ? 'true' : 'false'; ?>
};

function setCheck(id, valid) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('met', valid);
    return valid;
}

function checkPasswordComplexity(password) {
    const checks = [setCheck('pw-len', password.length >= PW_RULES.minLen)];
    if (PW_RULES.reqUpper)   checks.push(setCheck('pw-upper',   /[A-Z]/.test(password)));
    if (PW_RULES.reqLower)   checks.push(setCheck('pw-lower',   /[a-z]/.test(password)));
    if (PW_RULES.reqNumber)  checks.push(setCheck('pw-num',     /[0-9]/.test(password)));
    if (PW_RULES.reqSpecial) checks.push(setCheck('pw-special', /[^A-Za-z0-9]/.test(password)));

    // Strength meter: share of rules met; all four bars only once every rule is met.
    const met = checks.filter(Boolean).length;
    let level = 0;
    if (password.length) {
        level = met === checks.length ? 4 : Math.max(1, Math.min(3, Math.round(met / checks.length * 4)));
    }
    const meter = document.getElementById('pwStrength');
    if (meter) {
        meter.dataset.level = level;
        document.getElementById('pwStrengthText').textContent = [' ', 'Weak', 'Fair', 'Good', 'Strong'][level];
    }
    return met === checks.length;
}

(function () {
    const form = document.getElementById('regForm');
    if (!form) return;
    const pw = document.getElementById('regPassword');
    const confirm = document.getElementById('f-confirm');
    const hint = document.getElementById('matchHint');
    const fields = Array.from(form.querySelectorAll('input[required], select[required]'));
    const zip = document.getElementById('f-zip');
    const unit = document.getElementById('f-unit');

    function checkMatch() {
        if (!confirm.value) {
            confirm.setCustomValidity('');
            hint.textContent = '';
            hint.className = 'match-hint';
            return;
        }
        const same = confirm.value === pw.value;
        confirm.setCustomValidity(same ? '' : 'Passwords do not match.');
        hint.innerHTML = same ? '<i class="fas fa-check"></i> Passwords match' : '<i class="fas fa-times"></i> Passwords do not match yet';
        hint.className = 'match-hint ' + (same ? 'ok' : 'bad');
    }

    function fieldValid(input) {
        if (input === pw) return checkPasswordComplexity(pw.value);
        return input.value.trim() !== '' && input.checkValidity();
    }

    // Message the server left under a field it rejected on the last submit.
    function serverHint(input) {
        return input.closest('.field').querySelector('.field-hint[data-server]');
    }

    function clearServerHint(input) {
        const el = serverHint(input);
        if (!el) return;
        el.removeAttribute('data-server');
        el.className = 'field-hint';
        el.textContent = '';
    }

    function mark(input, touched) {
        const wrap = input.closest('.input-wrap');
        const valid = fieldValid(input);
        wrap.classList.toggle('is-valid', valid && input.type !== 'password');
        wrap.classList.toggle('is-invalid', (touched && !valid && input.value !== '') || !!serverHint(input));
    }

    // Email / phone "already in use": asked once the user stops typing or leaves the field.
    // Registered before the generic listeners below so they see the reset validity.
    form.querySelectorAll('[data-check]').forEach(function (input) {
        const hintEl = document.getElementById(input.dataset.check + 'Hint');
        let timer = null;
        let checked = null;   // value the current hint is about
        let seq = 0;          // ignore responses for values the user already changed

        function setHint(cls, icon, text) {
            hintEl.removeAttribute('data-server');
            hintEl.className = 'field-hint' + (cls ? ' ' + cls : '');
            hintEl.textContent = '';
            if (!text) return;
            const i = document.createElement('i');
            i.className = 'fas ' + icon;
            hintEl.append(i, ' ' + text);
        }

        function check() {
            clearTimeout(timer);
            const value = input.value.trim();
            if (value === checked) return;
            checked = value;
            // Malformed values are handled by the normal validation; only ask about real ones.
            if (!value || !input.checkValidity()) {
                setHint('', '', '');
                return;
            }
            const mine = ++seq;
            setHint('', 'fa-spinner fa-spin', 'Checking…');
            fetch('register.php?check=' + input.dataset.check + '&value=' + encodeURIComponent(value), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (mine !== seq) return;
                if (!data.ok) { setHint('', '', ''); return; }
                if (data.available) {
                    setHint('ok', 'fa-check', data.message);
                } else {
                    input.setCustomValidity(data.message);
                    setHint('bad', 'fa-times', data.message);
                }
                mark(input, true);
                updateProgress();
            }).catch(function () {
                if (mine === seq) { checked = null; setHint('', '', ''); }
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            if (input.value.trim() !== checked) {
                seq++;
                checked = null;
                input.setCustomValidity('');
                setHint('', '', '');
            }
            timer = setTimeout(check, 700);
        });
        input.addEventListener('blur', check);
    });

    function updateProgress() {
        const done = fields.filter(fieldValid).length;
        const pct = Math.round(done / fields.length * 100);
        document.getElementById('formProgress').style.width = pct + '%';
        document.getElementById('formProgressLabel').textContent = pct === 100 ? 'Ready!' : pct + '% done';
    }

    fields.forEach(function (input) {
        function onEdit(e) {
            // ph-address.js also fires (untrusted) change events when it reloads a list.
            if (e && e.isTrusted) clearServerHint(input);
            if (input === zip) zip.value = zip.value.replace(/\D+/g, '').slice(0, 4);
            if (input === pw || input === confirm) checkMatch();
            mark(input, input.closest('.input-wrap').classList.contains('is-invalid'));
            updateProgress();
        }
        input.addEventListener('input', onEdit);
        // Province/city/barangay dropdowns (assets/js/ph-address.js) fire change when they reload.
        if (input.tagName === 'SELECT') input.addEventListener('change', onEdit);
        input.addEventListener('blur', function () { mark(input, true); });
    });

    // Optional unit/building field: just show the check once something is entered.
    unit.addEventListener('input', function () {
        clearServerHint(unit);
        unit.closest('.input-wrap').classList.remove('is-invalid');
        unit.closest('.input-wrap').classList.toggle('is-valid', unit.value.trim() !== '');
    });

    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(btn.dataset.target);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = '<i class="fas fa-eye' + (show ? '-slash' : '') + '"></i>';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    form.addEventListener('submit', function (e) {
        checkMatch();
        if (!form.checkValidity() || !checkPasswordComplexity(pw.value)) {
            e.preventDefault();
            fields.forEach(function (input) { mark(input, true); });
            const firstBad = fields.find(function (input) { return !fieldValid(input); });
            if (firstBad) {
                firstBad.focus();
                if (firstBad !== pw && firstBad.validationMessage) firstBad.reportValidity();
            }
            return;
        }
        document.getElementById('regSubmit').classList.add('loading');
    });

    // After a rejected submit the server re-fills the fields that were fine; show them as done.
    if (pw.value) checkPasswordComplexity(pw.value);
    if (confirm.value) checkMatch();
    fields.forEach(function (input) { if (input.value) mark(input, false); });
    if (unit.value.trim() !== '' && !serverHint(unit)) unit.closest('.input-wrap').classList.add('is-valid');
    updateProgress();
})();
</script>
<?php ias_alert_footer(); ?>
<?php if ($registered && !empty($activationFlash['fresh'])): ?>
<script>document.addEventListener('DOMContentLoaded',function(){if(typeof IAS_UI!=='undefined')IAS_UI.alert('Registration successful. Please check your email to activate your account.','success',0);});</script>
<?php endif; ?>
<?php if ($registered): ?>
<script>
// Resend button: count down the cooldown the server reported, then re-enable.
(function () {
    const btn = document.getElementById('resendBtn');
    const text = document.getElementById('resendText');
    const form = document.getElementById('resendForm');
    if (!btn) return;
    let left = parseInt(btn.dataset.wait, 10) || 0;
    function tick() {
        if (left > 0) {
            btn.disabled = true;
            text.textContent = 'Resend link in ' + left + 's';
            left--;
            setTimeout(tick, 1000);
        } else {
            btn.disabled = false;
            text.textContent = 'Resend activation link';
        }
    }
    tick();
    form.addEventListener('submit', function () {
        btn.classList.add('loading');
        text.textContent = 'Sending…';
    });
})();
</script>
<?php endif; ?>
<script src="assets/js/ph-address.js"></script>
<script src="assets/js/login-hero.js"></script>
</body>
</html>
