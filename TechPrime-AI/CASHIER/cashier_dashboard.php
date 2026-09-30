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

staff_page_start([
    'role' => 'cashier',
    'title' => 'Cashier Dashboard',
    'active' => 'dashboard',
    'heading' => 'Cashier Dashboard',
    'subtitle' => 'Welcome, ' . ($_SESSION['name'] ?? 'Cashier') . ' · ' . pos_format_datetime(gmdate('Y-m-d H:i:s'), 'l, F j, Y'),
    'extra_head' => '<link rel="stylesheet" href="cashier.css?v=5">',
]);
?>
        <div class="cash-page">
            <div class="cash-quick">
                <a href="cashier_pos.php" class="btn btn-primary"><i class="fas fa-cash-register"></i> Start New Sale</a>
                <a href="cashier_stock_alerts.php" class="btn btn-outline"><i class="fas fa-bell"></i> View Stock Alerts</a>
            </div>

            <div class="inv-stats-grid">
                <div class="inv-stat-card stat-green">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Today's Sales</div>
                            <div class="inv-stat-num"><?php echo (int)$today['count']; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-receipt"></i></div>
                    </div>
                    <div class="inv-stat-foot">Completed transactions today</div>
                </div>
                <div class="inv-stat-card stat-dark">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Today's Total</div>
                            <div class="inv-stat-num"><?php echo h(pos_peso($today['total'])); ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-coins"></i></div>
                    </div>
                    <div class="inv-stat-foot">Average sale <?php echo h(pos_peso($avgSale)); ?> · <?php echo (int)$today['units']; ?> unit<?php echo $today['units'] === 1 ? '' : 's'; ?> sold</div>
                </div>
                <div class="inv-stat-card stat-yellow">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Low Stock</div>
                            <div class="inv-stat-num" id="statLow"><?php echo (int)$alerts['low']; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                    <div class="inv-stat-foot">Products with 6–15 units left</div>
                </div>
                <div class="inv-stat-card stat-red">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Critical / Out</div>
                            <div class="inv-stat-num" id="statCritOut"><?php echo (int)$alerts['critical']; ?> / <?php echo (int)$alerts['out']; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-times-circle"></i></div>
                    </div>
                    <div class="inv-stat-foot">5 or fewer units / none left</div>
                </div>
            </div>

            <div class="cash-grid-2">
                <section class="cash-panel" aria-label="Recent transactions">
                    <div class="cash-panel-header">
                        <div>
                            <h3><span class="card-icon"><i class="fas fa-history"></i></span> My Recent Transactions</h3>
                            <div class="card-subtitle">Your last <?php echo count($recent); ?> sale<?php echo count($recent) === 1 ? '' : 's'; ?> · reprint a receipt anytime</div>
                        </div>
                    </div>
                    <div class="cash-panel-body">
                        <?php if (!$recent): ?>
                            <div class="empty-state-row"><i class="fas fa-receipt"></i> No sales yet. Start a new sale to see it here.</div>
                        <?php else: ?>
                        <div class="stocks-table-wrap">
                            <table class="stocks-table compact">
                                <thead><tr>
                                    <th>Invoice / Date</th>
                                    <th class="num">Items</th>
                                    <th>Payment</th>
                                    <th class="num">Total</th>
                                    <th></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($recent as $s): ?>
                                    <tr>
                                        <td>
                                            <span class="mono" style="white-space:nowrap;font-weight:700;"><?php echo h($s['invoice_no']); ?></span>
                                            <?php if ($s['status'] === 'voided'): ?><span class="stock-status-pill out">Voided</span><?php endif; ?>
                                            <span class="stocks-pcat"><?php echo h(pos_format_datetime($s['created_at'])); ?></span>
                                        </td>
                                        <td class="num"><?php echo (int)$s['units']; ?></td>
                                        <td><?php echo h(pos_payment_label((string)$s['payment_method'])); ?></td>
                                        <td class="num price-tag"><?php echo h(pos_peso($s['total'])); ?></td>
                                        <td class="num">
                                            <a class="btn btn-outline btn-xs" href="cashier_receipt.php?id=<?php echo (int)$s['id']; ?>&amp;print=1" target="_blank" rel="noopener">
                                                <i class="fas fa-print"></i> Reprint
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="cash-panel" aria-label="Stock alerts">
                    <div class="cash-panel-header">
                        <div>
                            <h3><span class="card-icon"><i class="fas fa-bell"></i></span> Stock Alerts</h3>
                            <div class="card-subtitle">Read-only · lowest stock first · restocking is handled by the Inventory Custodian</div>
                        </div>
                        <a href="cashier_stock_alerts.php" class="btn btn-outline btn-xs"><i class="fas fa-expand"></i> View all</a>
                    </div>
                    <div class="cash-panel-body">
                        <div class="alert-counts">
                            <span class="stock-status-pill out"><i class="fas fa-times-circle"></i> <span id="cntOut"><?php echo (int)$alerts['out']; ?></span> out</span>
                            <span class="stock-status-pill critical"><i class="fas fa-exclamation-circle"></i> <span id="cntCritical"><?php echo (int)$alerts['critical']; ?></span> critical</span>
                            <span class="stock-status-pill low"><i class="fas fa-exclamation-triangle"></i> <span id="cntLow"><?php echo (int)$alerts['low']; ?></span> low</span>
                            <span class="alerts-live" id="alertsLive" title="Refreshes every 30 seconds">Live stock</span>
                        </div>
                        <div class="empty-state-row" id="alertsEmpty" hidden><i class="fas fa-check-circle"></i> All products are well stocked.</div>
                        <div class="stocks-table-wrap alerts-scroll" id="alertsWrap" tabindex="0" aria-label="Stock alert list">
                            <table class="stocks-table compact">
                                <thead><tr><th>Product</th><th class="num">Stock</th><th>Status</th></tr></thead>
                                <tbody id="alertsRows"></tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </div>
<?php
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$script = '<script src="stock_alerts.js?v=1"></script>'
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
        document.getElementById('statLow').textContent = d.low;
        document.getElementById('statCritOut').textContent = d.critical + ' / ' + d.out;
        wrap.hidden = d.items.length === 0;
        empty.hidden = d.items.length !== 0;
        rows.innerHTML = d.items.map(function (p) {
            return '<tr>' +
                '<td><div class="stocks-pname">' + SA.esc(p.name || ('Product #' + p.id)) + '</div>' +
                '<span class="stocks-pcat">' + SA.esc(p.category) + '</span></td>' +
                '<td class="num"><span class="stocks-qty' + SA.qtyClass(p.status) + '">' + p.stock + '</span></td>' +
                '<td>' + SA.pill(p.status) + '</td>' +
            '</tr>';
        }).join('');
        live.textContent = 'Live · updated ' + SA.timeLabel(at);
    }

    render(INITIAL_ALERTS, new Date());
    SA.sync('../backend/api/stock_alerts.php', render);
})();
</script>
SCRIPT;

staff_page_end($script);
