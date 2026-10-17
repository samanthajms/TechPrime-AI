<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/staff_chat_lib.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];
$staffRoles = [
    'retail_officer' => 'Retail Officer',
    'inventory_custodian' => 'Inventory Custodian',
    'cashier' => 'Cashier',
];
$valid_tabs = ['all', 'client', 'admin', 'retail_officer', 'inventory_custodian', 'cashier'];

// Send the admin back to the tab/page they acted from, not to "All" page 1.
$usersRedirect = static function (array $extra) use ($valid_tabs): void {
    $tab = in_array($_POST['tab'] ?? '', $valid_tabs, true) ? $_POST['tab'] : 'all';
    $query = ['tab' => $tab];
    if ((int)($_POST['page'] ?? 1) > 1) {
        $query['page'] = (int)$_POST['page'];
    }
    header('Location: manage_users.php?' . http_build_query($query + $extra));
    exit;
};

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

        // On a rejected submit, reopen the form with what was typed (never the password).
        $createFailed = static function (string $message) use ($usersRedirect, $role, $name, $surname, $age, $address, $email): void {
            $_SESSION['staff_create_flash'] = [
                'error' => $message,
                'old' => compact('role', 'name', 'surname', 'address', 'email') + ['age' => $age > 0 ? $age : ''],
            ];
            $usersRedirect(['create' => 1]);
        };

        if (!isset($staffRoles[$role]) || $name === '' || $surname === '' || $age < 13 || $address === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $createFailed('Please complete all staff-account fields with valid information.');
        }
        if (!isPasswordComplex($password, $db)) {
            $createFailed('The temporary password does not meet the configured password rules.');
        }

        $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch(PDO::FETCH_ASSOC)) {
            $createFailed('That email address is already in use.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        // Staff accounts are created by an administrator and are ready to use
        // immediately; email activation remains exclusive to client sign-up.
        $insert = $db->prepare('INSERT INTO users (name, surname, age, address, email, password, role, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
        if (!$insert->execute([$name, $surname, $age, $address, $email, $hash, $role])) {
            $createFailed('Unable to create the staff account. Please try again.');
        }

        logActivity($db, $admin_id, 'create_staff_account', 'Admin created a ' . $staffRoles[$role] . ' account for ' . $email);

        header('Location: manage_users.php?' . http_build_query(['tab' => $role, 'success' => 'staff_created']));
        exit;
    }

    if ($action === 'block') {
        // FIX: Use prepared statements
        $stmt = $db->prepare("UPDATE users SET is_locked = 1 WHERE id = ?");
        $stmt->execute([$id]);

        $stmt2 = $db->prepare("INSERT INTO locked_accounts (user_id, reason) VALUES (?, 'Admin manual block')");
        $stmt2->execute([$id]);

        logActivity($db, $admin_id, 'block_user', "Admin blocked user ID $id");
        $usersRedirect(['success' => 'blocked', 'uid' => $id]);

    } elseif ($action === 'unblock') {
        // FIX: Use prepared statements
        $stmt = $db->prepare("UPDATE users SET is_locked = 0, failed_attempts = 0 WHERE id = ?");
        $stmt->execute([$id]);

        $stmt2 = $db->prepare("DELETE FROM locked_accounts WHERE user_id = ?");
        $stmt2->execute([$id]);

        logActivity($db, $admin_id, 'unblock_user', "Admin unblocked user ID $id");
        $usersRedirect(['success' => 'unblocked', 'uid' => $id]);

    } elseif ($action === 'delete') {
        if ($id !== $admin_id) {
            // FIX: Use prepared statement
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);

            logActivity($db, $admin_id, 'delete_user', "Admin deleted user ID $id");
            $usersRedirect(['success' => 'deleted']);
        }
    }

    $usersRedirect(['success' => 1]);
}
 
// Counts — one pass over users feeds both the summary cards and the tabs
$counts = array_map('intval', $db->query(
    "SELECT COUNT(*) AS all_users,
            COUNT(*) FILTER (WHERE role = 'client') AS client,
            COUNT(*) FILTER (WHERE role = 'admin') AS admin,
            COUNT(*) FILTER (WHERE role = 'retail_officer') AS retail_officer,
            COUNT(*) FILTER (WHERE role = 'inventory_custodian') AS inventory_custodian,
            COUNT(*) FILTER (WHERE role = 'cashier') AS cashier,
            COUNT(*) FILTER (WHERE COALESCE(is_locked, 0) = 1) AS locked,
            COUNT(*) FILTER (WHERE COALESCE(is_verified, 0) = 0) AS unverified
     FROM users"
)->fetch(PDO::FETCH_ASSOC));
$count_all = $counts['all_users'];
$count_staff = $count_all - $counts['client'];

