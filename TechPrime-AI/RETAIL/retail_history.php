<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/retail_reports.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

$retailId = (int)$_SESSION['user_id'];

$range = ias_resolve_report_range($_GET);
$from = $range['from'];
$to = $range['to'];
$customerFilter = trim($_GET['customer'] ?? '');
$productFilter = trim($_GET['product'] ?? '');

$filters = ['customer' => $customerFilter, 'product' => $productFilter];
$rows = ias_fetch_delivery_rows($db, $retailId, $from, $to, $filters);
$byCategory = ias_deliveries_by_category($rows);
$stats = ias_summarize_deliveries($db, $retailId, $from, $to, count($rows));

$presets = ias_report_date_presets();

function ias_qs2(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return h(http_build_query($params));
}

logActivity($db, $retailId, 'view_history', 'Retail Officer viewed delivery history');

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'History',
    'active' => 'history',
    'heading' => 'Delivery History',
    'subtitle' => 'Completed deliveries only',
    'extra_head' => <<<'EXTRA'
<style>
.report-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: flex-end; margin-bottom: 4px; }
.print-header { display: none; }
@media print {
    .sidebar, .topbar, .no-print, .report-toolbar { display: none !important; }
    .main { margin: 0 !important; }
    .page-content { padding: 0 !important; }
    .print-header {
        display: flex !important; align-items: center; gap: 14px;
        border-bottom: 2px solid #171717; padding-bottom: 12px; margin-bottom: 18px;
    }
    .print-header img { height: 48px; }
    .print-header h1 { font-size: 20px; margin: 0; }
    .print-header p { margin: 2px 0 0; font-size: 12px; color: #555; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; break-inside: avoid; }
}
</style>
EXTRA
]);

$logoPath = staff_logo_href();
?>

<div class="print-header">
    <img src="<?php echo h($logoPath); ?>" alt="EasyPC">
    <div>
        <h1>Delivery History</h1>
        <p><?php echo h($range['label']); ?> &middot; <?php echo h($from->format('M d, Y') . ' - ' . $to->format('M d, Y')); ?></p>
        <p>Prepared by <?php echo h($_SESSION['name'] ?? 'Retail Officer'); ?> on <?php echo h(date('M d, Y g:i A')); ?></p>
    </div>
