<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
checkSessionTimeout();

$isLoggedIn = isset($_SESSION['user_id']);
$activePage = 'shop';
$pageTitle  = 'Shop';
$searchQuery = '';
$bodyClass  = 'ep-shop-page-body';

/** Final client Shop categories (exact order). */
$shopCategoryNav = [
    'Display' => ['Display'],
    'Laptops' => ['Laptops'],
    'Audio' => ['Audio'],
    'Cooling' => ['Cooling'],
    'Speaker' => ['Speaker'],
    'Accessories' => ['Accessories'],
    'Others' => ['Others'],
];

$priceFloor = 0;
$priceCeiling = 100000;

$selectedCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
if ($selectedCategory !== '' && !isset($shopCategoryNav[$selectedCategory])) {
    $selectedCategory = '';
}
$selectedCategoryValues = $selectedCategory !== '' ? $shopCategoryNav[$selectedCategory] : [];

$selectedMinPrice = isset($_GET['price_min']) && $_GET['price_min'] !== ''
    ? (float) $_GET['price_min'] : $priceFloor;
$selectedMaxPrice = isset($_GET['price_max']) && $_GET['price_max'] !== ''
    ? (float) $_GET['price_max'] : $priceCeiling;

if ($selectedMinPrice < $priceFloor) $selectedMinPrice = $priceFloor;
if ($selectedMaxPrice > $priceCeiling) $selectedMaxPrice = $priceCeiling;
if ($selectedMinPrice > $selectedMaxPrice) {
    $tmp = $selectedMinPrice;
    $selectedMinPrice = $selectedMaxPrice;
    $selectedMaxPrice = $tmp;
}

$selectedRating = isset($_GET['rating']) && $_GET['rating'] !== ''
    ? (int) $_GET['rating'] : 0;
if ($selectedRating < 1 || $selectedRating > 5) {
    $selectedRating = 0;
}

$selectedBrand = isset($_GET['brand']) ? trim((string) $_GET['brand']) : '';

$productResult = $db->query(
    "SELECT p.*, u.name AS seller_name,
            (SELECT AVG(r.rating) FROM reviews r WHERE r.product_id = p.id) AS avg_rating
     FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE " . ias_client_product_list_sql_condition('p') . "
     ORDER BY p.id DESC"
);
$allProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetchAll(PDO::FETCH_ASSOC) : []
);

foreach ($allProducts as &$prod) {
    $prod['brand'] = ias_client_product_brand($prod);
    $prod['avg_rating'] = isset($prod['avg_rating']) && $prod['avg_rating'] !== null
        ? (float) $prod['avg_rating'] : null;
}
unset($prod);

$availableBrands = [];
foreach ($allProducts as $p) {
    if (($p['brand'] ?? '') !== '') {
        $availableBrands[$p['brand']] = true;
    }
}
$availableBrands = array_keys($availableBrands);
sort($availableBrands, SORT_STRING | SORT_FLAG_CASE);

if ($selectedBrand !== '' && !in_array($selectedBrand, $availableBrands, true)) {
    $selectedBrand = '';
}

$displayProducts = array_values(array_filter($allProducts, function ($p) use (
    $selectedCategoryValues,
    $selectedMinPrice,
    $selectedMaxPrice,
    $selectedRating,
    $selectedBrand
) {
    if (!empty($selectedCategoryValues) && !in_array($p['category'] ?? '', $selectedCategoryValues, true)) {
        return false;
    }
    $price = (float) ($p['price'] ?? 0);
    if ($price < $selectedMinPrice || $price > $selectedMaxPrice) {
        return false;
    }
    if ($selectedRating > 0) {
        if ($p['avg_rating'] === null || (float) $p['avg_rating'] < $selectedRating) {
            return false;
        }
    }
    if ($selectedBrand !== '' && ($p['brand'] ?? '') !== $selectedBrand) {
        return false;
    }
    return true;
}));

$resultCount = count($displayProducts);
$filtersActive = $selectedCategory !== ''
    || $selectedBrand !== ''
    || $selectedRating > 0
    || $selectedMinPrice > $priceFloor
    || $selectedMaxPrice < $priceCeiling;

$returnQuery = array_filter([
    'category' => $selectedCategory !== '' ? $selectedCategory : null,
    'price_min' => $selectedMinPrice > $priceFloor ? (string) (int) $selectedMinPrice : null,
    'price_max' => $selectedMaxPrice < $priceCeiling ? (string) (int) $selectedMaxPrice : null,
    'rating' => $selectedRating > 0 ? (string) $selectedRating : null,
    'brand' => $selectedBrand !== '' ? $selectedBrand : null,
], fn($v) => $v !== null);
$returnTo = 'shop.php' . ($returnQuery ? ('?' . http_build_query($returnQuery)) : '');

function ep_shop_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '' || $v === 0 || $v === '0') {
            unset($params[$k]);
        }
    }
    return 'shop.php' . ($params ? ('?' . http_build_query($params)) : '');
}

$minPct = ($priceCeiling > $priceFloor)
    ? (($selectedMinPrice - $priceFloor) / ($priceCeiling - $priceFloor)) * 100
    : 0;
