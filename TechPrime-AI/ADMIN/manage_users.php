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

$roleIcons = [
    'client' => 'ph-user',
    'admin' => 'ph-shield-check',
    'retail_officer' => 'ph-storefront',
    'inventory_custodian' => 'ph-warehouse',
    'cashier' => 'ph-cash-register',
];

staff_page_start([
    'role' => 'admin',
    'title' => 'Manage Users',
    'active' => 'users',
    'heading' => 'Manage Users',
    'subtitle' => 'Create staff accounts and block, unlock or remove user accounts',
    'actions' => '<button type="button" class="cx-btn cx-btn-primary no-print" data-open-create><i class="ph ph-user-plus" aria-hidden="true"></i> Create staff account</button>',
]);
?>

        <div class="sum-tiles" role="list" aria-label="Account summary">
            <div class="sum-tile" role="listitem">
                <span class="sum-tile-icon" aria-hidden="true"><i class="ph ph-users-three"></i></span>
                <div class="sum-tile-body">
                    <strong class="cx-num"><?php echo $count_all; ?></strong>
                    <span class="sum-tile-label">Total accounts</span>
                    <span class="sum-tile-hint"><?php echo $counts['client']; ?> client<?php echo $counts['client'] === 1 ? '' : 's'; ?> · <?php echo $count_staff; ?> staff</span>
                </div>
            </div>
            <div class="sum-tile" role="listitem">
                <span class="sum-tile-icon" aria-hidden="true"><i class="ph ph-identification-badge"></i></span>
                <div class="sum-tile-body">
                    <strong class="cx-num"><?php echo $count_staff; ?></strong>
                    <span class="sum-tile-label">Staff accounts</span>
                    <span class="sum-tile-hint">Including <?php echo $counts['admin']; ?> admin<?php echo $counts['admin'] === 1 ? '' : 's'; ?></span>
                </div>
            </div>
            <div class="sum-tile<?php echo $counts['locked'] > 0 ? ' is-alert' : ''; ?>" role="listitem">
                <span class="sum-tile-icon" aria-hidden="true"><i class="ph<?php echo $counts['locked'] > 0 ? '-fill' : ''; ?> ph-lock-key"></i></span>
                <div class="sum-tile-body">
                    <strong class="cx-num"><?php echo $counts['locked']; ?></strong>
                    <span class="sum-tile-label">Locked</span>
                    <span class="sum-tile-hint"><?php echo $counts['locked'] > 0 ? 'Cannot sign in until unlocked' : 'No locked accounts'; ?></span>
                </div>
            </div>
            <div class="sum-tile<?php echo $counts['unverified'] > 0 ? ' is-warn' : ''; ?>" role="listitem">
                <span class="sum-tile-icon" aria-hidden="true"><i class="ph<?php echo $counts['unverified'] > 0 ? '-fill' : ''; ?> ph-hourglass-medium"></i></span>
                <div class="sum-tile-body">
                    <strong class="cx-num"><?php echo $counts['unverified']; ?></strong>
                    <span class="sum-tile-label">Unverified</span>
                    <span class="sum-tile-hint"><?php echo $counts['unverified'] > 0 ? 'Email not yet confirmed' : 'Everyone is verified'; ?></span>
                </div>
            </div>
        </div>

        <section class="cx-card users-card" aria-labelledby="usersTitle">
            <div class="cx-card-head">
                <div>
                    <h2 id="usersTitle"><i class="ph ph-user-gear" aria-hidden="true"></i> User accounts</h2>
                    <p><?php echo (int)$usersTotal; ?> account<?php echo $usersTotal === 1 ? '' : 's'; ?> · <?php echo $active_tab === 'all' ? 'All roles' : h($tabLabels[$active_tab]); ?></p>
                </div>
            </div>
            <nav class="users-tabs" aria-label="Filter by role">
                <?php foreach ($tabLabels as $tabKey => $tabLabel): ?>
                    <a class="tab-btn<?php echo $active_tab === $tabKey ? ' active' : ''; ?>" href="manage_users.php?tab=<?php echo h($tabKey); ?>"<?php echo $active_tab === $tabKey ? ' aria-current="page"' : ''; ?>><?php echo h($tabLabel); ?> <span class="tab-count cx-num"><?php echo $tabKey === 'all' ? $count_all : $counts[$tabKey]; ?></span></a>
                <?php endforeach; ?>
            </nav>
            <?php if (empty($users)): ?>
                <div class="cx-empty">
                    <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-user-minus"></i></span>
                    <strong>No <?php echo h(strtolower($tabLabels[$active_tab])); ?> yet</strong>
                    <?php if (isset($staffRoles[$active_tab])): ?>
                        <span>New <?php echo h(strtolower($staffRoles[$active_tab])); ?> accounts you create will appear here.</span>
                        <button type="button" class="cx-btn cx-btn-primary" data-open-create><i class="ph ph-user-plus" aria-hidden="true"></i> Create <?php echo h($staffRoles[$active_tab]); ?> account</button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <div class="cx-table-wrap">
                <table class="cx-table users-table">
                <thead>
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col">Role</th>
                        <th scope="col">Verification</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="col-actions"><span class="cx-sr">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $uid = (int)$u['id'];
                        $isSelf = $uid === $admin_id;
                        $isLocked = (int)$u['is_locked'] === 1;
                        $fullName = trim($u['name'] . ' ' . $u['surname']);
                        $roleKey = preg_replace('/[^a-z_]/', '', (string)$u['role']);
                        $roleClass = 'role-' . $roleKey;
                        $avatarUrl = staff_chat_avatar_url($u['profile_image'] ?? null);
                        $lockInfo = [];
                        if (trim((string)($u['lock_reason'] ?? '')) !== '') {
                            $lockInfo[] = trim((string)$u['lock_reason']);
                        }
                        if (!empty($u['locked_at'])) {
                            $lockInfo[] = 'since ' . pos_format_datetime($u['locked_at'], 'M j, Y');
                        }
                        $rowClass = trim(($isLocked ? 'is-locked' : '') . ($uid === $flashUid ? ' row-flash' : ''));
                    ?>
                    <tr id="user-<?php echo $uid; ?>"<?php echo $rowClass !== '' ? ' class="' . $rowClass . '"' : ''; ?>>
                        <td data-label="User">
                            <div class="user-cell">
                                <span class="user-avatar <?php echo h($roleClass); ?>" aria-hidden="true"><?php if ($avatarUrl !== ''): ?><img src="<?php echo h($avatarUrl); ?>" alt="" loading="lazy"><?php else: echo h(staff_user_initials((string)$u['name'], (string)$u['surname'])); endif; ?></span>
                                <div class="user-name">
                                    <strong><span class="user-full"><?php echo h($fullName); ?></span><?php if ($isSelf): ?> <span class="you-chip">You</span><?php endif; ?></strong>
                                    <span class="user-sub"><span class="user-email" title="<?php echo h($u['email']); ?>"><?php echo h($u['email']); ?></span><span class="user-id cx-num">#<?php echo $uid; ?></span></span>
                                </div>
                            </div>
                        </td>
                        <td data-label="Role"><span class="role-badge <?php echo h($roleClass); ?>"><i class="ph <?php echo h($roleIcons[$roleKey] ?? 'ph-user'); ?>" aria-hidden="true"></i><?php echo h($roleLabels[$u['role']] ?? ucfirst((string)$u['role'])); ?></span></td>
                        <td data-label="Verification">
                            <?php if ($u['is_verified']): ?>
                                <span class="quiet-state"><i class="ph ph-seal-check" aria-hidden="true"></i>Verified</span>
                            <?php else: ?>
                                <span class="badge badge-unverified"><i class="ph ph-hourglass-medium" aria-hidden="true"></i>Pending</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?php if ($isLocked): ?>
                                <span class="badge badge-locked"><i class="ph-fill ph-lock-simple" aria-hidden="true"></i>Locked</span>
                                <?php if ($lockInfo): ?><div class="lock-info"><?php echo h(implode(' · ', $lockInfo)); ?></div><?php endif; ?>
                            <?php else: ?>
                                <span class="quiet-state"><span class="status-dot" aria-hidden="true"></span>Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-actions">
                            <div class="actions-cell">
                                <?php if ($isSelf): ?>
                                    <a href="admin_profile.php" class="cx-btn cx-btn-outline cx-btn-sm"><i class="ph ph-pencil-simple" aria-hidden="true"></i>Edit profile</a>
                                <?php else: ?>
                                    <?php if ($isLocked): ?>
                                        <form method="post">
                                            <?php echo $rowHidden('unblock', $uid); ?>
                                            <button type="submit" class="cx-btn cx-btn-sm row-act act-unlock" aria-label="<?php echo h('Unlock ' . $fullName); ?>"><i class="ph ph-lock-simple-open" aria-hidden="true"></i>Unlock</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" data-confirm="<?php echo h(($u['name'] !== '' ? $u['name'] : 'This user') . ' will not be able to sign in until you unlock the account.'); ?>" data-confirm-title="<?php echo h('Block ' . $fullName . '?'); ?>" data-confirm-ok="Block account" data-confirm-type="warning">
                                            <?php echo $rowHidden('block', $uid); ?>
                                            <button type="submit" class="cx-btn cx-btn-outline cx-btn-sm row-act act-block" aria-label="<?php echo h('Block ' . $fullName); ?>"><i class="ph ph-prohibit" aria-hidden="true"></i>Block</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" data-confirm="<?php echo h('This permanently deletes ' . $fullName . ' (' . $u['email'] . ') and cannot be undone.'); ?>" data-confirm-title="Delete this user?" data-confirm-ok="Delete user" data-confirm-type="danger">
                                        <?php echo $rowHidden('delete', $uid); ?>
                                        <button type="submit" class="cx-btn cx-btn-ghost-danger cx-btn-sm row-act act-delete" aria-label="<?php echo h('Delete ' . $fullName); ?>"><i class="ph ph-trash" aria-hidden="true"></i>Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php staff_pagination($page, $totalPages, $usersTotal, $perPage, $usersPageUrl, 'User account pages'); ?>
    </section>

