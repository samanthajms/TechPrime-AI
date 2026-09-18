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
    'delete_product' => 'Product Removed',
    'update_order_status' => 'Order Updated',
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

$inventoryActions = ['add_product', 'edit_product', 'delete_product', 'update_order_status'];

$where = ['l.action IN (' . implode(',', array_fill(0, count($inventoryActions), '?')) . ')'];
$types = str_repeat('s', count($inventoryActions));
$params = $inventoryActions;

if ($actionFilter !== '') {
    $where = ['l.action = ?'];
    $types = 's';
    $params = [$actionFilter];
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
        $where[] = 'DATE(l.created_at) = CURDATE()';
        break;
    case '7d':
        $where[] = 'l.created_at >= (NOW() - INTERVAL 7 DAY)';
        break;
    case '30d':
        $where[] = 'l.created_at >= (NOW() - INTERVAL 30 DAY)';
        break;
    case 'month':
        $where[] = 'YEAR(l.created_at) = YEAR(CURDATE()) AND MONTH(l.created_at) = MONTH(CURDATE())';
        break;
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$whereSql}";
$countStmt = $db->prepare($countSql);
$totalRows = 0;
if ($countStmt) {
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $totalRows = (int)($countStmt->get_result()->fetch_row()[0] ?? 0);
    $countStmt->close();
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
            LIMIT ? OFFSET ?";
$listStmt = $db->prepare($listSql);
$rows = [];
if ($listStmt) {
    $bindTypes = $types . 'ii';
    $bindParams = array_merge($params, [$perPage, $offset]);
    $listStmt->bind_param($bindTypes, ...$bindParams);
    $listStmt->execute();
    $res = $listStmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $listStmt->close();
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
    'extra_head' => <<<'EXTRA'
<style>
.inv-audit { display: flex; flex-direction: column; gap: 18px; }
.inv-audit-shell {
    background: linear-gradient(180deg, #ffffff 0%, #f8fbf6 100%);
    border: 1px solid var(--teal-light, #c6e6b3);
    border-radius: 18px;
    box-shadow: 0 10px 28px rgba(75, 139, 42, 0.1);
    overflow: hidden;
}
.inv-audit-head {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    padding: 20px 22px 16px;
    background: linear-gradient(135deg, #eef8e6 0%, #ffffff 72%);
    border-bottom: 1px solid rgba(75, 139, 42, 0.12);
}
.inv-audit-head h3 {
    margin: 0; font-size: 16px; font-weight: 800; color: var(--ep-green-dark);
    display: flex; align-items: center; gap: 10px;
}
.inv-audit-head .card-icon {
    width: 38px; height: 38px; border-radius: 11px; background: var(--ep-green); color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    box-shadow: 0 6px 14px rgba(75, 139, 42, 0.28);
}
.inv-audit-sub { margin-top: 4px; font-size: 12.5px; color: var(--text-muted); font-weight: 500; }
.inv-audit-count {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 999px;
    background: #fff; border: 1px solid var(--teal-light); color: var(--ep-green-dark);
    font-size: 12px; font-weight: 700;
}
.inv-audit-filters {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    padding: 14px 18px; border-bottom: 1px solid rgba(75, 139, 42, 0.1);
    background: #fff;
}
.inv-audit-filters .staff-search { flex: 1 1 220px; max-width: 320px; }
.inv-audit-select {
    min-width: 160px; height: 40px; padding: 0 12px;
    border: 1.5px solid var(--border); border-radius: 10px;
    background: #fff; font-family: inherit; font-size: 13px; font-weight: 600;
    color: var(--text-main); outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.inv-audit-select:focus {
    border-color: var(--ep-green);
    box-shadow: 0 0 0 3px rgba(97, 179, 55, 0.15);
}
.inv-audit-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.inv-audit-table {
    width: 100%; border-collapse: collapse; min-width: 780px;
}
.inv-audit-table thead th {
    text-align: left; padding: 12px 16px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.7px; color: var(--text-muted);
    background: #f4f9f0; border-bottom: 1px solid rgba(75, 139, 42, 0.12);
    white-space: nowrap;
}
.inv-audit-table tbody td {
    padding: 14px 16px; font-size: 13.5px; border-bottom: 1px solid #eef1ef;
    vertical-align: middle; color: var(--text-main);
}
.inv-audit-table tbody tr { transition: background 0.15s ease; }
.inv-audit-table tbody tr:nth-child(even) { background: rgba(248, 251, 246, 0.7); }
.inv-audit-table tbody tr:hover { background: rgba(238, 248, 230, 0.85); }
.inv-audit-table tbody tr:last-child td { border-bottom: none; }
.inv-audit-when { white-space: nowrap; }
.inv-audit-when strong { display: block; font-size: 13px; font-weight: 700; }
.inv-audit-when span { display: block; margin-top: 2px; font-size: 11.5px; color: var(--text-muted); font-weight: 500; }
.inv-audit-user { font-weight: 700; }
.inv-audit-user span { display: block; font-size: 11.5px; font-weight: 500; color: var(--text-muted); margin-top: 2px; }
.inv-audit-product { font-weight: 600; max-width: 220px; }
.inv-action-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
    border: 1px solid transparent; white-space: nowrap;
}
.inv-action-badge.act-add { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
.inv-action-badge.act-edit { background: #eef8e6; color: var(--ep-green-dark); border-color: var(--teal-light); }
.inv-action-badge.act-del { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
.inv-action-badge.act-order { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.inv-action-badge.act-other { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }
.inv-chg {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 52px; padding: 5px 10px; border-radius: 8px;
    font-size: 12.5px; font-weight: 800; font-variant-numeric: tabular-nums;
}
.inv-chg.chg-plus { background: #f0fdf4; color: #15803d; }
.inv-chg.chg-minus { background: #fef2f2; color: #b91c1c; }
.inv-chg.chg-updated { background: #f3f4f6; color: #4b5563; }
.inv-status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
    background: #eef8e6; color: var(--ep-green-dark); border: 1px solid var(--teal-light);
}
.inv-status-pill.adj { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.inv-audit-empty {
    text-align: center; padding: 56px 24px; color: var(--text-muted);
}
.inv-audit-empty i {
    display: inline-flex; width: 56px; height: 56px; border-radius: 16px;
    align-items: center; justify-content: center; font-size: 22px;
    background: var(--ep-green-light); color: var(--ep-green-dark);
    border: 1px solid var(--teal-light); margin-bottom: 14px;
}
.inv-audit-empty h4 { margin: 0 0 6px; font-size: 16px; font-weight: 800; color: var(--text-main); }
.inv-audit-empty p { margin: 0; font-size: 13.5px; font-weight: 500; }
.inv-audit-pagination {
    display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap;
    padding: 16px 18px; border-top: 1px solid rgba(75, 139, 42, 0.1); background: #fff;
}
.inv-audit-page {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    min-width: 36px; height: 36px; padding: 0 12px; border-radius: 10px;
    border: 1px solid var(--border); background: #fff; color: var(--text-main);
    font-size: 13px; font-weight: 700; text-decoration: none;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.inv-audit-page:hover { background: var(--ep-green-light); border-color: var(--ep-green); color: var(--ep-green-dark); }
.inv-audit-page.active {
    background: var(--ep-green); border-color: var(--ep-green); color: #fff;
    box-shadow: 0 4px 12px rgba(75, 139, 42, 0.25);
}
.inv-audit-page.disabled { opacity: 0.45; pointer-events: none; }
@media (max-width: 720px) {
    .inv-audit-filters { padding: 12px 14px; }
    .inv-audit-select { flex: 1 1 140px; min-width: 0; }
}
</style>
EXTRA
]);
?>

        <div class="inv-audit">
            <section class="inv-audit-shell" aria-label="Inventory activity log">
                <div class="inv-audit-head">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-clipboard-list"></i></span> Inventory Activity</h3>
                        <div class="inv-audit-sub">Track inventory changes and custodian actions</div>
                    </div>
                    <span class="inv-audit-count"><i class="fas fa-stream"></i> <?php echo number_format($totalRows); ?> record<?php echo $totalRows === 1 ? '' : 's'; ?></span>
                </div>

                <form class="inv-audit-filters" method="get" action="inventory_audit.php" role="search">
                    <div class="staff-search">
                        <i class="fas fa-search"></i>
                        <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search product or user…">
                    </div>
                    <label class="sr-only" for="auditAction">Action</label>
                    <select class="inv-audit-select" id="auditAction" name="action" aria-label="Filter by action" onchange="this.form.submit()">
                        <?php foreach ($allowedActions as $val => $label): ?>
                        <option value="<?php echo h($val); ?>" <?php echo $actionFilter === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="sr-only" for="auditRange">Date range</label>
                    <select class="inv-audit-select" id="auditRange" name="range" aria-label="Filter by date" onchange="this.form.submit()">
                        <?php foreach ($allowedRanges as $val => $label): ?>
                        <option value="<?php echo h($val); ?>" <?php echo $range === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn-primary btn-sm">Apply</button></noscript>
                </form>

                <?php if (empty($rows)): ?>
                <div class="inv-audit-empty">
                    <i class="fas fa-history" aria-hidden="true"></i>
                    <h4>No inventory activity yet</h4>
                    <p>Inventory actions will appear here when products are stocked, updated, or removed.</p>
                </div>
                <?php else: ?>
                <div class="inv-audit-table-wrap">
                    <table class="inv-audit-table">
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Product</th>
                                <th>Change</th>
                                <th>Status</th>
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
                            if ($action === 'add_product') $actClass = 'act-add';
                            elseif ($action === 'edit_product') $actClass = 'act-edit';
                            elseif ($action === 'delete_product') $actClass = 'act-del';
                            elseif ($action === 'update_order_status') $actClass = 'act-order';
                            $statusClass = ($parsed['status'] === 'Adjusted') ? 'adj' : '';
                            ?>
                            <tr>
                                <td class="inv-audit-when">
                                    <strong><?php echo h($dateLabel); ?></strong>
                                    <span><?php echo h($timeLabel); ?></span>
                                </td>
                                <td class="inv-audit-user">
                                    <?php echo h($row['user_name'] ?? 'System'); ?>
                                    <?php if (!empty($row['user_role'])): ?>
                                    <span><?php echo h(str_replace('_', ' ', (string)$row['user_role'])); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="inv-action-badge <?php echo h($actClass); ?>">
                                        <?php if ($action === 'add_product'): ?><i class="fas fa-plus-circle" aria-hidden="true"></i>
                                        <?php elseif ($action === 'edit_product'): ?><i class="fas fa-sync-alt" aria-hidden="true"></i>
                                        <?php elseif ($action === 'delete_product'): ?><i class="fas fa-trash-alt" aria-hidden="true"></i>
                                        <?php else: ?><i class="fas fa-clipboard-check" aria-hidden="true"></i><?php endif; ?>
                                        <?php echo h(inv_action_label($action)); ?>
                                    </span>
                                </td>
                                <td class="inv-audit-product" title="<?php echo h($details); ?>"><?php echo h($parsed['product']); ?></td>
                                <td>
                                    <span class="inv-chg <?php echo h($parsed['change_class']); ?>"><?php echo h($parsed['change']); ?></span>
                                </td>
                                <td>
                                    <span class="inv-status-pill <?php echo h($statusClass); ?>">
                                        <i class="fas <?php echo $statusClass === 'adj' ? 'fa-sliders-h' : 'fa-check'; ?>" aria-hidden="true"></i>
                                        <?php echo h($parsed['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <nav class="inv-audit-pagination" aria-label="Activity pagination">
                    <a class="inv-audit-page<?php echo $page <= 1 ? ' disabled' : ''; ?>"
                       href="<?php echo h(inv_audit_url(max(1, $page - 1), $q, $actionFilter, $range)); ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a class="inv-audit-page<?php echo $p === $page ? ' active' : ''; ?>"
                       href="<?php echo h(inv_audit_url($p, $q, $actionFilter, $range)); ?>"
                       <?php echo $p === $page ? 'aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                    <?php endfor; ?>
                    <a class="inv-audit-page<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"
                       href="<?php echo h(inv_audit_url(min($totalPages, $page + 1), $q, $actionFilter, $range)); ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>

<?php
staff_page_end();
?>