$maxPct = ($priceCeiling > $priceFloor)
    ? (($selectedMaxPrice - $priceFloor) / ($priceCeiling - $priceFloor)) * 100
    : 100;
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<nav class="ep-shop-cat-nav" aria-label="Shop categories">
    <div class="ep-shop-cat-nav-inner">
        <?php foreach ($shopCategoryNav as $label => $_vals): ?>
            <a href="<?php echo h(ep_shop_url(['category' => $label])); ?>"
               class="ep-shop-cat-btn<?php echo $selectedCategory === $label ? ' active' : ''; ?>"
               <?php echo $selectedCategory === $label ? 'aria-current="page"' : ''; ?>>
                <?php echo h($label); ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>

<main class="ep-main">
    <div class="ep-page-inner ep-shop-page">

        <div class="ep-shop-header">
            <div>
                <p class="ep-shop-kicker">EasyPC Store</p>
                <h2 class="ep-page-title">Shop</h2>
                <p class="ep-shop-subtitle">Browse real products from EasyPC inventory and refine by price, rating, or brand.</p>
            </div>
            <p class="ep-shop-count" aria-live="polite">
                <?php echo (int) $resultCount; ?> product<?php echo $resultCount === 1 ? '' : 's'; ?>
                <?php if ($filtersActive): ?> found<?php endif; ?>
            </p>
        </div>

        <div class="ep-shop-layout">
            <section class="ep-shop-products" aria-label="Products">
                <?php if (!empty($displayProducts)): ?>
                    <div class="ep-products-grid ep-shop-grid">
                        <?php foreach ($displayProducts as $p): ?>
                            <div class="ep-product-card ep-grid-card">
                                <div class="ep-shop-card-media">
                                    <img src="<?php echo h(ias_client_product_image_url($p)); ?>"
                                         class="ep-product-img" alt="<?php echo h($p['name']); ?>">
                                </div>
                                <div class="ep-shop-card-body">
                                    <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                                    <div class="ep-product-cat"><?php echo h($p['category'] ?: 'Uncategorized'); ?></div>
                                    <?php if ($p['avg_rating'] !== null): ?>
                                        <div class="ep-shop-card-rating" title="<?php echo number_format($p['avg_rating'], 1); ?> / 5">
                                            <?php
                                            $rounded = (int) round($p['avg_rating']);
                                            for ($i = 1; $i <= 5; $i++):
                                            ?>
                                                <i class="<?php echo $i <= $rounded ? 'fas' : 'far'; ?> fa-star" aria-hidden="true"></i>
                                            <?php endfor; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="ep-product-price">₱<?php echo number_format((float) $p['price'], 2); ?></div>
                                    <div class="ep-card-actions">
                                        <form method="POST" action="products.php" class="ep-buy-form">
                                            <input type="hidden" name="product_id" value="<?php echo (int) $p['id']; ?>">
                                            <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <button type="submit" name="add_to_cart" value="1" class="ep-cart-icon" title="Add to cart"><i class="fas fa-shopping-cart"></i></button>
                                            <button type="submit" name="buy_now" value="1" class="ep-buy-btn">BUY NOW</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="ep-empty-state ep-shop-empty">
                        <i class="fas fa-box-open" aria-hidden="true"></i>
                        <h3>No products match your filters</h3>
                        <p>Try adjusting the category, price, rating, or brand, or reset filters to see all products.</p>
                        <a href="shop.php" class="ep-btn ep-btn-primary">Reset Filters</a>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="ep-shop-sidebar" aria-label="Product filters">
                <form method="GET" action="shop.php" class="ep-shop-filter-card" id="epShopFilters">
                    <?php if ($selectedCategory !== ''): ?>
                        <input type="hidden" name="category" value="<?php echo h($selectedCategory); ?>">
                    <?php endif; ?>

                    <div class="ep-shop-filter-head">
                        <h3><i class="fas fa-sliders-h" aria-hidden="true"></i> Filters</h3>
                        <p>Refine by price, rating, and brand.</p>
                    </div>

                    <div class="ep-shop-filter-group">
                        <label>Price Range</label>
                        <div class="ep-shop-price-current" id="epShopPriceLabel">
                            ₱<?php echo number_format((int) $selectedMinPrice); ?> — ₱<?php echo number_format((int) $selectedMaxPrice); ?>
                        </div>

                        <div class="ep-shop-slider"
                             style="--ep-min-pct: <?php echo h(number_format($minPct, 2, '.', '')); ?>%; --ep-max-pct: <?php echo h(number_format($maxPct, 2, '.', '')); ?>%;">
                            <div class="ep-shop-slider-track" aria-hidden="true"></div>
                            <div class="ep-shop-slider-range" id="epShopSliderRange" aria-hidden="true"></div>
                            <input type="range" id="epShopRangeMin"
                                   min="<?php echo (int) $priceFloor; ?>"
                                   max="<?php echo (int) $priceCeiling; ?>"
                                   step="500"
                                   value="<?php echo (int) $selectedMinPrice; ?>"
                                   aria-label="Minimum price">
                            <input type="range" id="epShopRangeMax"
                                   min="<?php echo (int) $priceFloor; ?>"
                                   max="<?php echo (int) $priceCeiling; ?>"
                                   step="500"
                                   value="<?php echo (int) $selectedMaxPrice; ?>"
                                   aria-label="Maximum price">
                        </div>

                        <div class="ep-shop-price-bounds">
                            <span>₱0</span>
                            <span>₱100,000</span>
                        </div>

                        <input type="hidden" name="price_min" id="epShopPriceMin" value="<?php echo (int) $selectedMinPrice; ?>">
                        <input type="hidden" name="price_max" id="epShopPriceMax" value="<?php echo (int) $selectedMaxPrice; ?>">
                    </div>

                    <div class="ep-shop-filter-group">
                        <label>Rating</label>
                        <div class="ep-shop-rating-list" role="radiogroup" aria-label="Minimum rating">
                            <label class="ep-shop-rating-option<?php echo $selectedRating === 0 ? ' active' : ''; ?>">
                                <input type="radio" name="rating" value=""
                                       <?php echo $selectedRating === 0 ? 'checked' : ''; ?>>
                                <span class="ep-shop-rating-text">Any rating</span>
                            </label>
                            <?php for ($stars = 5; $stars >= 1; $stars--): ?>
                                <label class="ep-shop-rating-option<?php echo $selectedRating === $stars ? ' active' : ''; ?>">
                                    <input type="radio" name="rating" value="<?php echo $stars; ?>"
                                           <?php echo $selectedRating === $stars ? 'checked' : ''; ?>>
                                    <span class="ep-shop-rating-stars" aria-hidden="true">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="<?php echo $i <= $stars ? 'fas' : 'far'; ?> fa-star"></i>
                                        <?php endfor; ?>
                                    </span>
                                    <span class="ep-shop-rating-text">
                                        <?php echo $stars; ?> Star<?php echo $stars === 1 ? '' : 's'; ?>+
                                    </span>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="ep-shop-filter-group">
                        <label>Brand</label>
                        <div class="ep-shop-brand-list" role="radiogroup" aria-label="Brand">
                            <label class="ep-shop-brand-option<?php echo $selectedBrand === '' ? ' active' : ''; ?>">
                                <input type="radio" name="brand" value=""
                                       <?php echo $selectedBrand === '' ? 'checked' : ''; ?>>
                                <span>All Brands</span>
                            </label>
                            <?php if (!empty($availableBrands)): ?>
                                <?php foreach ($availableBrands as $brand): ?>
                                    <label class="ep-shop-brand-option<?php echo $selectedBrand === $brand ? ' active' : ''; ?>">
                                        <input type="radio" name="brand" value="<?php echo h($brand); ?>"
                                               <?php echo $selectedBrand === $brand ? 'checked' : ''; ?>>
                                        <span><?php echo h($brand); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="ep-shop-filter-hint">No brands available from current products.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ep-shop-filter-actions">
                        <button type="submit" class="ep-btn ep-btn-primary ep-shop-apply">Apply Filters</button>
                        <a href="shop.php" class="ep-btn ep-shop-reset">Reset Filters</a>
                    </div>
                </form>
            </aside>
        </div>

    </div>
