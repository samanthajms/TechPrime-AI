<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('courier');

$oid = (int)($_GET['id'] ?? 0);
if ($oid <= 0) {
    header('Location: courier_orders.php');
    exit;
}

$st = $db->prepare(
    "SELECT o.id, o.total, o.status, o.created_at, o.shipping_address, o.customer_phone,
            u.name, u.surname, u.email, u.address AS user_address
     FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1"
);
$st->bind_param('i', $oid);
$st->execute();
$order = $st->get_result()->fetch_assoc();
$st->close();

if (!$order) {
    header('Location: courier_orders.php');
    exit;
}

$itemStmt = $db->prepare(
    'SELECT p.name, p.price, oi.quantity FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?'
);
$itemStmt->bind_param('i', $oid);
$itemStmt->execute();
$itemRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemStmt->close();

$shipStmt = $db->prepare('SELECT * FROM shipments WHERE order_id = ? LIMIT 1');
$shipStmt->bind_param('i', $oid);
$shipStmt->execute();
$ship = $shipStmt->get_result()->fetch_assoc();
$shipStmt->close();

$addr = $order['shipping_address'] ?: $order['user_address'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Details - Courier</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f6f9; }
        .sidebar { width: 300px; height: 100vh; position: fixed; left: 0; top: 0; background: #0998a8; color: #fff; display: flex; flex-direction: column; padding: 20px 0 0; }
        .sidebar .brand { padding: 8px 24px 24px; text-align: center; color: #f5f500; font-weight: 800; font-size: 26px; }
        .sidebar a { display: flex; align-items: center; gap: 12px; padding: 14px 24px; color: #fff; text-decoration: none; font-weight: 600; border-left: 4px solid transparent; }
        .sidebar a.active { background: rgba(255,255,255,0.2); border-left-color: #f5f500; }
        .sidebar .logout-link { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.25); }
        .main { margin-left: 300px; padding: 20px; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #0998a8; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .btn { padding: 10px 18px; background: #0998a8; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">IAS</div>
    <a href="courier_dashboard.php">📊 <span>Dashboard</span></a>
    <a href="courier_orders.php" class="active">🛒 <span>Orders</span></a>
    <a href="courier_assign.php">🚚 <span>Delivery Assignment</span></a>
    <a href="courier_history.php">📜 <span>History</span></a>
    <a href="../logout.php" class="logout-link">🚪 <span>Logout</span></a>
</aside>

<div class="main">
    <p><a href="courier_orders.php" class="btn">← Back to Orders</a></p>
    <div class="grid">
        <div class="card">
            <h3>Customer</h3>
            <p><strong>Name:</strong> <?php echo h($order['name'] . ' ' . $order['surname']); ?></p>
            <p><strong>Email:</strong> <?php echo h($order['email']); ?></p>
            <p><strong>Phone:</strong> <?php echo h($order['customer_phone'] ?? ''); ?></p>
        </div>
        <div class="card">
            <h3>Shipping</h3>
            <p><?php echo nl2br(h($addr)); ?></p>
        </div>
        <?php if ($ship): ?>
        <div class="card">
            <h3>Shipment</h3>
            <p><strong>Carrier:</strong> <?php echo h($ship['carrier']); ?></p>
            <p><strong>Shipment:</strong> <?php echo h(ias_order_display_status(null, $ship['shipment_status'] ?? '')); ?></p>
            <p><strong>Order:</strong> <?php echo h(ias_order_display_status($order['status'] ?? '')); ?></p>
        </div>
        <?php endif; ?>
        <div class="card">
            <h3>Order</h3>
            <p><strong>Total:</strong> PHP <?php echo number_format((float)$order['total'], 2); ?></p>
            <p><strong>Status:</strong> <?php echo h(ias_order_display_status($order['status'] ?? '')); ?></p>
            <p><strong>Date:</strong> <?php echo h($order['created_at']); ?></p>
        </div>
    </div>
    <div class="card">
        <h3>Line Items</h3>
        <table class="table">
            <thead><tr><th>Product</th><th>Qty</th><th>Price</th></tr></thead>
            <tbody>
                <?php foreach ($itemRows as $it): ?>
                <tr>
                    <td><?php echo h($it['name']); ?></td>
                    <td><?php echo (int)$it['quantity']; ?></td>
                    <td>PHP <?php echo number_format((float)$it['price'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php ias_alert_footer(); ?>
</body>
</html>
