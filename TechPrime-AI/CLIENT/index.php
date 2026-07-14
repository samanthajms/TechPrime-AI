<?php
session_start();
// Correct paths to reach includes/backend from the CLIENT folder
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? h($_SESSION['name']) : "Guest";

// Categories list matching your buttons
$categories = ['Accessories', 'Audio', 'Cables and Adapters', 'Camera', 'Combo', 'Cooling', 'Customization', 'Display', 'Gaming Surface', 'Graphic Card', 'Hard Disk', 'Home & Office Furniture',
'Keyboard', 'Laptop GA2', 'Laptop GA3', 'Laptop PR2', 'Laptop PR3', 'Memory', 'Mini PC', 'Motherboard', 'Mouse', 'Network Device', 'PC Case', 'Power Station', 'Power Supply', 'Printer and Scanner', 
'Processor', 'Promotional', 'Recorder', 'Services', 'Software', 'Solid State Drive', 'Speaker', 'UPS & AVR', 'Value Plus'];
$deviceCategories     = ['Laptops', 'Desktop', 'Display'];
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];

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

// Icon badges per category tile (decorative)
$categoryIcons = [
    'Laptops'     => 'fa-laptop',
    'Desktop'     => 'fa-desktop',
    'Mobile'      => 'fa-mobile-alt',
    'Cameras'     => 'fa-camera',
    'Accessories' => 'fa-headphones',
];

// Fetch products from Database (joining users to get the Seller's name)
$productQuery = "SELECT p.*, u.name AS seller_name
                 FROM products p
                 INNER JOIN users u ON p.seller_id = u.id
                 WHERE " . ias_client_product_list_sql_condition('p') . "
                 ORDER BY p.id DESC
                 LIMIT 40";
$productResult = $db->query($productQuery);
$allDisplayProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : [],
    12
);