</div>

        <div class="card no-print">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-filter"></i></span> Filters</h3>
                    <div class="card-subtitle">Choose a date range and refine the list</div>
                </div>
            </div>
            <div class="card-body">
                <form method="get" id="filterForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Date Range</label>
                            <select name="preset" class="staff-select" id="presetSelect" onchange="toggleCustomDates(this.value)">
                                <?php foreach ($presets as $key => $p): ?>
                                <option value="<?php echo h($key); ?>" <?php echo $range['preset'] === $key ? 'selected' : ''; ?>><?php echo h($p['label']); ?></option>
                                <?php endforeach; ?>
                                <option value="custom" <?php echo $range['preset'] === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Product</label>
                            <input type="text" name="product" class="staff-input" placeholder="Search product..." value="<?php echo h($productFilter); ?>">
                        </div>
                    </div>
                    <div class="form-row" id="customDateRow" style="<?php echo $range['preset'] === 'custom' ? '' : 'display:none;'; ?>">
                        <div class="form-group">
                            <label class="form-label">From</label>
                            <input type="date" name="date_from" class="staff-input" value="<?php echo h($from->format('Y-m-d')); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">To</label>
                            <input type="date" name="date_to" class="staff-input" value="<?php echo h($to->format('Y-m-d')); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Customer (name or email)</label>
                            <input type="text" name="customer" class="staff-input" placeholder="Search customer..." value="<?php echo h($customerFilter); ?>">
                        </div>
                        <div class="form-group" style="display:flex; align-items:flex-end; gap:10px;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply Filters</button>
                            <a href="retail_history.php" class="btn btn-outline">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="report-toolbar no-print">
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_qs2(['type' => 'delivery', 'format' => 'csv']); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
            <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_qs2(['type' => 'delivery', 'format' => 'excel']); ?>"><i class="fas fa-file-excel"></i> Export Excel</a>
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-file-pdf"></i> Export PDF</button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Deliveries</div>
                <div class="stat-num"><?php echo number_format($stats['delivered']); ?></div>
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Shipments in Range</div>
                <div class="stat-num"><?php echo number_format($stats['total_shipments_in_range']); ?></div>
                <div class="stat-icon"><i class="fas fa-box"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Success Rate</div>
                <div class="stat-num"><?php echo number_format($stats['success_rate'], 1); ?>%</div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-chart-bar"></i></span> Deliveries by Category</h3>
                    <div class="card-subtitle">Completed deliveries in this range</div>
                </div>
                <button type="button" class="btn btn-outline btn-xs no-print" onclick="downloadChart('catChart','deliveries_by_category')"><i class="fas fa-download"></i> PNG</button>
            </div>
            <div class="card-body">
                <div class="chart-wrap"><canvas id="catChart"></canvas></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-history"></i></span> Completed Deliveries</h3>
                    <div class="card-subtitle">Finished shipments for your products &mdash; click a row for details</div>
                </div>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="table-wrap">
                    <table class="ias-table">
                        <thead>
                            <tr>
                                <th></th>
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
                            <?php foreach ($rows as $i => $r): ?>
                            <tr class="drill-toggle" onclick="toggleDrill(<?php echo (int)$i; ?>)" style="cursor:pointer;">
                                <td><i class="fas fa-caret-right" id="caret-<?php echo (int)$i; ?>"></i></td>
                                <td><strong>#<?php echo (int)$r['shipment_id']; ?></strong></td>
                                <td>#<?php echo (int)$r['order_id']; ?></td>
                                <td><?php echo h(trim($r['customer_name'] . ' ' . $r['customer_surname'])); ?></td>
                                <td><?php echo h($r['carrier']); ?></td>
                                <td>PHP <?php echo number_format((float)$r['total'], 2); ?></td>
                                <td class="text-muted text-small"><?php echo h($r['updated_at']); ?></td>
                                <td><span class="badge badge-active">Delivered</span></td>
                            </tr>
                            <tr id="drill-<?php echo (int)$i; ?>" style="display:none;">
                                <td colspan="8" style="background:#fafbfa;">
                                    <div style="padding:10px 16px;">
                                        <div class="text-small"><strong>Customer email:</strong> <?php echo h($r['customer_email']); ?></div>
                                        <div class="text-small"><strong>Products:</strong> <?php echo h($r['products']); ?></div>
                                        <div class="text-small"><strong>Category:</strong> <?php echo h($r['categories']); ?></div>
                                        <div class="text-small"><strong>Order placed:</strong> <?php echo h($r['order_created']); ?></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="empty-state">No completed deliveries in this range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php
$catLabels = json_encode(array_column($byCategory, 'category'));
$catData = json_encode(array_column($byCategory, 'deliveries'));
staff_page_end(<<<SCRIPTS
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function toggleCustomDates(val) {
    document.getElementById('customDateRow').style.display = (val === 'custom') ? '' : 'none';
}
function toggleDrill(i) {
    const row = document.getElementById('drill-' + i);
    const caret = document.getElementById('caret-' + i);
    const open = row.style.display !== 'none';
    row.style.display = open ? 'none' : '';
    caret.classList.toggle('fa-caret-right', open);
    caret.classList.toggle('fa-caret-down', !open);
}
function downloadChart(canvasId, name) {
    const canvas = document.getElementById(canvasId);
    const link = document.createElement('a');
    link.download = name + '.png';
    link.href = canvas.toDataURL('image/png');
    link.click();
}
const catCtx = document.getElementById('catChart').getContext('2d');
new Chart(catCtx, {
    type: 'bar',
    data: {
        labels: {$catLabels},
        datasets: [{ label: 'Deliveries', data: {$catData}, backgroundColor: '#61b337', borderRadius: 6 }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
</script>
SCRIPTS);
?>
