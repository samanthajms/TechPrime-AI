<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$logs = $db->query("SELECT l.*, u.name, u.email, u.role FROM logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 100");
$adminInitials = strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs — IAS Admin</title>
    <link rel="stylesheet" href="admin_shared.css">
    <style>
        .log-filter { padding: 0 24px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .log-filter input {
            padding: 8px 14px 8px 36px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: var(--slate-50) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.415l-3.868-3.833zm-5.242 1.156a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E") no-repeat 11px center;
            outline: none; font-family: var(--font-base); color: var(--text-main);
            transition: border-color .18s; min-width: 220px;
        }
        .log-filter input:focus { border-color: var(--teal); background-color: #fff; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <div>
            <div class="brand-text">IAS Admin</div>
            <div class="brand-sub">Control Panel</div>
        </div>
    </div>
    <nav>
        <a href="admin_dashboard.php">Dashboard</a>
        <a href="manage_users.php">Manage Users</a>
        <a href="view_logs.php" class="active">Activity Logs</a>
        <a href="admin_profile.php">My Profile</a>
        <a href="admin_settings.php">Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h2>Activity Logs</h2>
            <div class="breadcrumb">Last 100 system events</div>
        </div>
        <div class="topbar-right">
            <div class="admin-badge">
                <div class="avatar"><?php echo $adminInitials; ?></div>
                <?php echo h($_SESSION['name']); ?>
            </div>
        </div>
    </div>

    <div class="page-content">
        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon">📋</span> System Event Log</h3>
                    <div class="card-subtitle">Audit trail of all significant actions</div>
                </div>
            </div>
            <div class="log-filter" style="padding-top:16px;">
                <input type="text" id="logSearch" placeholder="Filter by user, action, or details…">
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
                        <?php while($l = $logs->fetch_assoc()): ?>
                        <tr>
                            <td class="mono text-small text-muted" style="white-space:nowrap"><?php echo $l['created_at']; ?></td>
                            <td>
                                <strong><?php echo h($l['name'] ?? 'Guest'); ?></strong>
                                <?php if($l['email']): ?>
                                    <br><span class="text-muted text-small"><?php echo h($l['email']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($l['role']): ?>
                                    <span class="badge badge-<?php echo h($l['role']); ?>"><?php echo h($l['role']); ?></span>
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
</div>

<script>
document.getElementById('logSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#logsTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>
