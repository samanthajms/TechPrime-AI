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

$categories = ['Accessories', 'Audio', 'Cables and Adapters', 'Camera', 'Combo', 'Cooling', 'Customization', 'Display', 'Gaming Surface', 'GPU', 'Graphic Card', 'Hard Disk', 'Home & Office Furniture',
'Keyboard', 'Laptop GA2', 'Laptop GA3', 'Laptop PR2', 'Laptop PR3', 'Memory', 'Mini PC', 'Motherboard', 'Mouse', 'Network Device', 'Others', 'PC Case', 'Power Station', 'Power Supply', 'Printer and Scanner', 'Printers and Scanners',
'Processor', 'Promotional', 'RAM', 'Recorder', 'Services', 'Software', 'Solid State Drive', 'Speaker', 'UPS & AVR', 'Value Plus'];

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

// New Arrivals: products added within the last 7 days (uses existing created_at)
$newArrivalsQuery = "SELECT p.*, u.name AS seller_name
                     FROM products p
                     INNER JOIN users u ON p.seller_id = u.id
                     WHERE " . ias_client_product_list_sql_condition('p') . "
                       AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                     ORDER BY p.created_at DESC
                     LIMIT 12";
$newArrivalsResult = $db->query($newArrivalsQuery);
$newArrivals = ias_client_filter_products_for_display(
    $newArrivalsResult ? $newArrivalsResult->fetch_all(MYSQLI_ASSOC) : [],
    6
);

$returnTo = 'index.php';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main ep-home-main">
    <section class="ep-section ep-featured-section">
        <div class="ep-featured-head">
            <div>
                <p class="ep-featured-kicker">Curated for you</p>
                <h3>Featured Products</h3>
                <p class="ep-featured-subtitle">Handpicked items from EasyPC inventory, ready for your next build.</p>
            </div>
        </div>

        <div class="ep-carousel-wrap">
            <button class="ep-arrow ep-arrow-left" onclick="epScroll('topSellersRow', -1)" aria-label="Scroll left"><i class="fas fa-arrow-left"></i></button>
            <div class="ep-carousel" id="topSellersRow">
                <?php if (!empty($topSellers)): ?>
                    <?php foreach ($topSellers as $p): ?>
                        <div class="ep-product-card ep-featured-card">
                            <div class="ep-featured-card-media">
                                <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="ep-product-img" alt="<?php echo h($p['name']); ?>">
                            </div>
                            <div class="ep-featured-card-body">
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
            <a href="category.php?type=Accessories" class="ep-btn ep-btn-primary">Browse Accessories</a>
        </div>
        <div class="ep-promo-icons" aria-hidden="true">
            <i class="fas fa-laptop"></i>
            <i class="fas fa-keyboard"></i>
            <i class="fas fa-mouse"></i>
        </div>
    </div>

    <section class="ep-promo-banner ep-new-arrivals-banner" aria-labelledby="epNewArrivalsTitle">
        <div class="ep-new-arrivals-copy">
            <h2 id="epNewArrivalsTitle">New Arrivals</h2>
            <p>Fresh stock added by EasyPC inventory within the last 7 days.</p>
            <a href="shop.php" class="ep-btn ep-btn-primary">CHECK NEW ARRIVALS</a>
        </div>
        <div class="ep-new-arrivals-products">
            <?php if (!empty($newArrivals)): ?>
                <?php foreach ($newArrivals as $p): ?>
                    <article class="ep-new-arrival-card">
                        <img src="<?php echo h(ias_client_product_image_url($p)); ?>"
                             alt="<?php echo h($p['name']); ?>">
                        <div class="ep-new-arrival-card-body">
                            <div class="ep-new-arrival-name"><?php echo h($p['name']); ?></div>
                            <div class="ep-new-arrival-price">₱<?php echo number_format((float) $p['price'], 2); ?></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="ep-new-arrivals-empty">
                    <i class="fas fa-box-open" aria-hidden="true"></i>
                    <span>No new arrivals in the last 7 days. Check back soon.</span>
                </div>
            <?php endif; ?>
        </div>
    </section>

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
