<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/retail_reports.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];

$cnt = [];
$cnt['clients'] = $db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn();
$cnt['retail_officer'] = $db->query("SELECT COUNT(*) FROM users WHERE role='retail_officer'")->fetchColumn();
$cnt['technician'] = $db->query("SELECT COUNT(*) FROM users WHERE role='technician'")->fetchColumn();
$cnt['inventory_custodian'] = $db->query("SELECT COUNT(*) FROM users WHERE role='inventory_custodian'")->fetchColumn();
$cnt['orders'] = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();

$statRange = ias_resolve_section_range($_GET, 'stat', 'this_month');
$statSalesRows = ias_fetch_all_sales_rows($db, $statRange['from'], $statRange['to']);
$statSales = ias_summarize_sales($statSalesRows);
$statDeliveries = ias_summarize_deliveries_all($db, $statRange['from'], $statRange['to']);

$restockStmt = $db->prepare(
    'SELECT id, name, category, stock
     FROM products
     WHERE stock <= 15
     ORDER BY stock ASC, name ASC
     LIMIT 15'
);
$restockStmt->execute();
$restockAlerts = $restockStmt->fetchAll(PDO::FETCH_ASSOC);
$restockTotal = (int)$db->query('SELECT COUNT(*) FROM products WHERE stock <= 15')->fetchColumn();

logActivity($db, $admin_id, 'view_dashboard', 'Admin viewed dashboard');

$dashboardCss = <<<'CSS'
.stock-status-pill {
    display:inline-flex; align-items:center; gap:6px;
    padding:5px 11px; border-radius:100px; font-size:11.5px; font-weight:700;
    white-space:nowrap; border:1px solid transparent;
}
.stock-status-pill.low { background:#fffbeb; color:#b45309; border-color:#fde68a; }
.stock-status-pill.critical { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
.stock-status-pill.out { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
CSS;

staff_page_start([
    'role' => 'admin',
    'title' => 'Admin Dashboard',
    'active' => 'dashboard',
    'heading' => 'System Administration',
    'subtitle' => 'Dashboard overview',
    'extra_head' => '<style>' . $dashboardCss . '</style>',
]);
?>

<p class="text-muted text-small" style="margin:-6px 0 18px;">System overview and statistics</p>

        <div class="stats-grid">
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

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Revenue (this month)</div>
                <div class="stat-num">₱<?php echo number_format($statSales['total_sales'], 2); ?></div>
                <div class="stat-icon"><i class="fas fa-peso-sign"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Orders</div>
                <div class="stat-num"><?php echo (int)$cnt['orders']; ?></div>
                <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Deliveries (this month)</div>
                <div class="stat-num"><?php echo number_format($statDeliveries['delivered']); ?></div>
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
            </div>
        </div>
        <p class="text-muted text-small" style="margin:-6px 0 18px;">Store-wide totals · <?php echo h($statRange['label']); ?> · <?php echo h($statRange['from']->format('M d, Y') . ' – ' . $statRange['to']->format('M d, Y')); ?></p>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-exclamation-triangle"></i></span> Restocking Alerts</h3>
                    <div class="card-subtitle">
                        Products at or below the low-stock threshold (15)
                        <?php if ($restockTotal > 0): ?>
                            · showing <?php echo count($restockAlerts); ?> of <?php echo (int)$restockTotal; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="table-wrap">
                    <table class="ias-table">
                        <thead>
                            <tr><th>Product</th><th>Category</th><th>Stock</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $statusLabels = ['out' => 'Out of Stock', 'critical' => 'Critical', 'low' => 'Low'];
                            foreach ($restockAlerts as $item):
                                $stock = (int)$item['stock'];
                                $status = inv_stock_status_label($stock);
                                $statusLabel = $statusLabels[$status] ?? ucfirst($status);
                            ?>
                            <tr>
                                <td><strong><?php echo h((string)$item['name']); ?></strong></td>
                                <td><?php echo h((string)$item['category']); ?></td>
                                <td><?php echo number_format($stock); ?></td>
                                <td><span class="stock-status-pill <?php echo h($status); ?>"><?php echo h($statusLabel); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($restockAlerts)): ?>
                            <tr><td colspan="4" class="empty-state">All products are above the low-stock threshold.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php
staff_page_end();
