<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];

$orderStatusOptions = [
    'to_pay' => 'To Pay',
    'to_ship' => 'To Ship',
    'to_receive' => 'With Courier',
    'to_review' => 'Completed',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $oid = (int)($_POST['order_id'] ?? 0);
    $status = $_POST['order_status'] ?? '';
    $allowed = array_keys($orderStatusOptions);
    $returnStatus = trim((string)($_POST['return_status'] ?? ''));
    $returnPage = max(1, (int)($_POST['return_page'] ?? 1));
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
        $redirectParams = ['alert' => 'updated', 'page' => $returnPage];
        if ($returnStatus !== '' && isset($orderStatusOptions[$returnStatus])) {
            $redirectParams['status'] = $returnStatus;
        }
        header('Location: inventory_orders.php?' . http_build_query($redirectParams));
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
$allRows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);

$selectedStatus = trim((string)($_GET['status'] ?? ''));
if ($selectedStatus !== '' && !isset($orderStatusOptions[$selectedStatus])) {
    $selectedStatus = '';
}

$filteredRows = $allRows;
if ($selectedStatus !== '') {
    $filteredRows = array_values(array_filter($allRows, static function ($row) use ($selectedStatus) {
        return ($row['status'] ?? '') === $selectedStatus;
    }));
}
$filteredCount = count($filteredRows);

$perPage = 20;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($filteredCount / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$pagedRows = array_slice($filteredRows, ($currentPage - 1) * $perPage, $perPage);

function inv_orders_page_url(int $page, string $status = ''): string
{
    $params = ['page' => $page];
    if ($status !== '') {
        $params['status'] = $status;
    }
    return 'inventory_orders.php?' . http_build_query($params);
}

/* Weekly total deliveries from shipments.created_at (last 8 weeks, Mon–Sun). */
$weekLabels = [];
$weekCounts = [];
$weekMap = [];
$weekStart = new DateTime('monday this week');
$weekStart->modify('-7 weeks');
for ($i = 0; $i < 8; $i++) {
    $ws = clone $weekStart;
    $ws->modify('+' . $i . ' weeks');
    $key = $ws->format('Y-m-d');
    $weekMap[$key] = 0;
    $weekLabels[] = $ws->format('M d');
}
$rangeStart = array_key_first($weekMap);
$weeklySql = "SELECT DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY)) AS week_start, COUNT(*) AS cnt
              FROM shipments
              WHERE created_at >= ?
              GROUP BY week_start
              ORDER BY week_start";
$weeklyStmt = $db->prepare($weeklySql);
$weeklyStmt->bind_param('s', $rangeStart);
$weeklyStmt->execute();
$weeklyRes = $weeklyStmt->get_result();
while ($w = $weeklyRes->fetch_assoc()) {
    $key = substr((string)($w['week_start'] ?? ''), 0, 10);
    if ($key !== '' && isset($weekMap[$key])) {
        $weekMap[$key] = (int)$w['cnt'];
    }
}
$weeklyStmt->close();
$weekCounts = array_values($weekMap);
$weekLabelsJson = json_encode($weekLabels);
$weekCountsJson = json_encode($weekCounts);

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Orders',
    'active' => 'orders',
    'heading' => 'Orders',
    'subtitle' => 'Customer orders and status updates',
    'extra_head' => <<<'EXTRA'
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
.orders-page { display: flex; flex-direction: column; gap: 22px; }

.orders-chart-panel {
    background: var(--card-bg, #fff);
    border-radius: 16px;
    border: 1px solid var(--ep-border, var(--border));
    box-shadow: var(--card-shadow);
    overflow: hidden;
}
.orders-chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 18px 24px;
    border-bottom: 1px solid var(--ep-border, var(--border));
    background: linear-gradient(to bottom, #fff 0%, var(--slate-50, #f8fafc) 100%);
}
.orders-chart-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--ep-text, var(--text-main));
    display: flex;
    align-items: center;
    gap: 10px;
}
.orders-chart-header .card-icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
}
.orders-chart-meta {
    font-size: 12px;
    color: var(--ep-muted, var(--text-muted));
    font-weight: 500;
}
.orders-chart-body { padding: 16px 22px 22px; }
.orders-chart-canvas-wrap {
    position: relative;
    height: 240px;
    width: 100%;
}

