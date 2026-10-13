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
$rows = ias_fetch_delivery_rows($db, $retailId, $dRange['from'], $dRange['to']);
$stats = ias_summarize_deliveries($db, $retailId, $dRange['from'], $dRange['to'], count($rows));

/** Export links carry the resolved dates so retail_export.php needs no knowledge of the period keys. */
function ias_sales_export_qs(array $range, string $format): string
{
    return h(http_build_query([
        'type' => 'delivery', 'format' => $format, 'preset' => 'custom',
        'date_from' => $range['from']->format('Y-m-d'), 'date_to' => $range['to']->format('Y-m-d'),
    ]));
}

logActivity($db, $retailId, 'view_history', 'Retail Officer viewed sales');

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Sales History',
    'active' => 'history',
    'heading' => 'Sales History',
    'subtitle' => 'Completed deliveries only',
    'extra_head' => <<<'EXTRA'
<style>
.report-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: flex-end; margin-bottom: 4px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.inv-stockin-panel { background: linear-gradient(180deg, #ffffff 0%, #f4f9f0 100%); border: 1px solid var(--teal-light, #c6e6b3); border-radius: 18px; box-shadow: 0 10px 28px rgba(75, 139, 42, 0.1); overflow: hidden; margin-bottom: 18px; }
.inv-stockin-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 22px 24px 16px; background: linear-gradient(135deg, #eef8e6 0%, #ffffff 70%); border-bottom: 1px solid rgba(75, 139, 42, 0.12); }
.inv-stockin-header h3 { margin: 0; font-size: 17px; font-weight: 800; color: var(--ep-green-dark); display: flex; align-items: center; gap: 12px; }
.inv-stockin-header .card-icon { width: 40px; height: 40px; border-radius: 12px; background: var(--ep-green); color: #fff; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 6px 14px rgba(75, 139, 42, 0.28); }
.inv-stockin-meta { margin-top: 4px; font-size: 12.5px; color: var(--text-muted); font-weight: 500; }
.inv-stockin-tools { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.inv-stockin-badge { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 999px; background: #fff; border: 1px solid var(--teal-light, #c6e6b3); color: var(--ep-green-dark); font-size: 12px; font-weight: 700; }
.inv-period-select { height: 38px; padding: 0 12px; border-radius: 999px; border: 1px solid var(--teal-light, #c6e6b3); background: #fff; font-family: inherit; font-size: 12.5px; font-weight: 700; color: var(--ep-green-dark); outline: none; cursor: pointer; box-shadow: 0 2px 8px rgba(75, 139, 42, 0.06); }
.inv-period-select:focus { border-color: var(--ep-green); box-shadow: 0 0 0 3px rgba(97, 179, 55, 0.15); }
.inv-stockin-panel .card-body { padding: 18px 20px 22px; }
.deliv-wrap { border: 1px solid var(--ep-border, #e5e7e5); border-radius: 12px; background: #fff; overflow-x: auto; }
.deliv-table { width: 100%; border-collapse: collapse; min-width: 760px; }
.deliv-table th { padding: 12px 14px; text-align: left; font-size: 11.5px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--ep-green-dark); background: #f4f9f0; border-bottom: 1px solid rgba(75, 139, 42, 0.18); white-space: nowrap; }
.deliv-table td { padding: 12px 14px; border-bottom: 1px solid #eef1ee; vertical-align: middle; font-size: 13.5px; }
.deliv-table tr.drill-toggle:hover td { background: #f8fbf6; }
.deliv-table .col-caret { width: 36px; padding-right: 0; text-align: center; }
.deliv-table .col-num { text-align: right; white-space: nowrap; }
.deliv-table .col-nowrap { white-space: nowrap; }
.deliv-table tbody tr:last-child td { border-bottom: 0; }
@media print { .inv-stockin-tools { display: none !important; } .inv-stockin-panel, .inv-stockin-header { background: #fff !important; } }
</style>
EXTRA
    . '<style>' . ias_retail_print_css() . '</style>',
]);

$logoPath = staff_logo_href();
?>

<div class="print-header">
    <img src="<?php echo h($logoPath); ?>" alt="EasyPC">
    <div>
        <h1>Sales</h1>
        <p><?php echo h($dRange['label']); ?> &middot; <?php echo h($dRange['from']->format('M d, Y') . ' - ' . $dRange['to']->format('M d, Y')); ?></p>
        <p>Prepared by <?php echo h($_SESSION['name'] ?? 'Retail Officer'); ?> on <?php echo h(ias_report_datetime(gmdate('Y-m-d H:i:s'))); ?></p>
    </div>
</div>

        <div class="report-toolbar no-print">
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()" title="Print this page"><i class="fas fa-print"></i> Print</button>
            <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_sales_export_qs($dRange, 'csv'); ?>" title="Raw data: one row per delivery, for importing into other systems"><i class="fas fa-file-csv"></i> Export CSV</a>
            <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_sales_export_qs($dRange, 'excel'); ?>" title="Excel workbook (.xlsx): summary, deliveries and per-product items with totals and filters"><i class="fas fa-file-excel"></i> Export Excel</a>
            <a class="btn btn-outline btn-sm" href="retail_export.php?<?php echo ias_sales_export_qs($dRange, 'pdf'); ?>" title="Download completed deliveries as a PDF file"><i class="fas fa-file-pdf"></i> Export PDF</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Deliveries</div>
                <div class="stat-num"><?php echo number_format($stats['delivered']); ?></div>
                <div class="stat-icon"><i class="fas fa-truck"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Shipments in Range</div>
                <div class="stat-num"><?php echo number_format($stats['total_shipments_in_range']); ?></div>
                <div class="stat-icon"><i class="fas fa-box"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Success Rate</div>
                <div class="stat-num"><?php echo number_format($stats['success_rate'], 1); ?>%</div>
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            </div>
        </div>

        <section class="inv-stockin-panel" aria-label="Completed deliveries">
            <div class="inv-stockin-header">
                <div>
                    <h3> Completed Deliveries</h3>
                    <div class="inv-stockin-meta">Finished shipments for your products &mdash; click a row for details · <?php echo h($dRange['from']->format('M d, Y') . ' – ' . $dRange['to']->format('M d, Y')); ?></div>
                </div>
                <div class="inv-stockin-tools">
                    <form method="get" id="deliveryPeriodForm">
                        <label class="sr-only" for="invDeliveryPeriodSelect">Time period</label>
                        <select id="invDeliveryPeriodSelect" name="dperiod" class="inv-period-select" aria-label="Completed deliveries time period" onchange="this.form.submit()">
                            <?php foreach ($periods as $k => $label): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $dRange['key'] === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <div class="card-body">
                    <table class="deliv-table">
                        <thead>
                            <tr>
                                <th class="col-caret"></th>
                                <th>Shipment</th>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Carrier</th>
                                <th class="col-num">Total</th>
                                <th>Delivered</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $i => $r): ?>
                            <tr class="drill-toggle" onclick="toggleDrill(<?php echo (int)$i; ?>)" style="cursor:pointer;">
                                <td class="col-caret"><i class="fas fa-caret-right" id="caret-<?php echo (int)$i; ?>"></i></td>
                                <td class="col-nowrap"><strong>#<?php echo (int)$r['shipment_id']; ?></strong></td>
                                <td class="col-nowrap">#<?php echo (int)$r['order_id']; ?></td>
                                <td><?php echo h(trim($r['customer_name'] . ' ' . $r['customer_surname'])); ?></td>
                                <td><?php echo h($r['carrier']); ?></td>
                                <td class="col-num">PHP <?php echo number_format((float)$r['total'], 2); ?></td>
                                <td class="text-muted text-small col-nowrap"><?php echo h($r['updated_at']); ?></td>
                                <td><span class="badge badge-active">Delivered</span></td>
                            </tr>
                            <tr id="drill-<?php echo (int)$i; ?>" style="display:none;">
                                <td colspan="8" style="background:#fafbfa;">
                                    <div style="padding:10px 16px;">
                                        <div class="text-small"><strong>Customer email:</strong> <?php echo h($r['customer_email']); ?></div>
                                        <div class="text-small"><strong>Products:</strong> <?php echo h($r['products']); ?></div>
                                        <div class="text-small"><strong>Category:</strong> <?php echo h(implode(', ', ias_category_groups_in($r['categories'] ?? ''))); ?></div>
                                        <div class="text-small"><strong>Order placed:</strong> <?php echo h($r['order_created']); ?></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="empty-state">No completed deliveries in this range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
            </div>
        </section>


<?php
staff_page_end(<<<'SCRIPTS'
<script>
function toggleDrill(i) {
    const row = document.getElementById('drill-' + i);
    const caret = document.getElementById('caret-' + i);
    const open = row.style.display !== 'none';
    row.style.display = open ? 'none' : '';
    caret.classList.toggle('fa-caret-right', open);
    caret.classList.toggle('fa-caret-down', !open);
}
</script>
SCRIPTS);
