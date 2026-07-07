<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('courier');

$cid = (int)$_SESSION['user_id'];
$activeNav = 'assign';
$allowedCarriers = ['JNT', 'LBC', 'NinjaVan', 'FlashExpress'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $orderId = (int)($_POST['order_id'] ?? 0);
    $carrier = $_POST['carrier'] ?? 'JNT';
    if (!in_array($carrier, $allowedCarriers, true)) {
        $carrier = 'JNT';
    }
    if ($orderId > 0) {
        $valid = $db->prepare("SELECT id FROM orders WHERE id = ? AND status = 'to_receive' LIMIT 1");
        $valid->bind_param('i', $orderId);
        $valid->execute();
        if ($valid->get_result()->fetch_assoc()) {
            $chk = $db->prepare('SELECT id FROM shipments WHERE order_id = ? LIMIT 1');
            $chk->bind_param('i', $orderId);
            $chk->execute();
            $ex = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($ex) {
                $up = $db->prepare('UPDATE shipments SET courier_id = ?, carrier = ?, shipment_status = ? WHERE id = ?');
                $st = 'processing';
                $shipmentId = (int)$ex['id'];
                $up->bind_param('issi', $cid, $carrier, $st, $shipmentId);
                $up->execute();
                $up->close();
            } else {
                $ins = $db->prepare('INSERT INTO shipments (order_id, courier_id, carrier, shipment_status) VALUES (?, ?, ?, ?)');
                $st = 'processing';
                $ins->bind_param('iiss', $orderId, $cid, $carrier, $st);
                $ins->execute();
                $ins->close();
            }
            logActivity($db, $cid, 'assign_shipment', "Order #$orderId / $carrier");
        }
        $valid->close();
    }
    header('Location: courier_orders.php?alert=assigned');
    exit;
}

$olist = $db->query(
    "SELECT o.id, u.name, u.surname FROM orders o
     INNER JOIN users u ON u.id = o.user_id
     LEFT JOIN shipments s ON s.order_id = o.id
     WHERE o.status = 'to_receive' AND (s.id IS NULL OR s.courier_id IS NULL OR s.courier_id = 0)
     ORDER BY o.id DESC LIMIT 200"
);
$orderOptions = $olist ? $olist->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Assignment - IAS</title>
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
        .card { background: #fff; padding: 28px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); max-width: 520px; }
        .row { margin-bottom: 18px; }
        .row label { display: block; font-weight: 700; margin-bottom: 8px; }
        .row select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; }
        .submit { width: 100%; padding: 14px; background: #0998a8; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">IAS</div>
    <span class="nav-label">Navigation</span>
    <a href="courier_dashboard.php">📊 <span>Dashboard</span></a>
    <a href="courier_orders.php">🛒 <span>Orders</span></a>
    <a href="courier_assign.php" class="active">🚚 <span>Delivery Assignment</span></a>
    <a href="courier_history.php">📜 <span>History</span></a>
    <a href="../logout.php" class="logout-link">🚪 <span>Logout</span></a>
</aside>

<div class="main">
    <header class="topbar"><h2>Delivery Assignment</h2></header>
    <div class="card">
        <p style="color:#666;line-height:1.5;">Assign a carrier partner to seller-passed orders. Use <strong>Orders</strong> to track shipment progress; <strong>History</strong> shows completed deliveries only.</p>
        <?php if (empty($orderOptions)): ?>
            <p style="color:#999;font-style:italic;">No unassigned orders. Wait for seller to pass orders.</p>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <div class="row">
                <label for="order_id">Order</label>
                <select id="order_id" name="order_id" required>
                    <option value="">— Select —</option>
                    <?php foreach ($orderOptions as $o): ?>
                    <option value="<?php echo (int)$o['id']; ?>">#<?php echo (int)$o['id']; ?> — <?php echo h($o['name'] . ' ' . $o['surname']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <label for="carrier">Carrier</label>
                <select id="carrier" name="carrier" required>
                    <option value="JNT">J&amp;T Express</option>
                    <option value="LBC">LBC</option>
                    <option value="NinjaVan">Ninja Van</option>
                    <option value="FlashExpress">Flash Express</option>
                </select>
            </div>
            <button type="submit" class="submit">Save Assignment</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php ias_alert_footer(); ?>
</body>
</html>
