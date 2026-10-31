<?php
/**
 * Staff app shell (the design introduced by the Cashier redesign), shared by every
 * role switched over in staff_app_shell_roles(): a collapsible green sidebar
 * (desktop), a slim top bar with the breadcrumb, a live store clock and the account
 * menu, and a bottom tab bar instead of the sidebar on phones/tablets. Roles with
 * more than five destinations get four tabs plus "More", which opens the full list
 * in a sheet. staff_page_start() / staff_page_end() hand those roles' pages here.
 *
 * Navigation comes from staff_nav_for_role(); the shell reads these extra keys:
 *   ph       Phosphor icon class (ph-…)
 *   short    tab bar label
 *   tab      true = one of the four phone tabs (only used when a role has > 5 items)
 *   section  label printed above this item in the sidebar (starts a new group)
 *   children [['slug','href','label'], …] sub-pages (shown under the item, active by active_child)
 *
 * Extra staff_page_start() options read here only:
 *   actions   => string  HTML for the page-header action area
 *   hide_head => bool    no page header (an sr-only h1 is still printed)
 *   report    => array   the page is a printable report (see staff_report_head()): loads
 *                        includes/staff_print.css and prints a letterhead + signature block
 *
 * The inventory custodian also gets the notifications bell (staff_app_notif_bell()).
 *
 * Styles: includes/staff_app.css (tokens, shell, components) + the role's own
 * stylesheet (CASHIER/cashier.css, ADMIN/admin.css, RETAIL/retail.css,
 * INVENTORY/inventory.css). staff_shared.css is still
 * loaded for the shared pieces rendered inside (cards, forms, alerts, dialogs).
 */

if (!function_exists('staff_app_shell_roles')) {
    /** Roles rendered with this shell; the others still use the legacy shell in staff_layout.php. */
    function staff_app_shell_roles(): array
    {
        return ['cashier', 'admin', 'retail_officer', 'inventory_custodian'];
    }
}

if (!function_exists('staff_app_role_css')) {
    /** The role's page stylesheet, relative to its folder (bump ?v= when it changes). */
    function staff_app_role_css(string $role): string
    {
        $map = [
            'cashier' => 'cashier.css?v=19',
            'admin' => 'admin.css?v=4',
            'retail_officer' => 'retail.css?v=2',
            'inventory_custodian' => 'inventory.css?v=3',
        ];
        return $map[$role] ?? '';
    }
}

if (!function_exists('staff_report_head')) {
    /**
     * Letterhead printed at the top of a report page (hidden on screen, see includes/staff_print.css).
     * $report keys:
     *   title => string  report name, e.g. "Sales History Report"
     *   lines => array   label => value rows under the title ("Period covered" => "…"); a value can be
     *                    ['text' => …, 'id' => …] when page JS keeps it up to date
     *   sign  => array   signature boxes [['role' => 'Prepared by', 'name' => …, 'note' => …], …]
     *                    (default: the signed-in user as "Prepared by" + a blank "Checked by")
     * "Prepared by" and "Date generated" are always added to the lines.
     */
    function staff_report_head(array $report, string $role): void
    {
        $lines = (array)($report['lines'] ?? []);
        $lines['Prepared by'] = staff_report_user_name() . ', ' . staff_role_label($role);
        $lines['Date generated'] = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A');
        ?>
    <header class="rpt-head">
        <div class="rpt-brand">
            <img src="<?php echo h(staff_logo_href()); ?>" alt="EasyPC">
            <div class="rpt-org">
                <strong>EasyPC One Oasis Branch</strong>
                <span>Rosario, Pasig City</span>
            </div>
        </div>
        <div class="rpt-title"><?php echo h((string)($report['title'] ?? 'Report')); ?></div>
        <dl class="rpt-meta">
            <?php foreach ($lines as $label => $value):
                $id = is_array($value) ? (string)($value['id'] ?? '') : '';
                $text = is_array($value) ? (string)($value['text'] ?? '') : (string)$value; ?>
            <div><dt><?php echo h((string)$label); ?></dt><dd<?php echo $id !== '' ? ' id="' . h($id) . '"' : ''; ?>><?php echo h($text); ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </header>
        <?php
    }

    function staff_report_user_name(): string
    {
        $name = trim((string)($_SESSION['name'] ?? '') . ' ' . (string)($_SESSION['surname'] ?? ''));
        return $name !== '' ? $name : 'Staff';
    }

    /** Signature block + end mark printed at the bottom of a report page. */
    function staff_report_sign(array $report, string $role): void
    {
        $sign = $report['sign'] ?? [
            ['role' => 'Prepared by', 'name' => staff_report_user_name(), 'note' => staff_role_label($role)],
            ['role' => 'Checked by', 'name' => '', 'note' => 'Signature over printed name'],
        ];
        ?>
    <div class="rpt-sign">
        <?php foreach ($sign as $s): ?>
        <div>
            <span class="rpt-sign-role"><?php echo h((string)($s['role'] ?? '')); ?>:</span>
            <span class="rpt-sign-line"></span>
            <span class="rpt-sign-name"><?php echo h((string)($s['name'] ?? '')); ?></span>
            <span><?php echo h((string)($s['note'] ?? '')); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="rpt-end">*** End of report ***</p>
        <?php
    }
}

