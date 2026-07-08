<?php
session_start();
// REVISION: Added ../ so this file can find the folders outside the ADMIN folder
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];

// Stats
$cnt = [];
$cnt['sellers'] = $db->query("SELECT COUNT(*) FROM users WHERE role='seller'")->fetch_row()[0];
$cnt['clients'] = $db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetch_row()[0];
$cnt['couriers'] = $db->query("SELECT COUNT(*) FROM users WHERE role='courier'")->fetch_row()[0];
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
                <div class="stat-label">Sellers</div>
                <div class="stat-num"><?php echo $cnt['sellers']; ?></div>
                <div class="stat-icon">S</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Clients</div>
                <div class="stat-num"><?php echo $cnt['clients']; ?></div>
                <div class="stat-icon">C</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Couriers</div>
                <div class="stat-num"><?php echo $cnt['couriers']; ?></div>
                <div class="stat-icon">D</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Orders</div>
                <div class="stat-num"><?php echo $cnt['orders']; ?></div>
                <div class="stat-icon">O</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon">A</span> Recent System Activity</h3>
                    <div class="card-subtitle">Latest audit events across the platform</div>
                </div>
            </div>
            <div class="table-wrap">
                <table class="ias-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($l = $logs->fetch_assoc()): ?>
                        <tr>
                            <td class="mono text-small text-muted" style="white-space:nowrap"><?php echo $l['created_at']; ?></td>
                            <td><strong><?php echo h($l['name'] ?? 'System'); ?></strong></td>
                            <td><span class="badge badge-action"><?php echo h($l['action']); ?></span></td>
                            <td class="text-muted text-small" style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo h($l['details']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="../includes/ui_alerts.js"></script>
</body>
</html>
