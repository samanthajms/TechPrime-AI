<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole(['technician', 'inventory_custodian']);

$cid = (int)$_SESSION['user_id'];

$stmt = $db->prepare(
    "SELECT s.id, s.carrier, s.updated_at, o.id AS order_id, o.total, u.name, u.surname
     FROM shipments s
     JOIN orders o ON s.order_id = o.id
     JOIN users u ON o.user_id = u.id
     WHERE s.courier_id = ? AND s.shipment_status = 'delivered'
     ORDER BY s.updated_at DESC"
);
$stmt->bind_param('i', $cid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

logActivity($db, $cid, 'view_history', 'Associate viewed delivery history');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery History - IAS</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f9; }
        .sidebar { width: 300px; height: 100vh; position: fixed; left: 0; top: 0; background: #0998a8; color: #fff; display: flex; flex-direction: column; padding: 20px 0 0; }
        .sidebar .brand { padding: 8px 24px 24px; text-align: center; color: #f5f500; font-weight: 800; font-size: 26px; }
        .sidebar .nav-label { padding: 4px 24px 8px; font-size: 10px; text-transform: uppercase; color: rgba(255,255,255,0.65); font-weight: 700; }
        .sidebar a { display: flex; align-items: center; gap: 12px; padding: 14px 24px; color: #fff; text-decoration: none; font-weight: 600; border-left: 4px solid transparent; }
        .sidebar a:hover { background: rgba(255,255,255,0.12); }
        .sidebar a.active { background: rgba(255,255,255,0.2); border-left-color: #f5f500; }
        .sidebar .logout-link { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.25); }
        .main { margin-left: 300px; padding: 20px; }
        .topbar { background: #fff; padding: 15px 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; border-radius: 8px; }
        .topbar h2 { margin: 0; font-size: 20px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); overflow: hidden; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        .table th { background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #555; }
        .badge { padding: 4px 10px; background: #d4edda; color: #155724; border-radius: 4px; font-size: 11px; font-weight: 700; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">IAS</div>
    <span class="nav-label">Navigation</span>
    <a href="associate_dashboard.php">📊 <span>Dashboard</span></a>
    <a href="associate_orders.php">🛒 <span>Orders</span></a>
    <a href="associate_assign.php">🚚 <span>Delivery Assignment</span></a>
    <a href="associate_history.php" class="active">📜 <span>History</span></a>
    <a href="../logout.php" class="logout-link">🚪 <span>Logout</span></a>
</aside>

<div class="main">
    <header class="topbar"><h2>Delivery History</h2></header>
    <p style="color:#666;">Completed deliveries only — not the same as active assignment queue.</p>
    <div class="card">
        <table class="table">
            <thead>
                <tr><th>Shipment</th><th>Order</th><th>Customer</th><th>Carrier</th><th>Total</th><th>Delivered</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong>#<?php echo (int)$r['id']; ?></strong></td>
                    <td>#<?php echo (int)$r['order_id']; ?></td>
                    <td><?php echo h($r['name'] . ' ' . $r['surname']); ?></td>
                    <td><?php echo h($r['carrier']); ?></td>
                    <td>PHP <?php echo number_format((float)$r['total'], 2); ?></td>
                    <td style="font-size:12px;color:#666;"><?php echo h($r['updated_at']); ?></td>
                    <td><span class="badge">Delivered</span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#999;">No completed deliveries yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php ias_alert_footer(); ?>
</body>
</html>
