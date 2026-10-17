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

// Completed Deliveries has its own time filter (dperiod)
$periods = ['month' => 'This Month', 'last' => 'Last Month', '3m' => 'Last 3 Months', 'year' => 'This Year'];
$dRange = ias_sales_period_range((string)($_GET['dperiod'] ?? 'year'));
// Server-side paging: count every delivery in the range, fetch only the current page.
$totalRows = ias_count_delivery_rows($db, $dRange['from'], $dRange['to']);
$perPage = 20;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$rows = ias_fetch_delivery_rows($db, $retailId, $dRange['from'], $dRange['to'], [
    'limit' => $perPage,
    'offset' => ($page - 1) * $perPage,
]);
$stats = ias_summarize_deliveries($db, $retailId, $dRange['from'], $dRange['to'], $totalRows);
$historyPageUrl = static function (int $p) use ($dRange): string {
    return 'retail_history.php?' . http_build_query(['dperiod' => $dRange['key'], 'page' => $p]);
};

/** Export links carry the resolved dates so retail_export.php needs no knowledge of the period keys. */
function ias_sales_export_qs(array $range, string $format): string
{
    return h(http_build_query([
        'type' => 'delivery', 'format' => $format, 'preset' => 'custom',
        'date_from' => $range['from']->format('Y-m-d'), 'date_to' => $range['to']->format('Y-m-d'),
    ]));
}

logActivity($db, $retailId, 'view_history', 'Retail Officer viewed sales');

$actions = '<button type="button" class="cx-btn cx-btn-outline no-print" onclick="window.print()"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>'
    . '<a class="cx-btn cx-btn-outline no-print" href="retail_export.php?' . ias_sales_export_qs($dRange, 'csv') . '" title="Raw data: one row per delivery, for importing into other systems"><i class="ph ph-file-csv" aria-hidden="true"></i> CSV</a>'
    . '<a class="cx-btn cx-btn-outline no-print" href="retail_export.php?' . ias_sales_export_qs($dRange, 'excel') . '" title="Excel workbook (.xlsx): summary, deliveries and per-product items with totals and filters"><i class="ph ph-microsoft-excel-logo" aria-hidden="true"></i> Excel</a>'
    . '<a class="cx-btn cx-btn-outline no-print" href="retail_export.php?' . ias_sales_export_qs($dRange, 'pdf') . '" title="Completed deliveries as a PDF file"><i class="ph ph-file-pdf" aria-hidden="true"></i> PDF</a>';

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Sales History',
    'active' => 'history',
    'heading' => 'Sales History',
    'subtitle' => 'Completed deliveries only',
    'actions' => $actions,
    'extra_head' => '<style>' . ias_retail_print_css() . '</style>',
]);

$logoPath = staff_logo_href();
$rangeText = $dRange['from']->format('M j, Y') . ' – ' . $dRange['to']->format('M j, Y');
?>

<div class="print-header">
    <img src="<?php echo h($logoPath); ?>" alt="EasyPC">
    <div>
        <h1>Sales</h1>
        <p><?php echo h($dRange['label']); ?> &middot; <?php echo h($dRange['from']->format('M d, Y') . ' - ' . $dRange['to']->format('M d, Y')); ?></p>
        <p>Prepared by <?php echo h($_SESSION['name'] ?? 'Retail Officer'); ?> on <?php echo h(ias_report_datetime(gmdate('Y-m-d H:i:s'))); ?></p>
    </div>
</div>

