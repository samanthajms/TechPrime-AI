<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'retail_officer') {
    header('Location: ../login.php');
    exit;
}

$db = getDbConnection();
$retailId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass_to_courier'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId > 0) {
        $verify = $db->prepare(
            'SELECT o.id FROM orders o WHERE o.id = ? AND EXISTS (
                SELECT 1 FROM order_items oi INNER JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = o.id AND p.seller_id = ?
            ) LIMIT 1'
        );
        $verify->bind_param('ii', $orderId, $retailId);
        $verify->execute();
        if ($verify->get_result()->fetch_assoc()) {
            $up = $db->prepare("UPDATE orders SET status = 'to_receive' WHERE id = ?");
            $up->bind_param('i', $orderId);
            $up->execute();
            $up->close();
            $chk = $db->prepare('SELECT id FROM shipments WHERE order_id = ? LIMIT 1');
            $chk->bind_param('i', $orderId);
            $chk->execute();
            if (!$chk->get_result()->fetch_assoc()) {
                $ins = $db->prepare("INSERT INTO shipments (order_id, shipment_status) VALUES (?, 'pending')");
                $ins->bind_param('i', $orderId);
                $ins->execute();
                $ins->close();
            }
            $chk->close();
            logActivity($db, $retailId, 'pass_to_courier', "Order #$orderId passed to courier");
            header('Location: retail_orders.php?alert=passed');
            exit;
        }
        $verify->close();
    }
}

$sql = "SELECT o.id, o.total, o.status, o.created_at, o.shipping_address, o.customer_phone,
               u.name, u.surname, u.email,
               (SELECT GROUP_CONCAT(CONCAT(pr.name, ' x', oi.quantity) SEPARATOR ', ')
                FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
                WHERE oi.order_id = o.id AND pr.seller_id = ?) AS products,
               (SELECT shipment_status FROM shipments WHERE order_id = o.id ORDER BY id DESC LIMIT 1) AS shipment_status,
               (SELECT carrier FROM shipments WHERE order_id = o.id ORDER BY id DESC LIMIT 1) AS carrier
        FROM orders o
        INNER JOIN users u ON u.id = o.user_id
        WHERE EXISTS (
            SELECT 1 FROM order_items oix INNER JOIN products prx ON prx.id = oix.product_id
            WHERE oix.order_id = o.id AND prx.seller_id = ?
        )
        ORDER BY o.id DESC";
$st = $db->prepare($sql);
$st->bind_param('ii', $retailId, $retailId);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Store Orders | Easy PC Retail</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ias-teal: #0998a8; --ias-gold: #f5f500; --sidebar-gray: #6a969a; --bg: #f4f7f6; }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); }
        .retail-header { background: var(--ias-teal); padding: 15px 30px; border-bottom: 3px solid var(--ias-gold); }
        .logo-text { color: var(--ias-gold); font-size: 24px; font-weight: 900; }
        .retail-layout { display: flex; flex: 1; overflow: hidden; }
        .retail-sidebar { background: var(--sidebar-gray); width: 260px; padding-top: 10px; display: flex; flex-direction: column; }
        .sidebar-item { background: transparent; color: white; border: none; padding: 15px 25px; width: 100%; text-align: left; font-size: 15px; font-weight: 600; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-item:hover, .sidebar-item.active { background: rgba(0,0,0,0.1); color: var(--ias-gold); }
        .logout-btn { background: #b22222 !important; margin-top: auto; }
        .retail-main { padding: 30px; flex: 1; overflow-y: auto; }
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px dashed var(--ias-teal); }
        .order-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .order-table th { text-align: left; padding: 15px; background: #f8f9fa; color: #888; font-size: 11px; text-transform: uppercase; }
        .order-table td { padding: 16px 15px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
        .status-pill { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .st-ship { background: #fff9db; color: #f08c00; }
        .st-courier { background: #e3f2fd; color: #1976d2; }
        .st-done { background: #e3faf3; color: #0ca678; }
        .btn-pass { background: var(--ias-teal); color: #fff; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px; }
        .price-tag { color: var(--ias-teal); font-weight: 800; }
        .ias-footer { background: var(--ias-teal); color: white; padding: 15px 30px; }
    </style>
</head>
<body>

<header class="retail-header"><div class="logo-text">EASY PC RETAIL</div></header>

<div class="retail-layout">
    <aside class="retail-sidebar">
        <button type="button" class="sidebar-item" onclick="location.href='retail_dashboard.php'">📊 Dashboard</button>
        <button type="button" class="sidebar-item" onclick="location.href='retail_products.php'">📦 My Products</button>
        <button type="button" class="sidebar-item active">📜 Orders</button>
        <button type="button" class="sidebar-item" onclick="location.href='retail_messages.php'">💬 Messages</button>
        <button type="button" class="sidebar-item" onclick="location.href='retail_reviews.php'">⭐ Reviews</button>
        <button type="button" class="sidebar-item" onclick="location.href='retail_settings.php'">⚙️ Settings</button>
        <button type="button" class="sidebar-item logout-btn" onclick="location.href='../logout.php'">🚪 Logout</button>
    </aside>

    <main class="retail-main">
        <section class="card">
            <h2 style="margin:0;">Fulfillment Center</h2>
            <p style="color:#666;font-size:14px;">Client orders appear here. Pass ready orders to the courier.</p>
            <table class="order-table">
                <thead>
                    <tr>
                        <th>Order ID</th><th>Customer</th><th>Products</th><th>Total</th>
                        <th>Order</th><th>Shipment</th><th>Date</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $st = $r['status'] ?? '';
                        $pill = $st === 'to_receive' ? 'st-courier' : ($st === 'to_review' ? 'st-done' : 'st-ship');
                        $canPass = !in_array($st, ['to_receive', 'to_review'], true);
                    ?>
                    <tr>
                        <td><strong>#<?php echo (int)$r['id']; ?></strong></td>
                        <td>
                            <div style="font-weight:700;"><?php echo h($r['name'] . ' ' . $r['surname']); ?></div>
                            <div style="font-size:11px;color:#999;"><?php echo h($r['email']); ?></div>
                            <div style="font-size:11px;color:#666;"><?php echo h($r['customer_phone'] ?? ''); ?></div>
                        </td>
                        <td><small><?php echo h($r['products']); ?></small></td>
                        <td class="price-tag">₱<?php echo number_format((float)$r['total'], 2); ?></td>
                        <td><span class="status-pill <?php echo $pill; ?>"><?php echo h(ias_order_display_status($st)); ?></span></td>
                        <td style="font-size:12px;"><?php
                            echo !empty($r['shipment_status'])
                                ? h(ias_order_display_status(null, $r['shipment_status']))
                                : '—';
                            if (!empty($r['carrier'])) {
                                echo '<br><small style="color:#888;">' . h($r['carrier']) . '</small>';
                            }
                        ?></td>
                        <td style="font-size:12px;color:#888;"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                        <td>
                            <?php if ($canPass): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="order_id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" name="pass_to_courier" class="btn-pass">Pass to Courier</button>
                            </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:40px;color:#bbb;">No orders yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>

<footer class="ias-footer">© 2026 Easy PC Retail Center.</footer>
<?php ias_alert_footer(); ?>
</body>
</html>
