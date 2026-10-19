<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$pageSizes = [25, 50, 100];
$q = trim((string)($_GET['q'] ?? ''));
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, $pageSizes, true)) {
    $perPage = 25;
}
$page = max(1, (int)($_GET['page'] ?? 1));

$staffRoles = ['admin', 'retail_officer', 'inventory_custodian', 'cashier'];
$conditions = ['u.role IN (' . implode(', ', array_fill(0, count($staffRoles), '?')) . ')'];
$params = $staffRoles;
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $conditions[] = "(COALESCE(u.name, '') ILIKE ? OR COALESCE(u.email, '') ILIKE ? OR COALESCE(l.action, '') ILIKE ?
                  OR COALESCE(l.details, '') ILIKE ? OR COALESCE(l.ip_address, '') ILIKE ?)";
    $params = array_merge($params, array_fill(0, 5, $like));
}
$whereSql = implode(' AND ', $conditions);

$countStmt = $db->prepare("SELECT COUNT(*) FROM logs l LEFT JOIN users u ON u.id = l.user_id WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listStmt = $db->prepare(
    "SELECT l.*, u.name, u.email, u.role
     FROM logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE {$whereSql}
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
);
$listStmt->execute($params);
$logs = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function admin_logs_url(int $page, string $q, int $perPage): string
{
    $parts = ['page' => $page];
    if ($q !== '') {
        $parts['q'] = $q;
    }
    if ($perPage !== 25) {
        $parts['per_page'] = $perPage;
    }
    return 'view_logs.php?' . http_build_query($parts);
}

$roleLabels = [];
foreach ($staffRoles as $r) {
    $roleLabels[$r] = staff_role_label($r);
}

staff_page_start([
    'role' => 'admin',
    'title' => 'Activity Logs',
    'active' => 'logs',
    'heading' => 'Activity Logs',
    'subtitle' => 'Staff activity, newest first',
]);
?>

        <section class="cx-card logs-card" aria-labelledby="logsTitle">
            <div class="cx-card-head">
                <div>
                    <h2 id="logsTitle"><i class="ph ph-clipboard-text" aria-hidden="true"></i> System event log</h2>
                    <p>Audit trail of all significant actions · <span class="cx-num"><?php echo number_format($totalRows); ?></span> record<?php echo $totalRows === 1 ? '' : 's'; ?><?php echo $q !== '' ? ' matching “' . h($q) . '”' : ''; ?></p>
                </div>
            </div>
            <form class="alerts-toolbar logs-toolbar" method="get" action="view_logs.php" role="search">
                <div class="search-wrap">
                    <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                    <label class="cx-sr" for="logSearch">Search logs</label>
                    <input type="search" id="logSearch" name="q" value="<?php echo h($q); ?>" placeholder="Search user, email, action, details or IP">
                </div>
                <div class="alerts-toolbar-side">
                    <label class="sort-wrap" for="logPerPage">
                        <span>Show</span>
                        <select id="logPerPage" name="per_page" onchange="this.form.submit()">
                            <?php foreach ($pageSizes as $size): ?>
                            <option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?> per page</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="cx-btn cx-btn-primary"><i class="ph ph-magnifying-glass" aria-hidden="true"></i> Search</button>
                    <?php if ($q !== ''): ?>
                    <a class="cx-btn cx-btn-ghost" href="<?php echo h(admin_logs_url(1, '', $perPage)); ?>"><i class="ph ph-x" aria-hidden="true"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php if (!$logs): ?>
                <div class="cx-empty">
                    <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-<?php echo $q !== '' ? 'magnifying-glass' : 'clipboard-text'; ?>"></i></span>
                    <strong><?php echo $q !== '' ? 'No logs match your search' : 'No activity yet'; ?></strong>
                    <span><?php echo $q !== '' ? 'Try a name, email, action or IP address.' : 'Sign-ins, sales and account changes will be listed here.'; ?></span>
                    <?php if ($q !== ''): ?><a class="cx-btn cx-btn-outline" href="<?php echo h(admin_logs_url(1, '', $perPage)); ?>">Clear search</a><?php endif; ?>
                </div>
            <?php else: ?>
            <div class="cx-table-wrap">
                <table class="cx-table logs-table" id="logsTable">
                    <thead>
                        <tr>
                            <th scope="col" class="col-time">Timestamp</th>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            <th scope="col">Action</th>
                            <th scope="col">Details</th>
                            <th scope="col" class="col-ip">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                        <tr>
                            <td data-label="Time" class="log-time cx-mono"><?php echo h($l['created_at']); ?></td>
                            <td data-label="User">
                                <span class="log-user"><?php echo h($l['name'] ?? 'Guest'); ?></span>
                                <?php if (!empty($l['email'])): ?>
                                    <span class="log-email"><?php echo h($l['email']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Role">
                                <?php if (!empty($l['role'])): ?>
                                    <span class="role-badge role-<?php echo h(preg_replace('/[^a-z_]/', '', (string)$l['role'])); ?>"><?php echo h($roleLabels[$l['role']] ?? ucfirst((string)$l['role'])); ?></span>
                                <?php else: ?>
                                    <span class="log-none">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action"><span class="badge badge-action"><?php echo h($l['action']); ?></span></td>
                            <td data-label="Details" class="log-details"><?php echo h($l['details']); ?></td>
                            <td data-label="IP" class="log-ip cx-mono"><?php echo h($l['ip_address']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php staff_pagination($page, $totalPages, $totalRows, $perPage, static fn(int $p): string => admin_logs_url($p, $q, $perPage), 'Activity log pages'); ?>
        </section>

<?php
staff_page_end();