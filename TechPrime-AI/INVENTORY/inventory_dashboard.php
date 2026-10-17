<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/product_categories.php';
require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
require_once __DIR__ . '/../includes/retail_reports.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];

$total = (int)($db->query('SELECT COUNT(*) FROM shipments')->fetchColumn() ?? 0);
$pending = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE shipment_status != 'delivered'")->fetchColumn() ?? 0);
$done = (int)($db->query("SELECT COUNT(*) FROM shipments WHERE shipment_status = 'delivered'")->fetchColumn() ?? 0);

$products = $db->query('SELECT id, name, category, stock, price, created_at FROM products ORDER BY id DESC');
$productRows = $products ? $products->fetchAll(PDO::FETCH_ASSOC) : [];

$productCount = count($productRows);
$totalStock = 0;
$criticalStockCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
// Same bands as inventory_stocks.php: critical 1–5, low 6–15
foreach ($productRows as &$row) {
    $stock = (int)($row['stock'] ?? 0);
    $totalStock += $stock;
    if ($stock <= 0) {
        $outOfStockCount++;
    } elseif ($stock <= 5) {
        $criticalStockCount++;
    } elseif ($stock <= 15) {
        $lowStockCount++;
    }
    $tax = ep_shop_classify_product($row);
    $row['tax_parent_label'] = $tax['parent_label'];
}
unset($row);
$categoryTabs = [];
foreach (ep_shop_taxonomy() as $info) {
    $categoryTabs[] = (string)$info['label'];
}
$completionRate = $total > 0 ? (int)round(($done / $total) * 100) : 0;
$runningLowCount = $criticalStockCount + $lowStockCount;

$stockYear = (int)date('Y');

require_once __DIR__ . '/../includes/inventory_alerts.php';
inv_sync_stock_alerts($db);

