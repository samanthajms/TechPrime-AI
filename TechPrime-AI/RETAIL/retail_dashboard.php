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

// Printed figures under the Sales by Category chart (the chart itself prints in greyscale)
$trendByMonth = $from->diff($to)->days > 62;
$trendRows = [];
foreach ($dash['trend_labels'] as $k => $label) {
    $units = (int)($dash['trend_data'][$k] ?? 0);
    if ($trendByMonth || $units > 0) {
        $trendRows[] = [(string)$label, $units];
    }
}
$msTotals = ['sales' => array_sum(array_column($monthlySales, 'total_sales')), 'tx' => array_sum(array_column($monthlySales, 'transactions')), 'units' => array_sum(array_column($monthlySales, 'units_sold'))];

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$trendJson = json_encode(['labels' => $dash['trend_labels'], 'data' => $dash['trend_data'], 'total' => array_sum($dash['trend_data'])], $jsonFlags);
$msChartJson = json_encode([
    'labels' => array_column($monthlySales, 'month'),
    'sales' => array_map(fn($m) => round((float)$m['total_sales'], 2), $monthlySales),
    'units' => array_map(fn($m) => (int)$m['units_sold'], $monthlySales),
], $jsonFlags);

logActivity($db, $retailId, 'view_dashboard', 'Retail Officer viewed dashboard');

$firstName = trim((string)($_SESSION['name'] ?? ''));
$actions = '<button type="button" class="cx-btn cx-btn-outline no-print" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>'
    . '<a class="cx-btn cx-btn-outline no-print" href="retail_export.php?' . ias_dashboard_qs(['type' => 'dashboard', 'format' => 'csv']) . '" title="Summary, monthly sales and sales by category as CSV"><i class="ph ph-file-csv" aria-hidden="true"></i> Export CSV</a>'
    . '<a class="cx-btn cx-btn-outline no-print" href="retail_export.php?' . ias_dashboard_qs(['type' => 'dashboard', 'format' => 'pdf']) . '" title="The dashboard as a PDF file"><i class="ph ph-file-pdf" aria-hidden="true"></i> Export PDF</a>';

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Retail Dashboard',
    'active' => 'dashboard',
    'heading' => $firstName !== '' ? 'Good to see you, ' . $firstName : 'Retail Dashboard',
    'subtitle' => date('l, F j, Y') . ' · sales performance at a glance',
    'actions' => $actions,
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>',
    'report' => [
        'title' => 'Sales Summary Report',
        'lines' => [
            'Summary period' => $cardRange['label'] . ' (' . $cardRange['from']->format('M j, Y') . ' – ' . $cardRange['to']->format('M j, Y') . ')',
            'Monthly sales' => 'Last ' . $msMonths . ' months',
            'Sales by category' => $range['label'] . ' (' . $from->format('M j, Y') . ' – ' . $to->format('M j, Y') . ')',
            'Category' => $category !== '' ? $category : 'All categories',
        ],
    ],
]);
?>

