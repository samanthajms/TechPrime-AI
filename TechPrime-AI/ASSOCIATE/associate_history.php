<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole(['technician', 'inventory_custodian']);

$cid = (int)$_SESSION['user_id'];

$stmt = $db->prepare(
    "SELECT s.id, s.carrier, s.updated_at, o.id AS order_id, o.total, u.name, u.surname
     FROM shipments s
     JOIN orders o ON s.order_id = o.id
     JOIN users u ON o.user_id = u.id
     WHERE s.courier_id = ? AND s.shipment_status = 'delivered'
     ORDER BY s.updated_at DESC"
);
$stmt->bind_param('i', $cid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

logActivity($db, $cid, 'view_history', 'Associate viewed delivery history');

staff_page_start([
    'role' => $_SESSION['role'],
    'title' => 'Delivery History',
    'active' => 'history',
    'heading' => 'Delivery History',
    'subtitle' => 'Completed deliveries only',
]);
?>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-history"></i></span> Completed Deliveries</h3>
                    <div class="card-subtitle">Not the same as the active assignment queue</div>
                </div>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="table-wrap">
                    <table class="ias-table">
                        <thead>
                            <tr>
                                <th>Shipment</th>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Carrier</th>
                                <th>Total</th>
                                <th>Delivered</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong>#<?php echo (int)$r['id']; ?></strong></td>
                                <td>#<?php echo (int)$r['order_id']; ?></td>
                                <td><?php echo h($r['name'] . ' ' . $r['surname']); ?></td>
                                <td><?php echo h($r['carrier']); ?></td>
                                <td>PHP <?php echo number_format((float)$r['total'], 2); ?></td>
                                <td class="text-muted text-small"><?php echo h($r['updated_at']); ?></td>
                                <td><span class="badge badge-active">Delivered</span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="empty-state">No completed deliveries yet.</td></tr>
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
