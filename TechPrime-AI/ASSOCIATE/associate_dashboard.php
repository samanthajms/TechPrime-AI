<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/product_categories.php';
require_once __DIR__ . '/../backend/config/database.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole(['technician', 'inventory_custodian']);

$user_id = (int)$_SESSION['user_id'];
$welcomeName = h($_SESSION['name'] ?? 'Staff');
$roleLabel = h(ias_staff_role_label($_SESSION['role']));

logActivity($db, $user_id, 'view_dashboard', $roleLabel . ' viewed dashboard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $roleLabel; ?> Dashboard - Easy PC</title>
    <link rel="stylesheet" href="../ADMIN/admin_shared.css">
    <style>
        .empty-state { text-align: center; padding: 70px 20px; color: var(--text-muted); }
        .empty-state .empty-icon { font-size: 40px; margin-bottom: 14px; }
        .empty-state h3 { margin: 0 0 6px; color: var(--text-main); font-size: 17px; }
        .empty-state p { margin: 0; font-size: 13.5px; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <div>
            <div class="brand-text">Easy PC Staff</div>
            <div class="brand-sub"><?php echo $roleLabel; ?></div>
        </div>
    </div>
    <nav>
        <a href="associate_dashboard.php" class="active">Dashboard</a>
        <a href="associate_profile.php">Profile &amp; Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h2><?php echo $roleLabel; ?> Dashboard</h2>
            <div class="breadcrumb">Welcome, <?php echo $welcomeName; ?></div>
        </div>
    </div>

    <div class="page-content">
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon">🛠️</div>
                    <h3>Nothing here yet</h3>
                    <p>The <?php echo $roleLabel; ?> dashboard is being built. Check back soon.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php ias_alert_footer(); ?>
</body>
</html>