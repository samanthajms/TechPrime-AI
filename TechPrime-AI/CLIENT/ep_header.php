<?php
/**
 * ep_header.php — Shared EasyPC header & nav for all CLIENT pages.
 *
 * Expected: $isLoggedIn (bool), $activePage (string), optional $searchQuery, $pageTitle, $bodyClass
 * Home-only: $isHomePage = true
 * Category pages: $categoryHeroTitle (string), $currentCategory (string)
 */
if (!function_exists('ias_inventory_allowed_categories')) {
    require_once __DIR__ . '/../includes/product_categories.php';
}
$currentCategory = $currentCategory ?? '';
$epWishCount = 0;

$searchQuery = $searchQuery ?? '';
$isHomePage  = ($activePage ?? '') === 'home' || !empty($isHomePage);
$bodyClass   = $bodyClass ?? '';

if (!isset($epCartPreview)) {
    require_once __DIR__ . '/../includes/client_helpers.php';
    if (!empty($_SESSION['user_id']) && isset($db)) {
        ep_ensure_session_cart($db);
    } elseif (!empty($_SESSION['user_id'])) {
        ep_ensure_session_cart(getDbConnection());
    }
    $epCartPreview = ep_get_cart_preview($db ?? getDbConnection());
}
if (function_exists('ep_ensure_session_wishlist')) {
    $wishDb = $db ?? (function_exists('getDbConnection') ? getDbConnection() : null);
    if ($wishDb) {
        ep_ensure_session_wishlist($wishDb);
    }
    $epWishCount = count(ep_wishlist_ids());
}
$epCartItems  = $epCartPreview['items'];
$epCartTotal  = $epCartPreview['total'];
$epCartCount  = $epCartPreview['count'];

