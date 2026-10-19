<?php
/**
 * Top-bar notifications bell (Admin, Retail Officer, Inventory Custodian): mark read, mark all read, remove.
 * POST action=read|read_all|delete, id, csrf_token → {ok}. Rows are always scoped to the signed-in user;
 * "mark all read" only touches the types that role sees (inv_notification_types()).
 */
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/inventory_alerts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

checkSessionTimeout();
$role = (string)($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, inv_stock_alert_roles(), true)) {
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
    echo json_encode(['ok' => $id > 0 && inv_mark_notification_read($db, $uid, $id)]);
    exit;
}

if ($action === 'read_all') {
    echo json_encode(['ok' => inv_mark_all_notifications_read($db, $uid, inv_notification_types($role))]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    echo json_encode(['ok' => $id > 0 && inv_delete_notification($db, $uid, $id)]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
