<?php
/**
 * Mark inventory notifications as read (AJAX).
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

checkSessionTimeout();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'inventory_custodian') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

$db = getDbConnection();
$uid = (int)$_SESSION['user_id'];
$action = (string)($_POST['action'] ?? '');

if ($action === 'read') {
    $id = (int)($_POST['id'] ?? 0);
    $ok = $id > 0 && inv_mark_notification_read($db, $uid, $id);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'read_all') {
    echo json_encode(['ok' => inv_mark_all_notifications_read($db, $uid)]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $ok = $id > 0 && inv_delete_notification($db, $uid, $id);
    echo json_encode(['ok' => $ok]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
