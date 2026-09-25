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
$alerts = pos_stock_alerts($db, 10);
$avgSale = $today['count'] > 0 ? $today['total'] / $today['count'] : 0;

staff_page_start([
    'role' => 'cashier',
    'title' => 'Cashier Dashboard',
    'active' => 'dashboard',
    'heading' => 'Cashier Dashboard',
    'subtitle' => 'Welcome, ' . ($_SESSION['name'] ?? 'Cashier') . ' · ' . pos_format_datetime(gmdate('Y-m-d H:i:s'), 'l, F j, Y'),
    'extra_head' => '<link rel="stylesheet" href="cashier.css?v=3">',
]);
?>
        <div class="cash-page">
            <div class="cash-quick">
                <a href="cashier_pos.php" class="btn btn-primary"><i class="fas fa-cash-register"></i> Start New Sale</a>
                <a href="cashier_stock_in.php" class="btn btn-outline"><i class="fas fa-dolly"></i> Receive Stock</a>
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
                            <div class="inv-stat-num"><?php echo (int)$alerts['low']; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                    <div class="inv-stat-foot">Products with 6–15 units left</div>
                </div>
                <div class="inv-stat-card stat-red">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Critical / Out</div>
                            <div class="inv-stat-num"><?php echo (int)$alerts['critical']; ?> / <?php echo (int)$alerts['out']; ?></div>
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
                    </div>
                    <div class="cash-panel-body">
                        <div class="alert-counts">
                            <span class="stock-status-pill out"><i class="fas fa-times-circle"></i> <?php echo (int)$alerts['out']; ?> out</span>
                            <span class="stock-status-pill critical"><i class="fas fa-exclamation-circle"></i> <?php echo (int)$alerts['critical']; ?> critical</span>
                            <span class="stock-status-pill low"><i class="fas fa-exclamation-triangle"></i> <?php echo (int)$alerts['low']; ?> low</span>
                        </div>
                        <?php if (!$alerts['items']): ?>
                            <div class="empty-state-row"><i class="fas fa-check-circle"></i> All products are well stocked.</div>
                        <?php else: ?>
                        <div class="stocks-table-wrap">
                            <table class="stocks-table compact">
                                <thead><tr><th>Product</th><th class="num">Stock</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($alerts['items'] as $p):
                                    $stock = (int)$p['stock'];
                                    $status = inv_stock_status_label($stock);
                                    $label = ['out' => 'Out of Stock', 'critical' => 'Critical', 'low' => 'Low'][$status] ?? 'In Stock';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="stocks-pname"><?php echo h((string)$p['name']); ?></div>
                                            <span class="stocks-pcat"><?php echo h((string)$p['category']); ?></span>
                                        </td>
                                        <td class="num"><span class="stocks-qty <?php echo $status === 'out' ? 'out' : 'warn'; ?>"><?php echo $stock; ?></span></td>
                                        <td><span class="stock-status-pill <?php echo h($status); ?>"><?php echo h($label); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
<?php
staff_page_end();
