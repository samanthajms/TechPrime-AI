<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $oid = (int)($_POST['order_id'] ?? 0);
    $status = $_POST['order_status'] ?? '';
    $allowed = ['to_pay', 'to_ship', 'to_receive', 'to_review'];
    if ($oid > 0 && in_array($status, $allowed, true)) {
        $up = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $up->bind_param('si', $status, $oid);
        $up->execute();
        $up->close();

        // Ensure a shipment row exists for fulfillment tracking (no courier assignment)
        $chk = $db->prepare('SELECT id FROM shipments WHERE order_id = ? LIMIT 1');
        $chk->bind_param('i', $oid);
        $chk->execute();
        $ship = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$ship) {
            $shipStatus = $status === 'to_review' ? 'delivered' : 'pending';
            $ins = $db->prepare('INSERT INTO shipments (order_id, shipment_status) VALUES (?, ?)');
            $ins->bind_param('is', $oid, $shipStatus);
            $ins->execute();
            $ins->close();
        } else {
            $shipMap = [
                'to_pay' => 'pending',
                'to_ship' => 'processing',
                'to_receive' => 'out_for_delivery',
                'to_review' => 'delivered',
            ];
            $shipStatus = $shipMap[$status] ?? 'pending';
            $sid = (int)$ship['id'];
            $su = $db->prepare('UPDATE shipments SET shipment_status = ? WHERE id = ?');
            $su->bind_param('si', $shipStatus, $sid);
            $su->execute();
            $su->close();
        }

        logActivity($db, $uid, 'update_order_status', "Order #$oid -> $status");
        header('Location: inventory_orders.php?alert=updated');
        exit;
    }
    header('Location: inventory_orders.php?alert=error');
    exit;
}

$sql = "SELECT o.id, o.total, o.status, o.created_at, o.shipping_address, o.customer_phone,
               u.name, u.surname, u.email, u.address AS user_address,
               (SELECT GROUP_CONCAT(CONCAT(pr.name, ' x', oi.quantity) SEPARATOR ', ')
                FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
                WHERE oi.order_id = o.id) AS products,
               (SELECT shipment_status FROM shipments WHERE order_id = o.id ORDER BY id DESC LIMIT 1) AS shipment_status
        FROM orders o
        INNER JOIN users u ON u.id = o.user_id
        ORDER BY o.id DESC";
$rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Orders',
    'active' => 'orders',
    'heading' => 'Orders',
    'subtitle' => 'Customer orders and status updates',
    'extra_head' => <<<'EXTRA'
<style>
.status-pill { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.st-ship { background: #fff8db; color: #926c00; }
.st-courier { background: #eef4ff; color: #2452c9; }
.st-done { background: #eef8e6; color: #3d7422; }
.st-pay { background: #fef2f2; color: #b91c1c; }
.price-tag { color: var(--ep-green-dark); font-weight: 800; }
.customer-cell div { line-height: 1.35; }
.action-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
</style>
EXTRA
]);
?>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-shopping-cart"></i></span> Customer Orders</h3>
                    <div class="card-subtitle">All orders appear automatically</div>
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
                                <th>Shipment</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $ost = $r['status'] ?? '';
                                $pill = $ost === 'to_receive' ? 'st-courier' : ($ost === 'to_review' ? 'st-done' : ($ost === 'to_pay' ? 'st-pay' : 'st-ship'));
                                $addr = $r['shipping_address'] ?: ($r['user_address'] ?? '');
                            ?>
                            <tr>
                                <td><strong>#<?php echo (int)$r['id']; ?></strong></td>
                                <td class="customer-cell">
                                    <div style="font-weight:700;"><?php echo h($r['name'] . ' ' . $r['surname']); ?></div>
                                    <div class="text-muted text-small"><?php echo h($r['email']); ?></div>
                                    <div class="text-muted text-small"><?php echo h($r['customer_phone'] ?? ''); ?></div>
                                    <div class="text-small"><?php echo h($addr); ?></div>
                                </td>
                                <td><small><?php echo h($r['products'] ?? ''); ?></small></td>
                                <td class="price-tag">₱<?php echo number_format((float)$r['total'], 2); ?></td>
                                <td class="text-small"><?php
                                    echo !empty($r['shipment_status'])
                                        ? h(ias_order_display_status(null, $r['shipment_status']))
                                        : '—';
                                ?></td>
                                <td><span class="status-pill <?php echo $pill; ?>"><?php echo h(ias_order_display_status($ost)); ?></span></td>
                                <td class="text-muted text-small"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                                <td>
                                    <div class="action-row">
                                        <a href="inventory_details.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-outline btn-sm">View</a>
                                        <form method="post" style="display:flex;gap:6px;margin:0;align-items:center;flex-wrap:wrap;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$r['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <select name="order_status" class="form-control" style="width:auto;min-width:130px;">
                                                <?php
                                                $opts = [
                                                    'to_pay' => 'To Pay',
                                                    'to_ship' => 'To Ship',
                                                    'to_receive' => 'To Receive',
                                                    'to_review' => 'Completed',
                                                ];
                                                foreach ($opts as $val => $lbl):
                                                ?>
                                                <option value="<?php echo h($val); ?>"<?php echo $ost === $val ? ' selected' : ''; ?>><?php echo h($lbl); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                        </form>
                                    </div>
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

<?php
$flash = '';
if ($__m = ias_alert_message_from_request()) {
    $__t = ((!empty($_GET['alert']) && $_GET['alert'] === 'error') || !empty($_GET['error'])) ? 'error' : 'success';
    $flash = '<script>document.addEventListener("DOMContentLoaded",function(){if(typeof IAS_UI!=="undefined")IAS_UI.alert('
        . json_encode($__m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ','
        . json_encode($__t) . ',0);});</script>';
}
staff_page_end($flash);
?>
