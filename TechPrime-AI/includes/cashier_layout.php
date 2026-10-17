<?php
/**
 * Cashier app shell: a collapsible green sidebar with the cashier sections
 * (desktop), a slim top bar with the current section, a live clock and the
 * account menu, and a bottom tab bar instead of the sidebar on phones/tablets. staff_page_start() /
 * staff_page_end() hand cashier pages to these two functions, so every cashier
 * page (including the shared My Profile page) gets the same frame.
 *
 * Extra staff_page_start() options read here only:
 *   actions   => string  HTML for the page-header action area
 *   hide_head => bool    no page header (the POS workspace has its own)
 *
 * Styles: CASHIER/cashier.css (tokens + components). staff_shared.css is still
 * loaded for the shared pieces rendered inside (cards, forms, alerts, dialogs).
 */

if (!function_exists('cashier_shell_nav')) {
    function cashier_shell_nav(): array
    {
        return [
            ['key' => 'dashboard', 'href' => 'cashier_dashboard.php', 'label' => 'Dashboard', 'short' => 'Home', 'icon' => 'ph-squares-four'],
            ['key' => 'pos', 'href' => 'cashier_pos.php', 'label' => 'POS Checkout', 'short' => 'Checkout', 'icon' => 'ph-cash-register'],
            ['key' => 'alerts', 'href' => 'cashier_stock_alerts.php', 'label' => 'Stock Alerts', 'short' => 'Alerts', 'icon' => 'ph-bell'],
            ['key' => 'profile', 'href' => 'cashier_profile.php', 'label' => 'My Profile', 'short' => 'Profile', 'icon' => 'ph-user-circle'],
        ];
    }
}