$jsStockProducts = array_map(static function ($p) {
    return [
        'category' => trim((string)($p['tax_parent_label'] ?? 'Others')) ?: 'Others',
        'created_at' => (string)($p['created_at'] ?? ''),
    ];
}, $productRows);
$stockProductsJson = json_encode($jsStockProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

logActivity($db, $uid, 'view_dashboard', 'Inventory Custodian viewed dashboard');

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Inventory Dashboard',
    'active' => 'dashboard',
    'heading' => 'Inventory Dashboard',
    'subtitle' => 'Welcome, ' . ($_SESSION['name'] ?? 'Inventory'),
    'extra_head' => <<<'EXTRA'
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
.inv-dash { display: flex; flex-direction: column; gap: 22px; }
/* Flex children default to min-width:auto, which stops Chart.js's canvas from shrinking */
.inv-dash > * { min-width: 0; }

/* Needs attention: the numbers a custodian acts on, each one a link to the filtered list */
.inv-attn { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.inv-attn-card {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 20px;
    border-radius: 14px;
    background: #fff;
    border: 1px solid var(--border);
    box-shadow: var(--card-shadow);
    color: inherit; text-decoration: none;
    transition: border-color 150ms ease, box-shadow 150ms ease, transform 160ms var(--ease-out);
}
.inv-attn-card:active { transform: scale(0.98); }
.inv-attn-card:focus-visible { outline: 2px solid var(--ep-green); outline-offset: 2px; }
.inv-attn-icon {
    width: 44px; height: 44px; flex-shrink: 0;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    background: var(--ep-green-light); color: var(--ep-green-dark);
}
.inv-attn-body { flex: 1; min-width: 0; }
.inv-attn-body > span { display: block; }
.inv-attn-num { font-size: 28px; font-weight: 800; line-height: 1; color: var(--text-main); font-variant-numeric: tabular-nums; }
.inv-attn-label { margin-top: 6px; font-size: 13px; font-weight: 600; color: var(--text-main); }
.inv-attn-label small { font-size: 12px; font-weight: 500; color: var(--text-muted); }
.inv-attn-hint { margin-top: 2px; font-size: 12px; color: var(--text-muted); }
.inv-attn-go { flex-shrink: 0; font-size: 13px; color: var(--slate-300); transition: color 150ms ease, transform 160ms var(--ease-out); }
/* Tones apply only when there is something to do; a zero stays calm */
.inv-attn-card.tone-danger { border-color: #fecaca; }
.inv-attn-card.tone-danger .inv-attn-icon { background: #fef2f2; color: #dc2626; }
.inv-attn-card.tone-danger .inv-attn-num { color: #dc2626; }
.inv-attn-card.tone-warn { border-color: #fed7aa; }
.inv-attn-card.tone-warn .inv-attn-icon { background: #fff7ed; color: #c2410c; }
.inv-attn-card.tone-warn .inv-attn-num { color: #c2410c; }
.inv-attn-card.tone-info .inv-attn-icon { background: var(--yellow-pale); color: #a16207; }

/* At a glance */
.inv-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.inv-kpi { padding: 16px 20px; border-radius: 14px; background: #fff; border: 1px solid var(--border); box-shadow: var(--card-shadow); }
.inv-kpi-label { font-size: 11px; font-weight: 700; letter-spacing: 0.85px; text-transform: uppercase; color: var(--text-muted); }
.inv-kpi-num { margin-top: 6px; font-size: 24px; font-weight: 800; line-height: 1; color: var(--ep-green-dark); font-variant-numeric: tabular-nums; }
.inv-kpi-num small { font-size: 14px; font-weight: 700; color: var(--text-muted); }
.inv-kpi-hint { margin-top: 8px; font-size: 12px; color: var(--text-muted); }
.inv-progress { margin-top: 10px; height: 6px; border-radius: 999px; background: var(--slate-100); overflow: hidden; }
.inv-progress span { display: block; height: 100%; border-radius: inherit; background: var(--ep-green); }

@media (hover: hover) and (pointer: fine) {
    .inv-attn-card:hover { border-color: var(--slate-300); box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08); }
    .inv-attn-card:hover .inv-attn-go { color: var(--text-main); transform: translateX(2px); }
}
@media (prefers-reduced-motion: reduce) {
    .inv-attn-card:active,
    .inv-attn-card:hover .inv-attn-go { transform: none; }
}

/* Products Stocked Per Month */
.inv-stockin-panel {
    background: linear-gradient(180deg, #ffffff 0%, #f4f9f0 100%);
    border: 1px solid var(--teal-light, #c6e6b3);
    border-radius: 18px;
    box-shadow: 0 10px 28px rgba(75, 139, 42, 0.1);
    overflow: hidden;
}
.inv-stockin-header {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    padding: 22px 24px 16px;
    background: linear-gradient(135deg, #eef8e6 0%, #ffffff 70%);
    border-bottom: 1px solid rgba(75, 139, 42, 0.12);
}
.inv-stockin-header h3 {
    margin: 0; font-size: 17px; font-weight: 800; color: var(--ep-green-dark);
    display: flex; align-items: center; gap: 12px;
}
.inv-stockin-header .card-icon {
    width: 40px; height: 40px; border-radius: 12px;
    background: var(--ep-green); color: #fff; display: inline-flex; align-items: center; justify-content: center;
    box-shadow: 0 6px 14px rgba(75, 139, 42, 0.28);
}
.inv-stockin-meta { margin-top: 4px; font-size: 12.5px; color: var(--text-muted); font-weight: 500; }
.inv-stockin-badge {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 999px;
    background: #fff; border: 1px solid var(--teal-light, #c6e6b3); color: var(--ep-green-dark);
    font-size: 12px; font-weight: 700;
}

.inv-category-nav {
    display: flex; align-items: center; gap: 8px; padding: 14px 18px;
    border-bottom: 1px solid rgba(75, 139, 42, 0.1);
    background: linear-gradient(180deg, #f8fbf6 0%, #ffffff 100%);
}
.inv-cat-arrow {
    flex: 0 0 auto; width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: 999px; border: 1px solid var(--teal-light, #c6e6b3); background: #fff;
    color: var(--ep-green-dark); cursor: pointer; font-size: 13px;
    transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease, transform 160ms var(--ease-out);
}
.inv-cat-arrow:active:not(:disabled) { transform: scale(0.94); }
.inv-cat-arrow:hover:not(:disabled) {
    background: var(--ep-green-light); border-color: var(--ep-green);
    box-shadow: 0 4px 12px rgba(75, 139, 42, 0.15);
}
.inv-cat-arrow:disabled { opacity: 0.4; cursor: default; }
.inv-category-bar {
    display: flex; align-items: center; gap: 8px; flex: 1 1 auto; min-width: 0;
    overflow-x: auto; scrollbar-width: none; padding: 2px;
}
.inv-category-bar::-webkit-scrollbar { display: none; }
.inv-cat-tab {
    flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
    min-height: 38px; padding: 8px 16px; border-radius: 999px;
    border: 1px solid var(--border); background: #fff; color: var(--text-main);
    font-size: 13px; font-weight: 700; line-height: 1.3; white-space: nowrap;
    cursor: pointer; font-family: inherit;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease, transform 160ms var(--ease-out);
}
.inv-cat-tab:hover {
    border-color: var(--ep-green); color: var(--ep-green-dark); background: var(--ep-green-light);
}
.inv-cat-tab:active { transform: scale(0.97); }
@media (prefers-reduced-motion: reduce) { .inv-cat-tab:active, .inv-cat-arrow:active:not(:disabled) { transform: none; } }
.inv-cat-tab.active {
    background: var(--ep-green); border-color: var(--ep-green); color: #fff;
    box-shadow: 0 6px 16px rgba(75, 139, 42, 0.28);
}
.inv-cat-tab span { display: block; white-space: nowrap; }

.inv-stockin-body { padding: 18px 22px 24px; }
.inv-stockin-canvas-wrap {
    position: relative; height: 320px; width: 100%;
    background: #fff; border: 1px solid rgba(75, 139, 42, 0.12);
    border-radius: 14px; padding: 12px 10px 8px;
}

.inv-period-select {
    height: 38px; padding: 0 12px; border-radius: 999px;
    border: 1px solid var(--teal-light, #c6e6b3); background: #fff;
    font-family: inherit; font-size: 12.5px; font-weight: 700;
    color: var(--ep-green-dark); outline: none; cursor: pointer;
    box-shadow: 0 2px 8px rgba(75, 139, 42, 0.06);
    transition: border-color 0.15s, box-shadow 0.15s;
}
.inv-period-select:focus {
    border-color: var(--ep-green);
    box-shadow: 0 0 0 3px rgba(97, 179, 55, 0.15);
}
.inv-stockin-tools { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

@media (max-width: 1100px) {
    .inv-attn, .inv-kpis { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 640px) {
    .inv-stockin-canvas-wrap { height: 260px; }
    .inv-cat-tab { padding: 8px 14px; font-size: 12.5px; }
}
.report-toolbar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
@media print {
    .main > .inv-page-banner, .inv-category-nav, .inv-stockin-tools, .inv-attn-go { display: none !important; }
    .inv-dash { gap: 14px; }
    .inv-attn-card, .inv-kpi, .inv-stockin-panel { box-shadow: none !important; break-inside: avoid; }
    .inv-attn, .inv-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
    .inv-stockin-canvas-wrap { height: 300px !important; }
}
</style>
EXTRA
    . '<style>' . ias_retail_print_css() . '</style>',
]);
?>

<div class="print-header">
    <img src="<?php echo h(staff_logo_href()); ?>" alt="EasyPC">
    <div>
        <h1>Inventory Dashboard</h1>
        <p>Deliveries and stock: current totals &middot; Products Stocked Per Month: <span id="invPrintScope">This Year &middot; All categories</span></p>
        <p>Prepared by <?php echo h($_SESSION['name'] ?? 'Inventory Custodian'); ?> on <?php echo h(ias_report_datetime(gmdate('Y-m-d H:i:s'))); ?></p>
    </div>
</div>

<div class="report-toolbar no-print">
    <button type="button" class="btn btn-outline btn-sm" onclick="window.print()" title="Print the dashboard"><i class="fas fa-print"></i> Print</button>
</div>

        <div class="inv-dash">

            <section class="inv-attn" aria-label="Needs attention">
                <a class="inv-attn-card<?php echo $outOfStockCount > 0 ? ' tone-danger' : ''; ?>" href="inventory_stocks.php?status=outofstock">
                    <span class="inv-attn-icon" aria-hidden="true"><i class="fas <?php echo $outOfStockCount > 0 ? 'fa-times-circle' : 'fa-check-circle'; ?>"></i></span>
                    <span class="inv-attn-body">
                        <span class="inv-attn-num"><?php echo $outOfStockCount; ?></span>
                        <span class="inv-attn-label">Out of stock</span>
                        <span class="inv-attn-hint"><?php echo $outOfStockCount > 0 ? 'Cannot be sold until restocked' : 'Every product has stock'; ?></span>
                    </span>
                    <i class="fas fa-chevron-right inv-attn-go" aria-hidden="true"></i>
                </a>
                <a class="inv-attn-card<?php echo $runningLowCount > 0 ? ' tone-warn' : ''; ?>" href="inventory_stocks.php?alert=low">
                    <span class="inv-attn-icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></span>
                    <span class="inv-attn-body">
                        <span class="inv-attn-num"><?php echo $runningLowCount; ?></span>
                        <span class="inv-attn-label">Running low <small>(15 or fewer)</small></span>
                        <span class="inv-attn-hint"><?php echo $criticalStockCount; ?> critical with 5 or fewer left</span>
                    </span>
                    <i class="fas fa-chevron-right inv-attn-go" aria-hidden="true"></i>
                </a>
                <a class="inv-attn-card<?php echo $pending > 0 ? ' tone-info' : ''; ?>" href="inventory_orders.php">
                    <span class="inv-attn-icon" aria-hidden="true"><i class="fas fa-truck"></i></span>
                    <span class="inv-attn-body">
                        <span class="inv-attn-num"><?php echo $pending; ?></span>
                        <span class="inv-attn-label">Pending shipments</span>
                        <span class="inv-attn-hint"><?php echo $pending > 0 ? 'Not yet delivered' : 'All shipments delivered'; ?></span>
                    </span>
                    <i class="fas fa-chevron-right inv-attn-go" aria-hidden="true"></i>
                </a>
            </section>

            <section class="inv-kpis" aria-label="At a glance">
                <div class="inv-kpi">
                    <div class="inv-kpi-label">Products in catalog</div>
                    <div class="inv-kpi-num"><?php echo number_format($productCount); ?></div>
                    <div class="inv-kpi-hint">Across <?php echo count($categoryTabs); ?> shop categories</div>
                </div>
                <div class="inv-kpi">
                    <div class="inv-kpi-label">Units in stock</div>
                    <div class="inv-kpi-num"><?php echo number_format($totalStock); ?></div>
                    <div class="inv-kpi-hint"><?php echo $productCount > 0 ? number_format($totalStock / $productCount, 1) : '0'; ?> units per product on average</div>
                </div>
                <div class="inv-kpi">
                    <div class="inv-kpi-label">Deliveries completed</div>
                    <div class="inv-kpi-num"><?php echo $done; ?> <small>of <?php echo $total; ?></small></div>
                    <div class="inv-progress" role="progressbar" aria-valuenow="<?php echo $completionRate; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Delivery completion"><span style="width: <?php echo $completionRate; ?>%;"></span></div>
                    <div class="inv-kpi-hint"><?php echo $completionRate; ?>% completion rate</div>
                </div>
            </section>

            <section class="inv-stockin-panel" aria-label="Products stocked per month">
                <div class="inv-stockin-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-chart-area"></i></span> Products Stocked Per Month</h3>
                        <div class="inv-stockin-meta">Products added to the catalog each month, by the date they were added</div>
                    </div>
                    <div class="inv-stockin-tools">
                        <label class="sr-only" for="invPeriodSelect">Time period</label>
                        <select id="invPeriodSelect" class="inv-period-select" aria-label="Time period filter">
                            <option value="month">This Month</option>
                            <option value="last">Last Month</option>
                            <option value="3m">Last 3 Months</option>
                            <option value="year" selected>This Year</option>
                        </select>
                        <span class="inv-stockin-badge" id="invStockinBadge"><i class="fas fa-layer-group"></i> All categories</span>
                    </div>
                </div>

                <div class="inv-category-nav" aria-label="Product category navigation">
                    <button type="button" class="inv-cat-arrow" id="invCatPrev" aria-label="Scroll categories left">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <nav class="inv-category-bar" id="invCategoryBar" aria-label="Product categories">
                        <button type="button" class="inv-cat-tab active" data-category=""><span>All</span></button>
                        <?php foreach ($categoryTabs as $cat): ?>
                        <button type="button" class="inv-cat-tab" data-category="<?php echo h($cat); ?>" title="<?php echo h($cat); ?>"><span><?php echo h($cat); ?></span></button>
                        <?php endforeach; ?>
                    </nav>
                    <button type="button" class="inv-cat-arrow" id="invCatNext" aria-label="Scroll categories right">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>

                <div class="inv-stockin-body">
                    <div class="inv-stockin-canvas-wrap">
                        <canvas id="invStockinChart" aria-label="Products stocked per month chart"></canvas>
                    </div>
                </div>
            </section>

        </div>

<?php
$chartScript = <<<SCRIPT
<script>
(function () {
    var STOCK_PRODUCTS = {$stockProductsJson};
    var STOCK_YEAR = {$stockYear};
    var MONTH_LABELS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var MONTH_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var selectedCategory = '';
    var selectedPeriod = 'year';
    var stockinChart = null;
    var now = new Date();
    var currentMonth = now.getMonth();
    var currentYear = now.getFullYear();

    function monthIndexInRange(d) {
        return { y: d.getFullYear(), m: d.getMonth() };
    }

    function buildSeries(category, period) {
        var labels = [];
        var values = [];
        var points = [];

        if (period === 'month') {
            labels = [MONTH_LABELS[currentMonth]];
            points = [{ y: currentYear, m: currentMonth }];
        } else if (period === 'last') {
            var lm = currentMonth - 1;
            var ly = currentYear;
            if (lm < 0) { lm = 11; ly -= 1; }
            labels = [MONTH_LABELS[lm] + (ly !== currentYear ? ' ' + ly : '')];
            points = [{ y: ly, m: lm }];
        } else if (period === '3m') {
            for (var i = 2; i >= 0; i--) {
                var mm = currentMonth - i;
                var yy = currentYear;
                if (mm < 0) { mm += 12; yy -= 1; }
                labels.push(MONTH_SHORT[mm] + ' ' + yy);
                points.push({ y: yy, m: mm });
            }
        } else {
            labels = MONTH_LABELS.slice();
            for (var m = 0; m < 12; m++) {
                points.push({ y: currentYear, m: m });
            }
        }

        values = points.map(function () { return 0; });
        STOCK_PRODUCTS.forEach(function (p) {
            if (category && String(p.category) !== category) return;
            if (!p.created_at) return;
            var d = new Date(String(p.created_at).replace(' ', 'T'));
            if (isNaN(d.getTime())) return;
            var info = monthIndexInRange(d);
            for (var i = 0; i < points.length; i++) {
                if (points[i].y === info.y && points[i].m === info.m) {
                    values[i] += 1;
                    break;
                }
            }
        });
        return { labels: labels, values: values };
    }

    function updateBadge() {
        var el = document.getElementById('invStockinBadge');
        if (!el) return;
        el.innerHTML = '<i class="fas fa-layer-group"></i> ' + (selectedCategory ? selectedCategory : 'All categories');
        var scope = document.getElementById('invPrintScope');
        var sel = document.getElementById('invPeriodSelect');
        if (scope && sel) {
            scope.textContent = sel.options[sel.selectedIndex].text + ' · ' + (selectedCategory || 'All categories');
        }
    }

    function renderStockinChart() {
        var canvas = document.getElementById('invStockinChart');
        if (!canvas || typeof Chart === 'undefined') return;
        var series = buildSeries(selectedCategory, selectedPeriod);
        updateBadge();
        if (stockinChart) {
            stockinChart.data.labels = series.labels;
            stockinChart.data.datasets[0].data = series.values;
            stockinChart.update();
            return;
        }
        stockinChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: series.labels,
                datasets: [{
                    label: 'Products Stocked',
                    data: series.values,
                    backgroundColor: 'rgba(75, 139, 42, 0.78)',
                    borderColor: 'rgba(75, 139, 42, 1)',
                    borderWidth: 1,
                    borderRadius: 8,
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    title: {
                        display: false, // the panel header already names the chart
                        text: 'Products Stocked Per Month',
                        color: '#2f5d1a',
                        font: { size: 14, weight: '700' },
                        padding: { bottom: 12 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var n = ctx.parsed.y;
                                return n + ' product' + (n === 1 ? '' : 's') + ' stocked';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 11, weight: '600' },
                            color: '#4b5563',
                            maxRotation: 45,
                            minRotation: 0,
                            autoSkip: true
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 11 }, color: '#6b7280' },
                        grid: { color: 'rgba(75, 139, 42, 0.08)' },
                        title: {
                            display: true,
                            text: 'Products Stocked',
                            color: '#6b7280',
                            font: { size: 11, weight: '600' }
                        }
                    }
                }
            }
        });
    }

    var periodSelect = document.getElementById('invPeriodSelect');
    if (periodSelect) {
        periodSelect.addEventListener('change', function () {
            selectedPeriod = periodSelect.value || 'year';
            renderStockinChart();
        });
    }

    var catBar = document.getElementById('invCategoryBar');
    var catPrev = document.getElementById('invCatPrev');
    var catNext = document.getElementById('invCatNext');

    function updateCatArrows() {
        if (!catBar || !catPrev || !catNext) return;
        var maxScroll = catBar.scrollWidth - catBar.clientWidth;
        var needsScroll = maxScroll > 2;
        catPrev.disabled = !needsScroll || catBar.scrollLeft <= 2;
        catNext.disabled = !needsScroll || catBar.scrollLeft >= maxScroll - 2;
    }
    function scrollCats(dir) {
        if (!catBar) return;
        catBar.scrollBy({ left: dir * Math.max(180, Math.floor(catBar.clientWidth * 0.55)), behavior: 'smooth' });
    }
    if (catPrev) catPrev.addEventListener('click', function () { scrollCats(-1); });
    if (catNext) catNext.addEventListener('click', function () { scrollCats(1); });
    if (catBar) {
        catBar.addEventListener('scroll', updateCatArrows);
        window.addEventListener('resize', updateCatArrows);
        updateCatArrows();
    }

    if (catBar) {
        catBar.addEventListener('click', function (e) {
            var btn = e.target.closest('.inv-cat-tab');
            if (!btn) return;
            e.preventDefault();
            catBar.querySelectorAll('.inv-cat-tab').forEach(function (b) {
                b.classList.toggle('active', b === btn);
                if (b === btn) b.setAttribute('aria-current', 'true');
                else b.removeAttribute('aria-current');
            });
            selectedCategory = btn.getAttribute('data-category') || '';
            renderStockinChart();
        });
    }

    renderStockinChart();
})();
</script>
SCRIPT;
staff_page_end($chartScript);
?>
