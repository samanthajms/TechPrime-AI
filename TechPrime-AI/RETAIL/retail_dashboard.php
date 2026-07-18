<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/product_categories.php';
require_once __DIR__ . '/../includes/retail_reports.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

$retailId = (int)$_SESSION['user_id'];
$presets = ias_report_date_presets();
$categories = ias_product_categories();

function retail_dashboard_preserve_hidden(array $ownKeys): void
{
    foreach ($_GET as $k => $v) {
        if (in_array($k, $ownKeys, true) || is_array($v)) {
            continue;
        }
        echo '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
}

$cardRange = ias_resolve_section_range($_GET, 'card', 'this_month');
$cardRows = ias_fetch_sales_rows($db, $retailId, $cardRange['from'], $cardRange['to']);
$cardSummary = ias_summarize_sales($cardRows);

$msMonths = max(1, min(24, (int)($_GET['ms_months'] ?? 12)));
$monthlySales = ias_monthly_sales_report($db, $retailId, $msMonths);

$dfRange = ias_resolve_section_range($_GET, 'df', 'this_month');
$dfCategory = trim($_GET['df_category'] ?? '');
$dfFilters = $dfCategory !== '' ? ['category' => $dfCategory] : [];
$dfDays = max(7, min(90, (int)($_GET['df_days'] ?? 30)));
$demandForecast = ias_product_demand_forecast($db, $retailId, $dfRange['from'], $dfRange['to'], $dfDays, $dfFilters);

$sfRange = ias_resolve_section_range($_GET, 'sf', 'this_month');
$sfDays = max(7, min(60, (int)($_GET['sf_days'] ?? 14)));
$salesForecast = ias_sales_forecast($db, $retailId, $sfRange['from'], $sfRange['to'], $sfDays);

logActivity($db, $retailId, 'view_dashboard', 'Retail Officer viewed dashboard');

$dashboardCss = <<<'CSS'
.report-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:flex-end; margin-bottom:12px; }
.card-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.filter-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:3000; align-items:center; justify-content:center; padding:20px; }
.filter-modal.open { display:flex; }
.filter-modal-card { background:#fff; border-radius:12px; padding:22px; width:min(460px,100%); max-height:90vh; overflow-y:auto; border:2px solid var(--ep-green); }
.filter-modal-card h4 { margin:0 0 14px; color:var(--ep-green-dark); }
.detail-panel { display:none; margin-top:14px; }
.detail-panel.open { display:block; }
.print-header { display:none; }
@media print {
    .sidebar, .topbar, .no-print, .report-toolbar, .filter-modal { display:none !important; }
    .main { margin:0 !important; }
    .page-content { padding:0 !important; }
    .detail-panel { display:block !important; }
    .print-header { display:flex !important; align-items:center; gap:14px; border-bottom:2px solid #171717; padding-bottom:12px; margin-bottom:18px; }
    .print-header img { height:48px; }
}
CSS;

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Retail Dashboard',
    'active' => 'dashboard',
    'heading' => 'Retail Dashboard',
    'subtitle' => 'Real-time sales analytics and forecasting',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><style>' . $dashboardCss . '</style>',
]);

$logoPath = staff_logo_href();
?>

<div class="print-header">
    <img src="<?php echo h($logoPath); ?>" alt="EasyPC">
    <div>
        <h1>Retail Dashboard</h1>
        <p>Prepared by <?php echo h($_SESSION['name'] ?? 'Retail Officer'); ?> on <?php echo h(date('M d, Y g:i A')); ?></p>
    </div>
</div>

<div class="report-toolbar no-print">
    <button type="button" class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_dashboard_qs(['type' => 'sales', 'format' => 'csv']); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
    <button type="button" class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-file-pdf"></i> Export PDF</button>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Sales (<?php echo h($cardRange['label']); ?>)</div>
        <div class="stat-num">₱<?php echo number_format($cardSummary['total_sales'], 2); ?></div>
        <div class="stat-icon"><i class="fas fa-peso-sign"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Transactions</div>
        <div class="stat-num"><?php echo number_format($cardSummary['transactions']); ?></div>
        <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Units Sold</div>
        <div class="stat-num"><?php echo number_format($cardSummary['units_sold']); ?></div>
        <div class="stat-icon"><i class="fas fa-boxes"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg. Transaction</div>
        <div class="stat-num">₱<?php echo number_format($cardSummary['avg_transaction'], 2); ?></div>
        <div class="stat-icon"><i class="fas fa-receipt"></i></div>
    </div>
