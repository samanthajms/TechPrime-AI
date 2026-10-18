<?php
/**
 * ep_header.php — Shared EasyPC header & nav for all CLIENT pages.
 *
 * Expected: $isLoggedIn (bool), $activePage (string), optional $searchQuery, $pageTitle, $bodyClass
 * Home-only: $isHomePage = true
 * Category pages: $currentCategory (string)
 * Every page gets body class "sf-page" plus storefront.css / storefront-pages.css (the homepage design);
 * page headers use sf_page_hero() from includes/storefront_components.php.
 */
if (!function_exists('ias_inventory_allowed_categories')) {
    require_once __DIR__ . '/../includes/product_categories.php';
}
$currentCategory = $currentCategory ?? '';
$epWishCount = 0;

$searchQuery = $searchQuery ?? '';
$isHomePage  = ($activePage ?? '') === 'home' || !empty($isHomePage);
$bodyClass   = trim(($bodyClass ?? '') . ' sf-page');   // storefront v2 (storefront.css) on every customer page
require_once __DIR__ . '/../includes/storefront_components.php';

/* Primo chat: shown where shoppers browse and compare, kept off checkout/payment and
 * account pages. A page can override this by setting $epShowPrimo before the header. */
if (!isset($epShowPrimo)) {
    $epShowPrimo = in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), [
        'index.php', 'shop.php', 'category.php', 'search.php',
        'products.php', 'product_detail.php', 'build_a_pc.php', 'cart.php',
    ], true);
}

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
    <link rel="stylesheet" href="styles.css?v=account-6">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php if ($epShowPrimo): ?><link rel="stylesheet" href="primo.css?v=ux-13"><?php endif; ?>
    <?php if (!empty($extraHead)) echo $extraHead; ?>
    <?php /* Dark theme: generated overrides, then hand-written fixes. Both only match html[data-theme="dark"]. */ ?>
    <link rel="stylesheet" href="ep_dark_auto.css?v=dark-33">
    <link rel="stylesheet" href="ep_dark.css?v=dark-19">
    <?php /* Header v2: after the dark files so its token-driven rules win over the old header ones. */ ?>
    <link rel="stylesheet" href="header.css?v=hd-9">
    <?php /* Storefront v2 (the homepage design) for every page: components, then the page-level styles.
             Token-driven dark mode; loaded last so they win over styles.css and the generated dark rules. */ ?>
    <link rel="stylesheet" href="storefront.css?v=sf-5">
    <link rel="stylesheet" href="storefront-pages.css?v=sfp-7">
    <?php echo ias_session_timeout_assets(); ?>
</head>
<body class="ep-body <?php echo h($bodyClass); ?>">

<?php
$epActive   = (string)($activePage ?? '');
$epScript   = basename($_SERVER['SCRIPT_NAME'] ?? '');
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
$epOnSettings = $epActive === 'account' && isset($_GET['settings']);
// Product pages (category, search, product detail) belong to the Shop section.
$epInShop = $epActive === 'shop'
    || in_array($epScript, ['category.php', 'search.php', 'products.php', 'product_detail.php'], true);
$epPrimaryLinks = [
    ['href' => 'index.php', 'icon' => 'fa-home', 'label' => 'Home', 'active' => $epActive === 'home'],
    ['href' => 'shop.php', 'icon' => 'fa-store', 'label' => 'Shop', 'active' => $epInShop],
    ['href' => 'build_a_pc.php', 'icon' => 'fa-desktop', 'label' => 'Build a PC',
     'active' => $epActive === 'build_a_pc' || $epActive === 'saved_builds'],
];
$epAvatarHtml = $epAvatarUrl !== ''
    ? '<img src="' . h($epAvatarUrl) . '" alt="">'
    : h($epInitial);

