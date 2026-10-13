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

$whereSql = 'TRUE';
$params = [];
if ($q !== '') {
    // Search is done in SQL so it covers every log, not just the page on screen.
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $whereSql = "(COALESCE(u.name, '') ILIKE ? OR COALESCE(u.email, '') ILIKE ? OR COALESCE(l.action, '') ILIKE ?
                  OR COALESCE(l.details, '') ILIKE ? OR COALESCE(l.ip_address, '') ILIKE ?)";
    $params = array_fill(0, 5, $like);
}

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

/** Page numbers to show: first, last and a window around the current page; 0 marks a gap. */
function admin_logs_page_list(int $page, int $totalPages): array
{
    $pages = [];
    for ($p = 1; $p <= $totalPages; $p++) {
        if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2) {
            if ($pages && end($pages) !== $p - 1) {
                $pages[] = 0;
            }
            $pages[] = $p;
        }
    }
    return $pages;
}

$roleLabels = ['client' => 'Client'];
foreach (['admin', 'retail_officer', 'inventory_custodian', 'cashier'] as $r) {
    $roleLabels[$r] = staff_role_label($r);
}

$firstShown = $totalRows ? $offset + 1 : 0;
$lastShown = $offset + count($logs);

staff_page_start([
    'role' => 'admin',
    'title' => 'Activity Logs',
    'active' => 'logs',
    'heading' => 'Activity Logs',
    'subtitle' => 'All system events, newest first',
    'extra_head' => '<style>
        .log-filter { padding: 0 0 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .log-filter .staff-search { max-width: 360px; flex: 1; }
        .log-select {
            height: 40px; padding: 0 12px; border: 1.5px solid var(--border); border-radius: 10px;
            background: #fff; font-family: inherit; font-size: 13px; font-weight: 600; color: var(--text-main);
            outline: none; transition: border-color .15s ease, box-shadow .15s ease;
        }
        .log-select:focus { border-color: var(--ep-green); box-shadow: 0 0 0 3px rgba(97,179,55,.15); }
        .log-pagination {
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
            margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border);
        }
        .log-pagination .pg-info { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .log-pagination .pg-controls { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .log-page {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            min-width: 36px; height: 36px; padding: 0 12px; border-radius: 10px;
            border: 1px solid var(--border); background: #fff; color: var(--text-main);
            font-size: 13px; font-weight: 600; text-decoration: none; font-variant-numeric: tabular-nums;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease, transform 160ms cubic-bezier(.23,1,.32,1);
        }
        a.log-page:hover { background: var(--ep-green-light); border-color: var(--ep-green); color: var(--ep-green-dark); }
        a.log-page:active { transform: scale(.95); }
        a.log-page:focus-visible { outline: 2px solid var(--ep-green); outline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) { a.log-page:active { transform: none; } }
        .log-page.active { background: var(--ep-green); border-color: var(--ep-green); color: #fff; }
        .log-page.disabled { opacity: .4; pointer-events: none; }
        .log-page.gap { border: none; background: none; min-width: 0; padding: 0 2px; }
    </style>',
]);
?>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-clipboard-list"></i></span> System Event Log</h3>
                    <div class="card-subtitle">Audit trail of all significant actions · <?php echo number_format($totalRows); ?> record<?php echo $totalRows === 1 ? '' : 's'; ?><?php echo $q !== '' ? ' matching “' . h($q) . '”' : ''; ?></div>
                </div>
            </div>
            <div class="card-body">
                <form class="log-filter" method="get" action="view_logs.php" role="search">
                    <div class="staff-search">
                        <i class="fas fa-search"></i>
                        <input type="search" name="q" value="<?php echo h($q); ?>" placeholder="Search user, email, action, details or IP…" aria-label="Search logs">
                    </div>
                    <label class="sr-only" for="logPerPage">Rows per page</label>
                    <select class="log-select" id="logPerPage" name="per_page" aria-label="Rows per page" onchange="this.form.submit()">
                        <?php foreach ($pageSizes as $size): ?>
                        <option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?> per page</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                    <?php if ($q !== ''): ?>
                    <a class="btn btn-outline btn-sm" href="<?php echo h(admin_logs_url(1, '', $perPage)); ?>">Clear</a>
                    <?php endif; ?>
                </form>
                <div class="table-wrap">
                    <table class="ias-table" id="logsTable">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $l): ?>
                            <tr>
                                <td class="mono text-small text-muted" style="white-space:nowrap"><?php echo h($l['created_at']); ?></td>
                                <td>
                                    <strong><?php echo h($l['name'] ?? 'Guest'); ?></strong>
                                    <?php if (!empty($l['email'])): ?>
                                        <br><span class="text-muted text-small"><?php echo h($l['email']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($l['role'])): ?>
                                        <span class="badge badge-info"><?php echo h($roleLabels[$l['role']] ?? ucfirst((string)$l['role'])); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted text-small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-action"><?php echo h($l['action']); ?></span></td>
                                <td class="text-muted text-small" style="max-width:280px"><?php echo h($l['details']); ?></td>
                                <td class="mono text-small text-muted"><?php echo h($l['ip_address']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$logs): ?>
                            <tr><td colspan="6" class="empty-state"><?php echo $q !== '' ? 'No logs match your search.' : 'No activity has been logged yet.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalRows > 0): ?>
                <nav class="log-pagination" aria-label="Activity log pages">
                    <div class="pg-info">Showing <?php echo number_format($firstShown); ?>–<?php echo number_format($lastShown); ?> of <?php echo number_format($totalRows); ?></div>
                    <?php if ($totalPages > 1): ?>
                    <div class="pg-controls">
                        <a class="log-page<?php echo $page <= 1 ? ' disabled' : ''; ?>" href="<?php echo h(admin_logs_url(max(1, $page - 1), $q, $perPage)); ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                        <?php foreach (admin_logs_page_list($page, $totalPages) as $p): ?>
                            <?php if ($p === 0): ?>
                            <span class="log-page gap" aria-hidden="true">…</span>
                            <?php else: ?>
                            <a class="log-page<?php echo $p === $page ? ' active' : ''; ?>" href="<?php echo h(admin_logs_url($p, $q, $perPage)); ?>"<?php echo $p === $page ? ' aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <a class="log-page<?php echo $page >= $totalPages ? ' disabled' : ''; ?>" href="<?php echo h(admin_logs_url(min($totalPages, $page + 1), $q, $perPage)); ?>">Next <i class="fas fa-chevron-right"></i></a>
                    </div>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            </div>
        </div>

<?php
staff_page_end();
?>