.orders-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}
.orders-status-select {
    min-width: 180px;
    height: 40px;
    border-radius: 10px !important;
    border: 1px solid var(--ep-border);
    font-weight: 600;
    font-size: 13px;
    background: #fff;
}
.price-tag { color: var(--ep-green-dark); font-weight: 800; }
.customer-cell div { line-height: 1.35; }
.action-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.orders-table th, .orders-table td { vertical-align: middle; }
.orders-table tbody tr { transition: background 0.15s ease; }
.orders-table tbody tr:hover { background: rgba(238, 248, 230, 0.7); }
.orders-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--ep-muted, var(--text-muted));
    font-weight: 500;
}
.orders-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
    padding: 18px 20px 8px;
    border-top: 1px solid var(--ep-border, var(--border));
    margin-top: 8px;
}
.orders-page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 12px;
    border-radius: 10px;
    border: 1px solid var(--ep-border, var(--border));
    background: #fff;
    color: var(--ep-text, var(--text-main));
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
}
.orders-page-link:hover:not(.disabled):not(.active) {
    border-color: var(--ep-green);
    color: var(--ep-green-dark);
    background: var(--ep-green-light);
}
.orders-page-link.active {
    background: var(--ep-green);
    border-color: var(--ep-green);
    color: #fff;
    box-shadow: 0 4px 12px rgba(75, 139, 42, 0.28);
}
.orders-page-link.disabled {
    opacity: 0.45;
    pointer-events: none;
}
.orders-page-summary {
    width: 100%;
    text-align: center;
    font-size: 12px;
    color: var(--ep-muted, var(--text-muted));
    font-weight: 500;
    margin-top: 6px;
}
.orders-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 600;
    background: var(--ep-green-light);
    color: var(--ep-green-dark);
    border: 1px solid var(--teal-light, #c6e6b3);
    margin-right: 8px;
}
@media (max-width: 640px) {
    .orders-chart-canvas-wrap { height: 200px; }
    .orders-status-select { width: 100%; min-width: 0; }
}
</style>
EXTRA
]);
?>

        <div class="orders-page">

            <section class="orders-chart-panel" aria-label="Weekly total deliveries">
                <div class="orders-chart-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-chart-line"></i></span> Weekly Total Deliveries</h3>
                        <div class="orders-chart-meta">Shipment totals by week for the last 8 weeks</div>
                    </div>
                </div>
                <div class="orders-chart-body">
                    <div class="orders-chart-canvas-wrap">
                        <canvas id="weeklyDeliveriesChart" aria-label="Weekly total deliveries chart"></canvas>
                    </div>
                </div>
            </section>

            <div class="card">
                <div class="card-header orders-card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-shopping-cart"></i></span> Customer Orders</h3>
                        <div class="card-subtitle">
                            <?php if ($selectedStatus !== ''): ?>
                                Filtered by <?php echo h($orderStatusOptions[$selectedStatus]); ?>
                            <?php else: ?>
                                All customer orders
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span class="orders-count-badge"><i class="fas fa-list"></i> <?php echo (int)$filteredCount; ?> order<?php echo $filteredCount === 1 ? '' : 's'; ?></span>
                        <form method="get" action="inventory_orders.php" id="statusFilterForm" style="margin:0;">
                            <label for="statusFilter" class="sr-only" style="position:absolute;left:-9999px;">Status</label>
                            <select name="status" id="statusFilter" class="form-control orders-status-select" aria-label="Filter by status" onchange="this.form.submit()">
                                <option value="">All Statuses</option>
                                <?php foreach ($orderStatusOptions as $val => $lbl): ?>
                                <option value="<?php echo h($val); ?>"<?php echo $selectedStatus === $val ? ' selected' : ''; ?>><?php echo h($lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
                <div class="card-body" style="padding-top:0;">
                    <div class="table-wrap">
                        <table class="ias-table orders-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Product</th>
                                    <th>Total</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagedRows as $r):
                                    $ost = $r['status'] ?? '';
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
                                    <td class="text-muted text-small"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></td>
                                    <td>
                                        <div class="action-row">
                                            <a href="inventory_details.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-outline btn-sm">View</a>
                                            <form method="post" style="display:flex;gap:6px;margin:0;align-items:center;flex-wrap:wrap;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="order_id" value="<?php echo (int)$r['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                <input type="hidden" name="return_status" value="<?php echo h($selectedStatus); ?>">
                                                <input type="hidden" name="return_page" value="<?php echo (int)$currentPage; ?>">
                                                <select name="order_status" class="form-control" style="width:auto;min-width:130px;" aria-label="Update order status">
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
                                <?php if (empty($pagedRows)): ?>
                                <tr><td colspan="6" class="orders-empty">No orders found<?php echo $selectedStatus !== '' ? ' for this status' : ' yet'; ?>.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($filteredCount > 0): ?>
                    <nav class="orders-pagination" aria-label="Orders pagination">
                        <?php
                        $prevPage = max(1, $currentPage - 1);
                        $nextPage = min($totalPages, $currentPage + 1);
                        ?>
                        <a href="<?php echo h(inv_orders_page_url($prevPage, $selectedStatus)); ?>"
                           class="orders-page-link<?php echo $currentPage <= 1 ? ' disabled' : ''; ?>"
                           aria-disabled="<?php echo $currentPage <= 1 ? 'true' : 'false'; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="<?php echo h(inv_orders_page_url($p, $selectedStatus)); ?>"
                               class="orders-page-link<?php echo $p === $currentPage ? ' active' : ''; ?>"
                               <?php echo $p === $currentPage ? 'aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                        <?php endfor; ?>
                        <a href="<?php echo h(inv_orders_page_url($nextPage, $selectedStatus)); ?>"
                           class="orders-page-link<?php echo $currentPage >= $totalPages ? ' disabled' : ''; ?>"
                           aria-disabled="<?php echo $currentPage >= $totalPages ? 'true' : 'false'; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                        <div class="orders-page-summary">
                            Showing <?php echo $filteredCount === 0 ? 0 : (($currentPage - 1) * $perPage + 1); ?>–<?php echo min($currentPage * $perPage, $filteredCount); ?> of <?php echo $filteredCount; ?> orders
                            (<?php echo (int)$perPage; ?> per page)
                        </div>
                    </nav>
                    <?php endif; ?>
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

$chartScript = <<<SCRIPT
<script>
(function () {
    var canvas = document.getElementById('weeklyDeliveriesChart');
    if (!canvas || typeof Chart === 'undefined') return;
    var labels = {$weekLabelsJson};
    var values = {$weekCountsJson};
    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Deliveries',
                data: values,
                backgroundColor: 'rgba(75, 139, 42, 0.8)',
                borderColor: 'rgba(75, 139, 42, 1)',
                borderWidth: 1,
                borderRadius: 6,
                maxBarThickness: 48
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function (items) {
                            return items.length ? 'Week of ' + items[0].label : '';
                        },
                        label: function (ctx) {
                            var n = ctx.parsed.y;
                            return n + ' deliver' + (n === 1 ? 'y' : 'ies');
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11, weight: '600' }, color: '#4b5563' }
                },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, font: { size: 11 }, color: '#6b7280' },
                    grid: { color: 'rgba(0,0,0,0.06)' }
                }
            }
        }
    });
})();
</script>
SCRIPT;

staff_page_end($flash . $chartScript);
?>