/* Categories: the same client taxonomy as Shop and the homepage tiles (links only, no query). */
if (!function_exists('ep_shop_taxonomy')) {
    require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
}
$epTaxonomy = ep_shop_taxonomy();
$epSections = ep_shop_special_sections();
?>
<a class="hd-sr hd-skip" href="#main">Skip to main content</a>
<header class="ep-header hd full-width">
    <div class="hd-wrap hd-row">
        <button type="button" class="hd-menu-btn" id="epMenuBtn"
                aria-label="Open menu" aria-controls="epDrawer" aria-expanded="false">
            <i class="fas fa-bars" aria-hidden="true"></i>
        </button>

        <a href="index.php" class="hd-logo">
            <img src="../assets/logo.png" alt="EasyPC home" width="137" height="32">
        </a>

        <?php /* Categories + page links. Below 1100px these move into the drawer. */ ?>
        <nav class="hd-nav" aria-label="Primary">
            <div class="hd-pop hd-cats" id="epCatsWrap">
                <button type="button" class="hd-cats-btn" id="epCatsBtn" aria-expanded="false" aria-controls="epCatsPanel">
                    <i class="fas fa-th-large" aria-hidden="true"></i><span>All categories</span>
                    <i class="fas fa-chevron-down hd-caret" aria-hidden="true"></i>
                </button>
                <div class="hd-panel hd-cats-panel" id="epCatsPanel" role="region" aria-label="All categories">
                    <div class="hd-cats-grid">
                        <?php foreach ($epTaxonomy as $slug => $info): ?>
                            <?php if (empty($info['subs'])) continue; ?>
                            <div class="hd-cat-group">
                                <a class="hd-cat-head" href="<?php echo h(ep_shop_url(['cat' => $slug], [])); ?>">
                                    <i class="fas <?php echo h($info['icon']); ?>" aria-hidden="true"></i><?php echo h($info['label']); ?>
                                </a>
                                <ul class="hd-cat-list">
                                    <?php foreach ($info['subs'] as $subSlug => $subLabel): ?>
                                        <li><a href="<?php echo h(ep_shop_url(['cat' => $slug, 'sub' => $subSlug], [])); ?>"><?php echo h($subLabel); ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                        <div class="hd-cat-group">
                            <span class="hd-cat-head"><i class="fas fa-bolt" aria-hidden="true"></i>Shop by section</span>
                            <ul class="hd-cat-list">
                                <?php foreach ($epSections as $secSlug => $sec): ?>
                                    <li><a href="<?php echo h(ep_shop_url(['section' => $secSlug], [])); ?>"><?php echo h($sec['label']); ?></a></li>
                                <?php endforeach; ?>
                                <?php if (isset($epTaxonomy['others'])): ?>
                                    <li><a href="<?php echo h(ep_shop_url(['cat' => 'others'], [])); ?>"><?php echo h($epTaxonomy['others']['label']); ?></a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="hd-cats-foot">
                        <span>Not sure what fits? Build a PC checks parts for you.</span>
                        <a href="shop.php">View all products <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>

            <ul class="hd-links">
                <?php foreach ($epPrimaryLinks as $link): ?>
                    <?php if ($link['href'] === 'index.php') continue; /* the logo is the Home link */ ?>
                    <li>
                        <a href="<?php echo h($link['href']); ?>" class="hd-link"
                           <?php echo $link['active'] ? 'aria-current="page"' : ''; ?>><?php echo h($link['label']); ?></a>
                    </li>
                <?php endforeach; ?>
                <?php if ($epShowPrimo): /* Survey feedback: shoppers missed the corner launcher, so Primo also gets a nav entry. */ ?>
                    <li class="is-primo">
                        <button type="button" class="hd-link" data-primo-open aria-controls="primoPanel">
                            <i class="fas fa-robot" aria-hidden="true"></i>Ask Primo
                        </button>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="hd-search" id="epSearchWrap">
            <form action="search.php" method="GET" id="epSearchForm" role="search" autocomplete="off">
                <label for="epSearchInput" class="hd-sr">Search products</label>
                <input id="epSearchInput" name="q" type="search" enterkeyhint="search"
                       placeholder="Search laptops, parts and accessories"
                       data-placeholder-short="Search products"
                       value="<?php echo h($searchQuery); ?>"
                       aria-autocomplete="list" aria-controls="epSearchSuggest" aria-expanded="false"
                       autocomplete="off">
                <button type="submit" class="hd-search-btn" aria-label="Search"><i class="fas fa-search" aria-hidden="true"></i></button>
            </form>
            <div id="epSearchSuggest" class="ep-search-suggest" role="listbox" hidden></div>
        </div>

        <div class="hd-actions">
            <?php /* Bell opens the preview on click only. Full page via "Show all notifications". */ ?>
            <div class="ep-notif-wrap hd-pop" id="epNotifWrap">
                <button type="button" id="notifBtn" class="hd-act ep-notif-trigger<?php echo $epActive === 'notifications' ? ' is-current' : ''; ?>"
                        title="Notifications"
                        aria-expanded="false" aria-controls="epNotifPanel" aria-haspopup="true">
                    <span class="ep-nav-item-icon">
                        <i class="fas fa-bell" aria-hidden="true"></i>
                        <?php if ($epNotifUnread > 0): ?>
                            <span class="badge ep-notif-badge"><?php echo $epNotifUnread > 99 ? '99+' : (int)$epNotifUnread; ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="hd-sr">Notifications</span>
                </button>
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
                        <a href="notifications.php" class="ep-notif-dd-foot">
                            Show all notifications <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    <?php else: ?>
                        <ul class="ep-notif-list" id="epNotifList">
                            <?php foreach ($epNotifItems as $n) echo ep_notif_render_item($n, 'dropdown'); ?>
                        </ul>
                        <a href="notifications.php" class="ep-notif-dd-foot">
                            Show all notifications <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <a href="wishlist.php" class="hd-act is-wish" title="Wishlist"
               <?php echo $epActive === 'wishlist' ? 'aria-current="page"' : ''; ?>>
                <span class="ep-nav-item-icon">
                    <i class="fas fa-heart" aria-hidden="true"></i>
                    <?php if ($epWishCount > 0): ?><span class="badge"><?php echo (int)$epWishCount; ?></span><?php endif; ?>
                </span>
                <span class="hd-sr">Wishlist<?php echo $epWishCount > 0 ? ', ' . (int)$epWishCount . ' saved' : ''; ?></span>
            </a>

            <?php /* Bag opens the cart preview on click only. Full page via "View Cart". */ ?>
            <div class="ep-cart-wrap hd-pop" id="epCartWrap">
                <button type="button" id="cartBtn" class="hd-act ep-cart-trigger<?php echo $epActive === 'cart' ? ' is-current' : ''; ?>" title="Cart"
                        aria-expanded="false" aria-controls="epCartDropdown" aria-haspopup="true">
                    <span class="ep-nav-item-icon">
                        <i class="fas fa-shopping-bag" aria-hidden="true"></i>
                        <?php if ($epCartCount > 0): ?>
                            <span class="badge"><?php echo (int)$epCartCount; ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="hd-sr">Cart</span>
                </button>
                <div id="epCartDropdown" class="ep-cart-dropdown" role="region" aria-label="Cart preview"></div>
                <span class="hd-sr" id="epCartLive" aria-live="polite" aria-atomic="true"></span>
            </div>

            <?php /* Account menu (desktop). Below 1100px the drawer carries these links. */ ?>
            <div class="hd-pop hd-acct-wrap" id="epAcctWrap">
                <button type="button" id="profileBtn" class="hd-acct<?php echo empty($isLoggedIn) ? ' is-guest' : ''; ?>"
                        aria-label="<?php echo !empty($isLoggedIn) ? 'Account menu for ' . h($epFirstName) : 'Account: log in or register'; ?>"
                        aria-expanded="false" aria-controls="epAcctMenu" aria-haspopup="true">
                    <?php if (!empty($isLoggedIn)): ?>
                        <span class="hd-avatar" aria-hidden="true"><?php echo $epAvatarHtml; ?></span>
                        <span class="hd-acct-name"><?php echo h($epFirstName); ?></span>
                    <?php else: ?>
                        <i class="far fa-user hd-acct-ico" aria-hidden="true"></i>
                        <span class="hd-acct-name">Log in</span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down hd-acct-caret" aria-hidden="true"></i>
                </button>
                <div class="hd-panel hd-acct-menu" id="epAcctMenu">
                    <?php if (!empty($isLoggedIn)): ?>
                        <div class="hd-acct-head">
                            <span class="hd-avatar" aria-hidden="true"><?php echo $epAvatarHtml; ?></span>
                            <span class="hd-acct-head-text">
                                <strong><?php echo h($epUserName !== '' ? $epUserName : 'Customer'); ?></strong>
                                <span>EasyPC One Oasis account</span>
                            </span>
                        </div>
                        <a href="user_dashboard.php" class="hd-menu-link"<?php echo $epActive === 'account' && !$epOnSettings ? ' aria-current="page"' : ''; ?>>
                            <i class="fas fa-box" aria-hidden="true"></i>My orders &amp; profile</a>
                        <a href="saved_builds.php" class="hd-menu-link"<?php echo $epActive === 'saved_builds' ? ' aria-current="page"' : ''; ?>>
                            <i class="fas fa-folder-open" aria-hidden="true"></i>Saved builds</a>
                        <a href="wishlist.php" class="hd-menu-link"<?php echo $epActive === 'wishlist' ? ' aria-current="page"' : ''; ?>>
                            <i class="fas fa-heart" aria-hidden="true"></i>Wishlist
                            <?php if ($epWishCount > 0): ?><span class="hd-menu-count"><?php echo (int)$epWishCount; ?></span><?php endif; ?></a>
                        <a href="notifications.php" class="hd-menu-link"<?php echo $epActive === 'notifications' ? ' aria-current="page"' : ''; ?>>
                            <i class="fas fa-bell" aria-hidden="true"></i>Notifications</a>
                        <a href="user_dashboard.php?settings=1" class="hd-menu-link"<?php echo $epOnSettings ? ' aria-current="page"' : ''; ?>>
                            <i class="fas fa-user-cog" aria-hidden="true"></i>Profile settings</a>
                    <?php else: ?>
                        <div class="hd-acct-head is-guest">
                            <span class="hd-acct-head-text">
                                <strong>Welcome to EasyPC</strong>
                                <span>Log in to track orders and save builds.</span>
                            </span>
                        </div>
                        <div class="hd-acct-auth">
                            <a href="../login.php" class="hd-btn is-primary">Log in</a>
                            <a href="../register.php" class="hd-btn">Register</a>
                        </div>
                        <a href="wishlist.php" class="hd-menu-link"><i class="fas fa-heart" aria-hidden="true"></i>Wishlist
                            <?php if ($epWishCount > 0): ?><span class="hd-menu-count"><?php echo (int)$epWishCount; ?></span><?php endif; ?></a>
                    <?php endif; ?>
                    <?php /* Light/dark theme switch; CSS shows the icon + label for the mode it switches to. */ ?>
                    <button type="button" class="hd-menu-link" id="epThemeToggle" data-theme-toggle>
                        <i class="fas fa-moon hd-when-light" aria-hidden="true"></i>
                        <i class="fas fa-sun hd-when-dark" aria-hidden="true"></i>
                        <span class="hd-when-light">Dark mode</span><span class="hd-when-dark">Light mode</span>
                    </button>
                    <?php if (!empty($isLoggedIn)): ?>
                        <div class="hd-menu-sep" role="presentation"></div>
                        <a href="../logout.php" class="hd-menu-link is-danger"><i class="fas fa-sign-out-alt" aria-hidden="true"></i>Log out</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</header>

