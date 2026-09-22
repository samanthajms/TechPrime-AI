<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/product_categories.php';

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
$lowStockCount = 0;
$outOfStockCount = 0;
$categoriesInUse = [];
foreach ($productRows as $row) {
    $stock = (int)($row['stock'] ?? 0);
    $totalStock += $stock;
    if ($stock <= 0) {
        $outOfStockCount++;
    } elseif ($stock <= 5) {
        $lowStockCount++;
    }
    $cat = trim((string)($row['category'] ?? ''));
    if ($cat !== '' && !in_array($cat, $categoriesInUse, true)) {
        $categoriesInUse[] = $cat;
    }
}
sort($categoriesInUse);
$allowedCategories = ias_inventory_allowed_categories();
$categoryTabs = array_values(array_unique(array_merge($categoriesInUse, $allowedCategories)));
sort($categoryTabs);
$completionRate = $total > 0 ? (int)round(($done / $total) * 100) : 0;

$stockYear = (int)date('Y');

require_once __DIR__ . '/../includes/inventory_alerts.php';
inv_sync_stock_alerts($db);

$jsStockProducts = array_map(static function ($p) {
    return [
        'category' => trim((string)($p['category'] ?? 'Accessories')) ?: 'Accessories',
        'created_at' => (string)($p['created_at'] ?? ''),
    ];
}, $productRows);
$stockProductsJson = json_encode($jsStockProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$chartLabels = json_encode(['Total Deliveries', 'Completed Deliveries', 'Products in Catalog']);
$chartValues = json_encode([(int)$total, (int)$done, (int)$productCount]);

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

.inv-hero {
    display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
    padding: 24px 28px;
    background: linear-gradient(135deg, #eef8e6 0%, #ffffff 55%, #f7fbf3 100%);
    border: 1px solid var(--teal-light, #c6e6b3);
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(75, 139, 42, 0.08);
    position: relative; overflow: hidden;
}
.inv-hero::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px;
    background: linear-gradient(180deg, var(--ep-green), var(--ep-green-dark));
}
.inv-hero-text { position: relative; z-index: 1; max-width: 640px; padding-left: 8px; }
.inv-hero-kicker {
    font-size: 11px; font-weight: 700; letter-spacing: 1.4px; text-transform: uppercase;
    color: var(--ep-green-dark); margin-bottom: 6px;
}
.inv-hero h1 { margin: 0 0 8px; font-size: 22px; font-weight: 800; line-height: 1.2; color: var(--text-main); }
.inv-hero p { margin: 0; font-size: 13.5px; line-height: 1.55; color: var(--text-muted); font-weight: 500; }

.inv-chart-panel, .inv-panel, .inv-stat-card, .inv-overview-chip {
    background: linear-gradient(180deg, #ffffff 0%, #f8fbf6 100%);
    border: 1px solid var(--border);
    box-shadow: 0 8px 22px rgba(75, 139, 42, 0.07);
}
.inv-chart-panel {
    border-radius: 16px; overflow: hidden;
    border-color: var(--teal-light, #c6e6b3);
}
.inv-chart-header {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    padding: 18px 24px; border-bottom: 1px solid var(--border);
    background: linear-gradient(to bottom, #fff 0%, #eef8e6 100%);
}
.inv-chart-header h3 {
    margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main);
    display: flex; align-items: center; gap: 10px;
}
.inv-chart-header .card-icon { width: 34px; height: 34px; border-radius: 10px; }
.inv-chart-meta { font-size: 12px; color: var(--text-muted); font-weight: 500; }
.inv-chart-body {
    padding: 18px 22px 22px;
    display: grid; grid-template-columns: minmax(0, 1fr) 200px; gap: 18px; align-items: center;
}
.inv-chart-canvas-wrap { position: relative; height: 220px; width: 100%; }
.inv-chart-summary { display: flex; flex-direction: column; gap: 10px; }
.inv-chart-metric {
    display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; border-radius: 12px;
    border: 1px solid var(--border); background: #fff;
}
.inv-chart-metric strong { font-size: 20px; font-weight: 800; color: var(--text-main); line-height: 1.1; }
.inv-chart-metric span {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-muted);
}
.inv-chart-metric.m-total { border-left: 3px solid var(--ep-green); }
.inv-chart-metric.m-done { border-left: 3px solid #16a34a; }
.inv-chart-metric.m-catalog { border-left: 3px solid var(--ep-green-dark); }

.inv-stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; }
.inv-stat-card {
    border-radius: 14px; padding: 20px 22px; position: relative; overflow: hidden;
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}
.inv-stat-card:hover {
    transform: translateY(-4px); box-shadow: 0 14px 32px rgba(75, 139, 42, 0.12);
    border-color: rgba(75, 139, 42, 0.28);
}
.inv-stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
    background: var(--stat-accent, var(--ep-green));
}
.inv-stat-card.stat-deliveries { --stat-accent: var(--ep-green); }
.inv-stat-card.stat-pending { --stat-accent: var(--ep-yellow); }
.inv-stat-card.stat-completed { --stat-accent: #16a34a; }
.inv-stat-card.stat-products { --stat-accent: var(--ep-green-dark); }
.inv-stat-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.inv-stat-icon {
    width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center;
    font-size: 17px; background: var(--ep-green-light); color: var(--ep-green-dark);
    border: 1px solid var(--teal-light); flex-shrink: 0;
}
.inv-stat-card.stat-pending .inv-stat-icon {
    background: var(--yellow-pale); color: var(--ep-yellow-dark); border-color: #fde68a;
}
.inv-stat-card.stat-completed .inv-stat-icon {
    background: #f0fdf4; color: #16a34a; border-color: #bbf7d0;
}
.inv-stat-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.85px;
    color: var(--text-muted); margin-bottom: 4px;
}
.inv-stat-num { font-size: 32px; font-weight: 800; color: var(--text-main); line-height: 1; }
.inv-stat-foot {
    font-size: 12px; color: var(--text-muted); font-weight: 500; margin-top: 10px; padding-top: 10px;
    border-top: 1px solid var(--border);
}

.inv-overview { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.inv-overview-chip {
    display: flex; align-items: center; gap: 14px;
    padding: 20px 22px;
    border-radius: 14px;
    min-height: 148px;
    box-sizing: border-box;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.inv-overview-chip:hover {
    transform: translateY(-2px); box-shadow: 0 10px 24px rgba(75, 139, 42, 0.1);
    border-color: rgba(75, 139, 42, 0.25);
}
.inv-overview-chip i {
    width: 42px; height: 42px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; background: var(--ep-green-light); color: var(--ep-green-dark);
    border: 1px solid var(--teal-light); flex-shrink: 0;
}
.inv-overview-chip.chip-warn i { background: var(--yellow-pale); color: #b45309; border-color: #fde68a; }
.inv-overview-chip.chip-danger i { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.inv-overview-chip strong {
    display: block;
    font-size: 32px;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1;
}
.inv-overview-chip span {
    display: block;
    margin-top: 4px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.85px;
    color: var(--text-muted);
    line-height: 1.3;
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
    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
}
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
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}
.inv-cat-tab:hover {
    border-color: var(--ep-green); color: var(--ep-green-dark); background: var(--ep-green-light);
    transform: translateY(-1px);
}
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
    .inv-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .inv-overview { grid-template-columns: 1fr; }
    .inv-chart-body { grid-template-columns: 1fr; }
    .inv-chart-summary { flex-direction: row; flex-wrap: wrap; }
    .inv-chart-metric { flex: 1 1 140px; }
}
@media (max-width: 640px) {
    .inv-stats-grid { grid-template-columns: 1fr; }
    .inv-hero { padding: 18px; }
    .inv-hero h1 { font-size: 18px; }
    .inv-stockin-canvas-wrap { height: 260px; }
    .inv-cat-tab { padding: 8px 14px; font-size: 12.5px; }
}
</style>
EXTRA
]);
?>

        <div class="inv-dash">

            <section class="inv-chart-panel" aria-label="Delivery and catalog overview">
                <div class="inv-chart-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-chart-bar"></i></span> Status Overview</h3>
                        <div class="inv-chart-meta">Total deliveries, completed deliveries, and catalog size</div>
                    </div>
                </div>
                <div class="inv-chart-body">
                    <div class="inv-chart-canvas-wrap">
                        <canvas id="invStatusChart" aria-label="Status overview chart"></canvas>
                    </div>
                    <div class="inv-chart-summary">
                        <div class="inv-chart-metric m-total">
                            <strong><?php echo (int)$total; ?></strong>
                            <span>Total Deliveries</span>
                        </div>
                        <div class="inv-chart-metric m-done">
                            <strong><?php echo (int)$done; ?></strong>
                            <span>Completed Deliveries</span>
                        </div>
                        <div class="inv-chart-metric m-catalog">
                            <strong><?php echo (int)$productCount; ?></strong>
                            <span>Products in Catalog</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="inv-hero">
                <div class="inv-hero-text">
                    <div class="inv-hero-kicker">Inventory Control Center</div>
                    <h1>Operations Overview</h1>
                    <p>Monitor deliveries, track stock levels, and manage your warehouse inventory from one centralized dashboard.</p>
                </div>
            </section>

            <div class="inv-stats-grid">
                <div class="inv-stat-card stat-deliveries">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Total Deliveries</div>
                            <div class="inv-stat-num"><?php echo $total; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-truck"></i></div>
                    </div>
                    <div class="inv-stat-foot">All shipment records</div>
                </div>
                <div class="inv-stat-card stat-pending">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Pending Shipments</div>
                            <div class="inv-stat-num"><?php echo $pending; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-clock"></i></div>
                    </div>
                    <div class="inv-stat-foot">Awaiting delivery</div>
                </div>
                <div class="inv-stat-card stat-completed">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Completed Deliveries</div>
                            <div class="inv-stat-num"><?php echo $done; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <div class="inv-stat-foot"><?php echo $completionRate; ?>% completion rate</div>
                </div>
                <div class="inv-stat-card stat-products">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Products in Catalog</div>
                            <div class="inv-stat-num"><?php echo $productCount; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-cubes"></i></div>
                    </div>
                    <div class="inv-stat-foot"><?php echo number_format($totalStock); ?> total units in stock</div>
                </div>
            </div>

            <div class="inv-overview">
                <div class="inv-overview-chip">
                    <i class="fas fa-warehouse"></i>
                    <div>
                        <strong><?php echo number_format($totalStock); ?></strong>
                        <span>Total stock units</span>
                    </div>
                </div>
                <div class="inv-overview-chip chip-warn">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong><?php echo $lowStockCount; ?></strong>
                        <span>Low stock items (≤ 5)</span>
                    </div>
                </div>
                <div class="inv-overview-chip chip-danger">
                    <i class="fas fa-times-circle"></i>
                    <div>
                        <strong><?php echo $outOfStockCount; ?></strong>
                        <span>Out of stock items</span>
                    </div>
                </div>
            </div>

            <section class="inv-stockin-panel" aria-label="Products stocked per month">
                <div class="inv-stockin-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-chart-area"></i></span> Products Stocked Per Month</h3>
                        <div class="inv-stockin-meta">Total number of products stocked each month · based on real catalog data</div>
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
    var statusCanvas = document.getElementById('invStatusChart');
    if (statusCanvas && typeof Chart !== 'undefined') {
        new Chart(statusCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: {$chartLabels},
                datasets: [{
                    label: 'Count',
                    data: {$chartValues},
                    backgroundColor: [
                        'rgba(75, 139, 42, 0.85)',
                        'rgba(22, 163, 74, 0.85)',
                        'rgba(45, 90, 39, 0.85)'
                    ],
                    borderColor: [
                        'rgba(75, 139, 42, 1)',
                        'rgba(22, 163, 74, 1)',
                        'rgba(45, 90, 39, 1)'
                    ],
                    borderWidth: 1,
                    borderRadius: 8,
                    maxBarThickness: 64
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ctx.parsed.y + ' records'; }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: '600' }, color: '#4b5563', maxRotation: 0, autoSkip: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 11 }, color: '#6b7280' },
                        grid: { color: 'rgba(0,0,0,0.06)' }
                    }
                }
            }
        });
    }

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
                        display: true,
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
