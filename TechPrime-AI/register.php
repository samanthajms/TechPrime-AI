<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/mailer.php';

$error = $_GET['error'] ?? '';
$registered = false;
$registeredEmail = '';

// Load DB connection and current password rules for frontend display
$connection = getDbConnection();
$pwRules = getPasswordRules($connection);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role            = $_POST['role'] ?? 'client';
    $name            = trim($_POST['name'] ?? '');
    $surname         = trim($_POST['surname'] ?? '');
    $age             = intval($_POST['age'] ?? 0);
    $address         = trim($_POST['address'] ?? '');
    $email           = strtolower(trim($_POST['email'] ?? ''));
    $password        = $_POST['password'] ?? '';
    $confirm         = $_POST['confirm_password'] ?? '';

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
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    if ($checkEmail->get_result()->num_rows > 0) {
        header("Location: register.php?error=Email already in use.");
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $activationToken = bin2hex(random_bytes(32));

    $stmt = $connection->prepare("INSERT INTO users (name, surname, age, address, email, password, role, is_verified, activation_token) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)");
    $stmt->bind_param("ssisssss", $name, $surname, $age, $address, $email, $hashedPassword, $role, $activationToken);

    if ($stmt->execute()) {
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
    <title>Create Account | Easy PC</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ias-teal: #0998a8; --bg: #f4f7f6; }
        body { margin:0; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px 0; }
        .reg-card { background: white; padding: 35px; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); width: 100%; max-width: 450px; border-top: 6px solid var(--ias-teal); }
        h1 { color: var(--ias-teal); font-weight: 900; margin: 0 0 5px; text-align: center; }
        p.sub { color: #888; font-size: 14px; margin-bottom: 25px; text-align: center; font-weight: 600; }
        .error-box { background: #fff5f5; color: #ff4757; padding: 12px; border-radius: 10px; font-size: 13px; font-weight: 700; margin-bottom: 20px; border: 1px solid #ffccd5; }
        .success-card { text-align: center; padding: 20px 0; }
        .success-icon { font-size: 60px; margin-bottom: 15px; }
        .success-title { color: var(--ias-teal); font-size: 22px; font-weight: 900; margin-bottom: 10px; }
        .success-msg { color: #444; font-size: 14px; line-height: 1.7; margin-bottom: 20px; }
        .email-highlight { background: #e7f5f7; color: var(--ias-teal); padding: 8px 14px; border-radius: 8px; font-weight: 800; display: inline-block; margin: 8px 0; word-break: break-all; }
        .gmail-btn { display: inline-block; background: #EA4335; color: white; padding: 12px 24px; border-radius: 12px; text-decoration: none; font-weight: 700; font-size: 14px; margin-top: 10px; transition: 0.3s; }
        .gmail-btn:hover { background: #c5221f; transform: translateY(-2px); }
        .note { color: #999; font-size: 12px; margin-top: 15px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        label { display: block; font-size: 11px; font-weight: 800; color: #666; text-transform: uppercase; margin-bottom: 5px; }
        input, select { width: 100%; padding: 12px; border: 2px solid #eee; border-radius: 10px; box-sizing: border-box; font-family: inherit; font-size: 14px; }
        input:focus { outline: none; border-color: var(--ias-teal); background: #f0fbfc; }
        #passwordComplexity { font-size: 11px; margin: 10px 0; padding: 12px; background: #fafafa; border-radius: 10px; border: 1px solid #eee; }
        .invalid { color: #e74c3c; font-weight: 600; }
        .valid { color: #27ae60; font-weight: 600; }
        .btn-reg { background: var(--ias-teal); color: white; border: none; width: 100%; padding: 15px; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.3s; margin-top: 15px; }
        .btn-reg:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(9, 152, 168, 0.3); }
        .footer-link { margin-top: 20px; font-size: 13px; color: #666; text-align: center; }
        .footer-link a { color: var(--ias-teal); text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>

<div class="reg-card">
    <h1>Create Account</h1>
    <p class="sub">Join the Easy PC Marketplace</p>

    <?php if ($registered): ?>
        <!-- ✅ Registration Success — Email Activation Notice -->
        <div class="success-card">
            <div class="success-icon">📧</div>
            <div class="success-title">Check Your Gmail!</div>
            <div class="success-msg">
                We've sent an activation link to:<br>
                <span class="email-highlight"><?php echo htmlspecialchars($registeredEmail); ?></span><br><br>
                Please open your Gmail and click the activation link to verify your account before logging in.
            </div>
            <a href="https://mail.google.com" target="_blank" class="gmail-btn">📬 Open Gmail</a>
            <p class="note">Didn't receive it? Check your spam folder.<br>The link expires in 24 hours.</p>
        </div>
        <div class="footer-link">
            Already activated? <a href="login.php">Sign In</a>
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="error-box">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="register.php" method="POST" id="regForm">
            <label>Account Type</label>
            <select name="role" style="margin-bottom: 15px;">
                <option value="client">Client</option>
                <option value="retail_officer">Retail Officer</option>
                <option value="technician">Technician</option>
                <option value="inventory_custodian">Inventory Custodian</option>
                <option value="admin">Admin</option>
            </select>

            <div class="form-grid">
                <div><label>First Name</label><input type="text" name="name" required></div>
                <div><label>Surname</label><input type="text" name="surname" required></div>
            </div>

            <div class="form-grid">
                <div><label>Age</label><input type="number" name="age" min="13" required></div>
                <div><label>Location</label><input type="text" name="address" placeholder="City, Country" required></div>
            </div>

            <label>Email Address</label>
            <input type="email" name="email" style="margin-bottom: 15px;" required>

            <label>Password</label>
            <input type="password" name="password" id="regPassword" oninput="checkPasswordComplexity(this.value)" required>
            
            <div id="passwordComplexity">
                <div id="pw-len" class="invalid">✖ At least <?php echo (int)$pwRules['min_length']; ?> characters</div>
                <?php if ($pwRules['require_upper']): ?>
                <div id="pw-upper" class="invalid">✖ 1 Uppercase letter (A–Z)</div>
                <?php endif; ?>
                <?php if ($pwRules['require_lower']): ?>
                <div id="pw-lower" class="invalid">✖ 1 Lowercase letter (a–z)</div>
                <?php endif; ?>
                <?php if ($pwRules['require_number']): ?>
                <div id="pw-num" class="invalid">✖ 1 Number (0–9)</div>
                <?php endif; ?>
                <?php if ($pwRules['require_special']): ?>
                <div id="pw-special" class="invalid">✖ 1 Special character (!@#$%…)</div>
                <?php endif; ?>
            </div>

            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>

            <button type="submit" class="btn-reg">Register Account</button>
        </form>

        <div class="footer-link">
            Already have an account? <a href="login.php">Sign In</a>
        </div>
    <?php endif; ?>
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
    if (!el) return;
    const text = el.innerText.slice(2); // strip ✔/✖ prefix
    el.className = valid ? 'valid' : 'invalid';
    el.innerHTML = (valid ? '✔ ' : '✖ ') + text;
}

function checkPasswordComplexity(password) {
    setCheck('pw-len',     password.length >= PW_RULES.minLen);
    if (PW_RULES.reqUpper)   setCheck('pw-upper',   /[A-Z]/.test(password));
    if (PW_RULES.reqLower)   setCheck('pw-lower',   /[a-z]/.test(password));
    if (PW_RULES.reqNumber)  setCheck('pw-num',     /[0-9]/.test(password));
    if (PW_RULES.reqSpecial) setCheck('pw-special', /[^A-Za-z0-9]/.test(password));
}
</script>
<?php ias_alert_footer(); ?>
<?php if ($registered): ?>
<script>document.addEventListener('DOMContentLoaded',function(){if(typeof IAS_UI!=='undefined')IAS_UI.alert('Registration successful. Please check your email to activate your account.','success',0);});</script>
<?php endif; ?>
</body>
</html>