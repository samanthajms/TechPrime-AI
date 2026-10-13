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

function retail_dashboard_preserve_hidden(array $ownKeys): void
{
    foreach ($_GET as $k => $v) {
        if (in_array($k, $ownKeys, true) || is_array($v)) {
            continue;
        }
        echo '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
}

// Same data source as the CSV/PDF export (retail_export.php?type=dashboard), so the files match the screen.
$dash = ias_retail_dashboard_data($db, $retailId, $_GET);
$cardRange = $dash['card_range'];
$cardSummary = $dash['card_summary'];
$msMonths = $dash['ms_months'];
$monthlySales = $dash['monthly_sales'];

// Sales by Category: same period + category filter as the Inventory dashboard's "Products Stocked Per Month"
$periods = ['month' => 'This Month', 'last' => 'Last Month', '3m' => 'Last 3 Months', 'year' => 'This Year'];
$range = $dash['range'];
$from = $range['from'];
$to = $range['to'];
$categoryTabs = ias_category_groups();
$category = $dash['category'];

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$trendJson = json_encode(['labels' => $dash['trend_labels'], 'data' => $dash['trend_data'], 'total' => array_sum($dash['trend_data'])], $jsonFlags);
$msChartJson = json_encode([
    'labels' => array_column($monthlySales, 'month'),
    'sales' => array_map(fn($m) => round((float)$m['total_sales'], 2), $monthlySales),
    'units' => array_map(fn($m) => (int)$m['units_sold'], $monthlySales),
], $jsonFlags);

logActivity($db, $retailId, 'view_dashboard', 'Retail Officer viewed dashboard');

$dashboardCss = <<<'CSS'
.report-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:flex-end; margin-bottom:12px; }
.card-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.filter-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:3000; align-items:center; justify-content:center; padding:20px; }
.filter-modal.open { display:flex; }
.filter-modal-card { background:#fff; border-radius:12px; padding:22px; width:min(460px,100%); max-height:90vh; overflow-y:auto; border:2px solid var(--ep-green); }
.filter-modal-card h4 { margin:0 0 14px; color:var(--ep-green-dark); }
.ms-view[hidden] { display:none !important; }
.ms-order-group { margin:0 0 10px; }
.ms-order-group summary { cursor:pointer; font-size:13px; padding:6px 0; }
.ms-chart-wrap { position:relative; height:320px; margin-top:10px; }
.view-toggle .btn.active { background:var(--ep-green); border-color:var(--ep-green); color:#fff; }
.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
.inv-stockin-panel { background:linear-gradient(180deg,#ffffff 0%,#f4f9f0 100%); border:1px solid var(--teal-light,#c6e6b3); border-radius:18px; box-shadow:0 10px 28px rgba(75,139,42,.1); overflow:hidden; margin-bottom:18px; }
.inv-stockin-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; padding:22px 24px 16px; background:linear-gradient(135deg,#eef8e6 0%,#ffffff 70%); border-bottom:1px solid rgba(75,139,42,.12); }
.inv-stockin-header h3 { margin:0; font-size:17px; font-weight:800; color:var(--ep-green-dark); display:flex; align-items:center; gap:12px; }
.inv-stockin-meta { margin-top:4px; font-size:12.5px; color:var(--text-muted); font-weight:500; }
.inv-stockin-tools { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.inv-stockin-badge { display:inline-flex; align-items:center; gap:6px; padding:7px 12px; border-radius:999px; background:#fff; border:1px solid var(--teal-light,#c6e6b3); color:var(--ep-green-dark); font-size:12px; font-weight:700; }
.inv-period-select { height:38px; padding:0 12px; border-radius:999px; border:1px solid var(--teal-light,#c6e6b3); background:#fff; font-family:inherit; font-size:12.5px; font-weight:700; color:var(--ep-green-dark); outline:none; cursor:pointer; box-shadow:0 2px 8px rgba(75,139,42,.06); }
.inv-period-select:focus { border-color:var(--ep-green); box-shadow:0 0 0 3px rgba(97,179,55,.15); }
.inv-category-nav { display:flex; align-items:center; gap:8px; padding:14px 18px; border-bottom:1px solid rgba(75,139,42,.1); background:linear-gradient(180deg,#f8fbf6 0%,#ffffff 100%); }
.inv-cat-arrow { flex:0 0 auto; width:38px; height:38px; display:inline-flex; align-items:center; justify-content:center; border-radius:999px; border:1px solid var(--teal-light,#c6e6b3); background:#fff; color:var(--ep-green-dark); cursor:pointer; font-size:13px; transition:background-color .15s ease,border-color .15s ease,box-shadow .15s ease,opacity .15s ease,transform 160ms var(--ease-out); }
.inv-cat-arrow:hover:not(:disabled) { background:var(--ep-green-light); border-color:var(--ep-green); box-shadow:0 4px 12px rgba(75,139,42,.15); }
.inv-cat-arrow:disabled { opacity:.4; cursor:default; }
.inv-category-bar { display:flex; align-items:center; gap:8px; flex:1 1 auto; min-width:0; overflow-x:auto; scrollbar-width:none; padding:2px; }
.inv-category-bar::-webkit-scrollbar { display:none; }
.inv-stockin-panel .card-body { padding:18px 20px 22px; }
.trend-card { border:1px solid var(--ep-border,#e5e7e5); border-radius:12px; padding:14px 16px; margin-bottom:18px; background:#fff; }
.trend-wrap { position:relative; height:260px; margin-top:10px; }
.inv-cat-tab { flex:0 0 auto; display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:8px 16px; border-radius:999px; border:1px solid var(--border); background:#fff; color:var(--text-main); font-size:13px; font-weight:700; line-height:1.3; white-space:nowrap; text-decoration:none; cursor:pointer; font-family:inherit; transition:background-color .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease,transform 160ms var(--ease-out); }
.inv-cat-tab:hover { border-color:var(--ep-green); color:var(--ep-green-dark); background:var(--ep-green-light); }
.inv-cat-tab:active { transform:scale(.97); }
.inv-cat-arrow:active:not(:disabled) { transform:scale(.94); }
@media (prefers-reduced-motion: reduce) { .inv-cat-tab:active, .inv-cat-arrow:active:not(:disabled) { transform:none; } }
.inv-cat-tab.active { background:var(--ep-green); border-color:var(--ep-green); color:#fff; box-shadow:0 6px 16px rgba(75,139,42,.28); }
.inv-cat-tab span { display:block; white-space:nowrap; }
@media (max-width: 640px) { .inv-cat-tab { padding:8px 14px; font-size:12.5px; } }
.pie-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:18px; }
.pie-card { border:1px solid var(--ep-border,#e5e7e5); border-radius:12px; padding:14px; break-inside:avoid; }
.pie-card h4 { margin:0 0 2px; color:var(--ep-green-dark); font-size:15px; }
.pie-wrap { position:relative; height:240px; margin-top:8px; }
.print-only { display:none; }
@media print {
    .filter-modal, .inv-stockin-tools, .inv-category-nav, .view-toggle { display:none !important; }
    .print-only { display:inline !important; }
    /* Print always shows the Monthly Sales table, whichever view is active on screen */
    #msDetailView { display:block !important; }
    #msGraphView { display:none !important; }
    .inv-stockin-panel, .inv-stockin-header { background:#fff !important; }
}
CSS;
$dashboardCss .= ias_retail_print_css();

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Retail Dashboard',
    'active' => 'dashboard',
    'heading' => 'Retail Dashboard',
    'subtitle' => 'Real-time sales analytics',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><style>' . $dashboardCss . '</style>',
]);

$logoPath = staff_logo_href();
?>

<div class="print-header">
    <img src="<?php echo h($logoPath); ?>" alt="EasyPC">
    <div>
        <h1>Retail Dashboard</h1>
        <p>Summary: <?php echo h($cardRange['label']); ?> &middot; Sales by Category: <?php echo h($range['label'] . ($category !== '' ? ' (' . $category . ')' : '')); ?></p>
        <p>Prepared by <?php echo h($_SESSION['name'] ?? 'Retail Officer'); ?> on <?php echo h(ias_report_datetime(gmdate('Y-m-d H:i:s'))); ?></p>
    </div>
</div>

<div class="report-toolbar no-print">
    <button type="button" class="btn btn-outline btn-sm" onclick="window.print()" title="Print the dashboard"><i class="fas fa-print"></i> Print</button>
    <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_dashboard_qs(['type' => 'dashboard', 'format' => 'csv']); ?>" title="Download the summary, monthly sales and sales by category as CSV"><i class="fas fa-file-csv"></i> Export CSV</a>
    <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_dashboard_qs(['type' => 'dashboard', 'format' => 'pdf']); ?>" title="Download the dashboard as a PDF file"><i class="fas fa-file-pdf"></i> Export PDF</a>
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
            <span class="view-toggle" role="group" aria-label="Monthly sales view" style="display:inline-flex; gap:6px;">
                <button type="button" class="btn btn-outline btn-xs active" id="msBtnDetail" onclick="setMsView('detail')" aria-pressed="true"><i class="fas fa-table"></i> Table View</button>
                <button type="button" class="btn btn-outline btn-xs" id="msBtnGraph" onclick="setMsView('graph')" aria-pressed="false"><i class="fas fa-chart-column"></i> Graph View</button>
            </span>
        </div>
    </div>
    <div class="card-body" style="padding-top:0;">
        <div id="msGraphView" class="ms-view" hidden>
            <?php if (empty($monthlySales)): ?>
            <div class="empty-state">No sales data yet.</div>
            <?php else: ?>
            <div class="ms-chart-wrap"><canvas id="msChart"></canvas></div>
            <?php endif; ?>
        </div>
        <div id="msDetailView" class="ms-view">
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
        </div>
    </div>
</div>

<section class="inv-stockin-panel" aria-label="Sales by category">
    <div class="inv-stockin-header">
        <div>
            <h3>Sales by Category</h3>
            <div class="inv-stockin-meta">Units delivered per product, one chart per category group · <?php echo h($from->format('M d, Y') . ' – ' . $to->format('M d, Y')); ?><span class="print-only"> · <?php echo h($category !== '' ? $category : 'All categories'); ?></span></div>
        </div>
        <div class="inv-stockin-tools no-print">
            <form method="get" id="periodForm">
                <?php retail_dashboard_preserve_hidden(['period']); ?>
                <label class="sr-only" for="invPeriodSelect">Time period</label>
                <select id="invPeriodSelect" name="period" class="inv-period-select" aria-label="Sales by category time period" onchange="this.form.submit()">
                    <?php foreach ($periods as $k => $label): ?>
                    <option value="<?php echo h($k); ?>" <?php echo $range['key'] === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <span class="inv-stockin-badge"><i class="fas fa-layer-group"></i> <?php echo h($category !== '' ? $category : 'All categories'); ?></span>
        </div>
    </div>
    <div class="inv-category-nav no-print" aria-label="Product category navigation">
        <button type="button" class="inv-cat-arrow" id="invCatPrev" aria-label="Scroll categories left">
            <i class="fas fa-chevron-left"></i>
        </button>
        <nav class="inv-category-bar" id="invCategoryBar" aria-label="Product categories">
            <a class="inv-cat-tab<?php echo $category === '' ? ' active' : ''; ?>" href="?<?php echo h(http_build_query(array_diff_key($_GET, ['category' => 1]))); ?>"><span>All</span></a>
            <?php foreach ($categoryTabs as $cat): ?>
            <a class="inv-cat-tab<?php echo $category === $cat ? ' active' : ''; ?>" title="<?php echo h($cat); ?>" href="?<?php echo h(http_build_query(array_merge($_GET, ['category' => $cat]))); ?>"><span><?php echo h($cat); ?></span></a>
            <?php endforeach; ?>
        </nav>
        <button type="button" class="inv-cat-arrow" id="invCatNext" aria-label="Scroll categories right">
            <i class="fas fa-chevron-right"></i>
        </button>
    </div>
    <div class="card-body">
        <div class="trend-card">
            <div class="trend-wrap"><canvas id="trendChart"></canvas></div>
        </div>
    </div>
</section>

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

<?php
$scripts = <<<'SCRIPTS'
<script>
function openFilterModal(id){ document.getElementById(id).classList.add('open'); }
function closeFilterModal(id){ document.getElementById(id).classList.remove('open'); }
function toggleSectionCustom(sel, rowId){
    document.getElementById(rowId).style.display = (sel.value === 'custom') ? '' : 'none';
}

// Monthly Sales Report: Detailed View (table) <-> Graph View (chart)
const MS_DATA = __MS_DATA__;
let msChart = null;
function setMsView(view) {
    const graph = view === 'graph';
    document.getElementById('msDetailView').hidden = graph;
    document.getElementById('msGraphView').hidden = !graph;
    [['msBtnDetail', !graph], ['msBtnGraph', graph]].forEach(function (b) {
        const el = document.getElementById(b[0]);
        el.classList.toggle('active', b[1]);
        el.setAttribute('aria-pressed', b[1] ? 'true' : 'false');
    });
    const canvas = document.getElementById('msChart');
    if (graph && canvas && !msChart) {
        msChart = new Chart(canvas.getContext('2d'), {
            data: {
                labels: MS_DATA.labels,
                datasets: [
                    { type: 'bar', label: 'Total sales (PHP)', data: MS_DATA.sales, backgroundColor: '#61b337', borderRadius: 4, maxBarThickness: 40, yAxisID: 'y' },
                    { type: 'line', label: 'Units sold', data: MS_DATA.units, borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: 0.3, yAxisID: 'y1' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Sales (PHP)' }, ticks: { callback: function (v) { return '₱' + Number(v).toLocaleString(); } } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Units' }, ticks: { precision: 0 } }
                } }
        });
    }
}

// Sales by Category
(function () {
    var catBar = document.getElementById('invCategoryBar');
    var catPrev = document.getElementById('invCatPrev');
    var catNext = document.getElementById('invCatNext');
    if (!catBar || !catPrev || !catNext) return;
    function updateCatArrows() {
        var maxScroll = catBar.scrollWidth - catBar.clientWidth;
        var needsScroll = maxScroll > 2;
        catPrev.disabled = !needsScroll || catBar.scrollLeft <= 2;
        catNext.disabled = !needsScroll || catBar.scrollLeft >= maxScroll - 2;
    }
    function scrollCats(dir) {
        catBar.scrollBy({ left: dir * Math.max(180, Math.floor(catBar.clientWidth * 0.55)), behavior: 'smooth' });
    }
    catPrev.addEventListener('click', function () { scrollCats(-1); });
    catNext.addEventListener('click', function () { scrollCats(1); });
    catBar.addEventListener('scroll', updateCatArrows);
    window.addEventListener('resize', updateCatArrows);
    var active = catBar.querySelector('.inv-cat-tab.active');
    if (active && catBar.scrollWidth > catBar.clientWidth) {
        catBar.scrollLeft = active.offsetLeft - (catBar.clientWidth - active.offsetWidth) / 2;
    }
    updateCatArrows();
})();
function downloadChart(canvasId, name) {
    const canvas = document.getElementById(canvasId);
    const link = document.createElement('a');
    link.download = name + '.png';
    link.href = canvas.toDataURL('image/png');
    link.click();
}
const TREND = __TREND__;
new Chart(document.getElementById('trendChart').getContext('2d'), {
    type: 'bar',
    data: { labels: TREND.labels, datasets: [{ label: 'Units delivered', data: TREND.data, backgroundColor: '#61b337', borderRadius: 4, maxBarThickness: 36 }] },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } } } }
});
</script>
SCRIPTS;
staff_page_end(str_replace(['__MS_DATA__', '__TREND__'], [$msChartJson, $trendJson], $scripts));