if (!function_exists('cashier_shell_start')) {
    function cashier_shell_start(array $opts): void
    {
        $GLOBALS['IAS_CASHIER_SHELL'] = true;
        $title = $opts['title'] ?? 'EasyPC';
        $active = $opts['active'] ?? '';
        $heading = $opts['heading'] ?? $title;
        $subtitle = $opts['subtitle'] ?? '';
        $actions = (string)($opts['actions'] ?? '');
        $hideHead = !empty($opts['hide_head']);
        $extraHead = $opts['extra_head'] ?? '';
        $nav = cashier_shell_nav();
        $userName = $_SESSION['name'] ?? 'User';
        $userSurname = trim((string)($_SESSION['surname'] ?? ''));
        $userFullName = trim($userName . ' ' . $userSurname);
        $userEmail = (string)($_SESSION['email'] ?? '');
        $initials = staff_user_initials($userName, $userSurname);
        $css = staff_css_href();
        $logo = staff_logo_href();
        $logout = staff_logout_href();

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
    <title><?php echo h($title); ?> — EasyPC Cashier</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@500;600;700&amp;family=Fira+Sans:wght@400;500;600;700;800&amp;display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/regular/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/fill/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="<?php echo h($css); ?>?v=ep-motion-2">
    <link rel="stylesheet" href="cashier.css?v=11">
    <?php echo $extraHead; ?>
</head>
<body class="cx-app cx-page-<?php echo h($active !== '' ? $active : 'other'); ?>">
<script>
/* Apply the saved sidebar width before first paint (same preference key as the other staff roles) */
try {
    if (localStorage.getItem('ep_sidebar_collapsed') === '1' && window.matchMedia('(min-width: 901px)').matches) {
        document.body.classList.add('cx-side-collapsed');
    }
} catch (e) {}
</script>
<a class="cx-skip" href="#cxMain">Skip to main content</a>

<aside class="cx-sidebar" id="cxSidebar" aria-label="Cashier navigation">
    <div class="cx-side-brand">
        <a class="cx-side-logo" href="cashier_dashboard.php" aria-label="EasyPC Cashier, go to dashboard">
            <img src="<?php echo h($logo); ?>" alt="" width="96" height="23">
        </a>
        <div class="cx-side-store">
            <strong>Cashier</strong>
            <span>One Oasis · Pasig</span>
        </div>
    </div>

    <nav class="cx-side-nav" aria-label="Cashier sections">
        <span class="cx-side-label">Menu</span>
        <?php foreach ($nav as $item): $on = $item['key'] === $active; ?>
        <a href="<?php echo h($item['href']); ?>" class="cx-side-link<?php echo $on ? ' is-active' : ''; ?>"<?php echo $on ? ' aria-current="page"' : ''; ?> title="<?php echo h($item['label']); ?>">
            <i class="ph<?php echo $on ? '-fill' : ''; ?> <?php echo h($item['icon']); ?>" aria-hidden="true"></i>
            <span class="cx-side-text"><?php echo h($item['label']); ?></span>
        </a>
        <?php endforeach; ?>
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
        <a class="cx-brand" href="cashier_dashboard.php" aria-label="EasyPC Cashier, go to dashboard">
            <span class="cx-brand-mark"><img src="<?php echo h($logo); ?>" alt="" width="88" height="21"></span>
            <span class="cx-brand-text"><strong>Cashier</strong><span>One Oasis · Pasig</span></span>
        </a>

        <?php foreach ($nav as $item): if ($item['key'] !== $active) { continue; } ?>
        <div class="cx-crumb">
            <span>Cashier</span>
            <i class="ph ph-caret-right" aria-hidden="true"></i>
            <strong><?php echo h($item['key'] === 'dashboard' && $heading === 'Receipt' ? 'Receipt' : $item['label']); ?></strong>
        </div>
        <?php endforeach; ?>

        <div class="cx-topbar-right">
            <div class="cx-clock" id="cxClock">
                <i class="ph ph-clock" aria-hidden="true"></i>
                <span class="cx-clock-date" data-clock="date"></span>
                <time class="cx-clock-time" data-clock="time"></time>
            </div>
            <div class="cx-user" id="cxUser">
                <button type="button" class="cx-user-btn" id="cxUserBtn" aria-haspopup="true" aria-expanded="false" aria-controls="cxUserMenu">
                    <span class="cx-avatar<?php echo $userAvatarUrl !== '' ? ' has-img' : ''; ?>" aria-hidden="true"><?php echo $avatar; ?></span>
                    <span class="cx-user-text">
                        <span class="cx-user-name"><?php echo h($displayName); ?></span>
                        <span class="cx-user-role">Cashier</span>
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
                    <a role="menuitem" href="cashier_profile.php"<?php echo $active === 'profile' ? ' aria-current="page"' : ''; ?>>
                        <i class="ph ph-user-circle" aria-hidden="true"></i><span>My Profile</span>
                    </a>
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
        $GLOBALS['IAS_CASHIER_NAV'] = ['nav' => $nav, 'active' => $active];
    }
}

if (!function_exists('cashier_shell_end')) {
    function cashier_shell_end(string $extraScripts = ''): void
    {
        $nav = $GLOBALS['IAS_CASHIER_NAV']['nav'] ?? cashier_shell_nav();
        $active = $GLOBALS['IAS_CASHIER_NAV']['active'] ?? '';
        ?>
</main>
</div><!-- /.cx-frame -->

<nav class="cx-tabbar" aria-label="Cashier sections (bottom)">
    <?php foreach ($nav as $item): $on = $item['key'] === $active; ?>
    <a href="<?php echo h($item['href']); ?>" class="cx-tab<?php echo $on ? ' is-active' : ''; ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>>
        <i class="ph<?php echo $on ? '-fill' : ''; ?> <?php echo h($item['icon']); ?>" aria-hidden="true"></i>
        <span><?php echo h($item['short']); ?></span>
    </a>
    <?php endforeach; ?>
</nav>

<script src="../includes/ui_alerts.js"></script>
<?php echo ias_session_timeout_assets(); ?>
<?php echo $extraScripts; ?>
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
