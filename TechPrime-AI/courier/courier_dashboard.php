<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('courier');

$cid = (int)$_SESSION['user_id'];

$total = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE courier_id = $cid")->fetch_row()[0] ?? 0);
$pending = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE courier_id = $cid AND shipment_status != 'delivered'")->fetch_row()[0] ?? 0);
$done = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE courier_id = $cid AND shipment_status = 'delivered'")->fetch_row()[0] ?? 0);

logActivity($db, $cid, 'view_dashboard', 'Courier viewed dashboard');

staff_page_start([
    'role' => 'courier',
    'title' => 'Courier Dashboard',
    'active' => 'dashboard',
    'heading' => 'Courier Dashboard',
    'subtitle' => 'Welcome, ' . ($_SESSION['name'] ?? 'Courier'),
]);
?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Deliveries</div>
                <div class="stat-num"><?php echo $total; ?></div>
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Shipments</div>
                <div class="stat-num"><?php echo $pending; ?></div>
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Completed Deliveries</div>
                <div class="stat-num"><?php echo $done; ?></div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
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