<?php
/* ---- Navigation drawer (≤ 1100px; opened by #epMenuBtn) ---- */
$epDrawerLinks = [
    ['href' => 'index.php', 'icon' => 'fa-home', 'label' => 'Home', 'active' => $epActive === 'home'],
    ['href' => 'shop.php', 'icon' => 'fa-store', 'label' => 'Shop', 'active' => $epInShop],
    ['categories' => true],
    ['href' => 'build_a_pc.php', 'icon' => 'fa-desktop', 'label' => 'Build a PC', 'active' => $epActive === 'build_a_pc'],
];
if (!empty($isLoggedIn)) {
    $epDrawerLinks[] = ['href' => 'saved_builds.php', 'icon' => 'fa-folder-open', 'label' => 'Saved builds', 'active' => $epActive === 'saved_builds'];
}
$epDrawerLinks[] = ['href' => 'wishlist.php', 'icon' => 'fa-heart', 'label' => 'Wishlist', 'active' => $epActive === 'wishlist', 'count' => $epWishCount];
$epDrawerLinks[] = ['href' => 'cart.php', 'icon' => 'fa-shopping-bag', 'label' => 'Cart', 'active' => $epActive === 'cart', 'count' => $epCartCount, 'id' => 'epDrawerCartCount'];
$epDrawerCatOpen = $epActive === 'shop' && !empty($_GET['cat']);
?>
<div class="hd-backdrop" id="epDrawerBackdrop" hidden></div>
<aside class="hd-drawer" id="epDrawer" role="dialog" aria-modal="true" aria-label="Main menu" aria-hidden="true">
    <div class="hd-drawer-head">
        <img src="../assets/logo.png" alt="EasyPC" width="119" height="28">
        <button type="button" class="hd-drawer-close" id="epDrawerClose" aria-label="Close menu">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>

    <div class="hd-drawer-account">
        <?php if (!empty($isLoggedIn)): ?>
            <span class="hd-avatar" aria-hidden="true"><?php echo $epAvatarHtml; ?></span>
            <div class="hd-drawer-account-text">
                <strong>Hi, <?php echo h($epUserName !== '' ? $epUserName : 'Customer'); ?>!</strong>
                <a href="user_dashboard.php">View my profile</a>
            </div>
        <?php else: ?>
            <div class="hd-drawer-account-text">
                <strong>Welcome to EasyPC</strong>
                <span>Log in to track orders and save builds.</span>
            </div>
            <div class="hd-drawer-auth">
                <a href="../login.php" class="hd-btn is-primary">Log in</a>
                <a href="../register.php" class="hd-btn">Register</a>
            </div>
        <?php endif; ?>
    </div>

    <nav class="hd-drawer-nav" aria-label="Main menu">
        <?php foreach ($epDrawerLinks as $link): ?>
            <?php if (!empty($link['categories'])): ?>
                <details class="hd-drawer-group"<?php echo $epDrawerCatOpen ? ' open' : ''; ?>>
                    <summary class="hd-drawer-link">
                        <i class="fas fa-th-large" aria-hidden="true"></i>
                        <span>Categories</span>
                        <i class="fas fa-chevron-down hd-drawer-caret" aria-hidden="true"></i>
                    </summary>
                    <div class="hd-drawer-sub">
                        <?php foreach ($epTaxonomy as $slug => $info): ?>
                            <a href="<?php echo h(ep_shop_url(['cat' => $slug], [])); ?>"
                               <?php echo ($epActive === 'shop' && ($_GET['cat'] ?? '') === $slug) ? 'aria-current="page"' : ''; ?>><?php echo h($info['label']); ?></a>
                        <?php endforeach; ?>
                        <a href="shop.php" class="is-all">View all products</a>
                    </div>
                </details>
            <?php else: ?>
                <a href="<?php echo h($link['href']); ?>" class="hd-drawer-link"
                   <?php echo $link['active'] ? 'aria-current="page"' : ''; ?>>
                    <i class="fas <?php echo h($link['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo h($link['label']); ?></span>
                    <?php if (array_key_exists('count', $link)): ?>
                        <span class="hd-drawer-count"<?php echo isset($link['id']) ? ' id="' . h($link['id']) . '"' : ''; ?><?php echo (int)$link['count'] > 0 ? '' : ' hidden'; ?>><?php echo (int)$link['count']; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($epShowPrimo): ?>
            <button type="button" class="hd-drawer-link" data-primo-open aria-controls="primoPanel">
                <i class="fas fa-robot" aria-hidden="true"></i><span>Ask Primo (AI assistant)</span>
            </button>
        <?php endif; ?>

        <?php if (!empty($isLoggedIn)): ?>
            <div class="hd-drawer-label">My account</div>
            <a href="user_dashboard.php" class="hd-drawer-link"<?php echo $epActive === 'account' && !$epOnSettings ? ' aria-current="page"' : ''; ?>>
                <i class="fas fa-box" aria-hidden="true"></i><span>My orders &amp; profile</span>
            </a>
            <a href="notifications.php" class="hd-drawer-link"<?php echo $epActive === 'notifications' ? ' aria-current="page"' : ''; ?>>
                <i class="fas fa-bell" aria-hidden="true"></i><span>Notifications</span>
            </a>
            <a href="user_dashboard.php?settings=1" class="hd-drawer-link"<?php echo $epOnSettings ? ' aria-current="page"' : ''; ?>>
                <i class="fas fa-user-cog" aria-hidden="true"></i><span>Profile settings</span>
            </a>
        <?php endif; ?>

        <div class="hd-drawer-label">Store</div>
        <a href="tel:+639176255078" class="hd-drawer-link"><i class="fas fa-phone-alt" aria-hidden="true"></i><span>Call 0917 625 5078</span></a>
        <button type="button" class="hd-drawer-link" data-theme-toggle>
            <i class="fas fa-moon hd-when-light" aria-hidden="true"></i>
            <i class="fas fa-sun hd-when-dark" aria-hidden="true"></i>
            <span><span class="hd-when-light">Switch to dark mode</span><span class="hd-when-dark">Switch to light mode</span></span>
        </button>
    </nav>

    <?php if (!empty($isLoggedIn)): ?>
        <div class="hd-drawer-foot">
            <a href="../logout.php" class="hd-drawer-link is-danger">
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
    // The header height settles once Poppins has loaded; re-measure so content never sits under it.
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(epSetHeaderOffset);

    /* Skip link target: pages without <main id="main"> get one. */
    (function () {
        if (document.getElementById('main')) return;
        document.addEventListener('DOMContentLoaded', function () {
            var m = document.querySelector('main');
            if (m && !m.id) m.id = 'main';
        });
    })();

    /* Deeper shadow once content scrolls under the fixed header. */
    (function () {
        var hdr = document.querySelector('.ep-header');
        if (!hdr) return;
        var ticking = false;
        var sync = function () { ticking = false; hdr.classList.toggle('is-scrolled', window.scrollY > 4); };
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; requestAnimationFrame(sync); }
        }, { passive: true });
        sync();
    })();

    /* Phones get a shorter search placeholder so it is not cut off. */
    (function () {
        var input = document.getElementById('epSearchInput');
        if (!input || !input.dataset.placeholderShort) return;
        var full = input.getAttribute('placeholder');
        var mq = window.matchMedia('(max-width: 640px)');
        var apply = function () { input.setAttribute('placeholder', mq.matches ? input.dataset.placeholderShort : full); };
        apply();
        if (typeof mq.addEventListener === 'function') mq.addEventListener('change', apply);
        else if (typeof mq.addListener === 'function') mq.addListener(apply);
    })();

    /* ---- Light / dark theme (saved per browser; applied early in <head>) ----
       Header row and drawer each have a switch ([data-theme-toggle]). */
    var applyTheme = function (theme) {
        document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
    };
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem('ep_theme', next); } catch (e) {}
        });
    });
    // Keep other open tabs of the store in sync.
    window.addEventListener('storage', function (e) {
        if (e.key === 'ep_theme') applyTheme(e.newValue);
    });

    /* ---- Mobile navigation drawer ---- */
    var menuBtn = document.getElementById('epMenuBtn');
    var drawer = document.getElementById('epDrawer');
    var drawerBackdrop = document.getElementById('epDrawerBackdrop');
    var drawerClose = document.getElementById('epDrawerClose');
    var drawerMq = window.matchMedia('(max-width: 1100px)');
    if (menuBtn && drawer) {
        var drawerFocusable = function () {
            return Array.prototype.filter.call(
                drawer.querySelectorAll('a[href], button:not([disabled]), summary'),
                function (el) { return el.offsetParent !== null; }
            );
        };
        var backdropTimer = null;
        var openDrawer = function () {
            if (!drawerMq.matches) return;
            // Close header popups so they don't sit on top of the drawer.
            if (typeof window.epCloseHeaderPanels === 'function') window.epCloseHeaderPanels();

            drawer.classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
            if (drawerBackdrop) {
                clearTimeout(backdropTimer);
                drawerBackdrop.classList.remove('is-closing');
                drawerBackdrop.hidden = false;
            }
            document.documentElement.classList.add('ep-drawer-open');
            menuBtn.setAttribute('aria-expanded', 'true');
            if (drawerClose) drawerClose.focus({ preventScroll: true });
        };
        var closeDrawer = function (returnFocus) {
            if (!drawer.classList.contains('open')) return;
            drawer.classList.remove('open');
            drawer.setAttribute('aria-hidden', 'true');
            if (drawerBackdrop) {
                // Fade the backdrop out alongside the drawer instead of removing it at once.
                drawerBackdrop.classList.add('is-closing');
                clearTimeout(backdropTimer);
                backdropTimer = setTimeout(function () {
                    drawerBackdrop.hidden = true;
                    drawerBackdrop.classList.remove('is-closing');
                }, 220);
            }
            document.documentElement.classList.remove('ep-drawer-open');
            menuBtn.setAttribute('aria-expanded', 'false');
            if (returnFocus) menuBtn.focus({ preventScroll: true });
        };

        menuBtn.addEventListener('click', openDrawer);
        if (drawerClose) drawerClose.addEventListener('click', function () { closeDrawer(true); });
        if (drawerBackdrop) drawerBackdrop.addEventListener('click', function () { closeDrawer(true); });
        drawer.addEventListener('click', function (e) {
            if (e.target.closest('a[href], [data-primo-open]')) closeDrawer(false);
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

    /* Header popups — notifications, cart, account menu, all categories: open on click only
       (no hover), one at a time. Outside click / Escape closes; Escape returns focus to the button.
       Full pages are reached via "Show all notifications" / "View Cart" inside the panels. */
    (function () {
        var panels = [
            { wrap: document.getElementById('epNotifWrap'), trigger: document.getElementById('notifBtn') },
            { wrap: document.getElementById('epCartWrap'), trigger: document.getElementById('cartBtn') },
            { wrap: document.getElementById('epAcctWrap'), trigger: document.getElementById('profileBtn') },
            { wrap: document.getElementById('epCatsWrap'), trigger: document.getElementById('epCatsBtn') }
        ].filter(function (p) { return p.wrap && p.trigger; });
        window.epCloseHeaderPanels = function () { closeAll(null); };

        function setOpen(panel, open) {
            panel.wrap.classList.toggle('is-open', open);
            panel.trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function closeAll(except) {
            panels.forEach(function (p) {
                if (except && p.wrap === except) return;
                setOpen(p, false);
            });
        }

        panels.forEach(function (panel) {
            panel.trigger.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var willOpen = !panel.wrap.classList.contains('is-open');
                closeAll(panel.wrap);
                setOpen(panel, willOpen);
            });
        });

        document.addEventListener('click', function (e) {
            panels.forEach(function (panel) {
                if (!panel.wrap.classList.contains('is-open')) return;
                if (panel.wrap.contains(e.target)) return;
                setOpen(panel, false);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            panels.forEach(function (panel) {
                if (!panel.wrap.classList.contains('is-open')) return;
                setOpen(panel, false);
                panel.trigger.focus();
            });
        });
    })();

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
        // Tell screen readers when the count changes after the page loaded (not on first render).
        var liveCount = parseInt(preview.count, 10) || 0;
        var live = document.getElementById('epCartLive');
        if (live && typeof window.epCartLastCount === 'number' && window.epCartLastCount !== liveCount) {
            live.textContent = 'Cart updated: ' + liveCount + (liveCount === 1 ? ' item' : ' items');
        }
        window.epCartLastCount = liveCount;
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
                '<a href="cart.php" class="ep-cart-dd-btn">View Cart</a>' +
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
            if (bell) bell.className = 'fas fa-bell';   // one solid icon set in the header; the badge shows unread
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
                list.outerHTML = '<div class="ep-notif-empty">' +
                    '<span class="ep-notif-empty-icon"><i class="far fa-bell" aria-hidden="true"></i></span>' +
                    '<strong>You&rsquo;re all caught up</strong>' +
                    '<span>Order and payment updates will show up here.</span></div>';
                /* Keep the existing "Show all notifications" footer link. */
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

<?php /* The homepage hero lives in index.php; other pages render sf_page_hero() inside their <main>. */ ?>
