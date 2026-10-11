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

$cardRange = ias_resolve_section_range($_GET, 'card', 'this_month');
$cardRows = ias_fetch_sales_rows($db, $retailId, $cardRange['from'], $cardRange['to']);
$cardSummary = ias_summarize_sales($cardRows);

$msMonths = max(1, min(24, (int)($_GET['ms_months'] ?? 12)));
$monthlySales = ias_monthly_sales_report($db, $retailId, $msMonths);

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
    'subtitle' => 'Real-time sales analytics',
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
staff_page_end(<<<'SCRIPTS'
<script>
function openFilterModal(id){ document.getElementById(id).classList.add('open'); }
function closeFilterModal(id){ document.getElementById(id).classList.remove('open'); }
function toggleDetail(id){ document.getElementById(id).classList.toggle('open'); }
function toggleSectionCustom(sel, rowId){
    document.getElementById(rowId).style.display = (sel.value === 'custom') ? '' : 'none';
}
</script>
SCRIPTS);