<div class="rt-dash">
    <section class="cx-card cx-kpi-card" aria-labelledby="sumTitle">
        <div class="cx-card-head">
            <h2 id="sumTitle"><i class="ph ph-truck" aria-hidden="true"></i> <?php echo h($dRange['label']); ?></h2>
            <span class="cx-card-meta cx-num"><?php echo h($rangeText); ?></span>
        </div>
        <div class="kpis kpis-3">
            <div class="kpi kpi-hero">
                <span class="kpi-label">Deliveries completed</span>
                <strong class="kpi-value cx-num"><?php echo number_format($stats['delivered']); ?></strong>
                <span class="kpi-sub">shipments marked delivered</span>
            </div>
            <div class="kpi">
                <span class="kpi-label">Shipments in range</span>
                <strong class="kpi-value cx-num"><?php echo number_format($stats['total_shipments_in_range']); ?></strong>
                <span class="kpi-sub">all statuses</span>
            </div>
            <div class="kpi">
                <span class="kpi-label">Success rate</span>
                <strong class="kpi-value cx-num"><?php echo number_format($stats['success_rate'], 1); ?>%</strong>
                <span class="kpi-sub">delivered ÷ shipments</span>
            </div>
        </div>
    </section>

    <section class="cx-card" aria-labelledby="delivTitle">
        <div class="cx-card-head">
            <div>
                <h2 id="delivTitle"><i class="ph ph-package" aria-hidden="true"></i> Completed deliveries</h2>
                <p>Finished shipments for your products · open a row for the customer and products</p>
            </div>
            <div class="cx-card-tools no-print">
                <span class="cx-count"><i class="ph ph-list-checks" aria-hidden="true"></i> <?php echo number_format($totalRows); ?> deliver<?php echo $totalRows === 1 ? 'y' : 'ies'; ?></span>
                <form method="get" id="deliveryPeriodForm">
                    <label class="sort-wrap" for="invDeliveryPeriodSelect">
                        <span>Period</span>
                        <select id="invDeliveryPeriodSelect" name="dperiod" onchange="this.form.submit()">
                            <?php foreach ($periods as $k => $label): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $dRange['key'] === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <noscript><button type="submit" class="cx-btn cx-btn-outline">Apply</button></noscript>
                </form>
            </div>
        </div>
        <?php if (empty($rows)): ?>
            <div class="cx-empty">
                <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-truck"></i></span>
                <strong>No completed deliveries in this period</strong>
                <span>Pick a longer period, or check back after orders are delivered.</span>
            </div>
        <?php else: ?>
        <div class="cx-table-wrap">
            <table class="cx-table deliv-table">
                <thead>
                    <tr>
                        <th scope="col" class="col-caret"><span class="cx-sr">Details</span></th>
                        <th scope="col">Shipment</th>
                        <th scope="col">Order</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Carrier</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col">Delivered</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): $i = (int)$i; ?>
                    <tr class="drill-toggle" data-drill="<?php echo $i; ?>">
                        <td class="col-caret">
                            <button type="button" class="drill-btn" id="drillBtn-<?php echo $i; ?>" aria-expanded="false" aria-controls="drill-<?php echo $i; ?>">
                                <i class="ph ph-caret-right" aria-hidden="true"></i>
                                <span class="cx-sr">Details for shipment #<?php echo (int)$r['shipment_id']; ?></span>
                            </button>
                        </td>
                        <td data-label="Shipment" class="cx-mono"><strong>#<?php echo (int)$r['shipment_id']; ?></strong></td>
                        <td data-label="Order" class="cx-mono">#<?php echo (int)$r['order_id']; ?></td>
                        <td data-label="Customer" class="deliv-customer"><?php echo h(trim($r['customer_name'] . ' ' . $r['customer_surname'])); ?></td>
                        <td data-label="Carrier"><?php echo h($r['carrier']); ?></td>
                        <td data-label="Total" class="num cx-num"><span class="price-tag">₱<?php echo number_format((float)$r['total'], 2); ?></span></td>
                        <td data-label="Delivered" class="deliv-when cx-num"><?php echo h($r['updated_at']); ?></td>
                        <td data-label="Status"><span class="stock-status-pill ok"><i class="ph-fill ph-check-circle" aria-hidden="true"></i> Delivered</span></td>
                    </tr>
                    <tr class="drill-row" id="drill-<?php echo $i; ?>" hidden>
                        <td colspan="8">
                            <dl class="drill-grid">
                                <div><dt>Customer email</dt><dd><?php echo h($r['customer_email']); ?></dd></div>
                                <div><dt>Order placed</dt><dd class="cx-num"><?php echo h($r['order_created']); ?></dd></div>
                                <div class="drill-wide"><dt>Products</dt><dd><?php echo h($r['products']); ?></dd></div>
                                <div class="drill-wide"><dt>Category</dt><dd><?php echo h(implode(', ', ias_category_groups_in($r['categories'] ?? ''))); ?></dd></div>
                            </dl>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php staff_pagination($page, $totalPages, $totalRows, $perPage, $historyPageUrl, 'Completed deliveries pages'); ?>
    </section>
</div>

<?php
staff_page_end(<<<'SCRIPTS'
<script>
(function () {
    function toggleDrill(i) {
        var row = document.getElementById('drill-' + i);
        var btn = document.getElementById('drillBtn-' + i);
        if (!row || !btn) return;
        var open = row.hidden;
        row.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.closest('tr').classList.toggle('is-open', open);
    }
    /* The button is the control; a click anywhere else on the row does the same (but not on text selection) */
    document.addEventListener('click', function (e) {
        var tr = e.target.closest('tr.drill-toggle');
        if (!tr) return;
        if (!e.target.closest('.drill-btn') && String(window.getSelection ? window.getSelection() : '') !== '') return;
        toggleDrill(tr.getAttribute('data-drill'));
    });
})();
</script>
SCRIPTS);
