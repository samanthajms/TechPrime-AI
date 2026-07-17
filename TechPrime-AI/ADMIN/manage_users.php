<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
 
$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');
 
$admin_id = (int)$_SESSION['user_id'];
$staffRoles = [
    'retail_officer' => 'Retail Officer',
    'technician' => 'Technician',
    'inventory_custodian' => 'Inventory Custodian',
];
 
// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'create_staff') {
        $role = $_POST['role'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $surname = trim($_POST['surname'] ?? '');
        $age = (int)($_POST['age'] ?? 0);
        $address = trim($_POST['address'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (!isset($staffRoles[$role]) || $name === '' || $surname === '' || $age < 13 || $address === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: manage_users.php?error=' . urlencode('Please complete all staff-account fields with valid information.'));
            exit;
        }
        if (!isPasswordComplex($password, $db)) {
            header('Location: manage_users.php?error=' . urlencode('The temporary password does not meet the configured password rules.'));
            exit;
        }

        $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->bind_param('s', $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header('Location: manage_users.php?error=' . urlencode('That email address is already in use.'));
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        // Staff accounts are created by an administrator and are ready to use
        // immediately; email activation remains exclusive to client sign-up.
        $insert = $db->prepare('INSERT INTO users (name, surname, age, address, email, password, role, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
        $insert->bind_param('ssissss', $name, $surname, $age, $address, $email, $hash, $role);
        if (!$insert->execute()) {
            header('Location: manage_users.php?error=' . urlencode('Unable to create the staff account. Please try again.'));
            exit;
        }

        logActivity($db, $admin_id, 'create_staff_account', 'Admin created a ' . $staffRoles[$role] . ' account for ' . $email);

        header('Location: manage_users.php?success=staff_created');
        exit;
    }

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
$admins        = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id WHERE u.role='admin' ORDER BY u.name ASC");
$retail_officers        = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id WHERE u.role='retail_officer' ORDER BY u.name ASC");
$technicians        = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id WHERE u.role='technician' ORDER BY u.name ASC");
$inventory_custodians        = $db->query("SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id WHERE u.role='inventory_custodian' ORDER BY u.name ASC");

 
// Counts
$count_all     = $db->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$count_client  = $db->query("SELECT COUNT(*) as c FROM users WHERE role='client'")->fetch_assoc()['c'];
$count_admin   = $db->query("SELECT COUNT(*) as c FROM users WHERE role='admin'")->fetch_assoc()['c'];
$count_officer   = $db->query("SELECT COUNT(*) as c FROM users WHERE role='retail_officer'")->fetch_assoc()['c'];
$count_technician   = $db->query("SELECT COUNT(*) as c FROM users WHERE role='technician'")->fetch_assoc()['c'];
$count_custodian   = $db->query("SELECT COUNT(*) as c FROM users WHERE role='inventory_custodian'")->fetch_assoc()['c'];

 
$adminInitials = strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1));
$csrf = generateCsrfToken();
$active_tab = $_GET['tab'] ?? 'all';
$valid_tabs = ['all', 'client', 'admin', 'retail_officer', 'technician', 'inventory_custodian'];
if (!in_array($active_tab, $valid_tabs, true)) {
    $active_tab = 'all';
}
 
// Helper: fetch all users to PHP arrays for JS injection
function fetchToArray($result) {
    $arr = [];
    while ($row = $result->fetch_assoc()) $arr[] = $row;
    return $arr;
}
$user_rows = [
    'all'     => fetchToArray($all_users),
    'client'  => fetchToArray($clients),
    'admin'   => fetchToArray($admins),
    'retail_officer' => fetchToArray($retail_officers),
    'technician' => fetchToArray($technicians),
    'inventory_custodian' => fetchToArray($inventory_custodians),
    
];
$users = $user_rows[$active_tab];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users — EasyPC Admin</title>
    <link rel="stylesheet" href="admin_shared.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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
            text-decoration: none;
            transition: all .15s;
            font-family: var(--font-base);
        }
        .tab-btn:hover { border-color: var(--teal); color: var(--teal); background: var(--teal-pale); }
        .tab-btn.active { background: var(--ep-green); color: #fff; border-color: var(--ep-green); }
        .tab-btn .tab-count {
            background: rgba(255,255,255,.22);
            padding: 1px 7px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
        }
        .tab-btn:not(.active) .tab-count { background: var(--slate-100); color: var(--slate-600); }

        .staff-create-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .staff-create-grid .full-width { grid-column: 1 / -1; }
        .staff-create-grid label { display: block; margin-bottom: 5px; font-size: 12px; font-weight: 700; color: var(--text-muted); }
        .staff-create-grid input, .staff-create-grid select { width: 100%; box-sizing: border-box; padding: 9px 10px; border: 1.5px solid var(--border); border-radius: 8px; font: inherit; }
        .staff-create-grid input:focus, .staff-create-grid select:focus { outline: none; border-color: var(--teal); }
        .staff-create-actions { display: flex; align-items: end; }
        @media (max-width: 650px) { .staff-create-grid { grid-template-columns: 1fr; } .staff-create-grid .full-width { grid-column: auto; } }

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
        .search-wrap input:focus { border-color: var(--ep-green); box-shadow: 0 0 0 3px rgba(97,179,55,.15); }
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
        .user-card:hover { box-shadow: 0 4px 16px rgba(97,179,55,.12); border-color: var(--teal-light); }
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
            border-color: var(--ep-green);
            box-shadow: 0 0 0 3px rgba(97,179,55,.15);
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
        .duration-chip.selected { background: var(--ep-green); color: #fff; border-color: var(--ep-green); }
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
            background: var(--ep-green);
            color: #fff;
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
        .btn-contact:hover { background: var(--ep-green); color: #fff; border-color: var(--ep-green); }
        .users-summary { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        .users-summary .stat-card { padding: 16px 18px; }
        .users-summary .stat-num { font-size: 24px; }
        .user-name { display: flex; flex-direction: column; gap: 2px; }
        .actions-cell form { margin: 0; }
        .status-text { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; }
        .tabs-card { padding: 18px 22px 0; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/easypc-logo-transparent.png" alt="EasyPC" class="ep-logo-img brand-logo">
        <div>
            <div class="brand-text">EasyPC</div>
            <div class="brand-sub">Admin</div>
        </div>
    </div>
    <nav>
        <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
        <a href="manage_users.php" class="active"><i class="fas fa-users"></i><span>Manage Users</span></a>
        <a href="view_logs.php"><i class="fas fa-clipboard-list"></i><span>Activity Logs</span></a>
        <a href="admin_profile.php"><i class="fas fa-user"></i><span>My Profile</span></a>
        <a href="admin_settings.php"><i class="fas fa-cog"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
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

    <div class="page-content">
        <div class="stats-grid users-summary">
            <div class="stat-card">
                <div class="stat-label">All Users</div>
                <div class="stat-num"><?php echo (int)$count_all; ?></div>
                <div class="stat-icon">A</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Clients</div>
                <div class="stat-num"><?php echo (int)$count_client; ?></div>
                <div class="stat-icon">C</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Retail Officers</div>
                <div class="stat-num"><?php echo (int)$count_officer; ?></div>
                <div class="stat-icon">D</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Inventory Custodian</div>
                <div class="stat-num"><?php echo (int)$count_custodian; ?></div>
                <div class="stat-icon">D</div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon">+</span> Create Staff Account</h3>
                    <div class="card-subtitle">Create accounts for retail officers, technicians, and inventory custodians. New staff accounts are verified and ready to sign in.</div>
                </div>
            </div>
            <form method="post" class="staff-create-grid">
                <input type="hidden" name="action" value="create_staff">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <div>
                    <label for="staff-name">First Name</label>
                    <input id="staff-name" type="text" name="name" required>
                </div>
                <div>
                    <label for="staff-surname">Surname</label>
                    <input id="staff-surname" type="text" name="surname" required>
                </div>
                <div>
                    <label for="staff-role">Role</label>
                    <select id="staff-role" name="role" required>
                        <?php foreach ($staffRoles as $roleValue => $roleLabel): ?>
                            <option value="<?php echo h($roleValue); ?>"><?php echo h($roleLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="staff-age">Age</label>
                    <input id="staff-age" type="number" name="age" min="13" required>
                </div>
                <div>
                    <label for="staff-email">Email Address</label>
                    <input id="staff-email" type="email" name="email" required>
                </div>
                <div>
                    <label for="staff-password">Temporary Password</label>
                    <input id="staff-password" type="password" name="password" required>
                </div>
                <div class="full-width">
                    <label for="staff-address">Location</label>
                    <input id="staff-address" type="text" name="address" placeholder="City, Country" required>
                </div>
                <div class="full-width staff-create-actions">
                    <button type="submit" class="btn btn-primary">Create Staff Account</button>
                </div>
            </form>
        </div>

        <div class="card tabs-card">
            <div class="tab-nav">
                <a class="tab-btn <?php echo $active_tab === 'all' ? 'active' : ''; ?>" href="manage_users.php?tab=all">All <span class="tab-count"><?php echo (int)$count_all; ?></span></a>
                <a class="tab-btn <?php echo $active_tab === 'client' ? 'active' : ''; ?>" href="manage_users.php?tab=client">Clients <span class="tab-count"><?php echo (int)$count_client; ?></span></a>
                <a class="tab-btn <?php echo $active_tab === 'retail_officer' ? 'active' : ''; ?>" href="manage_users.php?tab=retail_officer">Retail Officers <span class="tab-count"><?php echo (int)$count_officer; ?></span></a>
                <a class="tab-btn <?php echo $active_tab === 'technician' ? 'active' : ''; ?>" href="manage_users.php?tab=technician">Technician <span class="tab-count"><?php echo (int)$count_technician; ?></span></a>
                <a class="tab-btn <?php echo $active_tab === 'inventory_custodian' ? 'active' : ''; ?>" href="manage_users.php?tab=inventory_custodian">Inventory Custodian <span class="tab-count"><?php echo (int)$count_custodian; ?></span></a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon">U</span> User Accounts</h3>
                    <div class="card-subtitle">Showing <?php echo count($users); ?> account<?php echo count($users) === 1 ? '' : 's'; ?> for the selected role</div>
                </div>
            </div>
            <div class="table-wrap">
                <table class="ias-table">
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
                    <?php foreach($users as $u): ?>
                    <tr>
                        <td>
                            <div class="user-name">
                                <strong><?php echo h(trim($u['name'] . ' ' . $u['surname'])); ?></strong>
                                <span class="text-muted text-small">ID #<?php echo (int)$u['id']; ?></span>
                            </div>
                        </td>
                        <td class="text-muted"><?php echo h($u['email']); ?></td>
                        <td><span class="badge badge-<?php echo h($u['role']); ?>"><?php echo h($u['role']); ?></span></td>
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
                            <div class="actions-cell">
                                <?php if((int)$u['id'] === $admin_id): ?>
                                    <a href="admin_profile.php" class="btn btn-primary btn-xs">Edit Profile</a>
                                <?php else: ?>
                                    <?php if($u['is_locked']): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="unblock">
                                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <button type="submit" class="btn btn-success btn-xs">Unlock</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="block">
                                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <button type="submit" class="btn btn-warn btn-xs">Block</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Permanently delete this user?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($users)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <p>No users found for this role.</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══ SCRIPTS ══════════════════════════════════════════════════════════════ -->
</div>

<script src="../includes/ui_alerts.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('success') === 'staff_created') {
            IAS_UI.alert('Staff account created and verified successfully.', 'success');
            window.history.replaceState({}, document.title, window.location.pathname);
        } else if (urlParams.has('success')) {
            IAS_UI.alert('User action completed successfully!', 'success');
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        if (urlParams.has('error')) {
            IAS_UI.alert(urlParams.get('error'), 'error');
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });
</script>
<?php include __DIR__ . '/chat_widget.php'; ?>
</body>
</html>
