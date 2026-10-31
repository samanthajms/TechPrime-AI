<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('cashier');

$uid = (int)$_SESSION['user_id'];

$today = pos_today_stats($db, $uid);
$recent = pos_recent_sales($db, $uid, 10);
$alerts = pos_stock_alerts($db);
$avgSale = $today['count'] > 0 ? $today['total'] / $today['count'] : 0;
$firstName = trim((string)($_SESSION['name'] ?? '')) ?: 'Cashier';
$payIcons = ['cash' => 'ph-money', 'gcash' => 'ph-device-mobile', 'maya' => 'ph-device-mobile', 'card' => 'ph-credit-card'];

staff_page_start([
    'role' => 'cashier',
    'title' => 'Cashier Dashboard',
    'active' => 'dashboard',
    'heading' => 'Good to see you, ' . $firstName,
    'subtitle' => pos_format_datetime(gmdate('Y-m-d H:i:s'), 'l, F j, Y') . ' · your sales and stock at a glance',
    'actions' => '<a href="cashier_stock_alerts.php" class="cx-btn cx-btn-outline"><i class="ph ph-bell" aria-hidden="true"></i> Stock alerts</a>',
]);
?>
        <div class="dash">
            <div class="dash-top">
                <section class="cx-card dash-today" aria-labelledby="todayTitle">
                    <div class="cx-card-head">
                        <h2 id="todayTitle"><i class="ph ph-chart-line-up" aria-hidden="true"></i> Today</h2>
                        <span class="cx-card-meta">Completed sales only</span>
                    </div>
                    <div class="kpis">
                        <div class="kpi kpi-hero">
                            <span class="kpi-label">Revenue</span>
                            <strong class="kpi-value cx-num"><?php echo h(pos_peso($today['total'])); ?></strong>
                            <span class="kpi-sub">VAT-inclusive</span>
                        </div>
                        <div class="kpi">
                            <span class="kpi-label">Transactions</span>
                            <strong class="kpi-value cx-num"><?php echo (int)$today['count']; ?></strong>
                            <span class="kpi-sub">sale<?php echo (int)$today['count'] === 1 ? '' : 's'; ?> completed</span>
                        </div>
                        <div class="kpi">
                            <span class="kpi-label">Average sale</span>
                            <strong class="kpi-value cx-num"><?php echo h(pos_peso($avgSale)); ?></strong>
                            <span class="kpi-sub">per transaction</span>
                        </div>
                        <div class="kpi">
                            <span class="kpi-label">Units sold</span>
                            <strong class="kpi-value cx-num"><?php echo (int)$today['units']; ?></strong>
                            <span class="kpi-sub">item<?php echo (int)$today['units'] === 1 ? '' : 's'; ?> scanned out</span>
                        </div>
                    </div>
                </section>

                <a class="dash-cta" href="cashier_pos.php">
                    <span class="dash-cta-icon" aria-hidden="true"><i class="ph ph-cash-register"></i></span>
                    <span class="dash-cta-text">
                        <strong>Start a new sale</strong>
                        <span>Open checkout and scan the first item</span>
                    </span>
                    <i class="ph ph-arrow-right dash-cta-arrow" aria-hidden="true"></i>
                </a>
            </div>

            <div class="dash-grid">
                <section class="cx-card" aria-labelledby="recentTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="recentTitle"><i class="ph ph-receipt" aria-hidden="true"></i> Recent transactions</h2>
                            <p>Your last <?php echo count($recent); ?> sale<?php echo count($recent) === 1 ? '' : 's'; ?> · reprint a receipt anytime</p>
                        </div>
                    </div>
                    <?php if (!$recent): ?>
                        <div class="cx-empty">
                            <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-receipt"></i></span>
                            <strong>No sales yet</strong>
                            <span>Completed sales will appear here with a reprint button.</span>
                            <a href="cashier_pos.php" class="cx-btn cx-btn-primary"><i class="ph ph-cash-register" aria-hidden="true"></i> Start a sale</a>
                        </div>
                    <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table tx-table">
                            <thead><tr>
                                <th scope="col">Invoice</th>
                                <th scope="col" class="num">Items</th>
                                <th scope="col">Payment</th>
                                <th scope="col" class="num">Total</th>
                                <th scope="col"><span class="cx-sr">Receipt</span></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($recent as $s):
                                $pm = (string)$s['payment_method'];
                                $voided = $s['status'] === 'voided'; ?>
                                <tr<?php echo $voided ? ' class="is-voided"' : ''; ?>>
                                    <td data-label="Invoice">
                                        <span class="tx-inv mono"><?php echo h($s['invoice_no']); ?></span>
                                        <span class="tx-time"><?php echo h(pos_format_datetime($s['created_at'])); ?></span>
                                        <?php if ($voided): ?><span class="cx-badge cx-badge-out">Voided</span><?php endif; ?>
                                    </td>
                                    <td data-label="Items" class="num cx-num"><?php echo (int)$s['units']; ?></td>
                                    <td data-label="Payment">
                                        <span class="pay-chip pay-<?php echo h($pm); ?>"><i class="ph <?php echo h($payIcons[$pm] ?? 'ph-wallet'); ?>" aria-hidden="true"></i> <?php echo h(pos_payment_label($pm)); ?></span>
                                    </td>
                                    <td data-label="Total" class="num tx-total cx-num"><?php echo h(pos_peso($s['total'])); ?></td>
                                    <td class="num tx-action">
                                        <a class="cx-btn cx-btn-outline cx-btn-sm" href="cashier_receipt.php?id=<?php echo (int)$s['id']; ?>&amp;print=1" data-print-receipt
                                           aria-label="Reprint receipt <?php echo h($s['invoice_no']); ?>">
                                            <i class="ph ph-printer" aria-hidden="true"></i> Reprint
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>

                <section class="cx-card watch" aria-labelledby="watchTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="watchTitle"><i class="ph ph-bell" aria-hidden="true"></i> Stock watch</h2>
                            <p>Read-only · restocking is handled by the Inventory Custodian</p>
                        </div>
                        <a href="cashier_stock_alerts.php" class="cx-link">View all <i class="ph ph-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    <div class="watch-counts">
                        <div class="watch-count is-out">
                            <strong class="cx-num" id="cntOut"><?php echo (int)$alerts['out']; ?></strong>
                            <span><i class="ph-fill ph-x-circle" aria-hidden="true"></i> Out of stock</span>
                        </div>
                        <div class="watch-count is-critical">
                            <strong class="cx-num" id="cntCritical"><?php echo (int)$alerts['critical']; ?></strong>
                            <span><i class="ph-fill ph-warning-circle" aria-hidden="true"></i> Critical</span>
                        </div>
                        <div class="watch-count is-low">
                            <strong class="cx-num" id="cntLow"><?php echo (int)$alerts['low']; ?></strong>
                            <span><i class="ph-fill ph-warning" aria-hidden="true"></i> Low</span>
                        </div>
                    </div>
                    <div class="watch-bar">
                        <span>Lowest stock first</span>
                        <span class="alerts-live" id="alertsLive" title="Refreshes every 30 seconds">Live stock</span>
                    </div>
                    <div class="cx-empty cx-empty-sm" id="alertsEmpty" hidden>
                        <span class="cx-empty-icon is-ok" aria-hidden="true"><i class="ph ph-check-circle"></i></span>
                        <strong>All products are well stocked</strong>
                    </div>
                    <div class="watch-scroll" id="alertsWrap" tabindex="0" aria-label="Stock alert list">
                        <ul class="watch-list" id="alertsRows"></ul>
                    </div>
                </section>
            </div>
        </div>
