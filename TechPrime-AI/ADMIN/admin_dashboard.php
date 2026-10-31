<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/retail_reports.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];

$cnt = [];
$cnt['clients'] = $db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn();
$cnt['retail_officer'] = $db->query("SELECT COUNT(*) FROM users WHERE role='retail_officer'")->fetchColumn();
$cnt['technician'] = $db->query("SELECT COUNT(*) FROM users WHERE role='technician'")->fetchColumn();
$cnt['inventory_custodian'] = $db->query("SELECT COUNT(*) FROM users WHERE role='inventory_custodian'")->fetchColumn();
$cnt['cashier'] = $db->query("SELECT COUNT(*) FROM users WHERE role='cashier'")->fetchColumn();
$cnt['admin'] = $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
$cnt['orders'] = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();

$statRange = ias_resolve_section_range($_GET, 'stat', 'this_month');
$statSalesRows = ias_fetch_all_sales_rows($db, $statRange['from'], $statRange['to']);
$statSales = ias_summarize_sales($statSalesRows);
$statDeliveries = ias_summarize_deliveries_all($db, $statRange['from'], $statRange['to']);

// Every low-stock product is rendered: the screen shows the first 15, Print lists them all.
$restockStmt = $db->prepare(
    'SELECT id, name, category, stock
     FROM products
     WHERE stock <= 15
     ORDER BY stock ASC, name ASC'
);
$restockStmt->execute();
$restockAlerts = $restockStmt->fetchAll(PDO::FETCH_ASSOC);
$restockTotal = count($restockAlerts);
$restockShown = 15;

logActivity($db, $admin_id, 'view_dashboard', 'Admin viewed dashboard');

$firstName = trim((string)($_SESSION['name'] ?? ''));
$rangeText = $statRange['from']->format('M j') . ' – ' . $statRange['to']->format('M j, Y');
$statusLabels = ['out' => 'Out of Stock', 'critical' => 'Critical', 'low' => 'Low Stock'];
$statusIcons = ['out' => 'ph-x-circle', 'critical' => 'ph-warning-circle', 'low' => 'ph-warning'];
$people = [
    ['tab' => 'client', 'label' => 'Clients', 'hint' => 'Storefront accounts', 'icon' => 'ph-user', 'count' => (int)$cnt['clients']],
    ['tab' => 'retail_officer', 'label' => 'Retail Officers', 'hint' => 'Reports and trend analysis', 'icon' => 'ph-storefront', 'count' => (int)$cnt['retail_officer']],
    ['tab' => 'inventory_custodian', 'label' => 'Inventory Custodians', 'hint' => 'Stock and receiving', 'icon' => 'ph-warehouse', 'count' => (int)$cnt['inventory_custodian']],
    ['tab' => 'cashier', 'label' => 'Cashiers', 'hint' => 'Walk-in POS checkout', 'icon' => 'ph-cash-register', 'count' => (int)$cnt['cashier']],
    ['tab' => 'admin', 'label' => 'Admins', 'hint' => 'Full system access', 'icon' => 'ph-shield-check', 'count' => (int)$cnt['admin']],
];

