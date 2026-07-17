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

$range = ias_resolve_report_range($_GET);
$from = $range['from'];
$to = $range['to'];
$compare = $_GET['compare'] ?? 'none'; // none | previous | yoy
$categoryFilter = trim($_GET['category'] ?? '');
$customerFilter = trim($_GET['customer'] ?? '');

$filters = ['category' => $categoryFilter, 'customer' => $customerFilter];
$rows = ias_fetch_sales_rows($db, $retailId, $from, $to, $filters);
$summary = ias_summarize_sales($rows);
$byCategory = ias_sales_by_category($rows);
$trend = ias_sales_daily_trend($rows, $from, $to);
$orders = ias_group_sales_by_order($rows);

$compareSummary = null;
$compareLabel = '';
if ($compare === 'previous') {
    $p = ias_previous_period($from, $to);
    $compareRows = ias_fetch_sales_rows($db, $retailId, $p['from'], $p['to'], $filters);
    $compareSummary = ias_summarize_sales($compareRows);
    $compareLabel = 'vs previous period (' . $p['from']->format('M d') . '–' . $p['to']->format('M d, Y') . ')';
} elseif ($compare === 'yoy') {
    $p = ias_year_ago_period($from, $to);
    $compareRows = ias_fetch_sales_rows($db, $retailId, $p['from'], $p['to'], $filters);
    $compareSummary = ias_summarize_sales($compareRows);
    $compareLabel = 'vs same period last year (' . $p['from']->format('M d, Y') . '–' . $p['to']->format('M d, Y') . ')';
}

logActivity($db, $retailId, 'view_sales_report', 'Retail Officer viewed sales report');

$presets = ias_report_date_presets();
$categories = ias_product_categories();

// Build query string helper so filter/sort links preserve current state
function ias_qs(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return h(http_build_query($params));
}

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Sales Report',
    'active' => 'reports',
    'heading' => 'Sales Report',
    'subtitle' => 'Filter, analyze, and export your sales performance',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>' . <<<'EXTRA'
<style>
.report-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: flex-end; margin-bottom: 4px; }
.pct-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11.5px; font-weight: 700; margin-left: 8px; }
.pct-up { background: #f0fdf4; color: #16a34a; }
.pct-down { background: #fef2f2; color: #dc2626; }
.pct-flat { background: #f1f5f9; color: #64748b; }
.drill-toggle { cursor: pointer; color: var(--ep-green-dark); font-weight: 700; }
.drill-row td { background: #fafbfa; padding: 0 !important; }
.drill-row .drill-inner { padding: 12px 18px; }
.drill-row table { width: 100%; font-size: 12.5px; }
.drill-row table td, .drill-row table th { padding: 6px 8px; }
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
        <h1>Sales Report</h1>
        <p><?php echo h($range['label']); ?> &middot; <?php echo h($from->format('M d, Y') . ' - ' . $to->format('M d, Y')); ?></p>
        <p>Prepared by <?php echo h($_SESSION['name'] ?? 'Retail Officer'); ?> on <?php echo h(date('M d, Y g:i A')); ?></p>
    </div>
</div>

        <div class="card no-print">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-filter"></i></span> Filters</h3>
                    <div class="card-subtitle">Choose a date range and refine your report</div>
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
                            <label class="form-label">Compare</label>
                            <select name="compare" class="staff-select">
                                <option value="none" <?php echo $compare === 'none' ? 'selected' : ''; ?>>No Comparison</option>
                                <option value="previous" <?php echo $compare === 'previous' ? 'selected' : ''; ?>>Previous Period (MoM/PoP)</option>
                                <option value="yoy" <?php echo $compare === 'yoy' ? 'selected' : ''; ?>>Year-over-Year (YoY)</option>
                            </select>
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
                            <label class="form-label">Category</label>
                            <select name="category" class="staff-select">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?php echo h($c); ?>" <?php echo $categoryFilter === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Customer (name or email)</label>
                            <input type="text" name="customer" class="staff-input" placeholder="Search customer..." value="<?php echo h($customerFilter); ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply Filters</button>
                    <a href="retail_reports.php" class="btn btn-outline">Reset</a>
                </form>
            </div>
        </div>

        <div class="report-toolbar no-print">
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_qs(['type' => 'sales', 'format' => 'csv']); ?>"><i class="fas fa-file-csv"></i> Export CSV</a>
            <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_qs(['type' => 'sales', 'format' => 'excel']); ?>"><i class="fas fa-file-excel"></i> Export Excel</a>
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-file-pdf"></i> Export PDF</button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Sales</div>
                <div class="stat-num">₱<?php echo number_format($summary['total_sales'], 2); ?></div>
                <div class="stat-icon"><i class="fas fa-peso-sign"></i></div>
                <?php if ($compareSummary !== null): ?>
                    <?php echo ias_render_pct_badge(ias_pct_change($summary['total_sales'], $compareSummary['total_sales'])); ?>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg. Transaction Value</div>
                <div class="stat-num">₱<?php echo number_format($summary['avg_transaction'], 2); ?></div>
                <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                <?php if ($compareSummary !== null): ?>
                    <?php echo ias_render_pct_badge(ias_pct_change($summary['avg_transaction'], $compareSummary['avg_transaction'])); ?>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Units Sold</div>
                <div class="stat-num"><?php echo number_format($summary['units_sold']); ?></div>
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <?php if ($compareSummary !== null): ?>
                    <?php echo ias_render_pct_badge(ias_pct_change($summary['units_sold'], $compareSummary['units_sold'])); ?>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Transactions</div>
                <div class="stat-num"><?php echo number_format($summary['transactions']); ?></div>
                <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                <?php if ($compareSummary !== null): ?>
                    <?php echo ias_render_pct_badge(ias_pct_change($summary['transactions'], $compareSummary['transactions'])); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($compareSummary !== null): ?>
        <p class="text-muted text-small" style="margin-top:-10px;"><?php echo h($compareLabel); ?></p>
        <?php endif; ?>

        <div class="dash-grid">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-chart-line"></i></span> Sales Trend</h3>
                        <div class="card-subtitle"><?php echo h($range['label']); ?></div>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs no-print" onclick="downloadChart('trendChart','sales_trend')"><i class="fas fa-download"></i> PNG</button>
                </div>
                <div class="card-body">
                    <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-chart-pie"></i></span> By Category</h3>
                        <div class="card-subtitle">Share of sales</div>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs no-print" onclick="downloadChart('categoryChart','sales_by_category')"><i class="fas fa-download"></i> PNG</button>
                </div>
                <div class="card-body">
                    <div class="chart-wrap"><canvas id="categoryChart"></canvas></div>
                </div>
            </div>
        </div>

<?php
$trendLabels = json_encode(array_map(fn($d) => date('M d', strtotime($d)), array_keys($trend)));
$trendData = json_encode(array_values($trend));
$catLabels = json_encode(array_column($byCategory, 'category'));
$catData = json_encode(array_column($byCategory, 'sales'));

staff_page_end(<<<SCRIPTS
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

const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: {$trendLabels},
        datasets: [{
            label: 'Sales (PHP)',
            data: {$trendData},
            borderColor: '#61b337',
            backgroundColor: 'rgba(97, 179, 55, 0.12)',
            fill: true, tension: 0.35, pointRadius: 3, borderWidth: 3
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } },
            x: { grid: { display: false } }
        }
    }
});

const catCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(catCtx, {
    type: 'bar',
    data: {
        labels: {$catLabels},
        datasets: [{
            label: 'Sales (PHP)',
            data: {$catData},
            backgroundColor: '#61b337',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } }
    }
});
</script>
SCRIPTS);
?>
