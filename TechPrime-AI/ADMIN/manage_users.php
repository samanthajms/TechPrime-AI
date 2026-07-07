<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
 
$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');
 
$admin_id = (int)$_SESSION['user_id'];
 
// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $id     = (int)$_POST['id'];
    $action = $_POST['action'];

    if ($action === 'block') {
        // FIX: Use prepared statements
        $stmt = $db->prepare("UPDATE users SET is_locked = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $stmt2 = $db->prepare("INSERT INTO locked_accounts (user_id, reason) VALUES (?, 'Admin manual block')");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();

        logActivity($db, $admin_id, 'block_user', "Admin blocked user ID $id");

    } elseif ($action === 'unblock') {
        // FIX: Use prepared statements
        $stmt = $db->prepare("UPDATE users SET is_locked = 0, failed_attempts = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $stmt2 = $db->prepare("DELETE FROM locked_accounts WHERE user_id = ?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();

        logActivity($db, $admin_id, 'unblock_user', "Admin unblocked user ID $id");

    } elseif ($action === 'delete') {
        if ($id !== $admin_id) {
            // FIX: Use prepared statement
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            logActivity($db, $admin_id, 'delete_user', "Admin deleted user ID $id");
        }
    }

    header("Location: manage_users.php?success=1");
    exit;
}
 
// ── Fetch Users by Role ───────────────────────────────────────────────────────
$all_users     = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id ORDER BY u.role, u.name ASC");
$clients       = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id WHERE u.role='client' ORDER BY u.name ASC");
$sellers       = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id WHERE u.role='seller' ORDER BY u.name ASC");
$couriers      = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id WHERE u.role='courier' ORDER BY u.name ASC");
$admins        = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id WHERE u.role='admin' ORDER BY u.name ASC");
 
// Counts
$count_all     = $db->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$count_client  = $db->query("SELECT COUNT(*) as c FROM users WHERE role='client'")->fetch_assoc()['c'];
$count_seller  = $db->query("SELECT COUNT(*) as c FROM users WHERE role='seller'")->fetch_assoc()['c'];
$count_courier = $db->query("SELECT COUNT(*) as c FROM users WHERE role='courier'")->fetch_assoc()['c'];
$count_admin   = $db->query("SELECT COUNT(*) as c FROM users WHERE role='admin'")->fetch_assoc()['c'];
 
$adminInitials = strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1));
$csrf = generateCsrfToken();
$active_tab = $_GET['tab'] ?? 'all';
 
