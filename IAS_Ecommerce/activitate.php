<?php
require_once __DIR__ . '/backend/config/database.php';

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $db = getDbConnection();

    // 1. Check for the token in the database
    $stmt = $db->prepare("SELECT id FROM users WHERE activation_token = ? AND is_verified = 0 LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        // 2. SUCCESS: Update user and CLEAR the token so it can't be used again
        $update = $db->prepare("UPDATE users SET is_verified = 1, activation_token = NULL WHERE id = ?");
        $update->bind_param("i", $user['id']);
        
        if ($update->execute()) {
            // Redirect with a encoded success message
            header("Location: login.php?success=" . urlencode("Account successfully activated! You can now log in."));
            exit;
        }
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