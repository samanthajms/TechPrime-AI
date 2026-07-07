<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/backend/config/database.php';

$db = getDbConnection();

if (isset($_SESSION['user_id'])) {
    logActivity($db, $_SESSION['user_id'], 'logout', "User logged out");
}

$_SESSION = [];
if (session_id() !== '' && ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: login.php?alert=logout');
exit;
?>
