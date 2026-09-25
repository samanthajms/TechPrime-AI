<?php
/**
 * Shared EasyPC staff layout (Admin / Retail / Inventory / Courier).
 * Design tokens match CLIENT/index.php.
 */

if (!function_exists('staff_nav_for_role')) {
    function staff_nav_for_role(string $role): array
    {
        switch ($role) {
            case 'admin':
                return [
                    ['key' => 'dashboard', 'href' => 'admin_dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                    ['key' => 'users', 'href' => 'manage_users.php', 'label' => 'Manage Users', 'icon' => 'fa-users'],
                    ['key' => 'logs', 'href' => 'view_logs.php', 'label' => 'Activity Logs', 'icon' => 'fa-clipboard-list'],
                    ['key' => 'profile', 'href' => 'admin_profile.php', 'label' => 'My Profile', 'icon' => 'fa-user'],
                    ['key' => 'settings', 'href' => 'admin_settings.php', 'label' => 'Settings', 'icon' => 'fa-cog'],
                ];
            case 'retail_officer':
                return [
                    ['key' => 'dashboard', 'href' => 'retail_dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                    ['key' => 'history', 'href' => 'retail_history.php', 'label' => 'History', 'icon' => 'fa-history'],
                    ['key' => 'profile', 'href' => 'retail_profile.php', 'label' => 'Profile', 'icon' => 'fa-user'],
                ];
            case 'inventory_custodian':
                return [
                    ['key' => 'dashboard', 'href' => 'inventory_dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                    ['key' => 'stocks', 'href' => 'inventory_stocks.php', 'label' => 'Stocks', 'icon' => 'fa-boxes'],
                    ['key' => 'orders', 'href' => 'inventory_orders.php', 'label' => 'Orders', 'icon' => 'fa-shopping-cart'],
                    ['key' => 'activity', 'href' => 'inventory_audit.php', 'label' => 'Activity', 'icon' => 'fa-clipboard-list'],
                    ['key' => 'profile', 'href' => 'inventory_profile.php', 'label' => 'Profile', 'icon' => 'fa-user'],
                ];
            case 'courier':
                return [
                    ['key' => 'dashboard', 'href' => 'courier_dashboard.php', 'label' => 'Dashboard', 'icon' => 'fa-tachometer-alt'],
                    ['key' => 'orders', 'href' => 'courier_orders.php', 'label' => 'Orders', 'icon' => 'fa-shopping-cart'],
                    ['key' => 'assign', 'href' => 'courier_assign.php', 'label' => 'Assignments', 'icon' => 'fa-truck'],
                    ['key' => 'history', 'href' => 'courier_history.php', 'label' => 'History', 'icon' => 'fa-history'],
                ];
            default:
                return [];
        }
    }
}

if (!function_exists('staff_role_label')) {
    function staff_role_label(string $role): string
    {
        $map = [
            'admin' => 'Admin',
            'retail_officer' => 'Retail Officer',
            'inventory_custodian' => 'Inventory Custodian',
        ];
        return $map[$role] ?? 'Staff';
    }
}

if (!function_exists('staff_css_href')) {
    function staff_css_href(): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/(ADMIN|RETAIL|INVENTORY|courier)$#', $scriptDir)) {
            return '../includes/staff_shared.css';
        }
        return 'includes/staff_shared.css';
    }
}

if (!function_exists('staff_logo_href')) {
    function staff_logo_href(): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/(ADMIN|RETAIL|INVENTORY|courier)$#', $scriptDir)) {
            return '../assets/logo.png';
        }
        return 'assets/easypc-logo-transparent.png';
    }
}

if (!function_exists('staff_logout_href')) {
    function staff_logout_href(): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/(ADMIN|RETAIL|INVENTORY|courier)$#', $scriptDir)) {
            return '../logout.php';
        }
        return 'logout.php';
    }
}

/**
 * Begin a staff page shell.
 *
 * @param array{
 *   title:string,
 *   active:string,
 *   heading:string,
 *   subtitle?:string,
 *   role?:string,
 *   extra_head?:string,
 *   nav?:array
 * } $opts
 */
