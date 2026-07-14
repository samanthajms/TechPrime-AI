<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$logs = $db->query("SELECT l.*, u.name, u.email, u.role FROM logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 100");

staff_page_start([
    'role' => 'admin',
    'title' => 'Activity Logs',
    'active' => 'logs',
    'heading' => 'Activity Logs',
    'subtitle' => 'Last 100 system events',
    'extra_head' => '<style>
        .log-filter { padding: 0 0 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .log-filter .staff-search { max-width: 360px; flex: 1; }
    </style>',
]);
?>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-clipboard-list"></i></span> System Event Log</h3>
                    <div class="card-subtitle">Audit trail of all significant actions</div>
                </div>
            </div>
            <div class="card-body">
                <div class="log-filter">
                    <div class="staff-search">
                        <i class="fas fa-search"></i>
                        <input type="text" id="logSearch" placeholder="Filter by user, action, or details…">
                    </div>
                </div>
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
                            <?php while ($l = $logs->fetch_assoc()): ?>
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
                                        <span class="badge badge-info"><?php echo h($l['role']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted text-small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-action"><?php echo h($l['action']); ?></span></td>
                                <td class="text-muted text-small" style="max-width:280px"><?php echo h($l['details']); ?></td>
                                <td class="mono text-small text-muted"><?php echo h($l['ip_address']); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<?php
staff_page_end(<<<'SCRIPTS'
<script>
document.getElementById('logSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#logsTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
SCRIPTS);
?>