$epNotifItems = [];
$epNotifUnread = 0;
$epNotifCsrf = '';
require_once __DIR__ . '/../includes/client_notifications.php';
if (!empty($isLoggedIn) && !empty($_SESSION['user_id'])) {
    $notifDb = $db ?? getDbConnection();
    $pack = inv_user_notifications($notifDb, (int)$_SESSION['user_id'], 8);
    $epNotifItems = $pack['items'] ?? [];
    $epNotifUnread = (int)($pack['unread'] ?? 0);
    $epNotifCsrf = generateCsrfToken();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($pageTitle)): ?>
        <title><?php echo h($pageTitle); ?> | EasyPC</title>
    <?php else: ?>
        <title>EasyPC</title>
    <?php endif; ?>
    <script>
        /* Apply the saved theme before the stylesheets load so dark mode never flashes white. */
        try { if (localStorage.getItem('ep_theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark'); } catch (e) {}
    </script>
    <link rel="stylesheet" href="styles.css?v=orders-1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
    <?php /* Dark theme: generated overrides, then hand-written fixes. Both only match html[data-theme="dark"]. */ ?>
    <link rel="stylesheet" href="ep_dark_auto.css?v=dark-6">
    <link rel="stylesheet" href="ep_dark.css?v=dark-3">
    <?php echo ias_session_timeout_assets(); ?>
</head>
<body class="ep-body <?php echo h($bodyClass); ?>">

<?php
$epActive   = (string)($activePage ?? '');
$epUserName = !empty($isLoggedIn) ? trim((string)($_SESSION['name'] ?? '')) : '';
$epFirstName = $epUserName !== '' ? preg_split('/\s+/', $epUserName)[0] : 'Customer';
$epInitial  = strtoupper(mb_substr($epFirstName, 0, 1));
// Profile picture uploaded on user_dashboard.php. The file name is reused on re-upload,
// so its modified time is added to the URL to show a new photo right away.
$epAvatarUrl = '';
if (!empty($isLoggedIn) && !empty($_SESSION['user_id'])) {
    try {
        $avStmt = ($db ?? getDbConnection())->prepare('SELECT profile_image FROM users WHERE id = ?');
        $avStmt->execute([(int)$_SESSION['user_id']]);
        $avPath = (string)($avStmt->fetchColumn() ?: '');
        $epAvatarUrl = ep_user_profile_image_url($avPath);
        if ($epAvatarUrl !== '') {
            $epAvatarUrl .= '?v=' . (int)@filemtime(__DIR__ . '/../assets/' . $avPath);
        }
    } catch (Throwable $e) {
        $epAvatarUrl = '';   // no profile_image column yet: fall back to the initial
    }
}
$epPrimaryLinks = [
    ['href' => 'index.php', 'icon' => 'fa-home', 'label' => 'Home', 'active' => $epActive === 'home'],
    ['href' => 'shop.php', 'icon' => 'fa-store', 'label' => 'Shop Now', 'active' => $epActive === 'shop'],
    ['href' => !empty($isLoggedIn) ? 'build_a_pc.php' : '../login.php', 'icon' => 'fa-desktop', 'label' => 'Build a PC',
     'active' => $epActive === 'build_a_pc' || $epActive === 'saved_builds'],
];
?>
<header class="top-header ep-header full-width">
<div class="ep-header-main">
    <button type="button" class="ep-menu-btn" id="epMenuBtn"
            aria-label="Open menu" aria-controls="epDrawer" aria-expanded="false">
        <i class="fas fa-bars" aria-hidden="true"></i>
    </button>

    <a href="index.php" class="logo ep-logo">
        <img src="../assets/logo.png" alt="EasyPC home" class="ep-logo-img">
    </a>

    <?php /* Page links. Below 900px they move into the drawer. */ ?>
    <nav class="ep-pnav" aria-label="Primary">
        <?php foreach ($epPrimaryLinks as $link): ?>
            <a href="<?php echo h($link['href']); ?>"
               class="ep-pnav-link<?php echo $link['active'] ? ' active' : ''; ?>"
               <?php echo $link['active'] ? 'aria-current="page"' : ''; ?>>
                <i class="fas <?php echo h($link['icon']); ?>" aria-hidden="true"></i>
                <span><?php echo h($link['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="search-wrap" id="epSearchWrap">
        <form action="search.php" method="GET" id="epSearchForm" role="search" autocomplete="off">
            <input id="epSearchInput" name="q" type="text" placeholder="Search laptops, parts and accessories"
                   value="<?php echo h($searchQuery); ?>" aria-label="Search products"
                   aria-autocomplete="list" aria-controls="epSearchSuggest" aria-expanded="false"
                   autocomplete="off">
            <button type="submit" class="search-icon" aria-label="Search"><i class="fas fa-search"></i></button>
        </form>
        <div id="epSearchSuggest" class="ep-search-suggest" role="listbox" hidden></div>
    </div>

    <?php /* Icon buttons; their labels are visually hidden but stay readable by screen readers. */ ?>
    <div class="ep-nav-actions">
        <?php /* Click → notifications page. Hover (mouse) or keyboard focus → latest-notifications preview. */ ?>
        <div class="ep-notif-wrap" id="epNotifWrap">
            <a href="<?php echo !empty($isLoggedIn) ? 'notifications.php' : '../login.php'; ?>" id="notifBtn"
               class="ep-nav-item ep-notif-trigger<?php echo $epActive === 'notifications' ? ' active' : ''; ?>"
               <?php echo $epActive === 'notifications' ? 'aria-current="page"' : ''; ?>>
                <span class="ep-nav-item-icon">
                    <i class="<?php echo $epNotifUnread > 0 ? 'fas' : 'far'; ?> fa-bell" aria-hidden="true"></i>
                    <?php if ($epNotifUnread > 0): ?>
                        <span class="badge ep-notif-badge"><?php echo $epNotifUnread > 99 ? '99+' : (int)$epNotifUnread; ?></span>
                    <?php endif; ?>
                </span>
                <span class="ep-nav-item-label">Notifications</span>
            </a>
            <div id="epNotifPanel" class="ep-notif-dropdown" role="region" aria-label="Latest notifications">
                <div class="ep-notif-dd-head">
                    <div class="ep-notif-dd-title">
                        <strong>Notifications</strong>
                        <span class="ep-notif-dd-new" id="epNotifNewChip"<?php echo $epNotifUnread > 0 ? '' : ' hidden'; ?>><?php echo (int)$epNotifUnread; ?> new</span>
                    </div>
                    <?php if (!empty($isLoggedIn)): ?>
                        <button type="button" class="ep-notif-readall" data-notif-readall<?php echo $epNotifUnread > 0 ? '' : ' hidden'; ?>>
                            <i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (empty($isLoggedIn)): ?>
                    <div class="ep-notif-empty">
                        <span class="ep-notif-empty-icon"><i class="far fa-bell" aria-hidden="true"></i></span>
                        <strong>Stay in the loop</strong>
                        <span>Log in to get updates on your orders and payments.</span>
                        <a href="../login.php" class="ep-cart-dd-btn is-primary">Log in</a>
                    </div>
                <?php elseif (empty($epNotifItems)): ?>
                    <div class="ep-notif-empty">
                        <span class="ep-notif-empty-icon"><i class="far fa-bell" aria-hidden="true"></i></span>
                        <strong>You're all caught up</strong>
                        <span>Order and payment updates will show up here.</span>
                    </div>
                <?php else: ?>
                    <ul class="ep-notif-list" id="epNotifList">
                        <?php foreach ($epNotifItems as $n) echo ep_notif_render_item($n, 'dropdown'); ?>
                    </ul>
                    <a href="notifications.php" class="ep-notif-dd-foot">
                        View all notifications <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php /* Click → cart page. Hover (mouse) or keyboard focus → preview, rendered by epUpdateCartPreview(). */ ?>
        <div class="ep-cart-wrap" id="epCartWrap">
            <a href="cart.php" id="cartBtn"
               class="ep-nav-item ep-cart-trigger<?php echo $epActive === 'cart' ? ' active' : ''; ?>"
               <?php echo $epActive === 'cart' ? 'aria-current="page"' : ''; ?>>
                <span class="ep-nav-item-icon">
                    <i class="fas fa-shopping-bag" aria-hidden="true"></i>
                    <?php if ($epCartCount > 0): ?>
                        <span class="badge"><?php echo (int)$epCartCount; ?></span>
                    <?php endif; ?>
                </span>
                <span class="ep-nav-item-label">Cart</span>
            </a>
            <div id="epCartDropdown" class="ep-cart-dropdown" role="region" aria-label="Cart preview"></div>
        </div>

        <?php /* Light/dark theme switch. Which icon and label show is decided by CSS from html[data-theme]. */ ?>
        <button type="button" id="epThemeToggle" class="ep-nav-item ep-theme-toggle" title="Switch light / dark mode">
            <span class="ep-nav-item-icon">
                <i class="fas fa-moon ep-theme-when-light" aria-hidden="true"></i>
                <i class="fas fa-sun ep-theme-when-dark" aria-hidden="true"></i>
            </span>
            <span class="ep-nav-item-label">
                <span class="ep-theme-when-light">Dark Mode</span>
                <span class="ep-theme-when-dark">Light Mode</span>
            </span>
        </button>

        <?php if (!empty($isLoggedIn)): ?>
            <a href="user_dashboard.php" id="profileBtn"
               class="ep-account<?php echo $epActive === 'account' ? ' active' : ''; ?>"
               title="My profile" <?php echo $epActive === 'account' ? 'aria-current="page"' : ''; ?>>
                <span class="ep-account-avatar" aria-hidden="true"><?php if ($epAvatarUrl !== ''): ?><img src="<?php echo h($epAvatarUrl); ?>" alt=""><?php else: echo h($epInitial); endif; ?></span>
                <span class="ep-account-name"><span class="sr-only">My profile: </span><?php echo h($epFirstName); ?></span>
            </a>
        <?php else: ?>
            <a href="../login.php" id="profileBtn" class="ep-account is-guest">
                <i class="far fa-user" aria-hidden="true"></i>
                <span class="ep-account-name">Log in</span>
            </a>
        <?php endif; ?>
    </div>
</div>

</header>

<?php
/* ---- Mobile navigation drawer (≤ 900px; opened by #epMenuBtn) ---- */
$epOnSettings = $epActive === 'account' && isset($_GET['settings']);
$epDrawerLinks = [
    ['href' => 'index.php', 'icon' => 'fa-home', 'label' => 'Home', 'active' => $epActive === 'home'],
    ['href' => 'shop.php', 'icon' => 'fa-store', 'label' => 'Shop Now', 'active' => $epActive === 'shop'],
    ['categories' => true],
    ['href' => !empty($isLoggedIn) ? 'build_a_pc.php' : '../login.php', 'icon' => 'fa-desktop', 'label' => 'Build a PC', 'active' => $epActive === 'build_a_pc'],
];
if (!empty($isLoggedIn)) {
    $epDrawerLinks[] = ['href' => 'saved_builds.php', 'icon' => 'fa-folder-open', 'label' => 'Saved Builds', 'active' => $epActive === 'saved_builds'];
}
$epDrawerLinks[] = ['href' => 'wishlist.php', 'icon' => 'fa-heart', 'label' => 'Wishlist', 'active' => $epActive === 'wishlist', 'count' => $epWishCount];
$epDrawerLinks[] = ['href' => 'cart.php', 'icon' => 'fa-shopping-bag', 'label' => 'Cart', 'active' => $epActive === 'cart', 'count' => $epCartCount, 'id' => 'epDrawerCartCount'];
?>
<div class="ep-drawer-backdrop" id="epDrawerBackdrop" hidden></div>
<aside class="ep-drawer" id="epDrawer" role="dialog" aria-modal="true" aria-label="Main menu" aria-hidden="true">
    <div class="ep-drawer-head">
        <img src="../assets/logo.png" alt="EasyPC" class="ep-drawer-logo">
        <button type="button" class="ep-drawer-close" id="epDrawerClose" aria-label="Close menu">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>

    <div class="ep-drawer-account">
        <?php if (!empty($isLoggedIn)): ?>
            <span class="ep-drawer-avatar" aria-hidden="true"><?php if ($epAvatarUrl !== ''): ?><img src="<?php echo h($epAvatarUrl); ?>" alt=""><?php else: echo h($epInitial); endif; ?></span>
            <div class="ep-drawer-account-text">
                <strong>Hi, <?php echo h($epUserName !== '' ? $epUserName : 'Customer'); ?>!</strong>
                <a href="user_dashboard.php">View my profile</a>
            </div>
        <?php else: ?>
            <div class="ep-drawer-account-text">
                <strong>Welcome to EasyPC</strong>
                <span>Log in to track orders and save builds.</span>
            </div>
            <div class="ep-drawer-auth">
                <a href="../login.php" class="ep-drawer-auth-btn is-primary">Log in</a>
                <a href="../register.php" class="ep-drawer-auth-btn">Register</a>
            </div>
        <?php endif; ?>
    </div>

    <nav class="ep-drawer-nav" aria-label="Main menu">
        <?php foreach ($epDrawerLinks as $link): ?>
            <?php if (!empty($link['categories'])): ?>
                <details class="ep-drawer-group"<?php echo $currentCategory !== '' ? ' open' : ''; ?>>
                    <summary class="ep-drawer-link">
                        <i class="fas fa-th-large" aria-hidden="true"></i>
                        <span>Categories</span>
                        <i class="fas fa-chevron-down ep-drawer-caret" aria-hidden="true"></i>
                    </summary>
                    <div class="ep-drawer-sub">
                        <?php foreach (ias_inventory_allowed_categories() as $cat): ?>
                            <a href="category.php?type=<?php echo urlencode($cat); ?>"
                               class="<?php echo strcasecmp($currentCategory, $cat) === 0 ? 'is-active' : ''; ?>"
                               <?php echo strcasecmp($currentCategory, $cat) === 0 ? 'aria-current="page"' : ''; ?>><?php echo h($cat); ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php else: ?>
                <a href="<?php echo h($link['href']); ?>"
                   class="ep-drawer-link<?php echo $link['active'] ? ' is-active' : ''; ?>"
                   <?php echo $link['active'] ? 'aria-current="page"' : ''; ?>>
                    <i class="fas <?php echo h($link['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo h($link['label']); ?></span>
                    <?php if (array_key_exists('count', $link)): ?>
                        <span class="ep-drawer-count"<?php echo isset($link['id']) ? ' id="' . h($link['id']) . '"' : ''; ?><?php echo (int)$link['count'] > 0 ? '' : ' hidden'; ?>><?php echo (int)$link['count']; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!empty($isLoggedIn)): ?>
            <div class="ep-drawer-label">My account</div>
            <a href="user_dashboard.php"
               class="ep-drawer-link<?php echo $epActive === 'account' && !$epOnSettings ? ' is-active' : ''; ?>">
                <i class="fas fa-box" aria-hidden="true"></i><span>My Orders &amp; Profile</span>
            </a>
            <a href="user_dashboard.php?settings=1"
               class="ep-drawer-link<?php echo $epOnSettings ? ' is-active' : ''; ?>">
                <i class="fas fa-user-cog" aria-hidden="true"></i><span>Profile Settings</span>
            </a>
        <?php endif; ?>
    </nav>

    <?php if (!empty($isLoggedIn)): ?>
        <div class="ep-drawer-foot">
            <a href="../logout.php" class="ep-drawer-link ep-drawer-logout">
                <i class="fas fa-sign-out-alt" aria-hidden="true"></i><span>Log out</span>
            </a>
        </div>
    <?php endif; ?>
</aside>

<script>
(function () {
    function epSetHeaderOffset() {
        var header = document.querySelector('.ep-header');
        var h = header ? header.offsetHeight : 0;
        document.body.style.paddingTop = h + 'px';
        // Used by the phone layout to place the cart dropdown under the header.
        document.documentElement.style.setProperty('--ep-header-h', h + 'px');
    }
    epSetHeaderOffset();
    window.addEventListener('resize', epSetHeaderOffset);

    /* ---- Light / dark theme (saved per browser; applied early in <head>) ---- */
    var themeBtn = document.getElementById('epThemeToggle');
    var applyTheme = function (theme) {
        document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
    };
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem('ep_theme', next); } catch (e) {}
        });
    }
    // Keep other open tabs of the store in sync.
    window.addEventListener('storage', function (e) {
        if (e.key === 'ep_theme') applyTheme(e.newValue);
    });

    /* ---- Mobile navigation drawer ---- */
    var menuBtn = document.getElementById('epMenuBtn');
    var drawer = document.getElementById('epDrawer');
    var drawerBackdrop = document.getElementById('epDrawerBackdrop');
    var drawerClose = document.getElementById('epDrawerClose');
    var drawerMq = window.matchMedia('(max-width: 900px)');
    if (menuBtn && drawer) {
        var drawerFocusable = function () {
            return Array.prototype.filter.call(
                drawer.querySelectorAll('a[href], button:not([disabled]), summary'),
                function (el) { return el.offsetParent !== null; }
            );
        };
        var openDrawer = function () {
            if (!drawerMq.matches) return;
            // Close header popups so they don't sit on top of the drawer.
            var cw = document.getElementById('epCartWrap');
            if (cw) cw.classList.remove('open');
            var nw = document.getElementById('epNotifWrap');
            if (nw) nw.classList.add('is-dismissed');

            drawer.classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
            if (drawerBackdrop) drawerBackdrop.hidden = false;
            document.documentElement.classList.add('ep-drawer-open');
            menuBtn.setAttribute('aria-expanded', 'true');
            if (drawerClose) drawerClose.focus({ preventScroll: true });
        };
        var closeDrawer = function (returnFocus) {
            if (!drawer.classList.contains('open')) return;
            drawer.classList.remove('open');
            drawer.setAttribute('aria-hidden', 'true');
            if (drawerBackdrop) drawerBackdrop.hidden = true;
            document.documentElement.classList.remove('ep-drawer-open');
            menuBtn.setAttribute('aria-expanded', 'false');
            if (returnFocus) menuBtn.focus({ preventScroll: true });
        };

        menuBtn.addEventListener('click', openDrawer);
        if (drawerClose) drawerClose.addEventListener('click', function () { closeDrawer(true); });
        if (drawerBackdrop) drawerBackdrop.addEventListener('click', function () { closeDrawer(true); });
        drawer.addEventListener('click', function (e) {
            if (e.target.closest('a[href]')) closeDrawer(false);
        });
        document.addEventListener('keydown', function (e) {
            if (!drawer.classList.contains('open')) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                closeDrawer(true);
            } else if (e.key === 'Tab') {
                // Keep keyboard focus inside the open drawer.
                var items = drawerFocusable();
                if (!items.length) return;
                var first = items[0], last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
        var onDrawerBreakpoint = function () { if (!drawerMq.matches) closeDrawer(false); };
        if (typeof drawerMq.addEventListener === 'function') drawerMq.addEventListener('change', onDrawerBreakpoint);
        else if (typeof drawerMq.addListener === 'function') drawerMq.addListener(onDrawerBreakpoint);
    }

    /* Cart and Notifications: the icon is a plain link to its page. The preview opens on
       mouse hover or keyboard focus (CSS). Escape hides it until the pointer/focus leaves. */
    function epHoverPreview(wrap, trigger) {
        if (!wrap || !trigger) return;
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var focusedInside = wrap.contains(document.activeElement);
            if (!focusedInside && !wrap.matches(':hover')) return;
            wrap.classList.add('is-dismissed');
            if (focusedInside) trigger.focus();
        });
        wrap.addEventListener('mouseenter', function () { wrap.classList.remove('is-dismissed'); });
        wrap.addEventListener('mouseleave', function () { wrap.classList.remove('is-dismissed'); });
        wrap.addEventListener('focusout', function (e) {
            if (!wrap.contains(e.relatedTarget)) wrap.classList.remove('is-dismissed');
        });
    }
    epHoverPreview(document.getElementById('epCartWrap'), document.getElementById('cartBtn'));
    epHoverPreview(document.getElementById('epNotifWrap'), document.getElementById('notifBtn'));

    /* Update header Cart badge + dropdown from cart preview JSON (no page reload). */
    window.epUpdateCartPreview = function (preview) {
        preview = preview || { items: [], total: 0, count: 0 };
        var cartWrap = document.getElementById('epCartWrap');
        var cartBtn = document.getElementById('cartBtn');
        var dropdown = document.getElementById('epCartDropdown');
        if (!cartWrap || !dropdown) return;

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
        function money(n) {
            return Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        var icon = cartBtn ? cartBtn.querySelector('.ep-nav-item-icon') : null;
        if (icon) {
            var badge = icon.querySelector('.badge');
            var count = parseInt(preview.count, 10) || 0;
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'badge';
                    icon.appendChild(badge);
                }
                badge.textContent = String(count);
            } else if (badge) {
                badge.remove();
            }
        }
        var drawerCount = document.getElementById('epDrawerCartCount');
        if (drawerCount) {
            var dc = parseInt(preview.count, 10) || 0;
            drawerCount.textContent = String(dc);
            drawerCount.hidden = dc <= 0;
        }

        var items = Array.isArray(preview.items) ? preview.items : [];
        var count = parseInt(preview.count, 10) || 0;
        var html;
        if (items.length) {
            html = '<div class="ep-cart-dd-head"><strong>My Cart</strong><span>' +
                count + (count === 1 ? ' item' : ' items') + '</span></div>';
            html += '<ul class="ep-cart-dd-list">';
            items.forEach(function (ci) {
                var qty = parseInt(ci.qty, 10) || 0;
                var thumb = ci.image
                    ? '<img src="' + escapeHtml(ci.image) + '" alt="" loading="lazy">'
                    : '<i class="fas fa-box" aria-hidden="true"></i>';
                html += '<li class="ep-cart-dd-item">' +
                    '<span class="ep-cart-dd-thumb">' + thumb + '</span>' +
                    '<span class="ep-cart-dd-info">' +
                        '<span class="ep-cart-dd-name">' + escapeHtml(ci.name || '') + '</span>' +
                        '<span class="ep-cart-dd-meta">₱' + money(ci.price) + ' × ' + qty + '</span>' +
                    '</span>' +
                    '<strong class="ep-cart-dd-sub">₱' + money(ci.subtotal) + '</strong>' +
                    '</li>';
            });
            html += '</ul>';
            html += '<div class="ep-cart-dd-total"><span>Subtotal</span><strong>₱' + money(preview.total) + '</strong></div>';
            html += '<div class="ep-cart-dd-actions">' +
                '<a href="cart.php" class="ep-cart-dd-btn">View Cart</a>' +
                '<a href="checkout.php" class="ep-cart-dd-btn is-primary">Checkout</a>' +
                '</div>';
        } else {
            html = '<div class="ep-cart-dd-empty">' +
                '<span class="ep-cart-dd-empty-icon"><i class="fas fa-shopping-bag" aria-hidden="true"></i></span>' +
                '<strong>Your cart is empty</strong>' +
                '<span>Browse our products and add something you like.</span>' +
                '<a href="shop.php" class="ep-cart-dd-btn is-primary">Start Shopping</a>' +
                '</div>';
        }
        dropdown.innerHTML = html;
    };
    window.epUpdateCartPreview(<?php echo json_encode($epCartPreview, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);

    /* ---- Search recommendations (existing search bar) ---- */
    var searchInput = document.getElementById('epSearchInput');
    var searchSuggest = document.getElementById('epSearchSuggest');
    var searchForm = document.getElementById('epSearchForm');
    var searchWrap = document.getElementById('epSearchWrap');
    if (searchInput && searchSuggest && searchForm) {
        var suggestTimer = null;
        var suggestAbort = null;
        var activeIdx = -1;

        var RECENT_KEY = 'ep_recent_searches';
        var RECENT_MAX = 6;

        function hideSuggest() {
            searchSuggest.hidden = true;
            searchSuggest.innerHTML = '';
            searchInput.setAttribute('aria-expanded', 'false');
            activeIdx = -1;
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function isSafeSearchTerm(t) {
            t = String(t || '').trim();
            if (!t || t.length > 80) return false;
            if (t.indexOf('@') !== -1) return false;
            if (/^\+?[\d\s\-().]{7,}$/.test(t)) return false;
            return true;
        }

        function loadRecents() {
            try {
                var raw = localStorage.getItem(RECENT_KEY);
                var arr = raw ? JSON.parse(raw) : [];
                if (!Array.isArray(arr)) return [];
                return arr.map(function (x) { return String(x || '').trim(); }).filter(isSafeSearchTerm).slice(0, RECENT_MAX);
            } catch (e) {
                return [];
            }
        }

        function saveRecent(term) {
            if (!isSafeSearchTerm(term)) return;
            var t = String(term).trim();
            var arr = loadRecents().filter(function (x) { return x.toLowerCase() !== t.toLowerCase(); });
            arr.unshift(t);
            try { localStorage.setItem(RECENT_KEY, JSON.stringify(arr.slice(0, RECENT_MAX))); } catch (e) {}
        }

        function highlightMatch(label, q) {
            var safe = escapeHtml(label);
            var qi = String(q || '').trim();
            if (!qi) return safe;
            try {
                var re = new RegExp('(' + qi.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
                return safe.replace(re, '<mark>$1</mark>');
            } catch (e) {
                return safe;
            }
        }

        function suggestIcon(type) {
            if (type === 'recent') return 'fa-history';
            if (type === 'category') return 'fa-tag';
            if (type === 'brand') return 'fa-industry';
            if (type === 'product') return 'fa-box';
            return 'fa-search';
        }

        function renderSuggest(items, q, heading) {
            if (!items || !items.length) {
                hideSuggest();
                return;
            }
            activeIdx = -1;
            var head = heading ? '<div class="ep-search-suggest-head">' + escapeHtml(heading) + '</div>' : '';
            searchSuggest.innerHTML = head + items.map(function (item, i) {
                var type = item.type || 'product';
                return '<button type="button" class="ep-search-suggest-item" role="option" data-idx="' + i + '" data-label="' + escapeHtml(item.label) + '">' +
                    '<i class="fas ' + suggestIcon(type) + '" aria-hidden="true"></i>' +
                    '<span>' + highlightMatch(item.label, q) + '</span>' +
                    '</button>';
            }).join('');
            searchSuggest.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
        }

        function showRecents() {
            var recents = loadRecents();
            if (!recents.length) {
                hideSuggest();
                return;
            }
            renderSuggest(recents.map(function (label) {
                return { label: label, type: 'recent' };
            }), '', 'Recent searches');
        }

        function runSearch(term) {
            var t = String(term || '').trim();
            if (!t) return;
            saveRecent(t);
            hideSuggest();
            window.location.href = 'search.php?q=' + encodeURIComponent(t);
        }

        function fetchSuggest(q) {
            if (suggestAbort && suggestAbort.abort) {
                try { suggestAbort.abort(); } catch (e) {}
            }
            var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            suggestAbort = controller;
            var url = 'search_suggest.php?q=' + encodeURIComponent(q);
            fetch(url, { signal: controller ? controller.signal : undefined, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (searchInput.value.trim() !== q) return;
                    renderSuggest((data && data.suggestions) || [], q);
                })
                .catch(function () { /* ignore abort/network */ });
        }

        searchInput.addEventListener('input', function () {
            var q = searchInput.value.trim();
            if (suggestTimer) clearTimeout(suggestTimer);
            if (!q) {
                showRecents();
                return;
            }
            suggestTimer = setTimeout(function () { fetchSuggest(q); }, 180);
        });

        searchInput.addEventListener('focus', function () {
            if (!searchInput.value.trim()) showRecents();
        });

        searchInput.addEventListener('keydown', function (e) {
            var items = searchSuggest.querySelectorAll('.ep-search-suggest-item');
            if (e.key === 'Escape') {
                hideSuggest();
                return;
            }
            if (searchSuggest.hidden || !items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = Math.min(items.length - 1, activeIdx + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = Math.max(0, activeIdx - 1);
            } else if (e.key === 'Enter' && activeIdx >= 0 && items[activeIdx]) {
                e.preventDefault();
                runSearch(items[activeIdx].getAttribute('data-label'));
                return;
            } else {
                return;
            }
            items.forEach(function (el, i) {
                el.classList.toggle('active', i === activeIdx);
            });
        });

        searchSuggest.addEventListener('mousedown', function (e) {
            var btn = e.target.closest('.ep-search-suggest-item');
            if (!btn) return;
            e.preventDefault();
            runSearch(btn.getAttribute('data-label'));
        });

        searchForm.addEventListener('submit', function () {
            saveRecent(searchInput.value);
            hideSuggest();
        });

        document.addEventListener('click', function (e) {
            if (!searchWrap.contains(e.target)) hideSuggest();
        });
    }

    /* ---- Persistent notifications (DB-backed) ----
       Rows (.ep-notif-item[data-id]) live in the header preview and on notifications.php;
       the same notification can be on screen twice, so updates apply to every copy. */
    var notifCsrf = <?php echo json_encode($epNotifCsrf ?? ''); ?>;
    if (notifCsrf) {
        var postClientNotif = function (action, id, keepalive) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('csrf_token', notifCsrf);
            if (id) body.set('id', String(id));
            return fetch('client_notif_api.php', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: !!keepalive,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).catch(function () { return null; });
        };
        var notifCopies = function (id) {
            return document.querySelectorAll('.ep-notif-item[data-id="' + (parseInt(id, 10) || 0) + '"]');
        };
        var markItemRead = function (item) {
            item.classList.remove('is-unread');
            item.classList.add('is-read');
            var btn = item.querySelector('[data-notif-action="read"]');
            if (btn) btn.remove();
            var sr = item.querySelector('.ep-notif-sr');
            if (sr) sr.remove();
        };
        var setUnread = function (unread) {
            unread = Math.max(0, parseInt(unread, 10) || 0);
            var icon = document.querySelector('#notifBtn .ep-nav-item-icon');
            var badge = document.querySelector('#notifBtn .ep-notif-badge');
            var bell = icon ? icon.querySelector('.fa-bell') : null;
            if (bell) bell.className = (unread > 0 ? 'fas' : 'far') + ' fa-bell';
            if (unread <= 0) {
                if (badge) badge.remove();
            } else {
                if (!badge && icon) {
                    badge = document.createElement('span');
                    badge.className = 'badge ep-notif-badge';
                    icon.appendChild(badge);
                }
                if (badge) badge.textContent = unread > 99 ? '99+' : String(unread);
            }
            var chip = document.getElementById('epNotifNewChip');
            if (chip) {
                chip.textContent = unread + ' new';
                chip.hidden = unread <= 0;
            }
            document.querySelectorAll('[data-notif-readall]').forEach(function (b) { b.hidden = unread <= 0; });
            document.dispatchEvent(new CustomEvent('ep:notif-unread', { detail: { unread: unread } }));
        };
        var removeItem = function (item) {
            var list = item.parentNode;
            item.remove();
            if (!list || list.querySelector('.ep-notif-item')) return;
            if (list.id === 'epNotifList') {
                var foot = document.querySelector('.ep-notif-dd-foot');
                if (foot) foot.remove();
                list.outerHTML = '<div class="ep-notif-empty">' +
                    '<span class="ep-notif-empty-icon"><i class="far fa-bell" aria-hidden="true"></i></span>' +
                    '<strong>You&rsquo;re all caught up</strong>' +
                    '<span>Order and payment updates will show up here.</span></div>';
            } else {
                document.dispatchEvent(new CustomEvent('ep:notif-list-empty', { detail: { list: list } }));
            }
        };

        document.addEventListener('click', function (e) {
            var readAll = e.target.closest('[data-notif-readall]');
            if (readAll) {
                e.preventDefault();
                readAll.disabled = true;
                postClientNotif('read_all').then(function (data) {
                    readAll.disabled = false;
                    if (!data || !data.ok) return;
                    document.querySelectorAll('.ep-notif-item.is-unread').forEach(markItemRead);
                    setUnread(data.unread);
                });
                return;
            }

            var item = e.target.closest('.ep-notif-item[data-id]');
            if (!item) return;
            var id = item.getAttribute('data-id');
            var btn = e.target.closest('[data-notif-action]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                var action = btn.getAttribute('data-notif-action');
                btn.disabled = true;
                postClientNotif(action, id).then(function (data) {
                    btn.disabled = false;
                    if (!data || !data.ok) return;
                    notifCopies(id).forEach(action === 'delete' ? removeItem : markItemRead);
                    setUnread(data.unread);
                });
                return;
            }

            // Opening an unread notification marks it read; links still navigate normally
            // (keepalive lets the request finish after the page unloads).
            if (e.target.closest('[data-notif-open]') && item.classList.contains('is-unread')) {
                notifCopies(id).forEach(markItemRead);
                postClientNotif('read', id, true).then(function (data) {
                    if (data && data.ok) setUnread(data.unread);
                });
            }
        });
    }
})();
</script>

<?php if ($isHomePage): ?>
<section class="ep-hero full-width">
    <div class="ep-hero-content">
        <p class="ep-hero-kicker">TECH IT EASY AT</p>
        <h1 class="ep-hero-title">Easy PC<br>One Oasis Branch</h1>
        <p class="ep-hero-subtitle">Explore the latest PCs, laptops &amp; accessories from EasyPC.</p>
    </div>
    <div class="ep-hero-visual" aria-hidden="true">
        <span class="ep-hero-icon md"><img src="../assets/headset.png" alt="Headset"></span>
        <span class="ep-hero-icon lg"><img src="../assets/desktop.png" alt="Desktop"></span>
        <span class="ep-hero-icon sm"><img src="../assets/mouse.png" alt="Mouse"></span>
    </div>
</section>
<section class="ep-feature-strip full-width">
    <span><i class="fas fa-shipping-fast"></i> Free Shipping on Orders Over &#8369;2,500</span>
    <span><i class="fas fa-undo"></i> 30-Day Money Back Guarantee</span>
    <span><i class="fas fa-headset"></i> 24/7 Customer Support</span>
</section>
<?php elseif (!empty($categoryHeroTitle)): ?>
<?php /* Category hero is rendered inside each category page main content for correct layout. */ ?>
<?php endif; ?>