if (!function_exists('staff_page_start')) {
    function staff_page_start(array $opts): void
    {
        $role = $opts['role'] ?? ($_SESSION['role'] ?? 'admin');
        $title = $opts['title'] ?? 'EasyPC';
        $active = $opts['active'] ?? '';
        $heading = $opts['heading'] ?? $title;
        $subtitle = $opts['subtitle'] ?? '';
        $extraHead = $opts['extra_head'] ?? '';
        $nav = $opts['nav'] ?? staff_nav_for_role($role);
        $userName = $_SESSION['name'] ?? 'User';
        $initials = strtoupper(substr($userName, 0, 1));
        $roleLabel = staff_role_label($role);
        $css = staff_css_href();
        $logo = staff_logo_href();
        $logout = staff_logout_href();
        $invTitleIcons = [
            'inventory_dashboard.php' => 'fa-tachometer-alt',
            'inventory_stocks.php' => 'fa-boxes',
            'inventory_orders.php' => 'fa-shopping-cart',
            'inventory_audit.php' => 'fa-clipboard-list',
            'inventory_details.php' => 'fa-shopping-cart',
            'inventory_profile.php' => 'fa-user',
        ];
        $invActiveIcons = [
            'dashboard' => 'fa-tachometer-alt',
            'stocks' => 'fa-boxes',
            'orders' => 'fa-shopping-cart',
            'activity' => 'fa-clipboard-list',
            'profile' => 'fa-user',
        ];
        $currentScript = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        $useInvPageTitle = ($role === 'inventory_custodian' && (
            isset($invTitleIcons[$currentScript]) || isset($invActiveIcons[$active])
        ));
        $invTitleIcon = $invTitleIcons[$currentScript]
            ?? ($invActiveIcons[$active] ?? 'fa-tachometer-alt');

        /* Inventory notifications (existing notifications table) */
        $staffNotifItems = [];
        $staffNotifUnread = 0;
        if ($role === 'inventory_custodian' && function_exists('getDbConnection') && isset($_SESSION['user_id'])) {
            if (!function_exists('inv_user_notifications')) {
                $alertsPath = __DIR__ . '/inventory_alerts.php';
                if (is_file($alertsPath)) {
                    require_once $alertsPath;
                }
            }
            if (function_exists('inv_user_notifications')) {
                try {
                    $nDb = getDbConnection();
                    $pack = inv_user_notifications($nDb, (int)$_SESSION['user_id'], 15);
                    $staffNotifItems = $pack['items'];
                    $staffNotifUnread = (int)$pack['unread'];
                } catch (Throwable $e) {
                    $staffNotifItems = [];
                    $staffNotifUnread = 0;
                }
            }
        }
        $staffNotifCsrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($title); ?> — EasyPC</title>
    <link rel="stylesheet" href="<?php echo h($css); ?>?v=inv-notif-1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php if ($useInvPageTitle): ?>
    <style>
      /* Guaranteed Inventory page title banner (reference design) */
      body.inv-title-mode .topbar.topbar-inv-compact {
        height: 56px !important;
        min-height: 56px !important;
      }
      .inv-page-banner {
        position: relative !important;
        display: flex !important;
        align-items: center !important;
        width: auto !important;
        box-sizing: border-box !important;
        margin: 18px 28px 8px !important;
        padding: 22px 28px !important;
        min-height: 96px !important;
        border-radius: 18px !important;
        background: linear-gradient(135deg, #62b236 0%, #4b8b2a 55%, #3d7422 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 14px 34px rgba(75, 139, 42, 0.35) !important;
        overflow: hidden !important;
        border: none !important;
      }
      .inv-page-banner-inner {
        position: relative !important;
        z-index: 2 !important;
        display: flex !important;
        align-items: center !important;
        gap: 16px !important;
        min-width: 0 !important;
      }
      .inv-page-banner-icon {
        width: 54px !important;
        height: 54px !important;
        border-radius: 14px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        background: rgba(255, 255, 255, 0.2) !important;
        border: 1px solid rgba(255, 255, 255, 0.3) !important;
        color: #fff !important;
        font-size: 22px !important;
        flex-shrink: 0 !important;
      }
      .inv-page-banner-text h1,
      .inv-page-banner-text .inv-page-banner-title {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 28px !important;
        font-weight: 800 !important;
        line-height: 1.15 !important;
        color: #ffffff !important;
        background: none !important;
        border: none !important;
        box-shadow: none !important;
      }
      .inv-page-banner-text p,
      .inv-page-banner-text .inv-page-banner-sub {
        margin: 6px 0 0 !important;
        font-size: 14px !important;
        font-weight: 500 !important;
        color: rgba(255, 255, 255, 0.92) !important;
        background: none !important;
        border: none !important;
      }
      .inv-page-banner-deco {
        position: absolute !important;
        inset: 0 !important;
        z-index: 1 !important;
        pointer-events: none !important;
        overflow: hidden !important;
      }
      .inv-page-banner-deco::before,
      .inv-page-banner-deco::after {
        content: '' !important;
        position: absolute !important;
        top: -70% !important;
        width: 200px !important;
        height: 240% !important;
        border-radius: 28px !important;
        transform: rotate(28deg) !important;
      }
      .inv-page-banner-deco::before {
        right: 56px !important;
        background: linear-gradient(180deg, rgba(255,255,255,0.22), rgba(255,255,255,0.02)) !important;
      }
      .inv-page-banner-deco::after {
        right: -20px !important;
        width: 150px !important;
        background: linear-gradient(180deg, rgba(196, 230, 160, 0.4), rgba(255,255,255,0.04)) !important;
      }
      @media (max-width: 900px) {
        .inv-page-banner { margin: 14px 16px 6px !important; padding: 18px 16px !important; }
        .inv-page-banner-text h1, .inv-page-banner-text .inv-page-banner-title { font-size: 22px !important; }
      }
    </style>
    <?php endif; ?>
    <?php echo $extraHead; ?>
</head>
<body class="<?php echo trim(($role === 'inventory_custodian' ? 'topnav-mode' : '') . ($useInvPageTitle ? ' inv-title-mode' : '')); ?>">
<div class="sidebar">
    <div class="sidebar-brand">
        <img src="<?php echo h($logo); ?>" alt="EasyPC" class="ep-logo-img brand-logo">
        <div>
            <div class="brand-text">EasyPC</div>
            <div class="brand-sub"><?php echo h($roleLabel); ?></div>
        </div>
    </div>
    <nav>
        <?php foreach ($nav as $item): ?>
            <a href="<?php echo h($item['href']); ?>" class="<?php echo ($item['key'] === $active) ? 'active' : ''; ?>">
                <i class="fas <?php echo h($item['icon']); ?>"></i>
                <span><?php echo h($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <a href="<?php echo h($logout); ?>"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
</div>

<div class="main">
    <div class="topbar<?php echo $useInvPageTitle ? ' topbar-inv-compact' : ''; ?>">
        <?php if (!$useInvPageTitle): ?>
        <div class="topbar-left">
            <h2><?php echo h($heading); ?></h2>
            <?php if ($subtitle !== ''): ?>
                <div class="breadcrumb"><?php echo h($subtitle); ?></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="topbar-left topbar-left-spacer" aria-hidden="true"></div>
        <?php endif; ?>
        <div class="topbar-right">
            <?php if ($role === 'inventory_custodian'): ?>
            <div class="staff-notif-wrap" id="staffNotifWrap">
                <button type="button" class="staff-notif-btn" id="staffNotifBtn"
                        aria-haspopup="true" aria-expanded="false" aria-controls="staffNotifPanel"
                        title="Notifications">
                    <i class="fas fa-bell" aria-hidden="true"></i>
                    <?php if ($staffNotifUnread > 0): ?>
                    <span class="staff-notif-badge" id="staffNotifBadge"><?php echo $staffNotifUnread > 99 ? '99+' : (int)$staffNotifUnread; ?></span>
                    <?php else: ?>
                    <span class="staff-notif-badge" id="staffNotifBadge" hidden>0</span>
                    <?php endif; ?>
                </button>
                <div class="staff-notif-panel" id="staffNotifPanel" role="menu" aria-label="Inventory notifications" hidden>
                    <div class="staff-notif-panel-head">
                        <strong>Notifications</strong>
                        <button type="button" class="staff-notif-markall" id="staffNotifMarkAll" title="Mark all as read">
                            <i class="fas fa-check-double" aria-hidden="true"></i>
                            <span>Mark all</span>
                        </button>
                    </div>
                    <ul class="staff-notif-list" id="staffNotifList">
                        <?php if (empty($staffNotifItems)): ?>
                        <li class="staff-notif-empty">
                            <i class="fas fa-bell-slash" aria-hidden="true"></i>
                            <span>No inventory notifications yet.</span>
                        </li>
                        <?php else: ?>
                            <?php foreach ($staffNotifItems as $n):
                                $nType = (string)($n['type'] ?? 'info');
                                $nRead = (int)($n['is_read'] ?? 0) === 1;
                                $nIcon = 'fa-info-circle';
                                $nLabel = 'Update';
                                if ($nType === 'low_stock') { $nIcon = 'fa-exclamation-triangle'; $nLabel = 'Low Stock'; }
                                elseif ($nType === 'out_of_stock') { $nIcon = 'fa-times-circle'; $nLabel = 'Out of Stock'; }
                                elseif ($nType === 'stock_updated') { $nIcon = 'fa-check-circle'; $nLabel = 'Stock Updated'; }
                                $when = function_exists('inv_relative_time')
                                    ? inv_relative_time((string)$n['created_at'])
                                    : (string)$n['created_at'];
                                $href = trim((string)($n['link'] ?? ''));
                                if ($href === '') {
                                    $href = 'inventory_stocks.php';
                                }
                                ?>
                        <li class="staff-notif-item<?php echo $nRead ? ' is-read' : ' is-unread'; ?>"
                            data-id="<?php echo (int)$n['id']; ?>"
                            data-type="<?php echo h($nType); ?>"
                            data-href="<?php echo h($href); ?>">
                            <button type="button" class="staff-notif-item-btn">
                                <span class="staff-notif-icon type-<?php echo h($nType); ?>" aria-hidden="true">
                                    <i class="fas <?php echo h($nIcon); ?>"></i>
                                </span>
                                <span class="staff-notif-body">
                                    <span class="staff-notif-type"><?php echo h($nLabel); ?></span>
                                    <span class="staff-notif-msg"><?php echo h((string)$n['message']); ?></span>
                                    <span class="staff-notif-time"><?php echo h($when); ?></span>
                                </span>
                                <?php if (!$nRead): ?><span class="staff-notif-dot" aria-hidden="true"></span><?php endif; ?>
                            </button>
                            <button type="button" class="staff-notif-remove" title="Remove notification" aria-label="Remove notification" data-notif-remove="<?php echo (int)$n['id']; ?>">
                                <i class="fas fa-times" aria-hidden="true"></i>
                            </button>
                        </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <script>
            (function () {
                var wrap = document.getElementById('staffNotifWrap');
                var btn = document.getElementById('staffNotifBtn');
                var panel = document.getElementById('staffNotifPanel');
                var badge = document.getElementById('staffNotifBadge');
                var markAll = document.getElementById('staffNotifMarkAll');
                var list = document.getElementById('staffNotifList');
                var csrf = <?php echo json_encode($staffNotifCsrf); ?>;
                if (!wrap || !btn || !panel) return;

                function setBadge(n) {
                    if (!badge) return;
                    n = Math.max(0, parseInt(n, 10) || 0);
                    if (n <= 0) {
                        badge.hidden = true;
                        badge.textContent = '0';
                    } else {
                        badge.hidden = false;
                        badge.textContent = n > 99 ? '99+' : String(n);
                    }
                }
                function unreadCount() {
                    return list ? list.querySelectorAll('.staff-notif-item.is-unread').length : 0;
                }
                function closePanel() {
                    panel.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                    wrap.classList.remove('open');
                }
                function openPanel() {
                    panel.hidden = false;
                    btn.setAttribute('aria-expanded', 'true');
                    wrap.classList.add('open');
                }
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (panel.hidden) openPanel(); else closePanel();
                });
                document.addEventListener('click', function (e) {
                    if (!wrap.contains(e.target)) closePanel();
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') closePanel();
                });

                function postNotif(action, id) {
                    var body = new URLSearchParams();
                    body.set('action', action);
                    body.set('csrf_token', csrf);
                    if (id) body.set('id', String(id));
                    return fetch('inventory_notif_api.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    }).then(function (r) { return r.json(); }).catch(function () { return null; });
                }

                if (list) {
                    list.addEventListener('click', function (e) {
                        var removeBtn = e.target.closest('[data-notif-remove]');
                        if (removeBtn) {
                            e.stopPropagation();
                            e.preventDefault();
                            var rid = removeBtn.getAttribute('data-notif-remove');
                            var item = removeBtn.closest('.staff-notif-item');
                            postNotif('delete', rid).then(function (data) {
                                if (!data || !data.ok) return;
                                if (item) item.remove();
                                setBadge(unreadCount());
                                if (list && !list.querySelector('.staff-notif-item')) {
                                    list.innerHTML = '<li class="staff-notif-empty"><i class="fas fa-bell-slash" aria-hidden="true"></i><span>No inventory notifications yet.</span></li>';
                                }
                            });
                            return;
                        }
                        var item = e.target.closest('.staff-notif-item');
                        if (!item) return;
                        if (e.target.closest('.staff-notif-remove')) return;
                        var id = item.getAttribute('data-id');
                        var href = item.getAttribute('data-href') || 'inventory_stocks.php';
                        var go = function () { window.location.href = href; };
                        if (item.classList.contains('is-unread')) {
                            postNotif('read', id).then(function () {
                                item.classList.remove('is-unread');
                                item.classList.add('is-read');
                                var dot = item.querySelector('.staff-notif-dot');
                                if (dot) dot.remove();
                                setBadge(unreadCount());
                                go();
                            });
                        } else {
                            go();
                        }
                    });
                }
                if (markAll) {
                    markAll.addEventListener('click', function (e) {
                        e.stopPropagation();
                        postNotif('read_all').then(function (data) {
                            if (!data || !data.ok) return;
                            if (list) {
                                list.querySelectorAll('.staff-notif-item.is-unread').forEach(function (el) {
                                    el.classList.remove('is-unread');
                                    el.classList.add('is-read');
                                    var dot = el.querySelector('.staff-notif-dot');
                                    if (dot) dot.remove();
                                });
                            }
                            setBadge(0);
                        });
                    });
                }
            })();
            </script>
            <?php endif; ?>
            <div class="admin-badge user-badge">
                <div class="avatar"><?php echo h($initials); ?></div>
                <?php echo h($userName); ?>
            </div>
        </div>
    </div>
    <?php if ($useInvPageTitle): ?>
    <div class="inv-page-banner" role="banner"
         style="background:linear-gradient(135deg,#62b236 0%,#4b8b2a 55%,#3d7422 100%);color:#fff;border-radius:18px;box-shadow:0 14px 34px rgba(75,139,42,.35);">
        <div class="inv-page-banner-inner">
            <div class="inv-page-banner-icon" aria-hidden="true">
                <i class="fas <?php echo h($invTitleIcon); ?>"></i>
            </div>
            <div class="inv-page-banner-text">
                <h1 class="inv-page-banner-title"><?php echo h($heading); ?></h1>
                <?php if ($subtitle !== ''): ?>
                <p class="inv-page-banner-sub"><?php echo h($subtitle); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="inv-page-banner-deco" aria-hidden="true"></div>
    </div>
    <?php endif; ?>
    <div class="page-content">
        <?php
    }
}

if (!function_exists('staff_page_end')) {
    function staff_page_end(string $extraScripts = ''): void
    {
        ?>
    </div><!-- /.page-content -->
</div><!-- /.main -->
<script src="<?php
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        echo preg_match('#/(ADMIN|RETAIL|INVENTORY|courier)$#', $scriptDir)
            ? '../includes/ui_alerts.js'
            : 'includes/ui_alerts.js';
    ?>"></script>
<?php echo $extraScripts; ?>
</body>
</html>
        <?php
    }
}
