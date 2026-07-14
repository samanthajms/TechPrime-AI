<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$oid = (int)($_GET['id'] ?? 0);
if ($oid <= 0) {
    header('Location: inventory_orders.php');
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
    header('Location: inventory_orders.php');
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

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Order #' . $oid,
    'active' => 'orders',
    'heading' => 'Order #' . $oid,
    'subtitle' => 'Order details',
    'extra_head' => '<style>.detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-bottom:20px;}</style>',
]);
?>

        <p style="margin:0 0 16px;">
            <a href="inventory_orders.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Orders</a>
        </p>

        <div class="detail-grid">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-user"></i></span> Customer</h3>
                    </div>
                </div>
                <div class="card-body">
                    <p><strong>Name:</strong> <?php echo h($order['name'] . ' ' . $order['surname']); ?></p>
                    <p><strong>Email:</strong> <?php echo h($order['email']); ?></p>
                    <p><strong>Phone:</strong> <?php echo h($order['customer_phone'] ?? ''); ?></p>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-map-marker-alt"></i></span> Shipping</h3>
                    </div>
                </div>
                <div class="card-body">
                    <p><?php echo nl2br(h($addr)); ?></p>
                </div>
            </div>
            <?php if ($ship): ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-shipping-fast"></i></span> Shipment</h3>
                    </div>
                </div>
                <div class="card-body">
                    <p><strong>Shipment:</strong> <?php echo h(ias_order_display_status(null, $ship['shipment_status'] ?? '')); ?></p>
                    <p><strong>Order:</strong> <?php echo h(ias_order_display_status($order['status'] ?? '')); ?></p>
                </div>
            </div>
            <?php endif; ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-receipt"></i></span> Order</h3>
                    </div>
                </div>
                <div class="card-body">
                    <p><strong>Total:</strong> PHP <?php echo number_format((float)$order['total'], 2); ?></p>
                    <p><strong>Status:</strong> <?php echo h(ias_order_display_status($order['status'] ?? '')); ?></p>
                    <p><strong>Date:</strong> <?php echo h($order['created_at']); ?></p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-box"></i></span> Line Items</h3>
                </div>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="table-wrap">
                    <table class="ias-table">
                        <thead>
                            <tr><th>Product</th><th>Qty</th><th>Price</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemRows as $it): ?>
                            <tr>
                                <td><?php echo h($it['name']); ?></td>
                                <td><?php echo (int)$it['quantity']; ?></td>
                                <td>PHP <?php echo number_format((float)$it['price'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($itemRows)): ?>
                            <tr><td colspan="3" class="empty-state">No line items.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php staff_page_end(); ?>
