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
            o.fulfillment_type, u.name, u.surname, u.email, u.address AS user_address
     FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1"
);
$st->execute([$oid]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    header('Location: inventory_orders.php');
    exit;
}

$itemStmt = $db->prepare(
    'SELECT p.name, p.price, oi.quantity FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?'
);
$itemStmt->execute([$oid]);
$itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
$shipStmt = $db->prepare('SELECT * FROM shipments WHERE order_id = ? LIMIT 1');
$shipStmt->execute([$oid]);
$ship = $shipStmt->fetch(PDO::FETCH_ASSOC);
$addr = $order['shipping_address'] ?: $order['user_address'];
$isPickup = strtolower(trim((string)($order['fulfillment_type'] ?? ''))) === 'pickup';

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Order #' . $oid,
    'active' => 'orders',
    'heading' => 'Order #' . $oid,
    'subtitle' => 'Order details · placed ' . format_utc_datetime((string)$order['created_at'], 'M j, Y'),
    'actions' => '<a href="inventory_orders.php" class="cx-btn cx-btn-outline"><i class="ph ph-arrow-left" aria-hidden="true"></i> Back to Orders</a>',
]);
?>

        <div class="inv-stack">
            <div class="od-grid">
                <section class="cx-card od-card" aria-labelledby="odCustomer">
                    <div class="cx-card-head"><h2 id="odCustomer"><i class="ph ph-user" aria-hidden="true"></i> Customer</h2></div>
                    <dl class="od-list">
                        <div><dt>Name</dt><dd><?php echo h($order['name'] . ' ' . $order['surname']); ?></dd></div>
                        <div><dt>Email</dt><dd><?php echo h($order['email']); ?></dd></div>
                        <div><dt>Phone</dt><dd class="cx-num"><?php echo h($order['customer_phone'] ?? '') ?: '—'; ?></dd></div>
                    </dl>
                </section>
                <section class="cx-card od-card" aria-labelledby="odShip">
                    <div class="cx-card-head"><h2 id="odShip"><i class="ph ph-<?php echo $isPickup ? 'storefront' : 'map-pin'; ?>" aria-hidden="true"></i> <?php echo $isPickup ? 'Pickup' : 'Shipping'; ?></h2></div>
                    <dl class="od-list">
                        <div><dt><?php echo $isPickup ? 'Pickup at' : 'Address'; ?></dt><dd><?php echo nl2br(h($addr)); ?></dd></div>
                    </dl>
                </section>
                <?php if ($ship && !$isPickup): ?>
                <section class="cx-card od-card" aria-labelledby="odShipment">
                    <div class="cx-card-head"><h2 id="odShipment"><i class="ph ph-truck" aria-hidden="true"></i> Shipment</h2></div>
                    <dl class="od-list">
                        <div><dt>Shipment</dt><dd><?php echo h(ias_order_display_status(null, $ship['shipment_status'] ?? '')); ?></dd></div>
                        <div><dt>Order</dt><dd><?php echo h(ias_order_display_status($order['status'] ?? '')); ?></dd></div>
                    </dl>
                </section>
                <?php endif; ?>
                <section class="cx-card od-card" aria-labelledby="odOrder">
                    <div class="cx-card-head"><h2 id="odOrder"><i class="ph ph-receipt" aria-hidden="true"></i> Order</h2></div>
                    <dl class="od-list">
                        <div><dt>Total</dt><dd class="od-total cx-num">₱<?php echo number_format((float)$order['total'], 2); ?></dd></div>
                        <div><dt>Status</dt><dd><?php echo h(ias_order_display_status($order['status'] ?? '')); ?></dd></div>
                        <div><dt>Date</dt><dd class="cx-num"><?php echo h(format_utc_datetime((string)$order['created_at'], 'M j, Y g:i A', '—')); ?></dd></div>
                    </dl>
                </section>
            </div>

            <section class="cx-card" aria-labelledby="odItems">
                <div class="cx-card-head">
                    <div>
                        <h2 id="odItems"><i class="ph ph-package" aria-hidden="true"></i> Line items</h2>
                        <p><?php echo count($itemRows); ?> product<?php echo count($itemRows) === 1 ? '' : 's'; ?> in this order</p>
                    </div>
                </div>
                <?php if (empty($itemRows)): ?>
                    <div class="cx-empty">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-package"></i></span>
                        <strong>No line items</strong>
                        <span>This order has no products attached.</span>
                    </div>
                <?php else: ?>
                <div class="cx-table-wrap">
                    <table class="cx-table od-table">
                        <thead>
                            <tr><th scope="col">Product</th><th scope="col" class="num">Qty</th><th scope="col" class="num">Price</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itemRows as $it): ?>
                            <tr>
                                <td data-label="Product"><span class="stocks-pname"><?php echo h($it['name']); ?></span></td>
                                <td data-label="Qty" class="num cx-num"><?php echo (int)$it['quantity']; ?></td>
                                <td data-label="Price" class="num cx-num"><span class="price-tag">₱<?php echo number_format((float)$it['price'], 2); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </section>
        </div>

<?php staff_page_end(); ?>
