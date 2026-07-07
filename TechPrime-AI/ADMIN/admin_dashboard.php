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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - IAS</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f9; color: #333; }
        .sidebar { width: 230px; height: 100vh; position: fixed; background: #2c3e50; color: #fff; padding-top: 20px; }
        .sidebar h3 { text-align: center; margin-bottom: 30px; color: #ecf0f1; }
        .sidebar a { display: block; padding: 12px 20px; color: #ecf0f1; text-decoration: none; transition: 0.3s; }
        .sidebar a:hover { background: #34495e; }
        .sidebar a.active { background: #3498db; border-left: 5px solid #2980b9; }
        .main { margin-left: 230px; padding: 20px; }
        .topbar { background: #fff; padding: 15px 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 8px; }
        .topbar h2 { margin: 0; font-size: 20px; color: #2c3e50; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { text-align: center; padding: 30px; }
        .stat-card h3 { margin: 0; font-size: 14px; color: #666; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .number { font-size: 36px; font-weight: 800; color: #3498db; margin: 10px 0; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
        .table th { background: #f8f9fa; color: #555; }
    </style>
</head>
<body>

<div class="sidebar">
    <div style="padding: 20px; text-align: center; color: #3498db; font-weight: 800; font-size: 24px;">IAS ADMIN</div>
    <a href="admin_dashboard.php" class="active">📊 Dashboard</a>
    <a href="manage_users.php">👥 Manage Users</a>
    <a href="view_logs.php">📜 Activity Logs</a>
    <a href="admin_profile.php">👤 My Profile</a>
    <a href="admin_settings.php">⚙️ Settings</a>
    <a href="../logout.php">🚪 Logout</a>
</div>

<div class="main">
    <div class="topbar">
        <h2>System Administration</h2>
        <div class="user-info" style="font-weight: 600;">Admin: <?php echo h($_SESSION['name']); ?></div>
    </div>

    <div class="stats-grid">
        <div class="card stat-card">
            <h3>Sellers</h3>
            <div class="number"><?php echo $cnt['sellers']; ?></div>
        </div>
        <div class="card stat-card">
            <h3>Clients</h3>
            <div class="number"><?php echo $cnt['clients']; ?></div>
        </div>
        <div class="card stat-card">
            <h3>Couriers</h3>
            <div class="number"><?php echo $cnt['couriers']; ?></div>
        </div>
        <div class="card stat-card">
            <h3>Orders</h3>
            <div class="number"><?php echo $cnt['orders']; ?></div>
        </div>
    </div>

    <div class="card">
        <h3>Recent System Activity</h3>
        <div style="overflow-x: auto;">
            <table class="table">
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
                        <td style="color: #666;"><?php echo $l['created_at']; ?></td>
                        <td><strong><?php echo h($l['name'] ?? 'System'); ?></strong></td>
                        <td><?php echo h($l['action']); ?></td>
                        <td><div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo h($l['details']); ?></div></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="../includes/ui_alerts.js"></script>
</body>
</html>