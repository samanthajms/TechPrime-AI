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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    if ($name === '' || $surname === '') {
        header("Location: register.php?error=" . urlencode('First name and surname are required.'));
        exit;
    }
    if ($age < 13) {
        header("Location: register.php?error=" . urlencode('Age must be 13 or older.'));
        exit;
    }
    if ($addressInput['error'] !== '') {
        header("Location: register.php?error=" . urlencode($addressInput['error']));
        exit;
    }
    if ($phone === '') {
        header("Location: register.php?error=" . urlencode('Phone number is required.'));
        exit;
    }
    // Keep phone reasonably sane without being overly strict by country.
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
        header("Location: register.php?error=" . urlencode('Please enter a valid phone number.'));
        exit;
    }
    if (strlen($phone) > 30) {
        header("Location: register.php?error=" . urlencode('Phone number is too long.'));
        exit;
    }

    if (empty($password) || $password !== $confirm) {
        header("Location: register.php?error=Passwords do not match!");
        exit;
    }

    // ── Enforce admin-configured password complexity rules ──────────────────
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
        header("Location: register.php?error=" . urlencode($msg));
        exit;
    }

    $checkEmail = $connection->prepare("SELECT email FROM users WHERE email = ? LIMIT 1");
    $checkEmail->execute([$email]);
    if ($checkEmail->fetch(PDO::FETCH_ASSOC)) {
        header("Location: register.php?error=Email already in use.");
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $activationToken = bin2hex(random_bytes(32));
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
         VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')'
    );
    $ok = $stmt->execute(array_values($values));

    if ($ok) {
        // Send activation email
        $activationLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST']
            . dirname($_SERVER['REQUEST_URI']) . '/activitate.php?token=' . $activationToken;

        sendActivationEmail($email, $name, $activationLink);

        $registered = true;
        $registeredEmail = $email;
    } else {
        header("Location: register.php?error=Registration failed. Try again.");
        exit;
    }
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
                <a href="https://mail.google.com" target="_blank" rel="noopener" class="gmail-btn"><i class="fas fa-inbox"></i> Open Gmail</a>
                <p class="note">Didn't receive it? Check your spam folder.<br>The link expires in 24 hours.</p>
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
                        <div class="input-wrap"><i class="fas fa-user lead"></i><input type="text" id="f-name" name="name" autocomplete="given-name" placeholder="Juan" required><i class="fas fa-check-circle ok"></i></div>
                    </div>
                    <div class="field">
                        <label for="f-surname">Surname</label>
                        <div class="input-wrap"><i class="fas fa-user lead"></i><input type="text" id="f-surname" name="surname" autocomplete="family-name" placeholder="Dela Cruz" required><i class="fas fa-check-circle ok"></i></div>
                    </div>
                    <div class="field">
                        <label for="f-age">Age</label>
                        <div class="input-wrap"><i class="fas fa-birthday-cake lead"></i><input type="number" id="f-age" name="age" min="13" max="120" inputmode="numeric" placeholder="18" required><i class="fas fa-check-circle ok"></i></div>
                    </div>
                </div>

                <div class="section-title reveal" style="--d:6">Contact</div>
                <div class="form-grid reveal" style="--d:7">
                    <div class="field">
                        <label for="f-phone">Phone Number</label>
                        <div class="input-wrap"><i class="fas fa-phone-alt lead"></i><input type="tel" id="f-phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="30" pattern="[0-9+\(\)\-\s]{7,30}" placeholder="e.g. 09171234567" required><i class="fas fa-check-circle ok"></i></div>
                    </div>
                    <div class="field">
                        <label for="f-email">Email Address</label>
                        <div class="input-wrap"><i class="fas fa-envelope lead"></i><input type="email" id="f-email" name="email" autocomplete="email" placeholder="you@gmail.com" required><i class="fas fa-check-circle ok"></i></div>
                    </div>
                </div>

                <div class="section-title reveal" style="--d:8">Delivery address</div>
                <div class="field reveal" style="--d:8">
                    <label for="f-street">House / Lot No. &amp; Street</label>
                    <div class="input-wrap"><i class="fas fa-home lead"></i><input type="text" id="f-street" name="addr_street" autocomplete="address-line1" minlength="5" maxlength="150" pattern=".*\S\s+\S.*" title="Enter your house / lot number and street name, e.g. 123 Rizal St." placeholder="e.g. 123 Rizal St." required><i class="fas fa-check-circle ok"></i></div>
                </div>
                <div class="field reveal" style="--d:9">
                    <label for="f-unit">Unit / Floor / Building / Subdivision <span class="optional">(optional)</span></label>
                    <div class="input-wrap"><i class="fas fa-building lead"></i><input type="text" id="f-unit" name="addr_unit" autocomplete="address-line2" maxlength="100" placeholder="e.g. Unit 4B, Oasis Tower"><i class="fas fa-check-circle ok"></i></div>
                </div>
                <div class="ph-address reveal" style="--d:10" data-psgc="assets/data/psgc">
                    <div class="form-grid">
                        <div class="field">
                            <label for="f-province">Province</label>
                            <div class="input-wrap"><i class="fas fa-map lead"></i><select id="f-province" name="addr_province" data-ph="province" autocomplete="address-level1" required></select><i class="fas fa-check-circle ok"></i></div>
                        </div>
                        <div class="field">
                            <label for="f-city">City / Municipality</label>
                            <div class="input-wrap"><i class="fas fa-city lead"></i><select id="f-city" name="addr_city" data-ph="city" autocomplete="address-level2" required></select><i class="fas fa-check-circle ok"></i></div>
                        </div>
                    </div>
                    <div class="form-grid zip">
                        <div class="field">
                            <label for="f-barangay">Barangay</label>
                            <div class="input-wrap"><i class="fas fa-map-marker-alt lead"></i><select id="f-barangay" name="addr_barangay" data-ph="barangay" autocomplete="address-level3" required></select><i class="fas fa-check-circle ok"></i></div>
                        </div>
                        <div class="field">
                            <label for="f-zip">ZIP Code</label>
                            <div class="input-wrap"><i class="fas fa-mail-bulk lead"></i><input type="text" id="f-zip" name="addr_zip" autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{4}" placeholder="1600" required><i class="fas fa-check-circle ok"></i></div>
                        </div>
                    </div>
                </div>

                <div class="section-title reveal" style="--d:9">Security</div>
                <div class="field reveal" style="--d:10">
                    <label for="regPassword">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock lead"></i>
                        <input type="password" name="password" id="regPassword" autocomplete="new-password" placeholder="Create a strong password" oninput="checkPasswordComplexity(this.value)" required>
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
                </div>

                <div class="field reveal" style="--d:11">
                    <label for="f-confirm">Confirm Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-shield-alt lead"></i>
                        <input type="password" id="f-confirm" name="confirm_password" autocomplete="new-password" placeholder="Re-enter your password" required>
                        <button type="button" class="pw-toggle" data-target="f-confirm" aria-label="Show password"><i class="fas fa-eye"></i></button>
                    </div>
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

    // Strength meter: share of rules met, with a bonus for longer passwords.
    const met = checks.filter(Boolean).length;
    let level = 0;
    if (password.length) {
        level = Math.max(1, Math.round(met / checks.length * 3));
        if (met === checks.length && password.length >= PW_RULES.minLen + 4) level = 4;
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

    function mark(input, touched) {
        const wrap = input.closest('.input-wrap');
        const valid = fieldValid(input);
        wrap.classList.toggle('is-valid', valid && input.type !== 'password');
        wrap.classList.toggle('is-invalid', touched && !valid && input.value !== '');
    }

    function updateProgress() {
        const done = fields.filter(fieldValid).length;
        const pct = Math.round(done / fields.length * 100);
        document.getElementById('formProgress').style.width = pct + '%';
        document.getElementById('formProgressLabel').textContent = pct === 100 ? 'Ready!' : pct + '% done';
    }

    fields.forEach(function (input) {
        function onEdit() {
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

    updateProgress();
})();
</script>
<?php ias_alert_footer(); ?>
<?php if ($registered): ?>
<script>document.addEventListener('DOMContentLoaded',function(){if(typeof IAS_UI!=='undefined')IAS_UI.alert('Registration successful. Please check your email to activate your account.','success',0);});</script>
<?php endif; ?>
<script src="assets/js/ph-address.js"></script>
<script src="assets/js/login-hero.js"></script>
</body>
</html>
