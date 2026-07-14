<?php
/**
 * ep_header.php — Shared EasyPC header & nav for all CLIENT pages.
 * Variables expected from the including file:
 *   $isLoggedIn  (bool)
 *   $activePage  (string) — 'home'|'desktop'|'laptop'|'peripherals'|'brands'|'products'
 *
 * Optional:
 *   $searchQuery (string) — pre-fills the search box
 */
$peripheralCategories = $peripheralCategories ?? ['Mobile', 'Cameras', 'Accessories'];
$searchQuery          = $searchQuery ?? '';
$isHomePage           = ($activePage ?? '') === 'home';
$bodyClass            = $bodyClass ?? '';

// Categories shown in the PERIPHERALS nav dropdown (label => actual category value).
// Left column fills first (7 items), then the right column (6 items).
$peripheralNavCategories = [
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($pageTitle)): ?>
        <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> | EasyPC</title>
    <?php else: ?>
        <title>EasyPC</title>
    <?php endif; ?>
    <link rel="stylesheet" href="../styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body class="ep-body <?php echo htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8'); ?>">

<header class="top-header ep-header full-width">
    <div class="logo ep-logo" onclick="location.href='index.php'" style="cursor:pointer;">
        <img src="../assets/easypc-logo-transparent.png" alt="EasyPC" class="ep-logo-img">
    </div>
    <div class="search-wrap">
        <form action="search.php" method="GET">
            <input name="q" type="text" placeholder="Search products..."
                   value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="search-icon"><i class="fas fa-search"></i></button>
        </form>
    </div>
    <div class="header-icons">
        <button class="icon-badge-btn" title="Wishlist" onclick="this.classList.toggle('active')">
            <i class="far fa-heart"></i>
        </button>
        <button id="notifBtn" class="icon-badge-btn" title="Notifications"
                onclick="document.getElementById('epNotifPanel').classList.toggle('hidden')">
            <i class="far fa-bell"></i>
        </button>
        <button id="cartBtn" class="icon-badge-btn" title="Cart" onclick="location.href='cart.php'">
            <i class="fas fa-shopping-bag"></i>
            <?php if (!empty($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
                <span class="badge"><?php echo count($_SESSION['cart']); ?></span>
            <?php endif; ?>
        </button>
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
    // Keep page content clear of the fixed header at all viewport sizes.
    (function () {
        function epSetHeaderOffset() {
            var header = document.querySelector('.ep-header');
            if (header) document.body.style.paddingTop = header.offsetHeight + 'px';
        }
        epSetHeaderOffset();
        window.addEventListener('resize', epSetHeaderOffset);
    })();
</script>

<section class="<?php echo $isHomePage ? 'ep-hero' : 'ep-nav-bar'; ?> full-width">
    <?php if ($isHomePage): ?>
    <p class="ep-hero-kicker">SHOP NOW AT</p>
    <h1 class="ep-hero-title">EASYPC ONE OASIS.</h1>
    <?php elseif (!empty($categoryHeroTitle)): ?>
    <div class="ep-category-hero">
        <h1><?php echo htmlspecialchars($categoryHeroTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>
    <?php endif; ?>

    <nav class="ep-nav">
        <a href="index.php"
           class="ep-nav-link<?php echo ($activePage ?? '') === 'home' ? ' active' : ''; ?>">HOME</a>
        <a href="category.php?type=Desktop"
           class="ep-nav-link<?php echo ($activePage ?? '') === 'desktop' ? ' active' : ''; ?>">DESKTOP</a>
        <a href="category.php?type=Laptops"
           class="ep-nav-link<?php echo ($activePage ?? '') === 'laptop' ? ' active' : ''; ?>">LAPTOP</a>

        <div class="ep-nav-dropdown">
            <button type="button"
                    class="ep-nav-link ep-nav-dropdown-btn<?php echo ($activePage ?? '') === 'peripherals' ? ' active' : ''; ?>"
                    onclick="epToggleDropdown(this)">
                PERIPHERALS <i class="fas fa-chevron-down"></i>
            </button>
            <div class="ep-dropdown-menu ep-dropdown-cols">
                <?php foreach ($peripheralNavCategories as $label => $value): ?>
                    <a href="category.php?type=<?php echo urlencode($value); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <a href="category.php?type=Brands"
           class="ep-nav-link<?php echo ($activePage ?? '') === 'brands' ? ' active' : ''; ?>">BRANDS</a>
    </nav>
</section>
