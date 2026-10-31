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
        // Manila wall-clock time, so the chart's month buckets follow the branch calendar (DB value is UTC).
        'created_at' => format_utc_datetime((string)($p['created_at'] ?? ''), 'Y-m-d H:i:s'),
    ];
}, $productRows);
$stockProductsJson = json_encode($jsStockProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

logActivity($db, $uid, 'view_dashboard', 'Inventory Custodian viewed dashboard');

$firstName = trim((string)($_SESSION['name'] ?? ''));
staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Inventory Dashboard',
    'active' => 'dashboard',
    'heading' => $firstName !== '' ? 'Good to see you, ' . $firstName : 'Inventory Dashboard',
    'subtitle' => date('l, F j, Y') . ' · stock, shipments and receiving at a glance',
    'actions' => '<button type="button" class="cx-btn cx-btn-outline no-print" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>',
    'report' => [
        'title' => 'Inventory Summary Report',
        'lines' => [
            'Stock and deliveries' => 'Current totals as of printing',
            'Products stocked per month' => ['text' => 'This Year · All categories', 'id' => 'invPrintScope'],
        ],
    ],
]);
?>

<div class="inv-dash">
    <section class="inv-attn" aria-labelledby="attnTitle">
        <h2 id="attnTitle" class="cx-sr">Needs attention</h2>
        <a class="level-tile inv-attn-tile <?php echo $outOfStockCount > 0 ? 'is-out' : 'is-ok'; ?>" href="inventory_stock_alerts.php?status=out">
            <span class="level-tile-icon" aria-hidden="true"><i class="ph-fill <?php echo $outOfStockCount > 0 ? 'ph-x-circle' : 'ph-check-circle'; ?>"></i></span>
            <span class="level-tile-body">
                <strong class="cx-num"><?php echo number_format($outOfStockCount); ?></strong>
                <span class="level-tile-label">Out of stock</span>
                <span class="level-tile-hint"><?php echo $outOfStockCount > 0 ? 'Cannot be sold until restocked' : 'Every product has stock'; ?></span>
            </span>
            <i class="ph ph-caret-right inv-attn-go" aria-hidden="true"></i>
        </a>
        <a class="level-tile inv-attn-tile <?php echo $runningLowCount > 0 ? 'is-low' : 'is-ok'; ?>" href="inventory_stock_alerts.php?status=running">
            <span class="level-tile-icon" aria-hidden="true"><i class="ph-fill <?php echo $runningLowCount > 0 ? 'ph-warning' : 'ph-check-circle'; ?>"></i></span>
            <span class="level-tile-body">
                <strong class="cx-num"><?php echo number_format($runningLowCount); ?></strong>
                <span class="level-tile-label">Running low <span class="inv-attn-note">(15 or fewer)</span></span>
                <span class="level-tile-hint"><?php echo (int)$criticalStockCount; ?> critical with 5 or fewer left</span>
            </span>
            <i class="ph ph-caret-right inv-attn-go" aria-hidden="true"></i>
        </a>
        <a class="level-tile inv-attn-tile <?php echo $pending > 0 ? 'is-info' : 'is-ok'; ?>" href="inventory_orders.php">
            <span class="level-tile-icon" aria-hidden="true"><i class="ph-fill <?php echo $pending > 0 ? 'ph-truck' : 'ph-check-circle'; ?>"></i></span>
            <span class="level-tile-body">
                <strong class="cx-num"><?php echo number_format($pending); ?></strong>
                <span class="level-tile-label">Pending shipments</span>
                <span class="level-tile-hint"><?php echo $pending > 0 ? 'Not yet delivered' : 'All shipments delivered'; ?></span>
            </span>
            <i class="ph ph-caret-right inv-attn-go" aria-hidden="true"></i>
        </a>
    </section>

    <div class="inv-top">
        <section class="cx-card cx-kpi-card" aria-labelledby="glanceTitle">
            <div class="cx-card-head">
                <h2 id="glanceTitle"><i class="ph ph-warehouse" aria-hidden="true"></i> At a glance</h2>
                <span class="cx-card-meta">Current totals</span>
            </div>
            <div class="kpis kpis-3">
                <div class="kpi kpi-hero">
                    <span class="kpi-label">Units in stock</span>
                    <strong class="kpi-value cx-num"><?php echo number_format($totalStock); ?></strong>
                    <span class="kpi-sub"><?php echo $productCount > 0 ? number_format($totalStock / $productCount, 1) : '0'; ?> per product on average</span>
                </div>
                <div class="kpi">
                    <span class="kpi-label">Products in catalog</span>
                    <strong class="kpi-value cx-num"><?php echo number_format($productCount); ?></strong>
                    <span class="kpi-sub">across <?php echo count($categoryTabs); ?> shop categories</span>
                </div>
                <div class="kpi">
                    <span class="kpi-label">Deliveries completed</span>
                    <strong class="kpi-value cx-num"><?php echo number_format($done); ?> <small>of <?php echo number_format($total); ?></small></strong>
                    <span class="inv-progress" role="progressbar" aria-valuenow="<?php echo $completionRate; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Delivery completion"><span style="width: <?php echo $completionRate; ?>%;"></span></span>
                    <span class="kpi-sub"><?php echo $completionRate; ?>% completion rate</span>
                </div>
            </div>
        </section>

        <a class="dash-cta no-print" href="inventory_stock_in.php">
            <span class="dash-cta-icon" aria-hidden="true"><i class="ph ph-barcode"></i></span>
            <span class="dash-cta-text">
                <strong>Receive a delivery</strong>
                <span>Scan each item to add it to stock</span>
            </span>
            <i class="ph ph-arrow-right dash-cta-arrow" aria-hidden="true"></i>
        </a>
    </div>

    <section class="cx-card" aria-labelledby="stockinTitle">
        <div class="cx-card-head">
            <div>
                <h2 id="stockinTitle"><i class="ph ph-chart-bar" aria-hidden="true"></i> Products stocked per month</h2>
                <p>Products added to the catalog each month, by the date they were added</p>
            </div>
            <div class="cx-card-tools">
                <label class="sort-wrap" for="invPeriodSelect">
                    <span>Period</span>
                    <select id="invPeriodSelect">
                        <option value="month">This Month</option>
                        <option value="last">Last Month</option>
                        <option value="3m">Last 3 Months</option>
                        <option value="year" selected>This Year</option>
                    </select>
                </label>
            </div>
        </div>
        <div class="cat-chips" id="invCategoryBar" role="group" aria-label="Product category">
            <button type="button" class="cat-chip active" data-category="" aria-pressed="true">All categories</button>
            <?php foreach ($categoryTabs as $cat): ?>
            <button type="button" class="cat-chip" data-category="<?php echo h($cat); ?>" aria-pressed="false"><?php echo h($cat); ?></button>
            <?php endforeach; ?>
        </div>
        <div class="cx-chart no-print"><canvas id="invStockinChart" role="img" aria-label="Products stocked per month chart"></canvas></div>
        <div class="cx-print-only">
            <table class="cx-table">
                <thead><tr><th scope="col">Month</th><th scope="col" class="num">Products stocked</th></tr></thead>
                <tbody id="invStockinPrintRows"></tbody>
                <tfoot><tr><th scope="row">Total</th><td class="num" id="invStockinPrintTotal">0</td></tr></tfoot>
            </table>
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
        if (el) {
            el.innerHTML = '<i class="ph ph-stack" aria-hidden="true"></i> ';
            el.appendChild(document.createTextNode(selectedCategory || 'All categories'));
        }
        var scope = document.getElementById('invPrintScope');
        var sel = document.getElementById('invPeriodSelect');
        if (scope && sel) {
            scope.textContent = sel.options[sel.selectedIndex].text + ' · ' + (selectedCategory || 'All categories');
        }
    }

    /* The printed report lists the chart's figures under it */
    function renderPrintTable(series) {
        var body = document.getElementById('invStockinPrintRows');
        if (!body) return;
        var total = 0;
        body.textContent = '';
        series.labels.forEach(function (label, i) {
            var tr = document.createElement('tr');
            var td1 = document.createElement('td');
            var td2 = document.createElement('td');
            td1.textContent = label;
            td2.className = 'num';
            td2.textContent = String(series.values[i]);
            total += series.values[i];
            tr.appendChild(td1);
            tr.appendChild(td2);
            body.appendChild(tr);
        });
        document.getElementById('invStockinPrintTotal').textContent = String(total);
    }

    function renderStockinChart() {
        var canvas = document.getElementById('invStockinChart');
        var series = buildSeries(selectedCategory, selectedPeriod);
        updateBadge();
        renderPrintTable(series);
        if (!canvas || typeof Chart === 'undefined') return;
        if (stockinChart) {
            stockinChart.data.labels = series.labels;
            stockinChart.data.datasets[0].data = series.values;
            stockinChart.update();
            return;
        }
        Chart.defaults.font.family = "'Fira Sans', 'Segoe UI', system-ui, sans-serif";
        Chart.defaults.color = '#45514a';
        stockinChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: series.labels,
                datasets: [{
                    label: 'Products Stocked',
                    data: series.values,
                    backgroundColor: '#4b8b2a',
                    borderRadius: 6,
                    maxBarThickness: 42
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
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
                        ticks: { font: { size: 12 }, maxRotation: 45, minRotation: 0, autoSkip: true }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 12 } },
                        grid: { color: '#e0e6dd' },
                        title: { display: true, text: 'Products stocked', font: { size: 12, weight: '600' } }
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
    if (catBar) {
        catBar.addEventListener('click', function (e) {
            var btn = e.target.closest('.cat-chip');
            if (!btn) return;
            catBar.querySelectorAll('.cat-chip').forEach(function (b) {
                b.classList.toggle('active', b === btn);
                b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
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
