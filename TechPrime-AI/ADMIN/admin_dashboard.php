<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];

$cnt = [];
$cnt['clients'] = $db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetch_row()[0];
$cnt['retail_officer'] = $db->query("SELECT COUNT(*) FROM users WHERE role='retail_officer'")->fetch_row()[0];
$cnt['technician'] = $db->query("SELECT COUNT(*) FROM users WHERE role='technician'")->fetch_row()[0];
$cnt['inventory_custodian'] = $db->query("SELECT COUNT(*) FROM users WHERE role='inventory_custodian'")->fetch_row()[0];
$cnt['orders'] = $db->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];

$logs = $db->query("SELECT l.*, u.name FROM logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 8");

logActivity($db, $admin_id, 'view_dashboard', "Admin viewed dashboard");

staff_page_start([
    'role' => 'admin',
    'title' => 'Admin Dashboard',
    'active' => 'dashboard',
    'heading' => 'System Administration',
    'subtitle' => 'Dashboard overview',
]);
?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Orders</div>
                <div class="stat-num"><?php echo (int)$cnt['orders']; ?></div>
                <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Clients</div>
                <div class="stat-num"><?php echo (int)$cnt['clients']; ?></div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Retail Officers</div>
                <div class="stat-num"><?php echo (int)$cnt['retail_officer']; ?></div>
                <div class="stat-icon"><i class="fas fa-store"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Inventory Custodian</div>
                <div class="stat-num"><?php echo (int)$cnt['inventory_custodian']; ?></div>
                <div class="stat-icon"><i class="fas fa-warehouse"></i></div>
            </div>
        </div>

<?php staff_page_end(); ?>
