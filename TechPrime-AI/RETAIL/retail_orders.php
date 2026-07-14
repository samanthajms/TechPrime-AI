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
<?php $active = 'orders'; include __DIR__ . '/../includes/retail_shell.php'; ?>
    <main class="retail-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">Fulfillment Center</h1>
                <p class="page-subtitle">Client orders appear here. Pass ready orders to the courier.</p>
            </div>
        </div>

        <section class="card">
            <div class="section-title">Active Order Flow</div>
            <div class="section-body" style="overflow-x:auto;">
                <table class="order-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Products</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Shipment</th>
                            <th>Date</th>
                            <th>Action</th>
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
                                <div style="font-weight:700; color: var(--ias-ink); "><?php echo h($r['name'] . ' ' . $r['surname']); ?></div>
                                <div style="font-size:13px; color: var(--ias-slate); "><?php echo h($r['email']); ?></div>
                                <div style="font-size:13px; color: var(--ias-slate); "><?php echo h($r['customer_phone'] ?? ''); ?></div>
                            </td>
                            <td><div style="color: var(--ias-slate); font-size:13px; line-height:1.5; "><?php echo h($r['products']); ?></div></td>
                            <td class="price-tag">₱<?php echo number_format((float)$r['total'], 2); ?></td>
                            <td><span class="status-pill <?php echo $pill; ?>"><?php echo h(ias_order_display_status($st)); ?></span></td>
                            <td style="font-size:13px; color: var(--ias-slate); ">
                                <?php echo !empty($r['shipment_status']) ? h(ias_order_display_status(null, $r['shipment_status'])) : '—'; ?>
                                <?php if (!empty($r['carrier'])): ?>
                                    <div style="margin-top:6px; color:#6f7e8a; font-size:12px; "><?php echo h($r['carrier']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:13px; color: var(--ias-slate); "><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                            <td>
                                <?php if ($canPass): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" name="pass_to_courier" class="btn-pass">Pass to Courier</button>
                                    </form>
                                <?php else: ?>
                                    <span class="status-pill st-done">Processing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:40px; color: var(--ias-slate);">No orders available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<footer class="ias-footer">© 2026 TechPrime AI Retail Center.</footer>
<?php ias_alert_footer(); ?>
</body>
</html>
