<?php
/**
 * ep_header.php — Shared EasyPC header & nav for all CLIENT pages.
 *
 * Expected: $isLoggedIn (bool), $activePage (string), optional $searchQuery, $pageTitle, $bodyClass
 * Home-only: $isHomePage = true
 * Category pages: $categoryHeroTitle (string)
 */
$peripheralNavCategories = $peripheralNavCategories ?? [
    'CCTV'                 => 'CCTV',
    'Headset'              => 'Headset',
    'Keyboard'             => 'Keyboard',
    'Keyboard And Mouse'   => 'Keyboard And Mouse',
    'Display'              => 'Display',
    'Mouse'                => 'Mouse',
    'Network Device'       => 'Network Device',
    'Printer & Scanner'    => 'Printer and Scanner',
    'Projector'            => 'Projector',
    'Recorder'             => 'Recorder',
    'Speaker'              => 'Speaker',
    'UPS & AVR'            => 'UPS & AVR',
    'Web & Digital Camera' => 'Web & Digital Camera',
];

$searchQuery = $searchQuery ?? '';
$isHomePage  = ($activePage ?? '') === 'home' || !empty($isHomePage);
$bodyClass   = $bodyClass ?? '';

if (!isset($epCartPreview)) {
    require_once __DIR__ . '/../includes/client_helpers.php';
    $epCartPreview = ep_get_cart_preview($db ?? getDbConnection());
}
$epCartItems  = $epCartPreview['items'];
$epCartTotal  = $epCartPreview['total'];
$epCartCount  = $epCartPreview['count'];
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
    <link rel="stylesheet" href="styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body class="ep-body <?php echo h($bodyClass); ?>">

<header class="top-header ep-header full-width">
    <div class="logo ep-logo" onclick="location.href='index.php'">
        <img src="../assets/logo.png" alt="EasyPC" class="ep-logo-img">
    </div>
    <div class="search-wrap">
        <form action="search.php" method="GET">
            <input name="q" type="text" placeholder="Search products..."
                   value="<?php echo h($searchQuery); ?>">
            <button type="submit" class="search-icon"><i class="fas fa-search"></i></button>
        </form>
    </div>
    <div class="header-icons">
        <button id="notifBtn" class="icon-badge-btn" title="Notifications"
                onclick="document.getElementById('epNotifPanel').classList.toggle('hidden')">
            <i class="far fa-bell"></i>
        </button>

        <div class="ep-cart-wrap" id="epCartWrap">
            <button id="cartBtn" type="button" class="icon-badge-btn ep-cart-trigger" title="Cart"
                    aria-haspopup="true" aria-expanded="false" aria-controls="epCartDropdown">
                <i class="fas fa-shopping-bag"></i>
                <?php if ($epCartCount > 0): ?>
                    <span class="badge"><?php echo (int)$epCartCount; ?></span>
                <?php endif; ?>
            </button>
            <div id="epCartDropdown" class="ep-cart-dropdown" role="menu" aria-label="Cart preview">
                <?php if (!empty($epCartItems)): ?>
                    <ul class="ep-cart-dropdown-list">
                        <?php foreach ($epCartItems as $ci): ?>
                            <li>
                                <span class="ep-cart-item-name"><?php echo h($ci['name']); ?></span>
                                <span class="ep-cart-item-meta">×<?php echo (int)$ci['qty']; ?> · ₱<?php echo number_format($ci['subtotal'], 2); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="ep-cart-dropdown-total">
                        <span>Total</span>
                        <strong>₱<?php echo number_format($epCartTotal, 2); ?></strong>
                    </div>
                    <a href="checkout.php" class="ep-btn ep-btn-primary ep-cart-checkout-btn">Checkout</a>
                    <a href="cart.php" class="ep-cart-view-link">View full cart</a>
                <?php else: ?>
                    <p class="ep-cart-empty">Your cart is empty.</p>
                    <a href="products.php" class="ep-cart-view-link">Browse products</a>
                <?php endif; ?>
            </div>
        </div>

        <button id="profileBtn" class="icon-badge-btn profile-outline-btn ep-account-btn"
                onclick="location.href='<?php echo $isLoggedIn ? 'user_dashboard.php' : '../login.php'; ?>'">
            <i class="far fa-user"></i>
            <span class="ep-account-label">
                <?php if ($isLoggedIn): ?>My Account<?php else: ?>Login /<br>Sign In<?php endif; ?>
            </span>
        </button>
    </div>
    <div id="epNotifPanel" class="notifications-panel hidden">
        <strong>Notifications</strong>
        <ul>
            <li>Welcome to EasyPC!</li>
            <li>Track your orders from your dashboard.</li>
        </ul>
    </div>
</header>

<script>
(function () {
    function epSetHeaderOffset() {
        var header = document.querySelector('.ep-header');
        var nav = document.querySelector('.ep-nav-bar');
        var h = (header ? header.offsetHeight : 0) + (nav ? nav.offsetHeight : 0);
        document.body.style.paddingTop = h + 'px';
    }
    epSetHeaderOffset();
    window.addEventListener('resize', epSetHeaderOffset);

    var wrap = document.getElementById('epCartWrap');
    var trigger = document.getElementById('cartBtn');
    if (wrap && trigger) {
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = wrap.classList.toggle('open');
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        wrap.addEventListener('mouseenter', function () {
            wrap.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
        });
        wrap.addEventListener('mouseleave', function () {
            wrap.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#epCartWrap')) {
                wrap.classList.remove('open');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    }
})();
</script>

<nav class="ep-nav ep-nav-bar full-width">
    <a href="index.php" class="ep-nav-link<?php echo ($activePage ?? '') === 'home' ? ' active' : ''; ?>">HOME</a>
    <a href="category.php?type=Desktop" class="ep-nav-link<?php echo ($activePage ?? '') === 'desktop' ? ' active' : ''; ?>">DESKTOP</a>
    <a href="category.php?type=Laptops" class="ep-nav-link<?php echo ($activePage ?? '') === 'laptop' ? ' active' : ''; ?>">LAPTOP</a>

    <div class="ep-nav-dropdown">
        <button type="button"
                class="ep-nav-link ep-nav-dropdown-btn<?php echo ($activePage ?? '') === 'peripherals' ? ' active' : ''; ?>"
                onclick="epToggleDropdown(this)">
            PERIPHERALS <i class="fas fa-chevron-down"></i>
        </button>
        <div class="ep-dropdown-menu ep-dropdown-cols">
            <?php foreach ($peripheralNavCategories as $label => $value): ?>
                <a href="category.php?type=<?php echo urlencode($value); ?>"><?php echo h($label); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <a href="category.php?type=Brands" class="ep-nav-link<?php echo ($activePage ?? '') === 'brands' ? ' active' : ''; ?>">BRANDS</a>
</nav>

<?php if ($isHomePage): ?>
<section class="ep-hero full-width">
    <div class="ep-hero-content">
        <p class="ep-hero-kicker">TECH IT EASY AT</p>
        <h1 class="ep-hero-title">Easy PC<br>One Oasis Branch</h1>
        <p class="ep-hero-subtitle">Explore the latest PCs, laptops &amp; accessories from EasyPC.</p>
        <a href="products.php" class="ep-btn ep-btn-primary">Shop Now</a>
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