if (!function_exists('staff_app_notif_bell')) {
    /**
     * Top-bar notifications (Admin, Retail Officer, Inventory Custodian): stock alerts for all three,
     * order updates for the custodian (inv_notification_types()). Stock alerts open the role's Stock Alerts
     * page, order updates open Orders (inv_notification_href()). Marked read / removed through
     * backend/api/staff_notifications.php.
     */
    function staff_app_notif_bell(string $role): void
    {
        if (!function_exists('inv_user_notifications')) {
            require_once __DIR__ . '/inventory_alerts.php';
        }
        if (!in_array($role, inv_stock_alert_roles(), true) || !function_exists('getDbConnection') || !isset($_SESSION['user_id'])) {
            return;
        }
        $items = [];
        $unread = 0;
        try {
            $pack = inv_user_notifications(getDbConnection(), (int)$_SESSION['user_id'], 15, inv_notification_types($role));
            $items = $pack['items'];
            $unread = (int)$pack['unread'];
        } catch (Throwable $e) {
            $items = [];
            $unread = 0;
        }
        $csrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
        $types = [
            'low_stock' => ['ph-warning', 'Low Stock'],
            'out_of_stock' => ['ph-x-circle', 'Out of Stock'],
            'stock_updated' => ['ph-check-circle', 'Stock Updated'],
            'order_update' => ['ph-receipt', 'Order Placed'],
            'new_order' => ['ph-shopping-cart-simple', 'Paid Order'],
            'cancel_requested' => ['ph-prohibit', 'Cancel Request'],
            'order_received' => ['ph-package', 'Order Completed'],
        ];
        $emptyText = $role === 'inventory_custodian' ? 'No stock alerts or order updates yet.' : 'No stock alerts yet.';
        ?>
            <div class="cx-notif" id="cxNotif" data-csrf="<?php echo h($csrf); ?>" data-empty="<?php echo h($emptyText); ?>">
                <button type="button" class="cx-notif-btn" id="cxNotifBtn" aria-haspopup="true" aria-expanded="false" aria-controls="cxNotifPanel">
                    <i class="ph ph-bell" aria-hidden="true"></i>
                    <span class="cx-notif-badge" id="cxNotifBadge" aria-hidden="true"<?php echo $unread > 0 ? '' : ' hidden'; ?>><?php echo $unread > 99 ? '99+' : (int)$unread; ?></span>
                    <span class="cx-sr" id="cxNotifLabel">Notifications, <?php echo $unread > 0 ? (int)$unread . ' unread' : 'none unread'; ?></span>
                </button>
                <div class="cx-notif-panel" id="cxNotifPanel" role="region" aria-label="Notifications" hidden>
                    <div class="cx-notif-head">
                        <strong>Notifications</strong>
                        <button type="button" class="cx-btn cx-btn-ghost cx-btn-sm" id="cxNotifMarkAll"><i class="ph ph-checks" aria-hidden="true"></i> Mark all read</button>
                    </div>
                    <ul class="cx-notif-list" id="cxNotifList">
                        <?php if (!$items): ?>
                        <li class="cx-notif-empty"><i class="ph ph-bell-slash" aria-hidden="true"></i><span><?php echo h($emptyText); ?></span></li>
                        <?php endif; ?>
                        <?php foreach ($items as $n):
                            $type = (string)($n['type'] ?? 'info');
                            $isRead = (int)($n['is_read'] ?? 0) === 1;
                            [$icon, $label] = $types[$type] ?? ['ph-info', 'Update'];
                            $when = function_exists('inv_relative_time') ? inv_relative_time((string)$n['created_at']) : (string)$n['created_at'];
                            $href = inv_notification_href($n, $role); ?>
                        <li class="cx-notif-item<?php echo $isRead ? ' is-read' : ' is-unread'; ?>" data-id="<?php echo (int)$n['id']; ?>" data-href="<?php echo h($href); ?>">
                            <button type="button" class="cx-notif-open">
                                <span class="cx-notif-icon type-<?php echo h($type); ?>" aria-hidden="true"><i class="ph-fill <?php echo h($icon); ?>"></i></span>
                                <span class="cx-notif-body">
                                    <span class="cx-notif-type"><?php echo h($label); ?><?php if (!$isRead): ?><span class="cx-sr"> (unread)</span><?php endif; ?></span>
                                    <span class="cx-notif-msg"><?php echo h((string)$n['message']); ?></span>
                                    <span class="cx-notif-time"><?php echo h($when); ?></span>
                                </span>
                                <?php if (!$isRead): ?><span class="cx-notif-dot" aria-hidden="true"></span><?php endif; ?>
                            </button>
                            <button type="button" class="cx-notif-remove" data-notif-remove="<?php echo (int)$n['id']; ?>" aria-label="Remove notification"><i class="ph ph-x" aria-hidden="true"></i></button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php
    }
}