<div id="createStaffModal" class="filter-modal no-print" role="dialog" aria-modal="true" aria-labelledby="createStaffTitle" aria-describedby="createStaffDesc" data-open-on-load="<?php echo $openCreate ? '1' : '0'; ?>">
    <div class="filter-modal-card staff-create-card">
        <div class="sc-head">
            <span class="sc-icon" aria-hidden="true"><i class="ph ph-user-plus"></i></span>
            <div>
                <h2 id="createStaffTitle">Create staff account</h2>
                <p id="createStaffDesc">For retail officers, inventory custodians and cashiers. The account is verified and can sign in right away.</p>
            </div>
            <button type="button" class="sc-close" data-close-create aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <?php if ($createError !== ''): ?>
            <div class="alert alert-error sc-error" role="alert"><i class="ph-fill ph-warning-circle" aria-hidden="true"></i><span><?php echo h($createError); ?></span></div>
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
                    <button type="button" class="pw-eye" aria-label="Show password" aria-pressed="false"><i class="ph ph-eye" aria-hidden="true"></i></button>
                </div>
                <ul class="pw-rules" id="staffPwRules" data-rules="<?php echo h(json_encode($pwRules)); ?>">
                    <?php foreach ($pwRuleItems as $ruleKey => $ruleLabel): ?>
                        <li data-rule="<?php echo h($ruleKey); ?>"><i class="ph ph-circle" aria-hidden="true"></i><span><?php echo h($ruleLabel); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="full-width">
                <label for="staff-address">Location</label>
                <input id="staff-address" type="text" name="address" placeholder="City, Country" value="<?php echo h((string)($old['address'] ?? '')); ?>" required>
            </div>
            <div class="full-width staff-create-actions">
                <button type="button" class="cx-btn cx-btn-outline" data-close-create>Cancel</button>
                <button type="submit" class="cx-btn cx-btn-primary">Create account</button>
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
            li.querySelector('i').className = ok ? 'ph-fill ph-check-circle' : 'ph ph-circle';
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
        eye.querySelector('i').className = show ? 'ph ph-eye-slash' : 'ph ph-eye';
        pw.focus({ preventScroll: true });
    });

    // ── Submit: lock the button so a double click can't post twice ─────────
    form.addEventListener('submit', function () {
        submitBtn.disabled = true;
        submitBtn.classList.add('is-busy');
        submitBtn.innerHTML = '<i class="ph ph-circle-notch cx-spin" aria-hidden="true"></i> Creating…';
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