</div>
<p class="text-muted text-small no-print" style="margin:-6px 0 18px;">Live totals from order line items · <?php echo h($cardRange['from']->format('M d, Y') . ' – ' . $cardRange['to']->format('M d, Y')); ?></p>

<div class="card">
    <div class="card-header">
        <div>
            <h3><span class="card-icon"><i class="fas fa-chart-bar"></i></span> Monthly Sales Report</h3>
            <div class="card-subtitle">Revenue by month from order details</div>
        </div>
        <div class="card-actions no-print">
            <button type="button" class="btn btn-outline btn-xs" onclick="openFilterModal('msModal')" title="Filter"><i class="fas fa-filter"></i></button>
            <button type="button" class="btn btn-outline btn-xs" onclick="toggleDetail('msDetail')" title="Detailed view"><i class="fas fa-table"></i></button>
        </div>
    </div>
    <div class="card-body" style="padding-top:0;">
        <div class="table-wrap">
            <table class="ias-table">
                <thead>
                    <tr><th>Month</th><th>Total Sales</th><th>Transactions</th><th>Units Sold</th><th>Avg. Transaction</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($monthlySales as $m): ?>
                    <tr>
                        <td><strong><?php echo h($m['month']); ?></strong></td>
                        <td>₱<?php echo number_format($m['total_sales'], 2); ?></td>
                        <td><?php echo number_format($m['transactions']); ?></td>
                        <td><?php echo number_format($m['units_sold']); ?></td>
                        <td>₱<?php echo number_format($m['avg_transaction'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthlySales)): ?>
                    <tr><td colspan="5" class="empty-state">No sales data yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="msDetail" class="detail-panel">
            <h4 style="margin:16px 0 10px;">Detailed Orders by Month</h4>
            <?php foreach ($monthlySales as $m): ?>
                <?php if (empty($m['orders'])) continue; ?>
                <p class="text-small"><strong><?php echo h($m['month']); ?></strong></p>
                <div class="table-wrap" style="margin-bottom:16px;">
                    <table class="ias-table">
                        <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Units</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($m['orders'] as $o): ?>
                            <tr>
                                <td>#<?php echo (int)$o['order_id']; ?></td>
                                <td><?php echo h($o['customer_name']); ?></td>
                                <td><?php echo h(substr($o['created_at'], 0, 10)); ?></td>
                                <td><?php echo (int)$o['units']; ?></td>
                                <td>₱<?php echo number_format($o['total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3><span class="card-icon"><i class="fas fa-box-open"></i></span> Product Demand Forecasting</h3>
            <div class="card-subtitle"><?php echo h($dfRange['label']); ?><?php echo $dfCategory !== '' ? ' · ' . h($dfCategory) : ' · All products'; ?> · next <?php echo (int)$dfDays; ?> days</div>
        </div>
        <div class="card-actions no-print">
            <button type="button" class="btn btn-outline btn-xs" onclick="openFilterModal('dfModal')" title="Filter"><i class="fas fa-filter"></i></button>
            <button type="button" class="btn btn-outline btn-xs" onclick="toggleDetail('dfDetail')" title="Detailed view"><i class="fas fa-table"></i></button>
        </div>
    </div>
    <div class="card-body" style="padding-top:0;">
        <div class="table-wrap">
            <table class="ias-table">
                <thead>
                    <tr><th>Product</th><th>Category</th><th>Units Sold</th><th>Avg / Day</th><th>Forecast Units</th><th>Forecast Revenue</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($demandForecast, 0, 10) as $row): ?>
                    <tr>
                        <td><strong><?php echo h($row['product_name']); ?></strong></td>
                        <td><?php echo h($row['category']); ?></td>
                        <td><?php echo number_format($row['units_sold']); ?></td>
                        <td><?php echo number_format($row['avg_daily_units'], 2); ?></td>
                        <td><?php echo number_format($row['forecast_units']); ?></td>
                        <td>₱<?php echo number_format($row['forecast_revenue'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($demandForecast)): ?>
                    <tr><td colspan="6" class="empty-state">No demand data for this range.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="dfDetail" class="detail-panel">
            <div class="table-wrap">
                <table class="ias-table">
                    <thead>
                        <tr><th>Product</th><th>Category</th><th>Units Sold</th><th>Revenue</th><th>Avg / Day</th><th>Forecast Units</th><th>Forecast Revenue</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($demandForecast as $row): ?>
                        <tr>
                            <td><?php echo h($row['product_name']); ?></td>
                            <td><?php echo h($row['category']); ?></td>
                            <td><?php echo number_format($row['units_sold']); ?></td>
                            <td>₱<?php echo number_format($row['revenue'], 2); ?></td>
                            <td><?php echo number_format($row['avg_daily_units'], 2); ?></td>
                            <td><?php echo number_format($row['forecast_units']); ?></td>
                            <td>₱<?php echo number_format($row['forecast_revenue'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3><span class="card-icon"><i class="fas fa-chart-line"></i></span> Sales Forecasting</h3>
            <div class="card-subtitle"><?php echo h($sfRange['label']); ?> · projected next <?php echo (int)$sfDays; ?> days (avg ₱<?php echo number_format($salesForecast['avg_daily'], 2); ?>/day)</div>
        </div>
        <div class="card-actions no-print">
            <button type="button" class="btn btn-outline btn-xs" onclick="openFilterModal('sfModal')" title="Filter"><i class="fas fa-filter"></i></button>
            <button type="button" class="btn btn-outline btn-xs" onclick="toggleDetail('sfDetail')" title="Detailed view"><i class="fas fa-table"></i></button>
        </div>
    </div>
    <div class="card-body">
        <div class="chart-wrap"><canvas id="sfChart"></canvas></div>
        <div id="sfDetail" class="detail-panel">
            <div class="table-wrap">
                <table class="ias-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Projected Sales (PHP)</th></tr></thead>
                    <tbody>
                    <?php foreach ($salesForecast['historical'] as $day => $val): ?>
                        <tr><td><?php echo h($day); ?></td><td>Actual</td><td>₱<?php echo number_format($val, 2); ?></td></tr>
                    <?php endforeach; ?>
                    <?php foreach ($salesForecast['forecast'] as $day => $val): ?>
                        <tr><td><?php echo h($day); ?></td><td>Forecast</td><td>₱<?php echo number_format($val, 2); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="msModal" class="filter-modal no-print" onclick="if(event.target===this)closeFilterModal('msModal')">
    <div class="filter-modal-card">
        <h4><i class="fas fa-filter"></i> Monthly Sales Filters</h4>
        <form method="get">
            <?php retail_dashboard_preserve_hidden(['ms_months']); ?>
            <div class="form-group">
                <label class="form-label">Months to include</label>
                <select name="ms_months" class="staff-select">
                    <?php foreach ([6, 12, 18, 24] as $n): ?>
                    <option value="<?php echo $n; ?>" <?php echo $msMonths === $n ? 'selected' : ''; ?>><?php echo $n; ?> months</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Apply</button>
            <button type="button" class="btn btn-outline" onclick="closeFilterModal('msModal')">Cancel</button>
        </form>
    </div>
</div>

<div id="dfModal" class="filter-modal no-print" onclick="if(event.target===this)closeFilterModal('dfModal')">
    <div class="filter-modal-card">
        <h4><i class="fas fa-filter"></i> Demand Forecast Filters</h4>
        <form method="get">
            <?php retail_dashboard_preserve_hidden(['df_preset', 'df_from', 'df_to', 'df_category', 'df_days']); ?>
            <div class="form-group">
                <label class="form-label">Date Range</label>
                <select name="df_preset" class="staff-select" onchange="toggleSectionCustom(this,'dfCustom')">
                    <?php foreach ($presets as $key => $p): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $dfRange['preset'] === $key ? 'selected' : ''; ?>><?php echo h($p['label']); ?></option>
                    <?php endforeach; ?>
                    <option value="custom" <?php echo $dfRange['preset'] === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>
            <div id="dfCustom" class="form-row" style="<?php echo $dfRange['preset'] === 'custom' ? '' : 'display:none;'; ?>">
                <div class="form-group"><label class="form-label">From</label><input type="date" name="df_from" class="staff-input" value="<?php echo h($dfRange['from']->format('Y-m-d')); ?>"></div>
                <div class="form-group"><label class="form-label">To</label><input type="date" name="df_to" class="staff-input" value="<?php echo h($dfRange['to']->format('Y-m-d')); ?>"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Category (optional)</label>
                <select name="df_category" class="staff-select">
                    <option value="">All Products (default)</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo h($c); ?>" <?php echo $dfCategory === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Forecast horizon (days)</label>
                <input type="number" name="df_days" class="staff-input" min="7" max="90" value="<?php echo (int)$dfDays; ?>">
            </div>
            <button type="submit" class="btn btn-primary">Apply</button>
            <button type="button" class="btn btn-outline" onclick="closeFilterModal('dfModal')">Cancel</button>
        </form>
    </div>
</div>

<div id="sfModal" class="filter-modal no-print" onclick="if(event.target===this)closeFilterModal('sfModal')">
    <div class="filter-modal-card">
        <h4><i class="fas fa-filter"></i> Sales Forecast Filters</h4>
        <form method="get">
            <?php retail_dashboard_preserve_hidden(['sf_preset', 'sf_from', 'sf_to', 'sf_days']); ?>
            <div class="form-group">
                <label class="form-label">Historical Range</label>
                <select name="sf_preset" class="staff-select" onchange="toggleSectionCustom(this,'sfCustom')">
                    <?php foreach ($presets as $key => $p): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $sfRange['preset'] === $key ? 'selected' : ''; ?>><?php echo h($p['label']); ?></option>
                    <?php endforeach; ?>
                    <option value="custom" <?php echo $sfRange['preset'] === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>
            <div id="sfCustom" class="form-row" style="<?php echo $sfRange['preset'] === 'custom' ? '' : 'display:none;'; ?>">
                <div class="form-group"><label class="form-label">From</label><input type="date" name="sf_from" class="staff-input" value="<?php echo h($sfRange['from']->format('Y-m-d')); ?>"></div>
                <div class="form-group"><label class="form-label">To</label><input type="date" name="sf_to" class="staff-input" value="<?php echo h($sfRange['to']->format('Y-m-d')); ?>"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Forecast days ahead</label>
                <input type="number" name="sf_days" class="staff-input" min="7" max="60" value="<?php echo (int)$sfDays; ?>">
            </div>
            <button type="submit" class="btn btn-primary">Apply</button>
            <button type="button" class="btn btn-outline" onclick="closeFilterModal('sfModal')">Cancel</button>
        </form>
    </div>
</div>

<?php
$histLabels = json_encode(array_map(fn($d) => date('M d', strtotime($d)), array_keys($salesForecast['historical'])));
$histData = json_encode(array_values($salesForecast['historical']));
$fcLabels = json_encode(array_map(fn($d) => date('M d', strtotime($d)), array_keys($salesForecast['forecast'])));
$fcData = json_encode(array_values($salesForecast['forecast']));
$histCount = count($salesForecast['historical']);
$fcCount = count($salesForecast['forecast']);

staff_page_end(<<<SCRIPTS
<script>
function openFilterModal(id){ document.getElementById(id).classList.add('open'); }
function closeFilterModal(id){ document.getElementById(id).classList.remove('open'); }
function toggleDetail(id){ document.getElementById(id).classList.toggle('open'); }
function toggleSectionCustom(sel, rowId){
    document.getElementById(rowId).style.display = (sel.value === 'custom') ? '' : 'none';
}
const sfCtx = document.getElementById('sfChart').getContext('2d');
const histData = {$histData};
const fcData = {$fcData};
new Chart(sfCtx, {
    type: 'line',
    data: {
        labels: {$histLabels}.concat({$fcLabels}),
        datasets: [
            {
                label: 'Actual Sales',
                data: histData.concat(Array({$fcCount}).fill(null)),
                borderColor: '#61b337',
                backgroundColor: 'rgba(97, 179, 55, 0.12)',
                fill: true, tension: 0.35, borderWidth: 3, pointRadius: 3
            },
            {
                label: 'Forecast',
                data: Array({$histCount}).fill(null).concat(fcData),
                borderColor: '#fed700',
                backgroundColor: 'rgba(254, 215, 0, 0.15)',
                borderDash: [6, 4],
                fill: true, tension: 0.35, borderWidth: 3, pointRadius: 3
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString() } },
            x: { grid: { display: false } }
        }
    }
});
</script>
SCRIPTS);