if (!function_exists('staff_app_nav_badge')) {
    /**
     * Red count bubble on a nav item ("99+" above 99); the sr-only text keeps the link name readable.
     * Items with 'badge_live' always print the bubble (hidden at 0) with data-nav-badge so page JS can update it.
     */
    function staff_app_nav_badge(array $item): string
    {
        $n = (int)($item['badge'] ?? 0);
        $live = (string)($item['badge_live'] ?? '');
        if ($n <= 0 && $live === '') {
            return '';
        }
        $word = (string)($item['badge_sr'] ?? 'waiting');
        $data = $live !== '' ? ' data-nav-badge="' . h($live) . '" data-nav-badge-word="' . h($word) . '"' : '';
        return '<span class="cx-nav-badge"' . $data . ' aria-hidden="true"' . ($n > 0 ? '' : ' hidden') . '>' . ($n > 99 ? '99+' : $n) . '</span>'
            . '<span class="cx-sr"' . ($live !== '' ? ' data-nav-badge-sr="' . h($live) . '"' : '') . '>' . ($n > 0 ? ', ' . $n . ' ' . h($word) : '') . '</span>';
    }
}

if (!function_exists('staff_app_css_href')) {
    function staff_app_css_href(): string
    {
        return str_replace('staff_shared.css', 'staff_app.css', staff_css_href()) . '?v=8';
    }
}

if (!function_exists('staff_app_nav')) {
    /** staff_nav_for_role() items that the shell can draw (each needs a Phosphor icon). */
    function staff_app_nav(string $role): array
    {
        $nav = [];
        foreach (staff_nav_for_role($role) as $item) {
            $item['ph'] = $item['ph'] ?? 'ph-circle';
            $item['short'] = $item['short'] ?? $item['label'];
            $item['children'] = $item['children'] ?? [];
            $item['badge'] = 0;
            $nav[] = $item;
        }
        // Admin, retail officer, inventory custodian: unread staff-chat messages on the Messages tab.
        // Kept live by the chat widget (staff_chat_widget.php) and the Messages page through data-nav-badge.
        if (function_exists('getDbConnection') && isset($_SESSION['user_id'])) {
            require_once __DIR__ . '/staff_chat_lib.php';
            if (staff_chat_role_ok($role)) {
                try {
                    $unreadMsgs = staff_chat_unread_total(getDbConnection(), (int)$_SESSION['user_id']);
                } catch (Throwable $e) {
                    $unreadMsgs = 0;
                }
                foreach ($nav as &$item) {
                    if ($item['key'] === 'messages') {
                        $item['badge'] = $unreadMsgs;
                        $item['badge_live'] = 'messages';
                        $item['badge_sr'] = 'unread';
                    }
                }
                unset($item);
            }
        }
        // Inventory custodian: count of orders waiting on them, shown on the Orders tab.
        if ($role === 'inventory_custodian' && function_exists('getDbConnection')) {
            if (!function_exists('inv_orders_action_count')) {
                require_once __DIR__ . '/inventory_alerts.php';
            }
            try {
                $pending = inv_orders_action_count(getDbConnection());
            } catch (Throwable $e) {
                $pending = 0;
            }
            foreach ($nav as &$item) {
                if ($item['key'] === 'orders') {
                    $item['badge'] = $pending;
                }
            }
            unset($item);
        }
        return $nav;
    }
}

