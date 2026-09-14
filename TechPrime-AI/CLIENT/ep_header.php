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
                   value="<?php echo h($searchQuery); ?>" aria-label="Search products">
            <button type="submit" class="search-icon" aria-label="Search"><i class="fas fa-search"></i></button>
        </form>
    </div>

    <nav class="ep-nav-actions" aria-label="Primary">
        <a href="index.php"
           class="ep-nav-item<?php echo ($activePage ?? '') === 'home' ? ' active' : ''; ?>"
           <?php echo ($activePage ?? '') === 'home' ? 'aria-current="page"' : ''; ?>>
            <span class="ep-nav-item-icon"><i class="fas fa-home" aria-hidden="true"></i></span>
            <span class="ep-nav-item-label">Home</span>
        </a>

        <a href="shop.php"
           class="ep-nav-item<?php echo ($activePage ?? '') === 'shop' ? ' active' : ''; ?>"
           <?php echo ($activePage ?? '') === 'shop' ? 'aria-current="page"' : ''; ?>>
            <span class="ep-nav-item-icon"><i class="fas fa-store" aria-hidden="true"></i></span>
            <span class="ep-nav-item-label">Shop Now</span>
        </a>

        <div class="ep-cart-wrap" id="epCartWrap">
            <button id="cartBtn" type="button" class="ep-nav-item ep-cart-trigger"
                    aria-haspopup="true" aria-expanded="false" aria-controls="epCartDropdown">
                <span class="ep-nav-item-icon">
                    <i class="fas fa-shopping-bag" aria-hidden="true"></i>
                    <?php if ($epCartCount > 0): ?>
                        <span class="badge"><?php echo (int)$epCartCount; ?></span>
                    <?php endif; ?>
                </span>
                <span class="ep-nav-item-label">Cart</span>
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
                    <a href="shop.php" class="ep-cart-view-link">Browse products</a>
                <?php endif; ?>
            </div>
        </div>

        <button id="notifBtn" type="button" class="ep-nav-item"
                onclick="document.getElementById('epNotifPanel').classList.toggle('hidden')">
            <span class="ep-nav-item-icon"><i class="far fa-bell" aria-hidden="true"></i></span>
            <span class="ep-nav-item-label">Notifications</span>
        </button>

        <button id="profileBtn" type="button" class="ep-nav-item"
                onclick="location.href='<?php echo $isLoggedIn ? 'user_dashboard.php' : '../login.php'; ?>'">
            <span class="ep-nav-item-icon"><i class="far fa-user" aria-hidden="true"></i></span>
            <span class="ep-nav-item-label">My Profile</span>
        </button>
    </nav>

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
        var h = header ? header.offsetHeight : 0;
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