// Split into two sections so the page doesn't just repeat the same row twice
$topSellers      = array_slice($allDisplayProducts, 0, 8);
$recommendations = array_slice($allDisplayProducts, 0, 6);
if (empty($recommendations)) {
    $recommendations = $topSellers;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EASYPC</title>
    <link rel="stylesheet" href="../styles.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</head>
<body class="ep-body">

    <header class="top-header ep-header full-width">
        <div class="logo ep-logo" onclick="location.href='index.php'">
            <img src="../assets/easypc-logo-transparent.png" alt="EasyPC" class="ep-logo-img">
        </div>
        <div class="search-wrap">
            <form action="search.php" method="GET">
                <input name="q" type="text" placeholder="Search products...">
                <button type="submit" class="search-icon"><i class="fas fa-search"></i></button>
            </form>
        </div>
        <div class="header-icons">
            <button class="icon-badge-btn" title="Wishlist" onclick="this.classList.toggle('active')">
                <i class="far fa-heart"></i>
            </button>
            <button id="notifBtn" class="icon-badge-btn" title="Notifications" onclick="document.getElementById('notificationsPanel').classList.toggle('hidden')">
                <i class="far fa-bell"></i>
            </button>
            <button id="cartBtn" class="icon-badge-btn" title="Cart" onclick="location.href='cart.php'">
                <i class="fas fa-shopping-bag"></i>
                <?php if(isset($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
                    <span class="badge"><?php echo count($_SESSION['cart']); ?></span>
                <?php endif; ?>
            </button>
            <button id="profileBtn" class="icon-badge-btn profile-outline-btn ep-account-btn"
                onclick="location.href='<?php echo isset($_SESSION['user_id']) ? 'user_dashboard.php' : '../login.php'; ?>'">
                <i class="far fa-user"></i>
                <span class="ep-account-label">
                    <?php if ($isLoggedIn): ?>My Account<?php else: ?>Login /<br>Sign In<?php endif; ?>
                </span>
            </button>
        </div>
        <div id="notificationsPanel" class="notifications-panel hidden">
            <strong>Notifications</strong>
            <ul>
                <li>Welcome to EasyPC, <?php echo $userName; ?>!</li>
                <li>Track your orders anytime from your dashboard.</li>
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

    <section class="ep-hero full-width">
        <p class="ep-hero-kicker">SHOP NOW AT</p>
        <h1 class="ep-hero-title">EASYPC ONE OASIS.</h1>

        <nav class="ep-nav">
            <a href="index.php" class="ep-nav-link active">HOME</a>
            <a href="category.php?type=Desktop" class="ep-nav-link">DESKTOP</a>
            <a href="category.php?type=Laptops" class="ep-nav-link">LAPTOP</a>

            <div class="ep-nav-dropdown">
                <button type="button" class="ep-nav-link ep-nav-dropdown-btn" onclick="epToggleDropdown(this)">PERIPHERALS <i class="fas fa-chevron-down"></i></button>
                <div class="ep-dropdown-menu ep-dropdown-cols">
                    <?php foreach ($peripheralNavCategories as $label => $value): ?>
                        <a href="category.php?type=<?php echo urlencode($value); ?>"><?php echo h($label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <a href="category.php?type=Brands" class="ep-nav-link">BRANDS</a>
        </nav>
    </section>

    <main class="ep-main">

        <section class="ep-section">
            <div class="ep-section-head">
                <h3>Top Sellers</h3>
                <a href="products.php" class="ep-see-more">See more</a>
            </div>

            <div class="ep-carousel-wrap">
                <button class="ep-arrow ep-arrow-left" onclick="epScroll('topSellersRow', -1)" aria-label="Scroll left"><i class="fas fa-arrow-left"></i></button>

                <div class="ep-carousel" id="topSellersRow">
                    <?php if (!empty($topSellers)): ?>
                        <?php foreach ($topSellers as $p): ?>
                            <div class="ep-product-card">
                                <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="ep-product-img" alt="<?php echo h($p['name']); ?>">
                                <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                                <div class="ep-product-cat"><?php echo h($p['category'] ?: 'Uncategorized'); ?></div>
                                <div class="ep-product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                                <div class="ep-card-actions">
                                    <button type="button" class="ep-heart-btn" onclick="this.classList.toggle('active')" aria-label="Save"><i class="far fa-heart"></i></button>
                                    <form action="products.php" method="POST" class="ep-buy-form">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                        <input type="hidden" name="add_to_cart" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <button  type="submit" class="ep-cart-icon"><i class="fas fa-shopping-cart"></i></button>
                                        <button class="ep-buy-btn">BUY NOW</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No products available yet. Check back later!</div>
                    <?php endif; ?>
                </div>

                <button class="ep-arrow ep-arrow-right" onclick="epScroll('topSellersRow', 1)" aria-label="Scroll right"><i class="fas fa-arrow-right"></i></button>
            </div>
        </section>

        <div class="ep-section-head">
                <h3>Categories</h3>
        </div>
        <section class="ep-categories-grid" id="epCategoriesGrid">
            <?php foreach ($categories as $cat): ?>
                <a class="ep-cat-tile" href="category.php?type=<?php echo urlencode($cat); ?>">
                    <span class="ep-cat-badge"><i class="fas <?php echo h($categoryIcons[$cat] ?? 'fa-wrench'); ?>"></i></span>
                    <span class="ep-cat-label"><?php echo strtoupper(h($cat)); ?></span>
                </a>
            <?php endforeach; ?>

        </section>
        <nav class="ep-pagination" id="epCategoriesPagination" aria-label="Categories pagination"></nav>

        <section class="ep-tech-match">
            <h2 class="ep-match-title">Tech and Match</h2>
            <p class="ep-match-subtitle">Find compatible devices for your setup.</p>

            <div class="ep-match-panel">
                <div class="ep-match-grid">
                    <div class="ep-match-left">
                        <h4>Find a match for...</h4>
                        <label class="ep-radio">
                            <input type="radio" name="epMatchType" value="device" checked onchange="epUpdateMatch()"> Device
                        </label>
                        <label class="ep-radio">
                            <input type="radio" name="epMatchType" value="peripherals" onchange="epUpdateMatch()"> Peripherals
                        </label>
                    </div>
                    <div class="ep-match-right">
                        <h4>Device/Peripherals</h4>
                        <div class="ep-display-box">
                               
                            <ul id="epMatchList" class="ep-display-list">
                                <?php foreach ($deviceCategories as $dc): ?>
                                    <li>- <a href="category.php?type=<?php echo urlencode($dc); ?>"><?php echo h($dc); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="ep-recommendations">
                    <h4>Recommendations</h4>
                    <div class="ep-rec-wrap">
                        <button class="ep-arrow ep-arrow-left" onclick="epScroll('epRecRow', -1)" aria-label="Scroll left"><i class="fas fa-arrow-left"></i></button>
                        <div class="ep-rec-box">
                            <div class="ep-carousel ep-rec-carousel" id="epRecRow">
                                <?php if (!empty($recommendations)): ?>
                                    <?php foreach ($recommendations as $p): ?>
                                        <div class="ep-product-card ep-rec-card">
                                            <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="ep-product-img" alt="<?php echo h($p['name']); ?>">
                                            <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                                            <div class="ep-product-cat"><?php echo h($p['category'] ?: 'Uncategorized'); ?></div>
                                            <div class="ep-product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                                            <div class="ep-card-actions">
                                                <button type="button" class="ep-heart-btn" onclick="this.classList.toggle('active')" aria-label="Save"><i class="far fa-heart"></i></button>
                                                <button type="button" class="ep-view-btn" onclick="location.href='products.php?id=<?php echo (int)$p['id']; ?>'">VIEW PRODUCT</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">No recommendations yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="ep-arrow ep-arrow-right" onclick="epScroll('epRecRow', 1)" aria-label="Scroll right"><i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <button id="messagesFloatBtn" class="messages-float-btn ep-chat-float" onclick="location.href='messages.php'">
        <i class="fas fa-comment-dots"></i>
    </button>

    <footer class="ep-footer full-width">
        <div class="ep-footer-grid">
            <div class="ep-footer-brand">
                <div class="ep-footer-logo"><img src="../assets/easypc-logo-transparent.png" alt="EasyPC" class="ep-footer-logo-img"></div>
                <div class="ep-social-row">
                    <a href="#" aria-label="X"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="ep-footer-col">
                <h5>Shop</h5>
                <a href="category.php?type=Desktop">Desktop</a>
                <a href="category.php?type=Laptops">Laptop</a>
                <a href="category.php?type=Accessories">Accessories</a>
                <a href="products.php">All Products</a>
            </div>
            <div class="ep-footer-col">
                <h5>Explore</h5>
                <a href="index.php">Home</a>
                <a href="cart.php">Cart</a>
                <a href="user_dashboard.php">My Orders</a>
            </div>
            <div class="ep-footer-col">
                <h5>Resources</h5>
                <a href="privacy_policy.php">Privacy Policy</a>
                <a href="<?php echo $isLoggedIn ? 'user_dashboard.php' : '../login.php'; ?>">My Account</a>
                <a href="messages.php">Help Center</a>
            </div>
        </div>
        <div class="ep-footer-bottom">&copy; 2026 EASYPC E-Commerce. All Rights Reserved.</div>
    </footer>

    <script src="../includes/ui_alerts.js"></script>
    <script>
        // Check for "added to cart" message from products.php
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('added') && typeof IAS_UI !== 'undefined') {
                IAS_UI.alert('Added to cart!', 'success');
            }
        });

        // ── Categories grid: paginate two rows at a time ────────────────────
        (function () {
            const grid = document.getElementById('epCategoriesGrid');
            const pagination = document.getElementById('epCategoriesPagination');
            if (!grid || !pagination) return;

            const tiles = Array.from(grid.children);
            const ROWS_PER_PAGE = 2;
            let currentPage = 1;

            function getColumnCount() {
                const cols = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean);
                return Math.max(1, cols.length);
            }

            function render() {
                const perPage = getColumnCount() * ROWS_PER_PAGE;
                const totalPages = Math.max(1, Math.ceil(tiles.length / perPage));
                if (currentPage > totalPages) currentPage = totalPages;

                const start = (currentPage - 1) * perPage;
                const end = start + perPage;
                tiles.forEach((tile, i) => {
                    tile.style.display = (i >= start && i < end) ? '' : 'none';
                });

                pagination.innerHTML = '';
                if (totalPages <= 1) return;

                const makeLink = (label, page, opts = {}) => {
                    const a = document.createElement('a');
                    a.href = '#';
                    a.className = 'ep-page-link' + (opts.nav ? ' ep-page-nav' : '') +
                        (opts.active ? ' active' : '') + (opts.disabled ? ' disabled' : '');
                    a.innerHTML = label;
                    a.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (opts.disabled) return;
                        currentPage = page;
                        render();
                        grid.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    });
                    return a;
                };

                pagination.appendChild(makeLink('<i class="fas fa-arrow-left"></i> Previous', currentPage - 1, { nav: true, disabled: currentPage <= 1 }));
                for (let p = 1; p <= totalPages; p++) {
                    pagination.appendChild(makeLink(String(p), p, { active: p === currentPage }));
                }
                pagination.appendChild(makeLink('Next <i class="fas fa-arrow-right"></i>', currentPage + 1, { nav: true, disabled: currentPage >= totalPages }));
            }

            render();
            window.addEventListener('resize', render);
        })();

        // ── Carousel scroll helper ──────────────────────────────────────────
        function epScroll(id, dir) {
            const row = document.getElementById(id);
            if (!row) return;
            row.scrollBy({ left: dir * 320, behavior: 'smooth' });
        }

        // ── Nav dropdown toggle ─────────────────────────────────────────────
        function epToggleDropdown(btn) {
            const menu = btn.nextElementSibling;
            const isOpen = menu.classList.contains('open');
            document.querySelectorAll('.ep-dropdown-menu.open').forEach(m => m.classList.remove('open'));
            if (!isOpen) menu.classList.add('open');
        }
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.ep-nav-dropdown')) {
                document.querySelectorAll('.ep-dropdown-menu.open').forEach(m => m.classList.remove('open'));
            }
        });

        // ── Tech and Match: device vs peripherals list ──────────────────────
        const epDeviceCats = <?php echo json_encode($deviceCategories); ?>;
        const epPeripheralCats = <?php echo json_encode($peripheralCategories); ?>;
        function epUpdateMatch() {
            const type = document.querySelector('input[name="epMatchType"]:checked').value;
            const cats = type === 'device' ? epDeviceCats : epPeripheralCats;
            const list = document.getElementById('epMatchList');
            list.innerHTML = cats.map(c => `<li>- <a href="category.php?type=${encodeURIComponent(c)}">${c}</a></li>`).join('');
        }
    </script>
<?php ias_alert_footer(); ?>
</body>
</html>
