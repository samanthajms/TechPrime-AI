<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];

$q = trim((string)($_GET['q'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$range = trim((string)($_GET['range'] ?? 'all'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$allowedActions = [
    '' => 'All Activities',
    'add_product' => 'Stock Added',
    'edit_product' => 'Stock Updated',
    'pos_stock_in' => 'Stock In',
    'stock_out' => 'Stock Out',
    'delete_product' => 'Product Removed',
    'generate_barcode' => 'Barcode Generated',
    'update_order_status' => 'Order Updated',
    'complete_order' => 'Order Completed',
    'accept_order' => 'Order Accepted',
];
if (!array_key_exists($actionFilter, $allowedActions)) {
    $actionFilter = '';
}

$allowedRanges = [
    'all' => 'All Time',
    'today' => 'Today',
    '7d' => 'Last 7 Days',
    '30d' => 'Last 30 Days',
    'month' => 'This Month',
];
if (!array_key_exists($range, $allowedRanges)) {
    $range = 'all';
}

/* "Stock Out" is not a log action: it lists every successful stock decrease, one row per product. */
$stockOutView = $actionFilter === 'stock_out';

/** SQL condition limiting timestamp column $col to the chosen date range ('' = all time). */
function inv_audit_range_sql(string $range, string $col): string
{
    switch ($range) {
        case 'today':
            return "DATE({$col}) = CURRENT_DATE";
        case '7d':
            return "{$col} >= (NOW() - INTERVAL '7 day')";
        case '30d':
            return "{$col} >= (NOW() - INTERVAL '30 day')";
        case 'month':
            return "date_trunc('month', {$col}) = date_trunc('month', CURRENT_DATE)";
    }
    return '';
}

$inventoryActions = ['add_product', 'edit_product', 'pos_stock_in', 'delete_product', 'generate_barcode', 'update_order_status', 'complete_order', 'accept_order'];

$where = [
    'l.action IN (' . implode(',', array_fill(0, count($inventoryActions), '?')) . ')',
    'u.role = ?',
];
$types = str_repeat('s', count($inventoryActions) + 1);
$params = array_merge($inventoryActions, ['inventory_custodian']);

if ($actionFilter !== '') {
    $where = ['l.action = ?', 'u.role = ?'];
    $types = 'ss';
    $params = [$actionFilter, 'inventory_custodian'];
}

if ($q !== '') {
    $where[] = '(COALESCE(u.name, \'\') LIKE ? OR COALESCE(l.details, \'\') LIKE ? OR COALESCE(l.action, \'\') LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$rangeSql = inv_audit_range_sql($range, 'l.created_at');
if ($rangeSql !== '') {
    $where[] = $rangeSql;
}

$whereSql = implode(' AND ', $where);
$rows = [];
$totalRows = 0;
$unitsOut = 0;

if ($stockOutView) {
    /*
     * Successful stock decreases from every source:
     *  - POS sales: the stock_movements ledger (written in the same transaction as the sale)
     *  - online orders: order items of orders whose stock was deducted on completion,
     *    dated by the shipment marked delivered at that moment (shipments.updated_at)
     *  - manual edits on the Stocks page that lowered the count ("stock X → Y" in the log)
     */
    $soSql = "SELECT sm.created_at AS at, 'pos' AS source, sm.product_name AS product, sm.quantity AS qty,
                     sm.stock_before AS stock_before, sm.stock_after AS stock_after, ps.invoice_no AS ref,
                     COALESCE(u.name, ps.cashier_name) AS user_name, u.role AS user_role, sm.id AS row_id
              FROM stock_movements sm
              LEFT JOIN pos_sales ps ON ps.id = sm.sale_id
              LEFT JOIN users u ON u.id = sm.user_id
              WHERE sm.movement_type = 'stock_out'
              UNION ALL
              SELECT COALESCE(sh.updated_at, o.paid_at, o.created_at), 'order', COALESCE(pr.name, 'Product #' || oi.product_id), oi.quantity,
                     NULL, NULL, 'ORD-' || o.id,
                     NULLIF(TRIM(COALESCE(cu.name, '') || ' ' || COALESCE(cu.surname, '')), ''), cu.role, oi.id
              FROM order_items oi
              INNER JOIN orders o ON o.id = oi.order_id AND o.stock_deducted = TRUE
              LEFT JOIN products pr ON pr.id = oi.product_id
              LEFT JOIN LATERAL (SELECT s.updated_at FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) sh ON TRUE
              LEFT JOIN users cu ON cu.id = o.user_id
              WHERE oi.quantity > 0
              UNION ALL
              SELECT l.created_at, 'manual', COALESCE(SUBSTRING(l.details FROM '\"([^\"]{1,120})\"'), 'Product'),
                     m[1]::int - m[2]::int, m[1]::int, m[2]::int, NULL, u.name, u.role, l.id
              FROM logs l
              CROSS JOIN LATERAL regexp_match(l.details, 'stock\s+(\d+)\s*(?:→|->)\s*(\d+)') AS m
              LEFT JOIN users u ON u.id = l.user_id
              WHERE l.action = 'edit_product' AND m[1]::int > m[2]::int";

    $soWhere = [];
    $soParams = [];
    if ($q !== '') {
        $soWhere[] = "(so.product ILIKE ? OR COALESCE(so.user_name, '') ILIKE ? OR COALESCE(so.ref, '') ILIKE ?)";
        $like = '%' . $q . '%';
        array_push($soParams, $like, $like, $like);
    }
    $soRange = inv_audit_range_sql($range, 'so.at');
    if ($soRange !== '') {
        $soWhere[] = $soRange;
    }
    $soWhereSql = $soWhere ? 'WHERE ' . implode(' AND ', $soWhere) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(so.qty), 0) AS units FROM ({$soSql}) so {$soWhereSql}");
    $countStmt->execute($soParams);
    $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalRows = (int)($countRow['cnt'] ?? 0);
    $unitsOut = (int)($countRow['units'] ?? 0);
} else {
    $countSql = "SELECT COUNT(*) FROM logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$whereSql}";
    $countStmt = $db->prepare($countSql);
    if ($countStmt) {
        $countStmt->execute($params);
        $totalRows = (int)($countStmt->fetchColumn() ?? 0);
    }
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

if ($stockOutView) {
    $listStmt = $db->prepare("SELECT so.* FROM ({$soSql}) so {$soWhereSql}
                              ORDER BY so.at DESC, so.source, so.row_id DESC
                              LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
    $listStmt->execute($soParams);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $listSql = "SELECT l.*, u.name AS user_name, u.role AS user_role
                FROM logs l
                LEFT JOIN users u ON u.id = l.user_id
                WHERE {$whereSql}
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $listStmt = $db->prepare($listSql);
    if ($listStmt) {
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* Order rows: product name(s) + customer name for the orders referenced in the log details ("Order #N ..."). */
$orderInfo = [];
$orderIds = [];
foreach ($stockOutView ? [] : $rows as $r) {
    if (in_array((string)($r['action'] ?? ''), ['update_order_status', 'complete_order', 'accept_order'], true)
        && preg_match('/Order\s*#\s*(\d+)/i', (string)($r['details'] ?? ''), $m)) {
        $orderIds[(int)$m[1]] = true;
    }
}
if ($orderIds) {
    $ids = array_keys($orderIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ost = $db->prepare("SELECT o.id, TRIM(COALESCE(u.name, '') || ' ' || COALESCE(u.surname, '')) AS customer,
                                (SELECT STRING_AGG(pr.name, ', ' ORDER BY oi.id)
                                 FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
                                 WHERE oi.order_id = o.id) AS products
                         FROM orders o LEFT JOIN users u ON u.id = o.user_id
                         WHERE o.id IN ($in)");
    $ost->execute($ids);
    foreach ($ost->fetchAll(PDO::FETCH_ASSOC) as $oRow) {
        $orderInfo[(int)$oRow['id']] = $oRow;
    }
}

function inv_audit_url(int $page, string $q, string $action, string $range): string
{
    $parts = ['page' => $page];
    if ($q !== '') {
        $parts['q'] = $q;
    }
    if ($action !== '') {
        $parts['action'] = $action;
    }
    if ($range !== 'all') {
        $parts['range'] = $range;
    }
    return 'inventory_audit.php?' . http_build_query($parts);
}

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Inventory Activity',
    'active' => 'activity',
    'heading' => 'Inventory Activity',
    'subtitle' => 'Track inventory changes and custodian actions',
    'actions' => $stockOutView
        ? '<a class="cx-btn cx-btn-outline" href="inventory_audit.php"><i class="ph ph-clipboard-text" aria-hidden="true"></i> All activity</a>'
        : '<a class="cx-btn cx-btn-outline" href="inventory_audit.php?action=stock_out"><i class="ph ph-arrow-up" aria-hidden="true"></i> Stock Out</a>',
]);
$filtered = $q !== '' || $actionFilter !== '' || $range !== 'all';
$actionIcons = [
    'add_product' => 'ph-plus-circle',
    'pos_stock_in' => 'ph-arrow-down',
    'edit_product' => 'ph-arrows-clockwise',
    'generate_barcode' => 'ph-barcode',
    'delete_product' => 'ph-trash',
];
?>

        <section class="cx-card audit-card" aria-labelledby="auditTitle">
            <div class="cx-card-head">
                <div>
                    <?php if ($stockOutView): ?>
                    <h2 id="auditTitle"><i class="ph ph-arrow-up" aria-hidden="true"></i> Stock out</h2>
                    <p>Units taken out of stock by POS sales, completed online orders and manual adjustments, newest first · <span class="cx-num"><?php echo number_format($unitsOut); ?></span> unit<?php echo $unitsOut === 1 ? '' : 's'; ?> in <span class="cx-num"><?php echo number_format($totalRows); ?></span> record<?php echo $totalRows === 1 ? '' : 's'; ?><?php echo $q !== '' ? ' matching “' . h($q) . '”' : ''; ?></p>
                    <?php else: ?>
                    <h2 id="auditTitle"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Inventory activity log</h2>
                    <p>Stock and order changes by custodians, newest first · <span class="cx-num"><?php echo number_format($totalRows); ?></span> record<?php echo $totalRows === 1 ? '' : 's'; ?><?php echo $q !== '' ? ' matching “' . h($q) . '”' : ''; ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <form class="alerts-toolbar audit-toolbar" method="get" action="inventory_audit.php" role="search">
                <div class="search-wrap">
                    <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                    <label class="cx-sr" for="auditSearch">Search activity</label>
                    <input type="search" id="auditSearch" name="q" value="<?php echo h($q); ?>" placeholder="<?php echo $stockOutView ? 'Search product, user, invoice or order' : 'Search product or user'; ?>">
                </div>
                <div class="alerts-toolbar-side">
                    <label class="sort-wrap" for="auditAction">
                        <span>Action</span>
                        <select id="auditAction" name="action" onchange="this.form.submit()">
                            <?php foreach ($allowedActions as $val => $label): ?>
                            <option value="<?php echo h($val); ?>" <?php echo $actionFilter === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="sort-wrap" for="auditRange">
                        <span>Date</span>
                        <select id="auditRange" name="range" onchange="this.form.submit()">
                            <?php foreach ($allowedRanges as $val => $label): ?>
                            <option value="<?php echo h($val); ?>" <?php echo $range === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="cx-btn cx-btn-primary"><i class="ph ph-magnifying-glass" aria-hidden="true"></i> Search</button>
                    <?php if ($filtered): ?>
                    <a class="cx-btn cx-btn-ghost" href="inventory_audit.php"><i class="ph ph-x" aria-hidden="true"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($rows)): ?>
            <div class="cx-empty">
                <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-<?php echo $filtered ? 'magnifying-glass' : 'clipboard-text'; ?>"></i></span>
                <?php if ($stockOutView): ?>
                <strong><?php echo $q !== '' || $range !== 'all' ? 'No stock out matches these filters' : 'No stock out yet'; ?></strong>
                <span><?php echo $q !== '' || $range !== 'all' ? 'Try another product or date range.' : 'Units sold at the POS, completed online orders and manual reductions will appear here.'; ?></span>
                <?php else: ?>
                <strong><?php echo $filtered ? 'No activity matches these filters' : 'No inventory activity yet'; ?></strong>
                <span><?php echo $filtered ? 'Try another product, action or date range.' : 'Inventory actions will appear here when products are stocked, updated, or removed.'; ?></span>
                <?php endif; ?>
                <?php if ($filtered): ?><a class="cx-btn cx-btn-outline" href="inventory_audit.php">Clear filters</a><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="cx-table-wrap">
                <table class="cx-table audit-table">
                    <thead>
                        <tr>
                            <th scope="col" class="col-when">Date &amp; Time</th>
                            <th scope="col">User</th>
                            <th scope="col">Action</th>
                            <th scope="col">Product</th>
                            <th scope="col">Change</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($stockOutView):
                        $soSources = [
                            'pos' => ['POS sale', 'ph-cash-register'],
                            'order' => ['Online order', 'ph-shopping-cart-simple'],
                            'manual' => ['Manual adjustment', 'ph-sliders-horizontal'],
                        ];
                        foreach ($rows as $row):
                            [$srcLabel, $srcIcon] = $soSources[(string)$row['source']] ?? ['Stock out', 'ph-arrow-up'];
                            $qty = (int)$row['qty'];
                            $hasRange = $row['stock_before'] !== null && $row['stock_after'] !== null;
                            $userRole = (string)($row['user_role'] ?? '');
                            ?>
                        <tr class="act-out">
                            <td data-label="When" class="audit-when">
                                <strong class="cx-num"><?php echo h(pos_format_datetime((string)$row['at'], 'M j, Y')); ?></strong>
                                <span class="cx-num"><?php echo h(pos_format_datetime((string)$row['at'], 'g:i A')); ?></span>
                            </td>
                            <td data-label="User" class="audit-user">
                                <?php echo h((string)($row['user_name'] ?? '') !== '' ? $row['user_name'] : 'System'); ?>
                                <?php if ($userRole !== ''): ?>
                                <span><?php echo h($userRole === 'client' ? 'Customer' : staff_role_label($userRole)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <span class="inv-action-badge act-out">
                                    <i class="ph ph-arrow-up" aria-hidden="true"></i>
                                    Stock Out
                                </span>
                                <span class="audit-source">
                                    <i class="ph <?php echo h($srcIcon); ?>" aria-hidden="true"></i> <?php echo h($srcLabel); ?><?php if ((string)($row['ref'] ?? '') !== ''): ?> · <span class="cx-num"><?php echo h((string)$row['ref']); ?></span><?php endif; ?>
                                </span>
                            </td>
                            <td data-label="Product" class="audit-product"><?php echo h((string)$row['product']); ?></td>
                            <td data-label="Change">
                                <span class="inv-chg cx-num chg-minus">−<?php echo $qty; ?></span>
                                <?php if ($hasRange): ?>
                                <span class="audit-range" title="Stock before → after"><?php echo (int)$row['stock_before']; ?> → <?php echo (int)$row['stock_after']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <span class="inv-status-pill">
                                    <i class="ph-fill ph-check-circle" aria-hidden="true"></i>
                                    Completed
                                </span>
                            </td>
                        </tr>
                    <?php endforeach;
                    else:
                    foreach ($rows as $row):
                        $action = (string)($row['action'] ?? '');
                        $details = (string)($row['details'] ?? '');
                        $parsed = inv_parse_log_change($action, $details);
                        $ts = strtotime((string)$row['created_at']);
                        $dateLabel = $ts ? date('M j, Y', $ts) : (string)$row['created_at'];
                        $timeLabel = $ts ? date('g:i A', $ts) : '';
                        $actClass = 'act-other';
                        if ($action === 'add_product' || $action === 'pos_stock_in') $actClass = 'act-add';
                        elseif ($action === 'edit_product' || $action === 'generate_barcode') $actClass = 'act-edit';
                        elseif ($action === 'delete_product') $actClass = 'act-del';
                        elseif ($action === 'update_order_status') $actClass = 'act-order';
                        elseif ($action === 'complete_order' || $action === 'accept_order') $actClass = 'act-order';
                        $statusClass = ($parsed['status'] === 'Adjusted') ? 'adj' : '';
                        // Order rows: show the status the order moved to instead of a generic "Updated".
                        $orderTarget = '';
                        // update_order_status logs "Order #N -> to_ship"; accept_order logs "Order #N accepted → to_receive".
                        if (in_array($action, ['update_order_status', 'accept_order'], true)
                            && preg_match('/(?:->|→)\s*([a-z_]+)/iu', $details, $tm)) {
                            $orderTarget = [
                                'to_pay' => 'To pay', 'to_ship' => 'To ship', 'to_receive' => 'With courier',
                                'to_review' => 'Completed', 'cancelled' => 'Cancelled',
                            ][strtolower($tm[1])] ?? ucfirst(str_replace('_', ' ', $tm[1]));
                        } elseif ($action === 'complete_order') {
                            $orderTarget = 'Completed';
                        }
                        $oInfo = null;
                        if (in_array($action, ['update_order_status', 'complete_order', 'accept_order'], true)
                            && preg_match('/Order\s*#\s*(\d+)/i', $details, $om)) {
                            $oInfo = $orderInfo[(int)$om[1]] ?? null;
                        }
                        ?>
                        <tr class="<?php echo h($actClass); ?>">
                            <td data-label="When" class="audit-when">
                                <strong class="cx-num"><?php echo h($dateLabel); ?></strong>
                                <span class="cx-num"><?php echo h($timeLabel); ?></span>
                            </td>
                            <td data-label="User" class="audit-user">
                                <?php echo h($row['user_name'] ?? 'System'); ?>
                                <?php if (!empty($row['user_role'])): ?>
                                <span><?php echo h(staff_role_label((string)$row['user_role'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <span class="inv-action-badge <?php echo h($actClass); ?>">
                                    <i class="ph <?php echo h($actionIcons[$action] ?? 'ph-clipboard-text'); ?>" aria-hidden="true"></i>
                                    <?php echo h(inv_action_label($action)); ?>
                                </span>
                            </td>
                            <td data-label="Product" class="audit-product" title="<?php echo h($details); ?>">
                                <?php if ($oInfo): ?>
                                    <?php echo h((string)($oInfo['products'] ?? '') !== '' ? $oInfo['products'] : $parsed['product']); ?>
                                    <?php if (trim((string)($oInfo['customer'] ?? '')) !== ''): ?>
                                    <span class="audit-customer"><i class="ph ph-user" aria-hidden="true"></i> <?php echo h($oInfo['customer']); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo h($parsed['product']); ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Change">
                                <?php if ($orderTarget !== ''): ?>
                                    <span class="inv-chg chg-order"><i class="ph ph-arrow-right" aria-hidden="true"></i><?php echo h($orderTarget); ?></span>
                                <?php else: ?>
                                    <span class="inv-chg cx-num <?php echo h($parsed['change_class']); ?>"><?php echo h($parsed['change']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <span class="inv-status-pill <?php echo h($statusClass); ?>">
                                    <i class="ph<?php echo $statusClass === 'adj' ? ' ph-sliders-horizontal' : '-fill ph-check-circle'; ?>" aria-hidden="true"></i>
                                    <?php echo h($parsed['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php staff_pagination($page, $totalPages, $totalRows, $perPage, static fn(int $p): string => inv_audit_url($p, $q, $actionFilter, $range), 'Activity pages'); ?>
        </section>

<?php
staff_page_end();
?>
