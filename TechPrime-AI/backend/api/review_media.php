<?php
session_start();
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/order_reviews.php';

checkSessionTimeout();
$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['client', 'inventory_custodian'], true) || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$db = getDbConnection();
ep_review_ensure_schema($db);
ep_review_stream($db, $id, (int) $_SESSION['user_id'], $role);
