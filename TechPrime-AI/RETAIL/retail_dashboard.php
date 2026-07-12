<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

$user_id = (int)$_SESSION['user_id'];
$welcomeName = h($_SESSION['name'] ?? 'Retail Officer');

logActivity($db, $user_id, 'view_dashboard', 'Retail Officer viewed dashboard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retail Officer Dashboard - Easy PC</title>
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
            <div class="brand-text">Easy PC Retail</div>
            <div class="brand-sub">Retail Officer</div>
        </div>
    </div>
    <nav>
        <a href="retail_dashboard.php" class="active">Dashboard</a>
        <a href="retail_profile.php">Profile &amp; Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h2>Retail Officer Dashboard</h2>
            <div class="breadcrumb">Welcome, <?php echo $welcomeName; ?></div>
        </div>
    </div>

    <div class="page-content">
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon">🛒</div>
                    <h3>Nothing here yet</h3>
                    <p>The Retail Officer dashboard is being built. Check back soon.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php ias_alert_footer(); ?>
</body>
</html>