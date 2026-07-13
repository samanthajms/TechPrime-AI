<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];

// Stats
$cnt = [];
$cnt['clients'] = $db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetch_row()[0];
$cnt['retail_officer'] = $db->query("SELECT COUNT(*) FROM users WHERE role='retail_officer'")->fetch_row()[0];
$cnt['technician'] = $db->query("SELECT COUNT(*) FROM users WHERE role='technician'")->fetch_row()[0];
$cnt['inventory_custodian'] = $db->query("SELECT COUNT(*) FROM users WHERE role='inventory_custodian'")->fetch_row()[0];
$cnt['orders'] = $db->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];

// Recent Logs
$logs = $db->query("SELECT l.*, u.name FROM logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 10");

logActivity($db, $admin_id, 'view_dashboard', "Admin viewed dashboard");
$adminInitials = strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - IAS</title>
    <link rel="stylesheet" href="admin_shared.css">
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <div>
            <div class="brand-text">IAS Admin</div>
            <div class="brand-sub">Control Panel</div>
        </div>
    </div>
    <nav>
        <a href="admin_dashboard.php" class="active">Dashboard</a>
        <a href="manage_users.php">Manage Users</a>
        <a href="view_logs.php">Activity Logs</a>
        <a href="admin_profile.php">My Profile</a>
        <a href="admin_settings.php">Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h2>System Administration</h2>
            <div class="breadcrumb">Dashboard overview</div>
        </div>
        <div class="topbar-right">
            <div class="admin-badge">
                <div class="avatar"><?php echo $adminInitials; ?></div>
                <?php echo h($_SESSION['name']); ?>
            </div>
        </div>
    </div>

    <div class="page-content">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Orders</div>
                <div class="stat-num"><?php echo $cnt['orders']; ?></div>
                <div class="stat-icon">O</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Clients</div>
                <div class="stat-num"><?php echo $cnt['clients']; ?></div>
                <div class="stat-icon">C</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Retail Officers</div>
                <div class="stat-num"><?php echo $cnt['retail_officer']; ?></div>
                <div class="stat-icon">S</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Technician</div>
                <div class="stat-num"><?php echo $cnt['technician']; ?></div>
                <div class="stat-icon">T</div>
            </div>
                        <div class="stat-card">
                <div class="stat-label">Inventory Custodian</div>
                <div class="stat-num"><?php echo $cnt['inventory_custodian']; ?></div>
                <div class="stat-icon">IC</div>
            </div>
        </div>
    </div>
</div>

<script src="../includes/ui_alerts.js"></script>
</body>
</html>
