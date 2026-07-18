<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/product_categories.php';
require_once __DIR__ . '/../includes/retail_reports.php';

date_default_timezone_set('Asia/Manila');

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];

$cnt = [];
$cnt['clients'] = $db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetch_row()[0];
$cnt['retail_officer'] = $db->query("SELECT COUNT(*) FROM users WHERE role='retail_officer'")->fetch_row()[0];
$cnt['technician'] = $db->query("SELECT COUNT(*) FROM users WHERE role='technician'")->fetch_row()[0];
$cnt['inventory_custodian'] = $db->query("SELECT COUNT(*) FROM users WHERE role='inventory_custodian'")->fetch_row()[0];
$cnt['orders'] = $db->query("SELECT COUNT(*) FROM orders")->fetch_row()[0];

$presets = ias_report_date_presets();
$categories = ias_product_categories();

$dfRange = ias_resolve_section_range($_GET, 'df', 'this_month');
$dfCategory = trim($_GET['df_category'] ?? '');
$dfFilters = $dfCategory !== '' ? ['category' => $dfCategory] : [];
$dfDays = max(7, min(90, (int)($_GET['df_days'] ?? 30)));
$demandForecast = ias_product_demand_forecast($db, null, $dfRange['from'], $dfRange['to'], $dfDays, $dfFilters);

$sfRange = ias_resolve_section_range($_GET, 'sf', 'this_month');
$sfDays = max(7, min(60, (int)($_GET['sf_days'] ?? 14)));
$salesForecast = ias_sales_forecast($db, null, $sfRange['from'], $sfRange['to'], $sfDays);

logActivity($db, $admin_id, 'view_dashboard', 'Admin viewed dashboard');

function admin_dashboard_preserve_hidden(array $ownKeys): void
{
    foreach ($_GET as $k => $v) {
        if (in_array($k, $ownKeys, true) || is_array($v)) {
            continue;
        }
        echo '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
}

$dashboardCss = <<<'CSS'
.card-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.filter-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:3000; align-items:center; justify-content:center; padding:20px; }
.filter-modal.open { display:flex; }
.filter-modal-card { background:#fff; border-radius:12px; padding:22px; width:min(460px,100%); max-height:90vh; overflow-y:auto; border:2px solid var(--ep-green); }
.filter-modal-card h4 { margin:0 0 14px; color:var(--ep-green-dark); }
.detail-panel { display:none; margin-top:14px; }
.detail-panel.open { display:block; }
CSS;

staff_page_start([
    'role' => 'admin',
    'title' => 'Admin Dashboard',
    'active' => 'dashboard',
    'heading' => 'System Administration',
    'subtitle' => 'Dashboard overview with forecasting',
    'extra_head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><style>' . $dashboardCss . '</style>',
]);
?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Orders</div>
                <div class="stat-num"><?php echo (int)$cnt['orders']; ?></div>
                <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Clients</div>
                <div class="stat-num"><?php echo (int)$cnt['clients']; ?></div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Retail Officers</div>
                <div class="stat-num"><?php echo (int)$cnt['retail_officer']; ?></div>
                <div class="stat-icon"><i class="fas fa-store"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Inventory Custodian</div>
                <div class="stat-num"><?php echo (int)$cnt['inventory_custodian']; ?></div>
                <div class="stat-icon"><i class="fas fa-warehouse"></i></div>
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
                            <thead><tr><th>Date</th><th>Type</th><th>Sales (PHP)</th></tr></thead>
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

<div id="dfModal" class="filter-modal no-print" onclick="if(event.target===this)closeFilterModal('dfModal')">
    <div class="filter-modal-card">
        <h4><i class="fas fa-filter"></i> Demand Forecast Filters</h4>
        <form method="get">
            <?php admin_dashboard_preserve_hidden(['df_preset', 'df_from', 'df_to', 'df_category', 'df_days']); ?>
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
                    <option value="">All Products</option>
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
            <?php admin_dashboard_preserve_hidden(['sf_preset', 'sf_from', 'sf_to', 'sf_days']); ?>
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