<?php
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$script = '<script src="stock_alerts.js?v=3"></script>'
    . '<script src="receipt_print.js?v=2"></script>'
    . '<script>var INITIAL_ALERTS = ' . $alertsJson . ';</script>'
    . <<<'SCRIPT'
<script>
(function () {
    'use strict';
    var SA = CashierStockAlerts;
    var rows = document.getElementById('alertsRows');
    var wrap = document.getElementById('alertsWrap');
    var empty = document.getElementById('alertsEmpty');
    var live = document.getElementById('alertsLive');

    function render(d, at) {
        document.getElementById('cntOut').textContent = d.out;
        document.getElementById('cntCritical').textContent = d.critical;
        document.getElementById('cntLow').textContent = d.low;
        wrap.hidden = d.items.length === 0;
        empty.hidden = d.items.length !== 0;
        rows.innerHTML = d.items.map(function (p) {
            return '<li class="watch-item is-' + SA.esc(p.status) + '">' +
                '<div class="watch-item-info"><div class="watch-item-name">' + SA.esc(p.name || ('Product #' + p.id)) + '</div>' +
                '<div class="watch-item-cat">' + SA.esc(p.category) + '</div></div>' +
                '<div class="watch-item-qty"><span class="stocks-qty' + SA.qtyClass(p.status) + '">' + p.stock + '</span><span>left</span></div>' +
                SA.pill(p.status) +
            '</li>';
        }).join('');
        SA.markLive(live, at);
    }

    render(INITIAL_ALERTS, new Date());
    SA.sync('../backend/api/stock_alerts.php', render, function () { SA.markStale(live); });
})();
</script>
SCRIPT;

staff_page_end($script);
