<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/inventory_alerts.php';

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

switch ($range) {
    case 'today':
        $where[] = "DATE(l.created_at) = CURRENT_DATE";
        break;
    case '7d':
        $where[] = "l.created_at >= (NOW() - INTERVAL '7 day')";
        break;
    case '30d':
        $where[] = "l.created_at >= (NOW() - INTERVAL '30 day')";
        break;
    case 'month':
        $where[] = "date_trunc('month', l.created_at) = date_trunc('month', CURRENT_DATE)";
        break;
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$whereSql}";
$countStmt = $db->prepare($countSql);
$totalRows = 0;
if ($countStmt) {
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetchColumn() ?? 0);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = "SELECT l.*, u.name AS user_name, u.role AS user_role
            FROM logs l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE {$whereSql}
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$listStmt = $db->prepare($listSql);
$rows = [];
if ($listStmt) {
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Order rows: product name(s) + customer name for the orders referenced in the log details ("Order #N ..."). */
$orderInfo = [];
$orderIds = [];
foreach ($rows as $r) {
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
                    <h2 id="auditTitle"><i class="ph ph-clipboard-text" aria-hidden="true"></i> Inventory activity log</h2>
                    <p>Stock and order changes by custodians, newest first · <span class="cx-num"><?php echo number_format($totalRows); ?></span> record<?php echo $totalRows === 1 ? '' : 's'; ?><?php echo $q !== '' ? ' matching “' . h($q) . '”' : ''; ?></p>
                </div>
            </div>

            <form class="alerts-toolbar audit-toolbar" method="get" action="inventory_audit.php" role="search">
                <div class="search-wrap">
                    <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                    <label class="cx-sr" for="auditSearch">Search activity</label>
                    <input type="search" id="auditSearch" name="q" value="<?php echo h($q); ?>" placeholder="Search product or user">
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
                <strong><?php echo $filtered ? 'No activity matches these filters' : 'No inventory activity yet'; ?></strong>
                <span><?php echo $filtered ? 'Try another product, action or date range.' : 'Inventory actions will appear here when products are stocked, updated, or removed.'; ?></span>
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
                    <?php foreach ($rows as $row):
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
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php staff_pagination($page, $totalPages, $totalRows, $perPage, static fn(int $p): string => inv_audit_url($p, $q, $actionFilter, $range), 'Activity pages'); ?>
        </section>

<?php
staff_page_end();
?>
