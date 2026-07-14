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
                <div class="stat-label">Technician</div>
                <div class="stat-num"><?php echo (int)$cnt['technician']; ?></div>
                <div class="stat-icon"><i class="fas fa-tools"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Inventory Custodian</div>
                <div class="stat-num"><?php echo (int)$cnt['inventory_custodian']; ?></div>
                <div class="stat-icon"><i class="fas fa-warehouse"></i></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-clipboard-list"></i></span> Recent Activity</h3>
                    <div class="card-subtitle">Latest system events</div>
                </div>
                <a href="view_logs.php" class="btn btn-outline btn-sm">View all</a>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="table-wrap">
                    <table class="ias-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($logs && $logs->num_rows): ?>
                            <?php while ($row = $logs->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo h($row['name'] ?? 'System'); ?></td>
                                    <td><span class="badge badge-action"><?php echo h($row['action'] ?? ''); ?></span></td>
                                    <td><?php echo h($row['details'] ?? ''); ?></td>
                                    <td class="text-muted text-small"><?php echo h($row['created_at'] ?? ''); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="empty-state">No activity logged yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php staff_page_end(); ?>
