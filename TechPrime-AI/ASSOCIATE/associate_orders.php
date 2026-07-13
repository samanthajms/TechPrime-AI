<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole(['technician', 'inventory_custodian']);

$cid = (int)$_SESSION['user_id'];
$activeNav = 'orders';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $sid = (int)$_POST['shipment_id'];
    $status = $_POST['shipment_status'] ?? '';
    $allowed = ['pending', 'processing', 'shipped', 'out_for_delivery', 'delivered'];
    if (in_array($status, $allowed, true)) {
        $up = $db->prepare('UPDATE shipments SET shipment_status = ? WHERE id = ? AND courier_id = ?');
        $up->bind_param('sii', $status, $sid, $cid);
        $up->execute();
        if ($status === 'delivered') {
            $res = $db->prepare('SELECT order_id FROM shipments WHERE id = ? AND courier_id = ?');
            $res->bind_param('ii', $sid, $cid);
            $res->execute();
            if ($r = $res->get_result()->fetch_assoc()) {
                $oid = (int)$r['order_id'];
                $ordUp = $db->prepare("UPDATE orders SET status = 'to_review' WHERE id = ?");
                $ordUp->bind_param('i', $oid);
                $ordUp->execute();
                $ordUp->close();
            }
            $res->close();
        }
        $up->close();
        logActivity($db, $cid, 'update_shipment_status', "Shipment #$sid -> $status");
    }
    header('Location: associate_orders.php?alert=updated');
    exit;
}

$sql = "SELECT o.id AS oid, o.shipping_address AS ship_addr, u.name AS uname, u.surname AS usurname,
               s.id AS shipment_id, s.shipment_status, s.courier_id, s.carrier
        FROM orders o
        INNER JOIN users u ON o.user_id = u.id
        LEFT JOIN shipments s ON o.id = s.order_id
        WHERE o.status = 'to_receive' OR (s.courier_id = ? AND s.shipment_status != 'delivered')
        ORDER BY o.id DESC";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $cid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Associate Orders - IAS</title>
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
        .btn { padding: 8px 14px; background: #0998a8; color: #fff; border: none; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-block; }
        .btn-outline { background: transparent; border: 1px solid #0998a8; color: #0998a8; }
        select { padding: 6px; border-radius: 4px; border: 1px solid #ddd; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">IAS</div>
    <span class="nav-label">Navigation</span>
    <a href="associate_dashboard.php">📊 <span>Dashboard</span></a>
    <a href="associate_orders.php" class="active">🛒 <span>Orders</span></a>
    <a href="associate_assign.php">🚚 <span>Delivery Assignment</span></a>
    <a href="associate_history.php">📜 <span>History</span></a>
    <a href="../logout.php" class="logout-link">🚪 <span>Logout</span></a>
</aside>

<div class="main">
    <header class="topbar"><h2>Orders from Retail</h2></header>
    <p style="color:#666;margin:0 0 16px;">Orders passed by sellers. Assign carriers on Delivery Assignment, then update status here.</p>
    <div class="card">
        <table class="table">
            <thead>
                <tr><th>Order</th><th>Customer</th><th>Address</th><th>Carrier</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong>#<?php echo (int)$r['oid']; ?></strong></td>
                    <td><?php echo h($r['uname'] . ' ' . $r['usurname']); ?></td>
                    <td style="max-width:180px;font-size:12px;"><?php echo h($r['ship_addr']); ?></td>
                    <td><?php echo h($r['carrier'] ?? '—'); ?></td>
                    <td><?php echo h(ias_order_display_status(null, $r['shipment_status'] ?? 'pending')); ?></td>
                    <td style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <a href="associate_details.php?id=<?php echo (int)$r['oid']; ?>" class="btn btn-outline">View</a>
                        <?php if (!empty($r['courier_id']) && (int)$r['courier_id'] === $cid): ?>
                        <form method="post" style="display:flex;gap:6px;margin:0;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="shipment_id" value="<?php echo (int)$r['shipment_id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <?php
                            $curShip = $r['shipment_status'] ?? 'pending';
                            $opts = [
                                'pending' => 'Pending',
                                'processing' => 'Processing',
                                'shipped' => 'Shipped',
                                'out_for_delivery' => 'Out for Delivery',
                                'delivered' => 'Delivered',
                            ];
                            ?>
                            <select name="shipment_status">
                                <?php foreach ($opts as $val => $lbl): ?>
                                <option value="<?php echo h($val); ?>"<?php echo $curShip === $val ? ' selected' : ''; ?>><?php echo h($lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn">Update</button>
                        </form>
                        <?php elseif (empty($r['courier_id'])): ?>
                        <a href="associate_assign.php" class="btn">Assign Carrier</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">No orders from sellers yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php ias_alert_footer(); ?>
</body>
</html>