// Helper: fetch all users to PHP arrays for JS injection
function fetchToArray($result) {
    $arr = [];
    while ($row = $result->fetch_assoc()) $arr[] = $row;
    return $arr;
}
$users = $all_users;
$js_all     = json_encode(fetchToArray($all_users));
if ($all_users instanceof mysqli_result) {
    mysqli_data_seek($all_users, 0);
}
$js_clients = json_encode(fetchToArray($clients));
$js_sellers = json_encode(fetchToArray($sellers));
$js_couriers= json_encode(fetchToArray($couriers));
$js_admins  = json_encode(fetchToArray($admins));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users — IAS Admin</title>
    <link rel="stylesheet" href="admin_shared.css">
    <style>
        /* ── Tab Navigation ── */
        .tab-nav {
            display: flex;
            gap: 4px;
            padding: 0 0 20px 0;
            flex-wrap: wrap;
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: #fff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-muted);
            transition: all .15s;
            font-family: var(--font-base);
        }
        .tab-btn:hover { border-color: var(--teal); color: var(--teal); background: var(--teal-pale); }
        .tab-btn.active { background: var(--teal); color: var(--yellow); border-color: var(--teal); }
        .tab-btn .tab-count {
            background: rgba(255,255,255,.22);
            padding: 1px 7px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
        }
        .tab-btn:not(.active) .tab-count { background: var(--slate-100); color: var(--slate-600); }

        /* ── Toolbar ── */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 0 14px 0;
            flex-wrap: wrap;
        }
        .search-wrap {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 340px;
        }
        .search-wrap input {
            width: 100%;
            padding: 8px 12px 8px 36px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            outline: none;
            font-family: var(--font-base);
            color: var(--text-main);
            transition: border-color .15s;
        }
        .search-wrap input:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(9,152,167,.1); }
        .search-icon {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-400);
            font-size: 14px;
            pointer-events: none;
        }
        .view-toggle {
            display: flex;
            gap: 4px;
            background: var(--slate-100);
            border-radius: 8px;
            padding: 3px;
        }
        .view-btn {
            padding: 5px 11px;
            border-radius: 6px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 15px;
            color: var(--slate-400);
            transition: all .15s;
        }
        .view-btn.active { background: #fff; color: var(--teal); box-shadow: 0 1px 4px rgba(0,0,0,.08); }

        /* ── Grid View ── */
        .grid-view { display: none; }
        .grid-view.show { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
        .user-card {
            background: #fff;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            padding: 18px 16px 14px;
            transition: box-shadow .15s, border-color .15s;
            position: relative;
        }
        .user-card:hover { box-shadow: 0 4px 16px rgba(9,152,167,.1); border-color: var(--teal-light); }
        .user-card-top {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 12px;
        }
        .user-avatar {
            width: 42px; height: 42px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; font-weight: 800;
            flex-shrink: 0;
        }
        .avatar-admin    { background: #f3e8ff; color: #7c3aed; }
        .avatar-seller   { background: var(--teal-pale); color: var(--teal-deeper); }
        .avatar-client   { background: #eff6ff; color: #2563eb; }
        .avatar-courier  { background: #fff7ed; color: #c2410c; }
        .user-card-name { font-size: 14px; font-weight: 700; line-height: 1.2; }
        .user-card-email { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; word-break: break-all; }
        .user-card-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .user-card-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }
        .status-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-dot.active { background: #16a34a; }
        .status-dot.locked { background: #dc2626; }

        /* ── List View ── */
        .list-view { display: none; }
        .list-view.show { display: block; }
        .actions-cell { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }

        /* ── Lock reason tooltip ── */
        .lock-info { font-size: 11px; color: #dc2626; margin-top: 3px; }

        /* ── Empty state ── */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-muted);
        }
        .empty-state .empty-icon { font-size: 36px; margin-bottom: 10px; }
        .empty-state p { margin: 0; font-size: 14px; }

        /* ══════════════════════════════════════════════
           MODAL OVERLAY
        ══════════════════════════════════════════════ */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.55);
            backdrop-filter: blur(3px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.18);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
            animation: modalIn .2s cubic-bezier(.34,1.4,.64,1);
        }
        @keyframes modalIn {
            from { transform: scale(.93) translateY(8px); opacity: 0; }
            to   { transform: scale(1) translateY(0); opacity: 1; }
        }
        .modal-header {
            padding: 20px 22px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            gap: 13px;
        }
        .modal-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .modal-icon.suspend { background: #fffbeb; }
        .modal-icon.block   { background: #fef2f2; }
        .modal-icon.unlock  { background: #f0fdf4; }
        .modal-icon.info    { background: var(--teal-pale); }
        .modal-title { font-size: 16px; font-weight: 700; margin: 0 0 3px; }
        .modal-subtitle { font-size: 13px; color: var(--text-muted); margin: 0; }
        .modal-body { padding: 20px 22px; }
        .modal-footer {
            padding: 14px 22px 20px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        .modal-footer .btn { min-width: 90px; justify-content: center; }

        /* Suspend form fields */
        .modal-field { margin-bottom: 14px; }
        .modal-label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-main); margin-bottom: 5px; }
        .modal-select, .modal-input, .modal-textarea {
            width: 100%;
            padding: 8px 11px;
            border: 1.5px solid var(--border);
            border-radius: 7px;
            font-size: 13px;
            font-family: var(--font-base);
            color: var(--text-main);
            background: #fff;
            outline: none;
            transition: border-color .15s;
        }
        .modal-select:focus, .modal-input:focus, .modal-textarea:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(9,152,167,.1);
        }
        .modal-textarea { resize: vertical; min-height: 70px; }
        .duration-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-top: 6px;
        }
        .duration-chip {
            padding: 6px 4px;
            border: 1.5px solid var(--border);
            border-radius: 7px;
            text-align: center;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-muted);
            background: #fff;
            transition: all .15s;
        }
        .duration-chip:hover { border-color: var(--teal); color: var(--teal); }
        .duration-chip.selected { background: var(--teal); color: var(--yellow); border-color: var(--teal); }
        .user-info-box {
            background: var(--teal-pale);
            border: 1px solid var(--teal-light);
            border-radius: 9px;
            padding: 11px 14px;
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 16px;
        }
        .user-info-box .uib-avatar {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 800;
            background: var(--teal);
            color: var(--yellow);
            flex-shrink: 0;
        }
        .user-info-box .uib-name { font-size: 13.5px; font-weight: 700; }
        .user-info-box .uib-role { font-size: 11.5px; color: var(--teal-deeper); }

        /* View Info modal */
        .info-row {
            display: flex;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            gap: 12px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { width: 110px; flex-shrink: 0; font-weight: 600; color: var(--text-muted); font-size: 12px; }
        .info-value { color: var(--text-main); word-break: break-all; }

        /* ── Seller store actions ── */
        .btn-store { background: #f0fdf4; color: #166534; border: 1.5px solid #bbf7d0; }
        .btn-store:hover { background: #166534; color: #fff; border-color: #166534; }
        .btn-contact { background: var(--teal-pale); color: var(--teal-deeper); border: 1.5px solid var(--teal-light); }
        .btn-contact:hover { background: var(--teal); color: var(--yellow); border-color: var(--teal); }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div>
            <div class="brand-text">IAS Admin</div>
            <div class="brand-sub">Control Panel</div>
        </div>
    </div>
    <nav>
        <a href="admin_dashboard.php">Dashboard</a>
        <a href="manage_users.php" class="active">Manage Users</a>
        <a href="view_logs.php">Activity Logs</a>
        <a href="admin_profile.php">My Profile</a>
        <a href="admin_settings.php">Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪Logout</a>
    </div>
</div>

<!-- ══ MAIN ══════════════════════════════════════════════════════════════════ -->
<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h2>Manage Users</h2>
            <div class="breadcrumb">View, suspend, block or unlock user accounts by role</div>
        </div>
        <div class="topbar-right">
            <div class="admin-badge">
                <div class="avatar"><?php echo $adminInitials; ?></div>
                <?php echo h($_SESSION['name']); ?>
            </div>
        </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Verification</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($u = $users->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo h($u['name'] . ' ' . $u['surname']); ?></strong></td>
                        <td><?php echo h($u['email']); ?></td>
                        <td><span class="badge badge-<?php echo $u['role']; ?>"><?php echo $u['role']; ?></span></td>
                        <td>
                            <?php if($u['is_verified']): ?>
                                <span style="color: #27ae60;">✔ Verified</span>
                            <?php else: ?>
                                <span style="color: #e74c3c;">✘ Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($u['is_locked']): ?>
                                <span style="color: #e74c3c; font-weight: bold;">LOCKED</span>
                            <?php else: ?>
                                <span style="color: #27ae60; font-weight: bold;">ACTIVE</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center;">
                                <?php if((int)$u['id'] === $admin_id): ?>
                                    <a href="admin_profile.php" class="btn" style="background: #3498db;">Edit Profile</a>
                                <?php else: ?>
                                    <?php if($u['is_locked']): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="unblock">
                                            <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <button type="submit" class="btn btn-unblock">Unlock</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="block">
                                            <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <button type="submit" class="btn btn-block">Block</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Permanently delete this user?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <button type="submit" class="btn btn-delete">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══ SCRIPTS ══════════════════════════════════════════════════════════════ -->
<script src="../includes/ui_alerts.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) {
            IAS_UI.alert('User action completed successfully!', 'success');
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });
</script>
<?php include __DIR__ . '/chat_widget.php'; ?>
</body>
</html>