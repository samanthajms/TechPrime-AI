<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();

$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? h($_SESSION['name']) : 'Guest';
$activePage = 'home';
$isHomePage = true;

$categories = ['Accessories', 'Audio', 'Cables and Adapters', 'Camera', 'Combo', 'Cooling', 'Customization', 'Display', 'Gaming Surface', 'Graphic Card', 'Hard Disk', 'Home & Office Furniture',
'Keyboard', 'Laptop GA2', 'Laptop GA3', 'Laptop PR2', 'Laptop PR3', 'Memory', 'Mini PC', 'Motherboard', 'Mouse', 'Network Device', 'PC Case', 'Power Station', 'Power Supply', 'Printer and Scanner',
'Processor', 'Promotional', 'Recorder', 'Services', 'Software', 'Solid State Drive', 'Speaker', 'UPS & AVR', 'Value Plus'];

$categoryIcons = [
    'Laptops'     => 'fa-laptop',
    'Desktop'     => 'fa-desktop',
    'Mobile'      => 'fa-mobile-alt',
    'Cameras'     => 'fa-camera',
    'Accessories' => 'fa-headphones',
];

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
$topSellers = array_slice($allDisplayProducts, 0, 8);

$returnTo = 'index.php';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <section class="ep-section">
        <div class="ep-section-head">
            <h3>Featured Products</h3>
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
                                <form action="products.php" method="POST" class="ep-buy-form">
                                    <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <button type="submit" name="add_to_cart" value="1" class="ep-cart-icon" title="Add to cart"><i class="fas fa-shopping-cart"></i></button>
                                    <button type="submit" name="buy_now" value="1" class="ep-buy-btn">BUY NOW</button>
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

    <div class="ep-promo-banner">
        <div>
            <h2>Boost Your Productivity</h2>
            <p>Essential accessories for work &amp; play.</p>
            <a href="category.php?type=Accessories" class="ep-btn ep-btn-primary">Browse Now</a>
        </div>
        <div class="ep-promo-icons" aria-hidden="true">
            <i class="fas fa-laptop"></i>
            <i class="fas fa-keyboard"></i>
            <i class="fas fa-mouse"></i>
        </div>
    </div>

    <div class="ep-duo-grid">
        <a class="ep-duo-tile new-arrivals" href="products.php">
            <i class="fas fa-vr-cardboard ep-duo-icon"></i>
            <h3>New Arrivals</h3>
            <span class="ep-btn ep-btn-primary">Shop New</span>
        </a>
    </div>

    <div class="ep-section-head"><h3>Categories</h3></div>
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
                    <label for="epMatchCategory" class="sr-only">Product category</label>
                    <select id="epMatchCategory" class="ep-category-select" aria-label="Select product category">
                        <option value="">Choose a category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo h($cat); ?>"><?php echo h($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ep-match-right">
                    <h4>Browse category</h4>
                    <p id="epMatchHint" class="ep-match-subtitle" style="margin:0;">Select a category to browse matching products.</p>
                    <a id="epMatchBrowse" href="#" class="ep-btn ep-btn-primary" style="display:none;margin-top:12px;">Browse Category</a>
                </div>
            </div>
            <div class="ep-recommendations">
                <h4>Recommendations</h4>
                <div class="ep-rec-empty">Recommendations will appear here once configured.</div>
            </div>
        </div>
    </section>
</main>

<?php
$extraScripts = <<<'SCRIPTS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('added') && typeof IAS_UI !== 'undefined') {
        IAS_UI.alert('Added to cart!', 'success');
    }

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
            tiles.forEach((tile, i) => { tile.style.display = (i >= start && i < start + perPage) ? '' : 'none'; });
            pagination.innerHTML = '';
            if (totalPages <= 1) return;
            const makeLink = (label, page, opts = {}) => {
                const a = document.createElement('a');
                a.href = '#';
                a.className = 'ep-page-link' + (opts.nav ? ' ep-page-nav' : '') + (opts.active ? ' active' : '') + (opts.disabled ? ' disabled' : '');
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
            for (let p = 1; p <= totalPages; p++) pagination.appendChild(makeLink(String(p), p, { active: p === currentPage }));
            pagination.appendChild(makeLink('Next <i class="fas fa-arrow-right"></i>', currentPage + 1, { nav: true, disabled: currentPage >= totalPages }));
        }
        render();
        window.addEventListener('resize', render);
    })();

    const catSelect = document.getElementById('epMatchCategory');
    const browseBtn = document.getElementById('epMatchBrowse');
    const hint = document.getElementById('epMatchHint');
    if (catSelect && browseBtn) {
        catSelect.addEventListener('change', function () {
            const val = this.value;
            if (!val) {
                browseBtn.style.display = 'none';
                hint.textContent = 'Select a category to browse matching products.';
                return;
            }
            browseBtn.href = 'category.php?type=' + encodeURIComponent(val);
            browseBtn.style.display = 'inline-block';
            browseBtn.textContent = 'Browse ' + val;
            hint.textContent = 'View products in the ' + val + ' category.';
        });
    }
});
</script>
SCRIPTS;
?>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>