if (!function_exists('staff_app_shell_start')) {
    function staff_app_shell_start(array $opts): void
    {
        $role = (string)($opts['role'] ?? ($_SESSION['role'] ?? ''));
        $GLOBALS['IAS_APP_SHELL'] = true;
        $title = $opts['title'] ?? 'EasyPC';
        $active = $opts['active'] ?? '';
        $activeChild = (string)($opts['active_child'] ?? '');
        $heading = $opts['heading'] ?? $title;
        $subtitle = $opts['subtitle'] ?? '';
        $actions = (string)($opts['actions'] ?? '');
        $hideHead = !empty($opts['hide_head']);
        $extraHead = $opts['extra_head'] ?? '';
        $report = is_array($opts['report'] ?? null) ? $opts['report'] : null;
        $nav = staff_app_nav($role);
        $roleLabel = staff_role_label($role);
        $home = (string)($nav[0]['href'] ?? '#');
        $profileHref = '';
        $settingsHref = '';
        foreach ($nav as $item) {
            if ($item['key'] === 'profile') {
                $profileHref = (string)$item['href'];
            } elseif ($item['key'] === 'settings') {
                $settingsHref = (string)$item['href'];
            }
        }
        $userName = $_SESSION['name'] ?? 'User';
        $userSurname = trim((string)($_SESSION['surname'] ?? ''));
        $userFullName = trim($userName . ' ' . $userSurname);
        $userEmail = (string)($_SESSION['email'] ?? '');
        $initials = staff_user_initials($userName, $userSurname);
        $css = staff_css_href();
        $logo = staff_logo_href();
        $logout = staff_logout_href();
        $roleCss = staff_app_role_css($role);

        $userAvatarUrl = '';
        if (function_exists('getDbConnection') && isset($_SESSION['user_id'])) {
            try {
                require_once __DIR__ . '/staff_chat_lib.php';
                $aStmt = getDbConnection()->prepare('SELECT profile_image FROM users WHERE id = ? LIMIT 1');
                $aStmt->execute([(int)$_SESSION['user_id']]);
                $userAvatarUrl = staff_chat_avatar_url($aStmt->fetchColumn() ?: null);
            } catch (Throwable $e) {
                $userAvatarUrl = '';
            }
        }
        $avatar = $userAvatarUrl !== ''
            ? '<img src="' . h($userAvatarUrl) . '" alt="">'
            : h($initials);
        $displayName = $userFullName !== '' ? $userFullName : $userName;
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#4b8b2a">
    <title><?php echo h($title); ?> — EasyPC <?php echo h($roleLabel); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@500;600;700&amp;family=Fira+Sans:wght@400;500;600;700;800&amp;display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/regular/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/fill/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="<?php echo h($css); ?>?v=ep-motion-2">
    <link rel="stylesheet" href="<?php echo h(staff_app_css_href()); ?>">
    <?php if ($roleCss !== ''): ?><link rel="stylesheet" href="<?php echo h($roleCss); ?>"><?php endif; ?>
    <?php if ($report): ?><link rel="stylesheet" href="<?php echo h(str_replace('staff_shared.css', 'staff_print.css', $css)); ?>?v=1"><?php endif; ?>
    <?php echo $extraHead; ?>
</head>
<body class="cx-app cx-role-<?php echo h(str_replace('_', '-', $role)); ?> cx-page-<?php echo h($active !== '' ? $active : 'other'); ?><?php echo $report ? ' cx-report' : ''; ?>">
<script>
/* Apply the saved sidebar width before first paint (same preference key as the other staff roles) */
try {
    if (localStorage.getItem('ep_sidebar_collapsed') === '1' && window.matchMedia('(min-width: 901px)').matches) {
        document.body.classList.add('cx-side-collapsed');
    }
} catch (e) {}
</script>
<a class="cx-skip" href="#cxMain">Skip to main content</a>

<aside class="cx-sidebar" id="cxSidebar" aria-label="<?php echo h($roleLabel); ?> navigation">
    <div class="cx-side-brand">
        <a class="cx-side-logo" href="<?php echo h($home); ?>" aria-label="EasyPC <?php echo h($roleLabel); ?>, go to dashboard">
            <img src="<?php echo h($logo); ?>" alt="" width="96" height="23">
        </a>
        <div class="cx-side-store">
            <strong><?php echo h($roleLabel); ?></strong>
            <span>One Oasis · Pasig</span>
        </div>
    </div>

    <nav class="cx-side-nav" aria-label="<?php echo h($roleLabel); ?> sections">
        <span class="cx-side-label">Menu</span>
        <?php foreach ($nav as $item):
            $on = $item['key'] === $active;
            if (!empty($item['section'])): ?>
        <span class="cx-side-label cx-side-label-gap"><?php echo h($item['section']); ?></span>
            <?php endif;
            if ($item['children'] !== []):
                $subId = 'cxSub-' . preg_replace('/[^a-z0-9_-]/i', '', (string)$item['key']); ?>
        <div class="cx-side-group<?php echo $on ? ' is-open' : ''; ?>">
            <button type="button" class="cx-side-link cx-side-parent<?php echo $on ? ' is-active' : ''; ?>" aria-expanded="<?php echo $on ? 'true' : 'false'; ?>" aria-controls="<?php echo h($subId); ?>" title="<?php echo h($item['label']); ?>">
                <i class="ph<?php echo $on ? '-fill' : ''; ?> <?php echo h($item['ph']); ?>" aria-hidden="true"></i>
                <span class="cx-side-text"><?php echo h($item['label']); ?></span>
                <i class="ph ph-caret-down cx-side-caret" aria-hidden="true"></i>
            </button>
            <div class="cx-side-sub" id="<?php echo h($subId); ?>">
                <?php foreach ($item['children'] as $child):
                    $childOn = $on && (string)($child['slug'] ?? '') === $activeChild; ?>
                <a href="<?php echo h((string)$child['href']); ?>" class="cx-side-sublink<?php echo $childOn ? ' is-active' : ''; ?>"<?php echo $childOn ? ' aria-current="page"' : ''; ?>><?php echo h((string)$child['label']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
            <?php else: ?>
        <a href="<?php echo h($item['href']); ?>" class="cx-side-link<?php echo $on ? ' is-active' : ''; ?>"<?php echo $on ? ' aria-current="page"' : ''; ?> title="<?php echo h($item['label']); ?>">
            <i class="ph<?php echo $on ? '-fill' : ''; ?> <?php echo h($item['ph']); ?>" aria-hidden="true"></i>
            <span class="cx-side-text"><?php echo h($item['label']); ?></span>
            <?php echo staff_app_nav_badge($item); ?>
        </a>
            <?php endif;
        endforeach; ?>
    </nav>

    <div class="cx-side-foot">
        <button type="button" class="cx-side-link cx-side-toggle" id="cxSideToggle" aria-controls="cxSidebar" aria-expanded="true" title="Collapse sidebar">
            <i class="ph ph-sidebar-simple" aria-hidden="true"></i>
            <span class="cx-side-text">Collapse</span>
        </button>
        <a href="<?php echo h($logout); ?>" class="cx-side-link cx-side-logout" title="Log out">
            <i class="ph ph-sign-out" aria-hidden="true"></i>
            <span class="cx-side-text">Log out</span>
        </a>
    </div>
</aside>

<div class="cx-frame">
<header class="cx-topbar">
    <div class="cx-topbar-inner">
        <a class="cx-brand" href="<?php echo h($home); ?>" aria-label="EasyPC <?php echo h($roleLabel); ?>, go to dashboard">
            <span class="cx-brand-mark"><img src="<?php echo h($logo); ?>" alt="" width="88" height="21"></span>
            <span class="cx-brand-text"><strong><?php echo h($roleLabel); ?></strong><span>One Oasis · Pasig</span></span>
        </a>

        <?php foreach ($nav as $item): if ($item['key'] !== $active) { continue; }
            $crumbChild = '';
            foreach ($item['children'] as $child) {
                if ((string)($child['slug'] ?? '') === $activeChild) {
                    $crumbChild = (string)$child['label'];
                }
            } ?>
        <div class="cx-crumb">
            <span><?php echo h($roleLabel); ?></span>
            <i class="ph ph-caret-right" aria-hidden="true"></i>
            <?php if ($crumbChild !== ''): ?>
            <span><?php echo h($item['label']); ?></span>
            <i class="ph ph-caret-right" aria-hidden="true"></i>
            <strong><?php echo h($crumbChild); ?></strong>
            <?php else: ?>
            <strong><?php echo h($item['key'] === 'dashboard' && $heading === 'Receipt' ? 'Receipt' : $item['label']); ?></strong>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="cx-topbar-right">
            <div class="cx-clock" id="cxClock">
                <i class="ph ph-clock" aria-hidden="true"></i>
                <span class="cx-clock-date" data-clock="date"></span>
                <time class="cx-clock-time" data-clock="time"></time>
            </div>
            <?php staff_app_notif_bell($role); ?>
            <div class="cx-user" id="cxUser">
                <button type="button" class="cx-user-btn" id="cxUserBtn" aria-haspopup="true" aria-expanded="false" aria-controls="cxUserMenu">
                    <span class="cx-avatar<?php echo $userAvatarUrl !== '' ? ' has-img' : ''; ?>" aria-hidden="true"><?php echo $avatar; ?></span>
                    <span class="cx-user-text">
                        <span class="cx-user-name"><?php echo h($displayName); ?></span>
                        <span class="cx-user-role"><?php echo h($roleLabel); ?></span>
                    </span>
                    <i class="ph ph-caret-down cx-user-caret" aria-hidden="true"></i>
                    <span class="cx-sr">Account menu</span>
                </button>
                <div class="cx-user-menu" id="cxUserMenu" role="menu" aria-label="Account" hidden>
                    <div class="cx-user-menu-head">
                        <span class="cx-avatar cx-avatar-lg<?php echo $userAvatarUrl !== '' ? ' has-img' : ''; ?>" aria-hidden="true"><?php echo $avatar; ?></span>
                        <div>
                            <strong><?php echo h($displayName); ?></strong>
                            <?php if ($userEmail !== ''): ?><span><?php echo h($userEmail); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($profileHref !== ''): ?>
                    <a role="menuitem" href="<?php echo h($profileHref); ?>"<?php echo $active === 'profile' ? ' aria-current="page"' : ''; ?>>
                        <i class="ph ph-user-circle" aria-hidden="true"></i><span>My Profile</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($settingsHref !== ''): ?>
                    <a role="menuitem" href="<?php echo h($settingsHref); ?>"<?php echo $active === 'settings' ? ' aria-current="page"' : ''; ?>>
                        <i class="ph ph-gear-six" aria-hidden="true"></i><span>Settings</span>
                    </a>
                    <?php endif; ?>
                    <a role="menuitem" href="<?php echo h($logout); ?>" class="cx-user-logout">
                        <i class="ph ph-sign-out" aria-hidden="true"></i><span>Log out</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="cx-main" id="cxMain" tabindex="-1">
    <?php if (!$hideHead): ?>
    <div class="cx-pagehead">
        <div class="cx-pagehead-text">
            <h1><?php echo h($heading); ?></h1>
            <?php if ($subtitle !== ''): ?><p><?php echo h($subtitle); ?></p><?php endif; ?>
        </div>
        <?php if ($actions !== ''): ?><div class="cx-pagehead-actions"><?php echo $actions; ?></div><?php endif; ?>
    </div>
    <?php else: ?>
    <h1 class="cx-sr"><?php echo h($heading); ?></h1>
    <?php endif; ?>
        <?php
        if ($report) {
            staff_report_head($report, $role);
        }
        $GLOBALS['IAS_APP_NAV'] = ['role' => $role, 'nav' => $nav, 'active' => $active, 'active_child' => $activeChild, 'logout' => $logout, 'report' => $report];
    }
}

if (!function_exists('staff_app_shell_end')) {
    function staff_app_shell_end(string $extraScripts = ''): void
    {
        $state = $GLOBALS['IAS_APP_NAV'] ?? [];
        $role = (string)($state['role'] ?? ($_SESSION['role'] ?? ''));
        $nav = $state['nav'] ?? staff_app_nav($role);
        $active = (string)($state['active'] ?? '');
        $activeChild = (string)($state['active_child'] ?? '');
        $logout = (string)($state['logout'] ?? staff_logout_href());
        $roleLabel = staff_role_label($role);
        /* Up to five destinations fit the tab bar; more get four tabs + "More" (full list in a sheet). */
        $useMore = count($nav) > 5;
        $tabs = $useMore ? array_values(array_filter($nav, static fn($i) => !empty($i['tab']))) : $nav;
        $moreOn = $useMore && !in_array($active, array_column($tabs, 'key'), true);
        $report = $state['report'] ?? null;
        if ($report) {
            staff_report_sign($report, $role);
        }
        ?>
</main>
</div><!-- /.cx-frame -->

<nav class="cx-tabbar<?php echo $useMore ? ' has-more' : ''; ?>" style="--cx-tabs: <?php echo count($tabs) + ($useMore ? 1 : 0); ?>" aria-label="<?php echo h($roleLabel); ?> sections (bottom)">
    <?php foreach ($tabs as $item): $on = $item['key'] === $active; ?>
    <a href="<?php echo h($item['href']); ?>" class="cx-tab<?php echo $on ? ' is-active' : ''; ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>>
        <i class="ph<?php echo $on ? '-fill' : ''; ?> <?php echo h($item['ph']); ?>" aria-hidden="true"></i>
        <span><?php echo h($item['short']); ?></span>
        <?php echo staff_app_nav_badge($item); ?>
    </a>
    <?php endforeach; ?>
    <?php if ($useMore): ?>
    <button type="button" class="cx-tab cx-tab-more<?php echo $moreOn ? ' is-active' : ''; ?>" id="cxMoreBtn" aria-haspopup="dialog" aria-expanded="false" aria-controls="cxMore">
        <i class="ph<?php echo $moreOn ? '-fill' : ''; ?> ph-dots-three-circle" aria-hidden="true"></i>
        <span>More</span>
    </button>
    <?php endif; ?>
</nav>

<?php if ($useMore): ?>
<div class="cx-sheet" id="cxMore" role="dialog" aria-modal="true" aria-labelledby="cxMoreTitle" hidden>
    <div class="cx-sheet-scrim" data-sheet-close></div>
    <div class="cx-sheet-panel">
        <div class="cx-sheet-head">
            <h2 id="cxMoreTitle"><?php echo h($roleLabel); ?> menu</h2>
            <button type="button" class="cx-sheet-close" data-sheet-close aria-label="Close menu"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <nav class="cx-sheet-nav" aria-label="All <?php echo h(strtolower($roleLabel)); ?> sections">
            <?php foreach ($nav as $item):
                $on = $item['key'] === $active;
                if (!empty($item['section'])): ?>
            <span class="cx-sheet-label"><?php echo h($item['section']); ?></span>
                <?php endif;
                if ($item['children'] !== []): ?>
            <div class="cx-sheet-group">
                <span class="cx-sheet-link is-group<?php echo $on ? ' is-active' : ''; ?>">
                    <i class="ph<?php echo $on ? '-fill' : ''; ?> <?php echo h($item['ph']); ?>" aria-hidden="true"></i><?php echo h($item['label']); ?>
                </span>
                <?php foreach ($item['children'] as $child):
                    $childOn = $on && (string)($child['slug'] ?? '') === $activeChild; ?>
                <a href="<?php echo h((string)$child['href']); ?>" class="cx-sheet-sublink<?php echo $childOn ? ' is-active' : ''; ?>"<?php echo $childOn ? ' aria-current="page"' : ''; ?>><?php echo h((string)$child['label']); ?></a>
                <?php endforeach; ?>
            </div>
                <?php else: ?>
            <a href="<?php echo h($item['href']); ?>" class="cx-sheet-link<?php echo $on ? ' is-active' : ''; ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>>
                <i class="ph<?php echo $on ? '-fill' : ''; ?> <?php echo h($item['ph']); ?>" aria-hidden="true"></i><?php echo h($item['label']); ?><?php echo staff_app_nav_badge($item); ?>
            </a>
                <?php endif;
            endforeach; ?>
            <a href="<?php echo h($logout); ?>" class="cx-sheet-link cx-sheet-logout"><i class="ph ph-sign-out" aria-hidden="true"></i>Log out</a>
        </nav>
    </div>
</div>
<?php endif; ?>

<script src="../includes/ui_alerts.js"></script>
<?php echo ias_session_timeout_assets(); ?>
<?php echo $extraScripts; ?>
<?php if ($report): ?>
<script>
/* Charts on a report: redraw at the printed width, then back to the screen size */
(function () {
    function charts() { return typeof Chart !== 'undefined' && Chart.instances ? Object.values(Chart.instances) : []; }
    window.addEventListener('beforeprint', function () { charts().forEach(function (c) { c.resize(680, 260); }); });
    window.addEventListener('afterprint', function () { charts().forEach(function (c) { c.resize(); }); });
})();
</script>
<?php endif; ?>
<?php if (in_array($role, ['admin', 'retail_officer', 'inventory_custodian'], true)): /* roles with the Forecast page */ ?>
<script src="../includes/service_warmup.js?v=1" data-svc="forecast" defer></script>
<?php endif; ?>
<?php staff_chat_widget_maybe(); ?>
<script>
(function () {
    'use strict';
    /* Live clock in store time (Asia/Manila), refreshed on the minute */
    var clock = document.getElementById('cxClock');
    if (clock) {
        var dEl = clock.querySelector('[data-clock="date"]');
        var tEl = clock.querySelector('[data-clock="time"]');
        var fDate = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', weekday: 'short', month: 'short', day: 'numeric' });
        var fTime = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' });
        var tick = function () {
            var now = new Date();
            dEl.textContent = fDate.format(now);
            tEl.textContent = fTime.format(now);
            tEl.setAttribute('datetime', now.toISOString());
            setTimeout(tick, 60000 - (now.getSeconds() * 1000 + now.getMilliseconds()) + 50);
        };
        tick();
    }

    /* Sidebar: full width or icon rail on desktop; remembered across pages */
    var KEY = 'ep_sidebar_collapsed';
    var desk = window.matchMedia('(min-width: 901px)');
    var toggle = document.getElementById('cxSideToggle');
    function readCollapsed() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
    function applySide() {
        var collapsed = readCollapsed() && desk.matches;
        document.body.classList.toggle('cx-side-collapsed', collapsed);
        if (!toggle) return;
        var label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
        toggle.querySelector('.cx-side-text').textContent = collapsed ? 'Expand' : 'Collapse';
    }
    if (toggle) {
        toggle.addEventListener('click', function () {
            try { localStorage.setItem(KEY, readCollapsed() ? '0' : '1'); } catch (e) {}
            document.body.classList.add('cx-side-animate');
            applySide();
        });
    }
    if (typeof desk.addEventListener === 'function') desk.addEventListener('change', applySide);
    applySide();

    /* Sidebar items with sub-pages: open/close in place; on the icon rail, widen the sidebar first */
    Array.prototype.forEach.call(document.querySelectorAll('.cx-side-parent'), function (btn) {
        btn.addEventListener('click', function () {
            var group = btn.parentElement;
            if (document.body.classList.contains('cx-side-collapsed')) {
                try { localStorage.setItem(KEY, '0'); } catch (e) {}
                document.body.classList.add('cx-side-animate');
                applySide();
                group.classList.add('is-open');
            } else {
                group.classList.toggle('is-open');
            }
            btn.setAttribute('aria-expanded', group.classList.contains('is-open') ? 'true' : 'false');
        });
    });

    /* "More" sheet (phones/tablets, roles with more than five destinations) */
    var sheet = document.getElementById('cxMore');
    var moreBtn = document.getElementById('cxMoreBtn');
    if (sheet && moreBtn) {
        var setSheet = function (open) {
            sheet.hidden = !open;
            moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.classList.toggle('cx-sheet-open', open);
            if (open) {
                var cur = sheet.querySelector('[aria-current="page"]') || sheet.querySelector('.cx-sheet-close');
                if (cur) cur.focus({ preventScroll: true });
            } else {
                moreBtn.focus({ preventScroll: true });
            }
        };
        moreBtn.addEventListener('click', function () { setSheet(sheet.hidden); });
        sheet.addEventListener('click', function (e) { if (e.target.closest('[data-sheet-close]')) setSheet(false); });
        document.addEventListener('keydown', function (e) {
            if (sheet.hidden) return;
            if (e.key === 'Escape') { e.preventDefault(); setSheet(false); return; }
            if (e.key !== 'Tab') return;
            var items = sheet.querySelectorAll('a[href], button');
            var first = items[0], last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
        if (typeof desk.addEventListener === 'function') {
            desk.addEventListener('change', function () { if (desk.matches && !sheet.hidden) setSheet(false); });
        }
    }

<?php if (in_array($role, ['inventory_custodian', 'admin', 'retail_officer'], true)): ?>
    /* Notifications bell (admin, retail officer, inventory custodian): open, mark read, remove, mark all read */
    var nWrap = document.getElementById('cxNotif');
    if (nWrap) {
        var nBtn = document.getElementById('cxNotifBtn');
        var nPanel = document.getElementById('cxNotifPanel');
        var nBadge = document.getElementById('cxNotifBadge');
        var nLabel = document.getElementById('cxNotifLabel');
        var nList = document.getElementById('cxNotifList');
        var nCsrf = nWrap.getAttribute('data-csrf') || '';
        var EMPTY = '<li class="cx-notif-empty"><i class="ph ph-bell-slash" aria-hidden="true"></i><span></span></li>';
        var setNotifOpen = function (open) {
            nPanel.hidden = !open;
            nBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            nWrap.classList.toggle('is-open', open);
        };
        var syncCount = function () {
            var n = nList.querySelectorAll('.cx-notif-item.is-unread').length;
            nBadge.hidden = n <= 0;
            nBadge.textContent = n > 99 ? '99+' : String(n);
            nLabel.textContent = 'Notifications, ' + (n > 0 ? n + ' unread' : 'none unread');
        };
        var markRead = function (item) {
            item.classList.remove('is-unread');
            item.classList.add('is-read');
            Array.prototype.forEach.call(item.querySelectorAll('.cx-notif-dot, .cx-notif-type .cx-sr'), function (el) { el.remove(); });
        };
        var postNotif = function (action, id) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('csrf_token', nCsrf);
            if (id) body.set('id', String(id));
            return fetch('../backend/api/staff_notifications.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).catch(function () { return null; });
        };
        nBtn.addEventListener('click', function () { setNotifOpen(nPanel.hidden); });
        document.addEventListener('click', function (e) {
            if (!nPanel.hidden && !nWrap.contains(e.target)) setNotifOpen(false);
        }, true);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !nPanel.hidden) { setNotifOpen(false); nBtn.focus(); }
        });
        nList.addEventListener('click', function (e) {
            var rm = e.target.closest('[data-notif-remove]');
            if (rm) {
                var gone = rm.closest('.cx-notif-item');
                postNotif('delete', rm.getAttribute('data-notif-remove')).then(function (d) {
                    if (!d || !d.ok) return;
                    var next = gone.nextElementSibling || gone.previousElementSibling;
                    gone.remove();
                    if (!nList.querySelector('.cx-notif-item')) {
                        nList.innerHTML = EMPTY;
                        nList.querySelector('.cx-notif-empty span').textContent = nWrap.getAttribute('data-empty') || '';
                    }
                    syncCount();
                    (next ? next.querySelector('.cx-notif-open') : document.getElementById('cxNotifMarkAll')).focus();
                });
                return;
            }
            var open = e.target.closest('.cx-notif-open');
            if (!open) return;
            var item = open.closest('.cx-notif-item');
            var go = function () { if (item.getAttribute('data-href')) window.location.href = item.getAttribute('data-href'); };
            if (!item.classList.contains('is-unread')) { go(); return; }
            postNotif('read', item.getAttribute('data-id')).then(function () { markRead(item); syncCount(); go(); });
        });
        document.getElementById('cxNotifMarkAll').addEventListener('click', function () {
            postNotif('read_all').then(function (d) {
                if (!d || !d.ok) return;
                Array.prototype.forEach.call(nList.querySelectorAll('.cx-notif-item.is-unread'), markRead);
                syncCount();
            });
        });
    }

<?php endif; ?>
    /* Account menu */
    var wrap = document.getElementById('cxUser');
    var btn = document.getElementById('cxUserBtn');
    var menu = document.getElementById('cxUserMenu');
    if (!wrap || !btn || !menu) return;
    function setOpen(open) {
        menu.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        wrap.classList.toggle('is-open', open);
    }
    btn.addEventListener('click', function () {
        setOpen(menu.hidden);
        if (!menu.hidden) {
            var first = menu.querySelector('a');
            if (first) first.focus({ preventScroll: true });
        }
    });
    document.addEventListener('click', function (e) {
        if (!menu.hidden && !wrap.contains(e.target)) setOpen(false);
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !menu.hidden) { setOpen(false); btn.focus(); }
    });
    menu.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        var items = Array.prototype.slice.call(menu.querySelectorAll('a'));
        var i = items.indexOf(document.activeElement);
        if (i < 0) return;
        e.preventDefault();
        items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
    });
})();
</script>
</body>
</html>
        <?php
    }
}
