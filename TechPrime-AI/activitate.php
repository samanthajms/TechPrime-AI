<?php
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/includes/security.php';

if (isset($_GET['token'])) {
    $token = (string)$_GET['token'];
    $db = getDbConnection();

    // 1. Check for the token in the database
    $stmt = $db->prepare("SELECT id FROM users WHERE activation_token = ? AND is_verified = 0 LIMIT 1");
    $stmt->execute([$token]);
    $result = $stmt;
    $user = $result->fetch(PDO::FETCH_ASSOC);
    if ($user && ias_activation_token_expired($token)) {
        // Links work for 24 h; logging in with the right password opens the "Resend activation link" screen.
        header("Location: login.php?error=" . urlencode("This activation link has expired. Log in with your email and password to get a new one."));
        exit;
    }
    if ($user) {
        // 2. SUCCESS: Update user and CLEAR the token so it can't be used again
        $update = $db->prepare("UPDATE users SET is_verified = 1, activation_token = NULL WHERE id = ?");
        $update->execute([$user['id']]);
        header("Location: login.php?success=" . urlencode("Account successfully activated! You can now log in."));
        exit;
    } else {
        // 3. FAIL: Token not found or already verified
        header("Location: login.php?error=" . urlencode("Invalid or expired activation link."));
        exit;
    }
} else {
    header("Location: login.php");
    exit;
}
?>