<div class="rt-dash">
    <section class="cx-card" aria-labelledby="sumTitle">
        <div class="cx-card-head">
            <h2 id="sumTitle"><i class="ph ph-chart-line-up" aria-hidden="true"></i> <?php echo h($cardRange['label']); ?></h2>
            <span class="cx-card-meta cx-num"><?php echo h($cardRange['from']->format('M j') . ' – ' . $cardRange['to']->format('M j, Y')); ?> · live from order line items</span>
        </div>
        <div class="kpis">
            <div class="kpi kpi-hero">
                <span class="kpi-label">Total sales</span>
                <strong class="kpi-value cx-num">₱<?php echo number_format($cardSummary['total_sales'], 2); ?></strong>
                <span class="kpi-sub">VAT-inclusive</span>
            </div>
            <div class="kpi">
                <span class="kpi-label">Transactions</span>
                <strong class="kpi-value cx-num"><?php echo number_format($cardSummary['transactions']); ?></strong>
                <span class="kpi-sub">orders in this period</span>
            </div>
            <div class="kpi">
                <span class="kpi-label">Units sold</span>
                <strong class="kpi-value cx-num"><?php echo number_format($cardSummary['units_sold']); ?></strong>
                <span class="kpi-sub">items across all orders</span>
            </div>
            <div class="kpi">
                <span class="kpi-label">Avg. transaction</span>
                <strong class="kpi-value cx-num">₱<?php echo number_format($cardSummary['avg_transaction'], 2); ?></strong>
                <span class="kpi-sub">per order</span>
            </div>
        </div>
    </section>

    <section class="cx-card" aria-labelledby="msTitle">
        <div class="cx-card-head">
            <div>
                <h2 id="msTitle"><i class="ph ph-calendar-blank" aria-hidden="true"></i> Monthly sales report</h2>
                <p>Revenue by month from order details · last <?php echo (int)$msMonths; ?> months</p>
            </div>
            <div class="cx-card-tools no-print">
                <form method="get" class="rt-inline-form">
                    <?php retail_dashboard_preserve_hidden(['ms_months']); ?>
                    <label class="sort-wrap" for="msMonths">
                        <span>Show</span>
                        <select id="msMonths" name="ms_months" onchange="this.form.submit()">
                            <?php foreach ([6, 12, 18, 24] as $n): ?>
                            <option value="<?php echo $n; ?>" <?php echo $msMonths === $n ? 'selected' : ''; ?>><?php echo $n; ?> months</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <noscript><button type="submit" class="cx-btn cx-btn-outline">Apply</button></noscript>
                </form>
                <div class="cx-seg" role="group" aria-label="Monthly sales view">
                    <button type="button" id="msBtnDetail" onclick="setMsView('detail')" aria-pressed="true"><i class="ph ph-table" aria-hidden="true"></i> Table</button>
                    <button type="button" id="msBtnGraph" onclick="setMsView('graph')" aria-pressed="false"><i class="ph ph-chart-bar" aria-hidden="true"></i> Graph</button>
                </div>
            </div>
        </div>
        <?php if (empty($monthlySales)): ?>
            <div class="cx-empty">
                <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-receipt"></i></span>
                <strong>No sales data yet</strong>
                <span>Completed orders will be summarised here by month.</span>
            </div>
        <?php else: ?>
        <div id="msGraphView" class="ms-view" hidden>
            <div class="cx-chart"><canvas id="msChart" role="img" aria-label="Monthly sales and units sold chart. The table view lists the same figures."></canvas></div>
        </div>
        <div id="msDetailView" class="ms-view">
            <div class="cx-table-wrap">
                <table class="cx-table ms-table">
                    <thead>
                        <tr>
                            <th scope="col">Month</th>
                            <th scope="col" class="num">Total sales</th>
                            <th scope="col" class="num">Transactions</th>
                            <th scope="col" class="num">Units sold</th>
                            <th scope="col" class="num">Avg. transaction</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlySales as $m): ?>
                        <tr>
                            <td data-label="Month"><strong><?php echo h($m['month']); ?></strong></td>
                            <td data-label="Total sales" class="num cx-num"><span class="price-tag">₱<?php echo number_format($m['total_sales'], 2); ?></span></td>
                            <td data-label="Transactions" class="num cx-num"><?php echo number_format($m['transactions']); ?></td>
                            <td data-label="Units sold" class="num cx-num"><?php echo number_format($m['units_sold']); ?></td>
                            <td data-label="Avg. transaction" class="num cx-num">₱<?php echo number_format($m['avg_transaction'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="cx-print-row">
                            <th scope="row">Total</th>
                            <td class="num">₱<?php echo number_format((float)$msTotals['sales'], 2); ?></td>
                            <td class="num"><?php echo number_format((int)$msTotals['tx']); ?></td>
                            <td class="num"><?php echo number_format((int)$msTotals['units']); ?></td>
                            <td class="num">₱<?php echo number_format($msTotals['tx'] > 0 ? $msTotals['sales'] / $msTotals['tx'] : 0, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <section class="cx-card" aria-labelledby="catTitle">
        <div class="cx-card-head">
            <div>
                <h2 id="catTitle"><i class="ph ph-squares-four" aria-hidden="true"></i> Sales by category</h2>
                <p>Units delivered per product · <span class="cx-num"><?php echo h($from->format('M j, Y') . ' – ' . $to->format('M j, Y')); ?></span><span class="print-only"> · <?php echo h($category !== '' ? $category : 'All categories'); ?></span></p>
            </div>
            <div class="cx-card-tools no-print">
                <form method="get" id="periodForm">
                    <?php retail_dashboard_preserve_hidden(['period']); ?>
                    <label class="sort-wrap" for="invPeriodSelect">
                        <span>Period</span>
                        <select id="invPeriodSelect" name="period" onchange="this.form.submit()">
                            <?php foreach ($periods as $k => $label): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $range['key'] === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <noscript><button type="submit" class="cx-btn cx-btn-outline">Apply</button></noscript>
                </form>
            </div>
        </div>
        <nav class="cat-chips no-print" aria-label="Product categories">
            <a class="cat-chip<?php echo $category === '' ? ' active' : ''; ?>"<?php echo $category === '' ? ' aria-current="true"' : ''; ?> href="?<?php echo h(http_build_query(array_diff_key($_GET, ['category' => 1]))); ?>">All categories</a>
            <?php foreach ($categoryTabs as $cat): ?>
            <a class="cat-chip<?php echo $category === $cat ? ' active' : ''; ?>"<?php echo $category === $cat ? ' aria-current="true"' : ''; ?> href="?<?php echo h(http_build_query(array_merge($_GET, ['category' => $cat]))); ?>"><?php echo h($cat); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="cx-chart"><canvas id="trendChart" role="img" aria-label="Units delivered per product<?php echo $category !== '' ? ' in ' . h($category) : ''; ?>"></canvas></div>
        <div class="cx-print-only">
            <p class="rpt-caption">Units delivered by <?php echo $trendByMonth ? 'month' : 'day'; ?><?php echo $trendByMonth ? '' : ' (days without deliveries are left out)'; ?></p>
            <?php if (!$trendRows): ?>
                <p>No units delivered in this period.</p>
            <?php else: ?>
            <table class="cx-table">
                <thead><tr><th scope="col"><?php echo $trendByMonth ? 'Month' : 'Day'; ?></th><th scope="col" class="num">Units delivered</th></tr></thead>
                <tbody>
                    <?php foreach ($trendRows as $t): ?>
                    <tr><td><?php echo h($t[0]); ?></td><td class="num"><?php echo number_format($t[1]); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th scope="row">Total</th><td class="num"><?php echo number_format(array_sum(array_column($trendRows, 1))); ?></td></tr></tfoot>
            </table>
            <?php endif; ?>
            <p class="rpt-caption">Units delivered per product, by category</p>
            <?php if (empty($dash['by_group'])): ?>
                <p>No products delivered in this period.</p>
            <?php else: ?>
            <table class="cx-table">
                <thead><tr><th scope="col">Category</th><th scope="col">Product</th><th scope="col" class="num">Units</th></tr></thead>
                <tbody>
                    <?php foreach ($dash['by_group'] as $group => $items): foreach ($items as $it): ?>
                    <tr><td><?php echo h((string)$group); ?></td><td><?php echo h($it['product']); ?></td><td class="num"><?php echo number_format($it['units']); ?></td></tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
                <tfoot><tr><th scope="row" colspan="2">Total</th><td class="num"><?php echo number_format(array_sum(array_map(fn($items) => array_sum(array_column($items, 'units')), $dash['by_group']))); ?></td></tr></tfoot>
            </table>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
$scripts = <<<'SCRIPTS'
<script>
if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Fira Sans', 'Segoe UI', system-ui, sans-serif";
    Chart.defaults.color = '#45514a';
}

// Monthly Sales Report: table view <-> graph view
const MS_DATA = __MS_DATA__;
let msChart = null;
function setMsView(view) {
    const graph = view === 'graph';
    const detail = document.getElementById('msDetailView');
    if (!detail) return;
    detail.hidden = graph;
    document.getElementById('msGraphView').hidden = !graph;
    [['msBtnDetail', !graph], ['msBtnGraph', graph]].forEach(function (b) {
        document.getElementById(b[0]).setAttribute('aria-pressed', b[1] ? 'true' : 'false');
    });
    const canvas = document.getElementById('msChart');
    if (graph && canvas && !msChart) {
        msChart = new Chart(canvas.getContext('2d'), {
            data: {
                labels: MS_DATA.labels,
                datasets: [
                    { type: 'bar', label: 'Total sales (PHP)', data: MS_DATA.sales, backgroundColor: '#4b8b2a', borderRadius: 6, maxBarThickness: 40, yAxisID: 'y' },
                    { type: 'line', label: 'Units sold', data: MS_DATA.units, borderColor: '#b45309', backgroundColor: '#b45309', tension: 0.3, yAxisID: 'y1' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: '#e0e6dd' }, title: { display: true, text: 'Sales (PHP)' }, ticks: { callback: function (v) { return '₱' + Number(v).toLocaleString(); } } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Units' }, ticks: { precision: 0 } }
                } }
        });
    }
}

const TREND = __TREND__;
new Chart(document.getElementById('trendChart').getContext('2d'), {
    type: 'bar',
    data: { labels: TREND.labels, datasets: [{ label: 'Units delivered', data: TREND.data, backgroundColor: '#4b8b2a', borderRadius: 6, maxBarThickness: 36 }] },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, grid: { color: '#e0e6dd' }, ticks: { precision: 0 } }, x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } } } }
});
</script>
SCRIPTS;
staff_page_end(str_replace(['__MS_DATA__', '__TREND__'], [$msChartJson, $trendJson], $scripts));
