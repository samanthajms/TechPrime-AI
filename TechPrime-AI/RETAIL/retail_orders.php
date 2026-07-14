<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/staff_layout.php';

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

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Store Orders',
    'active' => 'orders',
    'heading' => 'Fulfillment Center',
    'subtitle' => 'Client orders appear here. Pass ready orders to the courier.',
    'extra_head' => <<<'EXTRA'
<style>
.status-pill { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.st-ship { background: #fff8db; color: #926c00; }
.st-courier { background: #eef4ff; color: #2452c9; }
.st-done { background: #eef8e6; color: #3d7422; }
.price-tag { color: var(--ep-green-dark); font-weight: 800; }
</style>
EXTRA
]);
?>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-shopping-bag"></i></span> Orders</h3>
                    <div class="card-subtitle">Manage fulfillment and courier handoff</div>
                </div>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="table-wrap">
                    <table class="ias-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Products</th>
                                <th>Total</th>
                                <th>Order</th>
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
                                    <div style="font-weight:700;"><?php echo h($r['name'] . ' ' . $r['surname']); ?></div>
                                    <div class="text-muted text-small"><?php echo h($r['email']); ?></div>
                                    <div class="text-muted text-small"><?php echo h($r['customer_phone'] ?? ''); ?></div>
                                </td>
                                <td><small><?php echo h($r['products']); ?></small></td>
                                <td class="price-tag">₱<?php echo number_format((float)$r['total'], 2); ?></td>
                                <td><span class="status-pill <?php echo $pill; ?>"><?php echo h(ias_order_display_status($st)); ?></span></td>
                                <td class="text-small"><?php
                                    echo !empty($r['shipment_status'])
                                        ? h(ias_order_display_status(null, $r['shipment_status']))
                                        : '—';
                                    if (!empty($r['carrier'])) {
                                        echo '<br><span class="text-muted">' . h($r['carrier']) . '</span>';
                                    }
                                ?></td>
                                <td class="text-muted text-small"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                                <td>
                                    <?php if ($canPass): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" name="pass_to_courier" class="btn btn-primary btn-sm">Pass to Courier</button>
                                    </form>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="empty-state">No orders yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php staff_page_end(); ?>