staff_page_start([
    'role' => 'admin',
    'title' => 'Admin Dashboard',
    'active' => 'dashboard',
    'heading' => $firstName !== '' ? 'Good to see you, ' . $firstName : 'Dashboard',
    'subtitle' => date('l, F j, Y') . ' · store sales, accounts and stock at a glance',
    'actions' => '<button type="button" class="cx-btn cx-btn-outline no-print" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>',
    'report' => [
        'title' => 'Store Summary Report',
        'lines' => [
            'Period covered' => $statRange['label'] . ' (' . $statRange['from']->format('M j, Y') . ' – ' . $statRange['to']->format('M j, Y') . ')',
            'Orders, accounts, stock' => 'All-time totals as of printing',
        ],
    ],
]);
?>

        <div class="adm-dash">
            <div class="adm-top">
                <section class="cx-card adm-month" aria-labelledby="monthTitle">
                    <div class="cx-card-head">
                        <h2 id="monthTitle"><i class="ph ph-chart-line-up" aria-hidden="true"></i> <?php echo h($statRange['label']); ?></h2>
                        <span class="cx-card-meta cx-num"><?php echo h($rangeText); ?> · store-wide</span>
                    </div>
                    <div class="kpis kpis-3">
                        <div class="kpi kpi-hero">
                            <span class="kpi-label">Revenue</span>
                            <strong class="kpi-value cx-num">₱<?php echo number_format($statSales['total_sales'], 2); ?></strong>
                            <span class="kpi-sub">Online orders + walk-in sales</span>
                        </div>
                        <div class="kpi">
                            <span class="kpi-label">Deliveries</span>
                            <strong class="kpi-value cx-num"><?php echo number_format($statDeliveries['delivered']); ?></strong>
                            <span class="kpi-sub">delivered in this period</span>
                        </div>
                        <div class="kpi">
                            <span class="kpi-label">Orders</span>
                            <strong class="kpi-value cx-num"><?php echo number_format((int)$cnt['orders']); ?></strong>
                            <span class="kpi-sub">online, all-time</span>
                        </div>
                    </div>
                </section>

                <a class="dash-cta no-print" href="manage_users.php?create=1">
                    <span class="dash-cta-icon" aria-hidden="true"><i class="ph ph-user-plus"></i></span>
                    <span class="dash-cta-text">
                        <strong>Create a staff account</strong>
                        <span>Add a cashier, retail officer or custodian</span>
                    </span>
                    <i class="ph ph-arrow-right dash-cta-arrow" aria-hidden="true"></i>
                </a>
            </div>

            <div class="adm-grid">
                <section class="cx-card" aria-labelledby="restockTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="restockTitle"><i class="ph ph-package" aria-hidden="true"></i> Restocking alerts</h2>
                            <p>
                                At or below the low-stock threshold (15), lowest stock first<?php if ($restockTotal > $restockShown): ?>
                                <span class="no-print">· showing <?php echo $restockShown; ?> of <?php echo (int)$restockTotal; ?></span><span class="cx-print-only">· all <?php echo (int)$restockTotal; ?> products</span><?php endif; ?>
                            </p>
                        </div>
                        <a href="admin_trends.php?view=demand" class="cx-link no-print">Product trends <i class="ph ph-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <?php if (empty($restockAlerts)): ?>
                        <div class="cx-empty">
                            <span class="cx-empty-icon is-ok" aria-hidden="true"><i class="ph ph-check-circle"></i></span>
                            <strong>All products are well stocked</strong>
                            <span>Nothing is at or below the low-stock threshold.</span>
                        </div>
                    <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table restock-table">
                            <thead><tr>
                                <th scope="col">Product</th>
                                <th scope="col" class="col-stock">Stock</th>
                                <th scope="col" class="col-status">Status</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($restockAlerts as $n => $item):
                                $stock = (int)$item['stock'];
                                $status = inv_stock_status_label($stock);
                                $pct = max(0, min(100, (int)round($stock / 15 * 100)));
                            ?>
                                <tr class="is-<?php echo h($status); ?><?php echo $n >= $restockShown ? ' cx-print-row' : ''; ?>">
                                    <td data-label="Product">
                                        <span class="stocks-pname"><?php echo h((string)$item['name']); ?></span>
                                        <span class="stocks-pcat"><?php echo h((string)$item['category']); ?></span>
                                    </td>
                                    <td data-label="Stock">
                                        <div class="qty-cell">
                                            <span class="stocks-qty<?php echo $status === 'out' ? ' out' : ' warn'; ?>"><?php echo number_format($stock); ?></span>
                                            <span class="qty-meter <?php echo h($status); ?>" aria-hidden="true"><i style="width:<?php echo $pct; ?>%"></i></span>
                                        </div>
                                    </td>
                                    <td data-label="Status">
                                        <span class="stock-status-pill <?php echo h($status); ?>"><i class="ph-fill <?php echo h($statusIcons[$status] ?? 'ph-warning'); ?>" aria-hidden="true"></i> <?php echo h($statusLabels[$status] ?? ucfirst($status)); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>

                <section class="cx-card" aria-labelledby="peopleTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="peopleTitle"><i class="ph ph-users-three" aria-hidden="true"></i> Accounts</h2>
                            <p>Registered users by role</p>
                        </div>
                        <a href="manage_users.php" class="cx-link no-print">Manage users <i class="ph ph-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <ul class="people-list">
                        <?php foreach ($people as $p): ?>
                        <li>
                            <a class="people-row" href="manage_users.php?tab=<?php echo h($p['tab']); ?>">
                                <span class="people-icon role-<?php echo h($p['tab']); ?>" aria-hidden="true"><i class="ph <?php echo h($p['icon']); ?>"></i></span>
                                <span class="people-text">
                                    <span class="people-label"><?php echo h($p['label']); ?></span>
                                    <span class="people-hint"><?php echo h($p['hint']); ?></span>
                                </span>
                                <strong class="people-count cx-num"><?php echo number_format($p['count']); ?></strong>
                                <i class="ph ph-caret-right people-go" aria-hidden="true"></i>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            </div>
        </div>

<?php
staff_page_end();
