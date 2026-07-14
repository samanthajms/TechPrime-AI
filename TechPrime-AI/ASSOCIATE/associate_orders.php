 <?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole(['technician', 'inventory_custodian']);

$cid = (int)$_SESSION['user_id'];

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

staff_page_start([
    'role' => $_SESSION['role'],
    'title' => 'Associate Orders',
    'active' => 'orders',
    'heading' => 'Orders from Retail',
    'subtitle' => 'Assign carriers, then update shipment status',
]);
?>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-shopping-cart"></i></span> Active Orders</h3>
                    <div class="card-subtitle">Orders passed by sellers</div>
                </div>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="table-wrap">
                    <table class="ias-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Address</th>
                                <th>Carrier</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong>#<?php echo (int)$r['oid']; ?></strong></td>
                                <td><?php echo h($r['uname'] . ' ' . $r['usurname']); ?></td>
                                <td class="text-small"><?php echo h($r['ship_addr']); ?></td>
                                <td><?php echo h($r['carrier'] ?? '—'); ?></td>
                                <td><span class="badge badge-pending"><?php echo h(ias_order_display_status(null, $r['shipment_status'] ?? 'pending')); ?></span></td>
                                <td>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                        <a href="associate_details.php?id=<?php echo (int)$r['oid']; ?>" class="btn btn-outline btn-sm">View</a>
                                        <?php if (!empty($r['courier_id']) && (int)$r['courier_id'] === $cid): ?>
                                        <form method="post" style="display:flex;gap:6px;margin:0;align-items:center;flex-wrap:wrap;">
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
                                            <select name="shipment_status" class="form-control" style="width:auto;min-width:140px;">
                                                <?php foreach ($opts as $val => $lbl): ?>
                                                <option value="<?php echo h($val); ?>"<?php echo $curShip === $val ? ' selected' : ''; ?>><?php echo h($lbl); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                        </form>
                                        <?php elseif (empty($r['courier_id'])): ?>
                                        <a href="associate_assign.php" class="btn btn-primary btn-sm">Assign Carrier</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rows)): ?>
                            <tr><td colspan="6" class="empty-state">No orders from sellers yet.</td></tr>
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