$csrf = generateCsrfToken();
$active_tab = $_GET['tab'] ?? 'all';
if (!in_array($active_tab, $valid_tabs, true)) {
    $active_tab = 'all';
}
$tabLabels = [
    'all' => 'All',
    'client' => 'Clients',
    'retail_officer' => 'Retail Officers',
    'inventory_custodian' => 'Inventory Custodians',
    'cashier' => 'Cashiers',
    'admin' => 'Admins',
];

// ── Users for the active tab, one page at a time (server-side paging) ─────────
$tabWhere = $active_tab === 'all' ? '' : 'WHERE u.role = ?';
$tabParams = $active_tab === 'all' ? [] : [$active_tab];
$tabOrder = $active_tab === 'all' ? 'u.role, u.name ASC' : 'u.name ASC';
$countStmt = $db->prepare("SELECT COUNT(*) FROM users u LEFT JOIN locked_accounts la ON la.user_id = u.id {$tabWhere}");
$countStmt->execute($tabParams);
$usersTotal = (int)$countStmt->fetchColumn();
$perPage = 25;
$totalPages = max(1, (int)ceil($usersTotal / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$usersStmt = $db->prepare(
    "SELECT u.*, la.reason as lock_reason, la.locked_at FROM users u
     LEFT JOIN locked_accounts la ON la.user_id = u.id
     {$tabWhere}
     ORDER BY {$tabOrder}, u.id ASC
     LIMIT " . (int)$perPage . ' OFFSET ' . (int)(($page - 1) * $perPage)
);
$usersStmt->execute($tabParams);
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
$usersPageUrl = static function (int $p) use ($active_tab): string {
    return 'manage_users.php?' . http_build_query(['tab' => $active_tab, 'page' => $p]);
};
$roleLabels = ['client' => 'Client', 'admin' => 'Admin'] + $staffRoles;
$flashUid = (int)($_GET['uid'] ?? 0);

// Every row form posts back where it came from (see $usersRedirect).
$rowHidden = static function (string $action, int $uid) use ($csrf, $active_tab, $page): string {
    return '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="id" value="' . $uid . '">'
        . '<input type="hidden" name="tab" value="' . h($active_tab) . '">'
        . '<input type="hidden" name="page" value="' . (int)$page . '">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
};

// Create-staff form: reopen with the typed values after a rejected submit.
$createFlash = $_SESSION['staff_create_flash'] ?? null;
unset($_SESSION['staff_create_flash']);
$old = is_array($createFlash['old'] ?? null) ? $createFlash['old'] : [];
$createError = (string)($createFlash['error'] ?? '');
$openCreate = $createFlash !== null || isset($_GET['create']);
$defaultStaffRole = $old['role'] ?? (isset($staffRoles[$active_tab]) ? $active_tab : 'retail_officer');

$pwRules = getPasswordRules($db);
$pwRuleItems = ['len' => 'At least ' . (int)$pwRules['min_length'] . ' characters'];
if ($pwRules['require_upper'])   { $pwRuleItems['upper']   = 'One uppercase letter'; }
if ($pwRules['require_lower'])   { $pwRuleItems['lower']   = 'One lowercase letter'; }
if ($pwRules['require_number'])  { $pwRuleItems['number']  = 'One number'; }
if ($pwRules['require_special']) { $pwRuleItems['special'] = 'One special character'; }

$usersCss = <<<'CSS'
        /* ── Summary ── */
        .users-summary { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
        .users-summary .stat-card { padding: 16px 18px; }
        .users-summary .stat-num { font-size: 26px; }
        .users-summary .stat-icon { top: 16px; transform: none; font-size: 18px; }
        .stat-hint { margin-top: 8px; font-size: 12px; font-weight: 500; color: var(--text-muted); }
        .stat-card.is-alert::after { background: #dc2626; }
        .stat-card.is-alert .stat-num,
        .stat-card.is-alert .stat-icon { color: #dc2626; }
        .stat-card.is-warn::after { background: var(--ep-yellow); }
        .stat-card.is-warn .stat-num,
        .stat-card.is-warn .stat-icon { color: #b45309; }

        /* ── Accounts card: header, tabs, table and pager share one surface ── */
        .users-card { padding: 0; overflow: hidden; }
        .users-tabs {
            display: flex;
            gap: 4px;
            padding: 10px 22px;
            border-bottom: 1px solid var(--border);
            overflow-x: auto;
            scrollbar-width: none;
        }
        .users-tabs::-webkit-scrollbar { display: none; }
        .tab-btn {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            white-space: nowrap;
            transition: background-color 150ms ease, color 150ms ease, transform 160ms var(--ease-out);
        }
        .tab-btn:active { transform: scale(.97); }
        .tab-btn:focus-visible { outline: 2px solid var(--ep-green); outline-offset: -2px; }
        .tab-btn.active { background: var(--ep-green-light); border-color: var(--teal-light); color: var(--ep-green-dark); }
        .tab-count {
            min-width: 20px;
            padding: 1px 7px;
            border-radius: 100px;
            background: var(--slate-100);
            color: var(--slate-600);
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            font-variant-numeric: tabular-nums;
        }
        .tab-btn.active .tab-count { background: var(--ep-green); color: #fff; }

        .users-card .ias-table td { padding-top: 10px; padding-bottom: 10px; }
        .users-card .ias-table th:first-child,
        .users-card .ias-table td:first-child { padding-left: 22px; }
        .users-card .ias-table th:last-child,
        .users-card .ias-table td:last-child { padding-right: 22px; }
        .users-card .staff-pagination { margin: 0; padding: 14px 22px 16px; }

        /* ── Rows ── */
        .user-cell { display: flex; align-items: center; gap: 11px; }
        .user-avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            font-size: 12px; font-weight: 700; letter-spacing: .02em;
        }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .user-name { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
        .user-name strong { display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600; color: var(--text-main); white-space: nowrap; }
        .user-sub { display: flex; align-items: baseline; gap: 6px; min-width: 0; font-size: 12px; color: var(--text-muted); }
        .user-id { flex-shrink: 0; color: var(--slate-400); font-variant-numeric: tabular-nums; }
        .you-chip {
            padding: 1px 6px;
            border-radius: 4px;
            background: var(--ep-green-light);
            color: var(--ep-green-dark);
            font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
        }
        .user-email {
            max-width: 280px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Role tint: shared by the avatar and the role badge so a role reads at a glance */
        .role-badge {
            display: inline-flex; align-items: center;
            padding: 3px 9px;
            border-radius: 100px;
            font-size: 11px; font-weight: 600;
            white-space: nowrap;
        }
        .role-client { background: var(--slate-100); color: var(--slate-600); }
        .role-admin { background: var(--ep-green-light); color: var(--teal-deeper); }
        .role-retail_officer { background: #eef4ff; color: #2452c9; }
        .role-inventory_custodian { background: #fff4e6; color: #c2410c; }
        .role-cashier { background: #f3f0ff; color: #6d28d9; }

        /* Normal states stay quiet; only what needs attention gets colour */
        .quiet-state { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 500; color: var(--text-muted); white-space: nowrap; }
        .quiet-state .fa-check { font-size: 10.5px; color: var(--ep-green); }
        .status-dot { width: 7px; height: 7px; border-radius: 50%; background: #16a34a; }
        .badge i { margin-right: 5px; font-size: 9.5px; }
        .lock-info { margin-top: 4px; font-size: 11.5px; color: var(--slate-500); white-space: nowrap; }

        .actions-cell { display: flex; gap: 6px; align-items: center; }
        .actions-cell form { margin: 0; }
        .row-act { min-width: 84px; justify-content: center; background: #fff; color: var(--slate-600); border: 1px solid var(--border); }
        .row-act i { font-size: 10.5px; }
        .row-act.act-unlock { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }

        @media (hover: hover) and (pointer: fine) {
            .tab-btn:not(.active):hover { background: var(--slate-100); color: var(--text-main); }
            .row-act:hover { background: var(--slate-50); color: var(--text-main); border-color: var(--slate-300); }
            .row-act.act-block:hover { background: var(--yellow-pale); color: #854d0e; border-color: #fde68a; }
            .row-act.act-delete:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
            .row-act.act-unlock:hover { background: #16a34a; color: #fff; border-color: #16a34a; }
        }

        /* The row you just changed holds a tint, then fades — confirms where the action landed */
        @keyframes userRowFlash {
            0%, 35% { background-color: var(--ep-green-light); }
            100% { background-color: transparent; }
        }
        tr.row-flash td { animation: userRowFlash 1800ms ease-out; }

        /* ── Empty state ── */
        .users-empty { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 44px 24px; }
        .users-empty .empty-icon {
            width: 44px; height: 44px;
            margin-bottom: 8px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--slate-100);
            color: var(--slate-400);
            font-size: 17px;
        }
        .users-empty .empty-title { margin: 0; font-size: 14px; font-weight: 600; color: var(--text-main); }
        .users-empty .empty-sub { margin: 0 0 12px; font-size: 13px; }

        /* ── Create staff modal ── */
        /* Above the floating chat widget (9000/9001), below IAS_UI dialogs (9999) */
        #createStaffModal { z-index: 9500; }
        .filter-modal-card.staff-create-card { width: min(540px, 100%); padding: 0; }
        .sc-head { display: flex; align-items: flex-start; gap: 12px; padding: 20px 22px 12px; }
        .sc-icon {
            width: 38px; height: 38px;
            flex-shrink: 0;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: var(--ep-green-light);
            border: 1px solid var(--teal-light);
            color: var(--ep-green-dark);
            font-size: 15px;
        }
        .staff-create-card .sc-head h4 { margin: 0 0 2px; font-size: 16px; color: var(--text-main); }
        .sc-head p { margin: 0; font-size: 12.5px; line-height: 1.5; color: var(--text-muted); }
        .sc-close {
            margin-left: auto;
            width: 32px; height: 32px;
            flex-shrink: 0;
            border: none;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: transparent;
            color: var(--slate-400);
            cursor: pointer;
            transition: background-color 150ms ease, color 150ms ease, transform 160ms var(--ease-out);
        }
        .sc-close:active { transform: scale(.94); }
        .sc-close:focus-visible { outline: none; box-shadow: var(--focus-ring); }
        .sc-error { margin: 0 22px 4px; }

        .staff-create-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 12px; padding: 8px 22px 0; }
        .staff-create-grid .full-width { grid-column: 1 / -1; }
        .staff-create-grid label { display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: var(--slate-600); }
        .staff-create-grid input,
        .staff-create-grid select {
            width: 100%;
            height: 40px;
            padding: 0 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: #fff;
            font: inherit;
            font-size: 13.5px;
            color: var(--text-main);
            transition: border-color 150ms ease, box-shadow 150ms ease;
        }
        .staff-create-grid input:focus,
        .staff-create-grid select:focus { outline: none; border-color: var(--ep-green); box-shadow: var(--focus-ring); }
        .staff-create-grid input:user-invalid { border-color: #fca5a5; }

        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 44px; }
        .pw-eye {
            position: absolute;
            right: 4px; top: 50%;
            transform: translateY(-50%);
            width: 32px; height: 32px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--slate-400);
            cursor: pointer;
            transition: background-color 150ms ease, color 150ms ease;
        }
        .pw-eye:focus-visible { outline: none; background: var(--ep-green-light); color: var(--ep-green-dark); }
        .pw-rules {
            list-style: none;
            margin: 8px 0 0;
            padding: 10px 12px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px 12px;
            background: var(--slate-50);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .pw-rules li { display: flex; align-items: center; gap: 7px; transition: color 150ms ease; }
        .pw-rules li i { font-size: 12px; color: var(--slate-300); transition: color 150ms ease; }
        .pw-rules li.ok { color: var(--ep-green-dark); font-weight: 600; }
        .pw-rules li.ok i { color: var(--ep-green); }

        .staff-create-actions {
            position: sticky;
            bottom: 0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin: 6px -22px 0;
            padding: 14px 22px 16px;
            border-top: 1px solid var(--border);
            background: var(--slate-50);
        }
        .staff-create-actions .btn { min-width: 96px; justify-content: center; }
        .btn.is-busy .fa-spin { animation-duration: .7s; }

        /* Exit is quicker than the enter in staff_shared.css (200ms) */
        .filter-modal.is-closing { opacity: 0; transition-duration: 150ms; }
        .filter-modal.is-closing .filter-modal-card { opacity: 0; transform: scale(.98); transition-duration: 150ms; }

        @media (hover: hover) and (pointer: fine) {
            .sc-close:hover { background: var(--slate-100); color: var(--text-main); }
            .pw-eye:hover { background: var(--ep-green-light); color: var(--ep-green-dark); }
        }
        @media (max-width: 650px) {
            .staff-create-grid,
            .pw-rules { grid-template-columns: 1fr; }
        }
        @media (prefers-reduced-motion: reduce) {
            .tab-btn:active,
            .sc-close:active { transform: none; }
            .filter-modal.is-closing .filter-modal-card { transform: none; }
        }
CSS;

staff_page_start([
    'role' => 'admin',
    'title' => 'Manage Users',
    'active' => 'users',
    'heading' => 'Manage Users',
    'subtitle' => 'Create staff accounts and block, unlock or remove user accounts',
    'extra_head' => '<style>' . $usersCss . '</style>',
]);
?>

        <div class="stats-grid users-summary">
            <div class="stat-card">
                <div class="stat-label">Total Accounts</div>
                <div class="stat-num"><?php echo $count_all; ?></div>
                <div class="stat-hint"><?php echo $counts['client']; ?> client<?php echo $counts['client'] === 1 ? '' : 's'; ?> · <?php echo $count_staff; ?> staff</div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Staff Accounts</div>
                <div class="stat-num"><?php echo $count_staff; ?></div>
                <div class="stat-hint">Including <?php echo $counts['admin']; ?> admin<?php echo $counts['admin'] === 1 ? '' : 's'; ?></div>
                <div class="stat-icon"><i class="fas fa-id-badge"></i></div>
            </div>
            <div class="stat-card<?php echo $counts['locked'] > 0 ? ' is-alert' : ''; ?>">
                <div class="stat-label">Locked</div>
                <div class="stat-num"><?php echo $counts['locked']; ?></div>
                <div class="stat-hint"><?php echo $counts['locked'] > 0 ? 'Cannot sign in until unlocked' : 'No locked accounts'; ?></div>
                <div class="stat-icon"><i class="fas fa-user-lock"></i></div>
            </div>
            <div class="stat-card<?php echo $counts['unverified'] > 0 ? ' is-warn' : ''; ?>">
                <div class="stat-label">Unverified</div>
                <div class="stat-num"><?php echo $counts['unverified']; ?></div>
                <div class="stat-hint"><?php echo $counts['unverified'] > 0 ? 'Email not yet confirmed' : 'Everyone is verified'; ?></div>
                <div class="stat-icon"><i class="fas fa-user-clock"></i></div>
            </div>
        </div>

        <div class="card users-card">
            <div class="card-header">
                <div>
                    <h3><span class="card-icon"><i class="fas fa-user-cog"></i></span> User Accounts</h3>
                    <div class="card-subtitle"><?php echo (int)$usersTotal; ?> account<?php echo $usersTotal === 1 ? '' : 's'; ?> · <?php echo $active_tab === 'all' ? 'All roles' : h($tabLabels[$active_tab]); ?></div>
                </div>
                <button type="button" class="btn btn-primary no-print" data-open-create><i class="fas fa-user-plus"></i> Create Staff Account</button>
            </div>
            <nav class="users-tabs" aria-label="Filter by role">
                <?php foreach ($tabLabels as $tabKey => $tabLabel): ?>
                    <a class="tab-btn<?php echo $active_tab === $tabKey ? ' active' : ''; ?>" href="manage_users.php?tab=<?php echo h($tabKey); ?>"<?php echo $active_tab === $tabKey ? ' aria-current="page"' : ''; ?>><?php echo h($tabLabel); ?> <span class="tab-count"><?php echo $tabKey === 'all' ? $count_all : $counts[$tabKey]; ?></span></a>
                <?php endforeach; ?>
            </nav>
            <div class="table-wrap">
                <table class="ias-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Verification</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $uid = (int)$u['id'];
                        $isSelf = $uid === $admin_id;
                        $isLocked = (int)$u['is_locked'] === 1;
                        $fullName = trim($u['name'] . ' ' . $u['surname']);
                        $roleClass = 'role-' . preg_replace('/[^a-z_]/', '', (string)$u['role']);
                        $avatarUrl = staff_chat_avatar_url($u['profile_image'] ?? null);
                        $lockInfo = [];
                        if (trim((string)($u['lock_reason'] ?? '')) !== '') {
                            $lockInfo[] = trim((string)$u['lock_reason']);
                        }
                        if (!empty($u['locked_at'])) {
                            $lockInfo[] = 'since ' . pos_format_datetime($u['locked_at'], 'M j, Y');
                        }
                    ?>
                    <tr id="user-<?php echo $uid; ?>"<?php echo $uid === $flashUid ? ' class="row-flash"' : ''; ?>>
                        <td>
                            <div class="user-cell">
                                <span class="user-avatar <?php echo h($roleClass); ?>" aria-hidden="true"><?php if ($avatarUrl !== ''): ?><img src="<?php echo h($avatarUrl); ?>" alt="" loading="lazy"><?php else: echo h(staff_user_initials((string)$u['name'], (string)$u['surname'])); endif; ?></span>
                                <div class="user-name">
                                    <strong><?php echo h($fullName); ?><?php if ($isSelf): ?> <span class="you-chip">You</span><?php endif; ?></strong>
                                    <span class="user-sub"><span class="user-email" title="<?php echo h($u['email']); ?>"><?php echo h($u['email']); ?></span><span class="user-id">#<?php echo $uid; ?></span></span>
                                </div>
                            </div>
                        </td>
                        <td><span class="role-badge <?php echo h($roleClass); ?>"><?php echo h($roleLabels[$u['role']] ?? ucfirst((string)$u['role'])); ?></span></td>
                        <td>
                            <?php if ($u['is_verified']): ?>
                                <span class="quiet-state"><i class="fas fa-check"></i>Verified</span>
                            <?php else: ?>
                                <span class="badge badge-unverified"><i class="fas fa-clock"></i>Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isLocked): ?>
                                <span class="badge badge-locked"><i class="fas fa-lock"></i>Locked</span>
                                <?php if ($lockInfo): ?><div class="lock-info"><?php echo h(implode(' · ', $lockInfo)); ?></div><?php endif; ?>
                            <?php else: ?>
                                <span class="quiet-state"><span class="status-dot"></span>Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions-cell">
                                <?php if ($isSelf): ?>
                                    <a href="admin_profile.php" class="btn btn-xs row-act"><i class="fas fa-pen"></i>Edit profile</a>
                                <?php else: ?>
                                    <?php if ($isLocked): ?>
                                        <form method="post">
                                            <?php echo $rowHidden('unblock', $uid); ?>
                                            <button type="submit" class="btn btn-xs row-act act-unlock"><i class="fas fa-lock-open"></i>Unlock</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" data-confirm="<?php echo h(($u['name'] !== '' ? $u['name'] : 'This user') . ' will not be able to sign in until you unlock the account.'); ?>" data-confirm-title="<?php echo h('Block ' . $fullName . '?'); ?>" data-confirm-ok="Block account" data-confirm-type="warning">
                                            <?php echo $rowHidden('block', $uid); ?>
                                            <button type="submit" class="btn btn-xs row-act act-block"><i class="fas fa-ban"></i>Block</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" data-confirm="<?php echo h('This permanently deletes ' . $fullName . ' (' . $u['email'] . ') and cannot be undone.'); ?>" data-confirm-title="Delete this user?" data-confirm-ok="Delete user" data-confirm-type="danger">
                                        <?php echo $rowHidden('delete', $uid); ?>
                                        <button type="submit" class="btn btn-xs row-act act-delete"><i class="fas fa-trash-alt"></i>Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state users-empty">
                                <span class="empty-icon"><i class="fas fa-user-slash"></i></span>
                                <p class="empty-title">No <?php echo h(strtolower($tabLabels[$active_tab])); ?> yet</p>
                                <?php if (isset($staffRoles[$active_tab])): ?>
                                    <p class="empty-sub">New <?php echo h(strtolower($staffRoles[$active_tab])); ?> accounts you create will appear here.</p>
                                    <button type="button" class="btn btn-primary btn-sm" data-open-create><i class="fas fa-user-plus"></i> Create <?php echo h($staffRoles[$active_tab]); ?> Account</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php staff_pagination($page, $totalPages, $usersTotal, $perPage, $usersPageUrl, 'User account pages'); ?>
    </div>

<div id="createStaffModal" class="filter-modal no-print" role="dialog" aria-modal="true" aria-labelledby="createStaffTitle" aria-describedby="createStaffDesc" data-open-on-load="<?php echo $openCreate ? '1' : '0'; ?>">
    <div class="filter-modal-card staff-create-card">
        <div class="sc-head">
            <span class="sc-icon" aria-hidden="true"><i class="fas fa-user-plus"></i></span>
            <div>
                <h4 id="createStaffTitle">Create staff account</h4>
                <p id="createStaffDesc">For retail officers, inventory custodians and cashiers. The account is verified and can sign in right away.</p>
            </div>
            <button type="button" class="sc-close" data-close-create aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <?php if ($createError !== ''): ?>
            <div class="alert alert-error sc-error" role="alert"><i class="fas fa-exclamation-circle"></i><span><?php echo h($createError); ?></span></div>
        <?php endif; ?>
        <form method="post" class="staff-create-grid" autocomplete="off">
            <input type="hidden" name="action" value="create_staff">
            <input type="hidden" name="tab" value="<?php echo h($active_tab); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <div>
                <label for="staff-name">First name</label>
                <input id="staff-name" type="text" name="name" value="<?php echo h((string)($old['name'] ?? '')); ?>" autocomplete="off" required>
            </div>
            <div>
                <label for="staff-surname">Surname</label>
                <input id="staff-surname" type="text" name="surname" value="<?php echo h((string)($old['surname'] ?? '')); ?>" autocomplete="off" required>
            </div>
            <div>
                <label for="staff-role">Role</label>
                <select id="staff-role" name="role" required>
                    <?php foreach ($staffRoles as $roleValue => $roleLabel): ?>
                        <option value="<?php echo h($roleValue); ?>"<?php echo $roleValue === $defaultStaffRole ? ' selected' : ''; ?>><?php echo h($roleLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="staff-age">Age</label>
                <input id="staff-age" type="number" name="age" min="13" inputmode="numeric" value="<?php echo h((string)($old['age'] ?? '')); ?>" required>
            </div>
            <div class="full-width">
                <label for="staff-email">Email address</label>
                <input id="staff-email" type="email" name="email" value="<?php echo h((string)($old['email'] ?? '')); ?>" autocomplete="off" spellcheck="false" required>
            </div>
            <div class="full-width">
                <label for="staff-password">Temporary password</label>
                <div class="pw-wrap">
                    <input id="staff-password" type="password" name="password" autocomplete="new-password" aria-describedby="staffPwRules" required>
                    <button type="button" class="pw-eye" aria-label="Show password" aria-pressed="false"><i class="far fa-eye"></i></button>
                </div>
                <ul class="pw-rules" id="staffPwRules" data-rules="<?php echo h(json_encode($pwRules)); ?>">
                    <?php foreach ($pwRuleItems as $ruleKey => $ruleLabel): ?>
                        <li data-rule="<?php echo h($ruleKey); ?>"><i class="far fa-circle" aria-hidden="true"></i><span><?php echo h($ruleLabel); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="full-width">
                <label for="staff-address">Location</label>
                <input id="staff-address" type="text" name="address" placeholder="City, Country" value="<?php echo h((string)($old['address'] ?? '')); ?>" required>
            </div>
            <div class="full-width staff-create-actions">
                <button type="button" class="btn btn-outline" data-close-create>Cancel</button>
                <button type="submit" class="btn btn-primary">Create account</button>
            </div>
        </form>
    </div>
</div>

<?php
staff_page_end(<<<'SCRIPTS'
<script>
(function () {
    // ── Feedback after a redirect ───────────────────────────────────────────
    var params = new URLSearchParams(window.location.search);
    var successText = {
        staff_created: 'Staff account created. It is verified and ready to sign in.',
        blocked: 'Account blocked. The user can no longer sign in.',
        unblocked: 'Account unlocked. The user can sign in again.',
        deleted: 'Account deleted.'
    };
    if (typeof IAS_UI !== 'undefined') {
        if (params.has('success')) {
            IAS_UI.alert(successText[params.get('success')] || 'User action completed successfully.', 'success');
        }
        if (params.has('error')) {
            IAS_UI.alert(params.get('error'), 'error');
        }
    }
    // Drop one-shot keys but keep tab/page, so a refresh stays on the same view.
    ['success', 'error', 'uid', 'create'].forEach(function (k) { params.delete(k); });
    var qs = params.toString();
    window.history.replaceState({}, document.title, window.location.href.split(/[?#]/)[0] + (qs ? '?' + qs : ''));

    var flashed = document.querySelector('tr.row-flash');
    if (flashed) {
        var r = flashed.getBoundingClientRect();
        if (r.top < 0 || r.bottom > window.innerHeight) flashed.scrollIntoView({ block: 'center' });
        flashed.addEventListener('animationend', function () { flashed.classList.remove('row-flash'); }, { once: true });
    }

    // ── Create staff modal ──────────────────────────────────────────────────
    var modal = document.getElementById('createStaffModal');
    if (!modal) return;
    var card = modal.querySelector('.filter-modal-card');
    var form = modal.querySelector('form');
    var submitBtn = form.querySelector('button[type="submit"]');
    var submitHtml = submitBtn.innerHTML;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var lastFocus = null;
    var closeTimer = 0;
    var pressedOnScrim = false;

    function isOpen() { return modal.classList.contains('open') && !modal.classList.contains('is-closing'); }

    function focusables() {
        return Array.prototype.filter.call(
            card.querySelectorAll('button:not([disabled]), input:not([type="hidden"]):not([disabled]), select, a[href]'),
            function (el) { return el.offsetParent !== null; }
        );
    }

    function openModal() {
        clearTimeout(closeTimer);
        if (!isOpen()) lastFocus = document.activeElement;
        modal.classList.remove('is-closing');
        modal.classList.add('open');
        requestAnimationFrame(function () {
            var first = form.querySelector('input:not([type="hidden"])');
            if (first) first.focus({ preventScroll: true });
        });
    }

    function closeModal() {
        if (!isOpen()) return;
        modal.classList.add('is-closing');
        closeTimer = setTimeout(function () {
            modal.classList.remove('open', 'is-closing');
        }, reduceMotion.matches ? 0 : 150);
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus({ preventScroll: true });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-open-create]')) openModal();
        else if (e.target.closest('[data-close-create]')) closeModal();
    });
    // Close on the scrim only when the press also started there, so a text
    // selection dragged out of an input never throws away the form.
    modal.addEventListener('mousedown', function (e) { pressedOnScrim = e.target === modal; });
    modal.addEventListener('click', function (e) {
        if (e.target === modal && pressedOnScrim) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (!isOpen()) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            closeModal();
        } else if (e.key === 'Tab') {
            var items = focusables();
            if (!items.length) return;
            var first = items[0], last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    // ── Password: live rule checklist + show/hide ───────────────────────────
    var pw = document.getElementById('staff-password');
    var rulesEl = document.getElementById('staffPwRules');
    var cfg = {};
    try { cfg = JSON.parse(rulesEl.getAttribute('data-rules') || '{}'); } catch (err) {}
    var tests = {
        len: function (v) { return v.length >= (parseInt(cfg.min_length, 10) || 8); },
        upper: function (v) { return /[A-Z]/.test(v); },
        lower: function (v) { return /[a-z]/.test(v); },
        number: function (v) { return /[0-9]/.test(v); },
        special: function (v) { return /[^A-Za-z0-9]/.test(v); }
    };
    function checkPassword() {
        var v = pw.value, allOk = true;
        rulesEl.querySelectorAll('[data-rule]').forEach(function (li) {
            var fn = tests[li.getAttribute('data-rule')];
            var ok = fn ? fn(v) : false;
            allOk = allOk && ok;
            li.classList.toggle('ok', ok);
            li.querySelector('i').className = ok ? 'fas fa-check-circle' : 'far fa-circle';
        });
        // Block the submit in the browser so a weak password never costs the typed form.
        pw.setCustomValidity(v === '' || allOk ? '' : 'Use a password that meets every rule listed below.');
    }
    pw.addEventListener('input', checkPassword);

    var eye = modal.querySelector('.pw-eye');
    eye.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eye.setAttribute('aria-pressed', show ? 'true' : 'false');
        eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        eye.querySelector('i').className = show ? 'far fa-eye-slash' : 'far fa-eye';
        pw.focus({ preventScroll: true });
    });

    // ── Submit: lock the button so a double click can't post twice ─────────
    form.addEventListener('submit', function () {
        submitBtn.disabled = true;
        submitBtn.classList.add('is-busy');
        submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Creating…';
    });
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        submitBtn.disabled = false;
        submitBtn.classList.remove('is-busy');
        submitBtn.innerHTML = submitHtml;
    });

    if (modal.getAttribute('data-open-on-load') === '1') openModal();
})();
</script>
SCRIPTS);
