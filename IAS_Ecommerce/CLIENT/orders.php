<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$status = isset($_GET['status']) ? ias_normalize_order_status_filter($_GET['status']) : 'All';
$dest = 'user_dashboard.php';
if ($status !== 'All') {
    $dest .= '?status=' . rawurlencode($status);
}
header('Location: ' . $dest);
exit;