</main>

<?php
$extraScripts = <<<'SCRIPTS'
<script>
(function () {
    var form = document.getElementById('epShopFilters');
    if (!form) return;

    var minInput = document.getElementById('epShopPriceMin');
    var maxInput = document.getElementById('epShopPriceMax');
    var minRange = document.getElementById('epShopRangeMin');
    var maxRange = document.getElementById('epShopRangeMax');
    var label = document.getElementById('epShopPriceLabel');
    var slider = form.querySelector('.ep-shop-slider');
    var floor = 0;
    var ceiling = 100000;

    function formatPeso(n) {
        return '₱' + Number(n).toLocaleString('en-PH');
    }

    function updateUI() {
        var minVal = Number(minRange.value);
        var maxVal = Number(maxRange.value);
        minInput.value = minVal;
        maxInput.value = maxVal;
        if (label) label.textContent = formatPeso(minVal) + ' — ' + formatPeso(maxVal);
        if (slider) {
            var span = ceiling - floor;
            var minPct = span > 0 ? ((minVal - floor) / span) * 100 : 0;
            var maxPct = span > 0 ? ((maxVal - floor) / span) * 100 : 100;
            slider.style.setProperty('--ep-min-pct', minPct + '%');
            slider.style.setProperty('--ep-max-pct', maxPct + '%');
        }
    }

    function syncFromRanges(changed) {
        var minVal = Number(minRange.value);
        var maxVal = Number(maxRange.value);
        if (changed === 'min' && minVal > maxVal) minRange.value = maxVal;
        if (changed === 'max' && maxVal < minVal) maxRange.value = minVal;
        updateUI();
    }

    minRange.addEventListener('input', function () { syncFromRanges('min'); });
    maxRange.addEventListener('input', function () { syncFromRanges('max'); });
    updateUI();
})();
</script>
SCRIPTS;
?>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
