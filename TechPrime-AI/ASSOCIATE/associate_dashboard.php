<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole(['technician', 'inventory_custodian']);

$cid = (int)$_SESSION['user_id'];
$welcomeName = h($_SESSION['name'] ?? 'Associate');

$total = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE courier_id = $cid")->fetch_row()[0] ?? 0);
$pending = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE courier_id = $cid AND shipment_status != 'delivered'")->fetch_row()[0] ?? 0);
$done = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE courier_id = $cid AND shipment_status = 'delivered'")->fetch_row()[0] ?? 0);

logActivity($db, $cid, 'view_dashboard', 'Associate viewed dashboard');
$activeNav = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Associate Dashboard - IAS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f9; color: #333; min-height: 100vh; }
        .sidebar { width: 300px; height: 100vh; position: fixed; left: 0; top: 0; background: #0998a8; color: #fff; display: flex; flex-direction: column; padding: 20px 0 0; z-index: 100; }
        .sidebar .brand { padding: 8px 24px 24px; text-align: center; color: #f5f500; font-weight: 800; font-size: 26px; }
        .sidebar .nav-label { padding: 4px 24px 8px; font-size: 10px; text-transform: uppercase; color: rgba(255,255,255,0.65); font-weight: 700; }
        .sidebar a { display: flex; align-items: center; gap: 12px; padding: 14px 24px; color: #fff; text-decoration: none; font-size: 15px; font-weight: 600; border-left: 4px solid transparent; }
        .sidebar a:hover { background: rgba(255,255,255,0.12); }
        .sidebar a.active { background: rgba(255,255,255,0.2); border-left-color: #f5f500; }
        .sidebar .logout-link { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.25); }
        .main { margin-left: 300px; min-height: 100vh; padding: 20px; }
        .topbar { background: #fff; padding: 15px 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .topbar h2 { margin: 0; font-size: 20px; color: #2c3e50; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); text-align: center; }
        .card h3 { margin: 0; font-size: 13px; color: #666; text-transform: uppercase; }
        .card .number { font-size: 36px; font-weight: 800; color: #0998a8; margin: 10px 0 0; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">IAS</div>
    <span class="nav-label">Navigation</span>
    <a href="associate_dashboard.php" class="<?php echo $activeNav === 'dashboard' ? 'active' : ''; ?>">📊 <span>Dashboard</span></a>
    <a href="associate_orders.php" class="<?php echo $activeNav === 'orders' ? 'active' : ''; ?>">🛒 <span>Orders</span></a>
    <a href="associate_assign.php" class="<?php echo $activeNav === 'assign' ? 'active' : ''; ?>">🚚 <span>Delivery Assignment</span></a>
    <a href="associate_history.php" class="<?php echo $activeNav === 'history' ? 'active' : ''; ?>">📜 <span>History</span></a>
    <a href="../logout.php" class="logout-link">🚪 <span>Logout</span></a>
</aside>

<div class="main">
    <header class="topbar">
        <h2>Associate Dashboard</h2>
        <div style="font-weight:600;color:#555;">Welcome, <?php echo $welcomeName; ?></div>
    </header>

    <div class="stats-grid">
        <div class="card"><h3>Total Deliveries</h3><div class="number"><?php echo $total; ?></div></div>
        <div class="card"><h3>Pending Shipments</h3><div class="number"><?php echo $pending; ?></div></div>
        <div class="card"><h3>Completed Deliveries</h3><div class="number"><?php echo $done; ?></div></div>
    </div>
</div>

<?php ias_alert_footer(); ?>
</body>
</html>
