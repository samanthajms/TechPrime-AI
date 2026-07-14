<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('courier');

$cid = (int)$_SESSION['user_id'];
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

staff_page_start([
    'role' => 'courier',
    'title' => 'Delivery Assignment',
    'active' => 'assign',
    'heading' => 'Delivery Assignment',
    'subtitle' => 'Assign a carrier partner to seller-passed orders',
]);
?>

        <div class="card" style="max-width:560px;">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-truck"></i></span> Assign Carrier</h3>
                    <div class="card-subtitle">Use Orders to track progress; History shows completed deliveries</div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($orderOptions)): ?>
                    <div class="empty-state">No unassigned orders. Wait for seller to pass orders.</div>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <div class="form-group">
                        <label class="form-label" for="order_id">Order</label>
                        <select id="order_id" name="order_id" class="form-control" required>
                            <option value="">— Select —</option>
                            <?php foreach ($orderOptions as $o): ?>
                            <option value="<?php echo (int)$o['id']; ?>">#<?php echo (int)$o['id']; ?> — <?php echo h($o['name'] . ' ' . $o['surname']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="carrier">Carrier</label>
                        <select id="carrier" name="carrier" class="form-control" required>
                            <option value="JNT">J&amp;T Express</option>
                            <option value="LBC">LBC</option>
                            <option value="NinjaVan">Ninja Van</option>
                            <option value="FlashExpress">Flash Express</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Assignment</button>
                </form>
                <?php endif; ?>
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
