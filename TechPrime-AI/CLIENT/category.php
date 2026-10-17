<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/product_categories.php';

$db = getDbConnection();

$type       = isset($_GET['type']) ? trim($_GET['type']) : 'Laptops';
$isLoggedIn = isset($_SESSION['user_id']);
$currentCategory = $type;
$activePage = '';
$pageTitle  = h($type);
$searchQuery = '';
$bodyClass = '';


/* ------------------------------------------------------------------ *
 * Pull the full, display-safe set of products for this category once,
 * then do faceting / filtering / sorting / pagination in PHP so the
 * counts stay consistent with whatever ias_client_filter_products_for_display()
 * decides to hide.
 * ------------------------------------------------------------------ */
$catBind = [];   // matches the old buckets AND the aligned Client/Custodian labels (see includes/product_categories.php)
$stmt = $db->prepare(
    "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url, u.name as seller_name
     FROM products p
     JOIN users u ON p.seller_id = u.id
     WHERE " . ias_category_in_sql('p.category', $type, $catBind) . " AND " . ias_client_product_list_sql_condition('p')
);
$stmt->execute($catBind);
$baseResult = $stmt;
$baseRows = $baseResult ? $baseResult->fetchAll(PDO::FETCH_ASSOC) : [];
$baseProducts = $baseRows;

// ---- Facet data (brands + price bounds) from the whole category ----
$availableBrands = [];
$allPrices = [];
foreach ($baseProducts as $p) {
    if (!empty($p['seller_name'])) {
        $availableBrands[$p['seller_name']] = true;
    }
    $allPrices[] = (float) $p['price'];
}
$availableBrands = array_keys($availableBrands);
sort($availableBrands, SORT_STRING | SORT_FLAG_CASE);
$categoryMinPrice = !empty($allPrices) ? (float) floor(min($allPrices)) : 0.0;
$categoryMaxPrice = !empty($allPrices) ? (float) ceil(max($allPrices)) : 0.0;
if ($categoryMaxPrice <= $categoryMinPrice) {
    $categoryMaxPrice = $categoryMinPrice + 100;
}

// ---- Read filter / sort / page params ----
$selectedBrand        = isset($_GET['brand']) ? trim($_GET['brand']) : '';
$selectedAvailability  = (isset($_GET['availability']) && in_array($_GET['availability'], ['in_stock', 'out_of_stock'], true))
    ? $_GET['availability'] : '';
$selectedMaxPrice = (isset($_GET['price_max']) && $_GET['price_max'] !== '')
    ? (float) $_GET['price_max'] : $categoryMaxPrice;
if ($selectedMaxPrice < $categoryMinPrice) $selectedMaxPrice = $categoryMinPrice;
if ($selectedMaxPrice > $categoryMaxPrice) $selectedMaxPrice = $categoryMaxPrice;

$sort = (isset($_GET['sort']) && in_array($_GET['sort'], ['price_asc', 'price_desc', 'rating'], true))
    ? $_GET['sort'] : 'price_asc';

$perPage = 12;

// ---- Apply filters ----
$filteredProducts = array_values(array_filter($baseProducts, function ($p) use ($selectedBrand, $selectedAvailability, $selectedMaxPrice) {
    if ($selectedBrand !== '' && ($p['seller_name'] ?? '') !== $selectedBrand) return false;
    $stock = isset($p['stock']) ? (int) $p['stock'] : 0;
    if ($selectedAvailability === 'in_stock' && $stock <= 0) return false;
    if ($selectedAvailability === 'out_of_stock' && $stock > 0) return false;
    if ((float) $p['price'] > $selectedMaxPrice) return false;
    return true;
}));

// ---- Apply sort ----
switch ($sort) {
    case 'price_desc':
        usort($filteredProducts, fn($a, $b) => $b['price'] <=> $a['price']);
        break;
    case 'rating':
        // No rating/reviews column exists yet — fall back to newest first.
        usort($filteredProducts, fn($a, $b) => $b['id'] <=> $a['id']);
        break;
    case 'price_asc':
    default:
        usort($filteredProducts, fn($a, $b) => $a['price'] <=> $b['price']);
        break;
}

// ---- Paginate ----
$totalCount = count($filteredProducts);
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
if ($page > $totalPages) $page = $totalPages;
$displayProducts = array_slice($filteredProducts, ($page - 1) * $perPage, $perPage);

// ---- Helpers ----
function ep_cat_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'category.php?' . http_build_query($params);
}

?>
<?php include __DIR__ . '/ep_header.php'; ?>

<?php
$catCsrf = generateCsrfToken();
$catReturn = 'category.php?' . http_build_query(['type' => $type]);
$catSorts = ['price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'rating' => 'Newest'];
$catPct = $categoryMaxPrice > $categoryMinPrice
    ? ($selectedMaxPrice - $categoryMinPrice) / ($categoryMaxPrice - $categoryMinPrice) * 100
    : 100;
?>
<main class="ep-main sf-main" id="main">
    <?php echo sf_page_hero([
        'crumbs' => [['Home', 'index.php'], ['Shop', 'shop.php'], [$type, '']],
        'eyebrow' => 'Category',
        'title' => $type,
        'sub' => count($baseProducts) > 0
            ? number_format(count($baseProducts)) . ' product' . (count($baseProducts) === 1 ? '' : 's') . ' from EasyPC One Oasis. Prices include VAT.'
            : 'Nothing is listed in this category right now.',
    ]); ?>

    <div class="sf-wrap sf-body">
        <div class="ep-shop-layout">

            <aside class="ep-shop-sidebar" aria-label="Product filters">
                <form method="GET" action="category.php" id="epFilterForm" class="ep-shop-filter-card">
                    <input type="hidden" name="type" value="<?php echo h($type); ?>">
                    <input type="hidden" name="sort" value="<?php echo h($sort); ?>">

                    <div class="ep-shop-filter-head">
                        <h3><i class="fas fa-sliders-h" aria-hidden="true"></i> Filters</h3>
                        <p>Changes apply as soon as you pick an option.</p>
                    </div>

                    <fieldset class="ep-shop-filter-group sf-fieldset">
                        <legend class="sf-legend">Brands</legend>
                        <div class="ep-shop-brand-list">
                            <label class="ep-shop-brand-option">
                                <input type="radio" name="brand" value="" onchange="this.form.submit()" <?php echo $selectedBrand === '' ? 'checked' : ''; ?>>
                                <span>All brands</span>
                            </label>
                            <?php foreach ($availableBrands as $brand): ?>
                                <label class="ep-shop-brand-option">
                                    <input type="radio" name="brand" value="<?php echo h($brand); ?>" onchange="this.form.submit()" <?php echo $selectedBrand === $brand ? 'checked' : ''; ?>>
                                    <span><?php echo h($brand); ?></span>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($availableBrands)): ?>
                                <p class="sf-filter-none">No sellers yet.</p>
                            <?php endif; ?>
                        </div>
                    </fieldset>

                    <div class="ep-shop-filter-group">
                        <label for="epPriceMaxRange">Maximum price</label>
                        <div class="ep-shop-price-current" id="epPriceMaxLabel">₱<?php echo number_format($selectedMaxPrice); ?></div>
                        <input type="range" class="sf-range" name="price_max" id="epPriceMaxRange"
                               min="<?php echo (int) $categoryMinPrice; ?>"
                               max="<?php echo (int) $categoryMaxPrice; ?>"
                               value="<?php echo (int) $selectedMaxPrice; ?>"
                               style="--sf-range-pct: <?php echo h(number_format($catPct, 2, '.', '')); ?>%"
                               oninput="document.getElementById('epPriceMaxLabel').textContent = '₱' + Number(this.value).toLocaleString(); document.getElementById('epPriceMaxBox').value = this.value; this.style.setProperty('--sf-range-pct', ((this.value - this.min) / ((this.max - this.min) || 1) * 100) + '%');"
                               onchange="this.form.submit()">
                        <div class="ep-shop-price-bounds">
                            <span>₱<?php echo number_format($categoryMinPrice); ?></span>
                            <span>₱<?php echo number_format($categoryMaxPrice); ?></span>
                        </div>
                        <?php /* Typed max price (unnamed): copies into the slider, which is what gets submitted. */ ?>
                        <div class="ep-shop-price-inputs">
                            <div class="ep-shop-price-field">
                                <span aria-hidden="true">Max ₱</span>
                                <input type="number" id="epPriceMaxBox" inputmode="numeric"
                                       min="<?php echo (int) $categoryMinPrice; ?>"
                                       max="<?php echo (int) $categoryMaxPrice; ?>" step="1"
                                       value="<?php echo (int) $selectedMaxPrice; ?>"
                                       aria-label="Maximum price in pesos"
                                       onchange="epApplyMaxPrice(this)"
                                       onkeydown="if (event.key === 'Enter') { event.preventDefault(); epApplyMaxPrice(this); }">
                            </div>
                        </div>
                    </div>

                    <fieldset class="ep-shop-filter-group sf-fieldset">
                        <legend class="sf-legend">Availability</legend>
                        <div class="ep-shop-brand-list">
                            <?php foreach (['' => 'All', 'in_stock' => 'In stock', 'out_of_stock' => 'Out of stock'] as $av => $avLabel): ?>
                                <label class="ep-shop-brand-option">
                                    <input type="radio" name="availability" value="<?php echo h($av); ?>" onchange="this.form.submit()" <?php echo $selectedAvailability === $av ? 'checked' : ''; ?>>
                                    <span><?php echo h($avLabel); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <div class="ep-shop-filter-actions">
                        <a href="category.php?type=<?php echo urlencode($type); ?>" class="ep-btn ep-shop-reset">Clear all</a>
                        <button type="submit" class="ep-btn ep-btn-primary sf-filter-apply">Apply</button>
                    </div>
                </form>
            </aside>

            <section class="ep-shop-products" aria-label="Products">
                <div class="sf-results-bar">
                    <p class="sf-results-count">
                        <?php if ($totalCount > 0): ?>
                            Showing <strong class="sf-num"><?php echo number_format(($page - 1) * $perPage + 1); ?>&ndash;<?php echo number_format(($page - 1) * $perPage + count($displayProducts)); ?></strong>
                            of <strong class="sf-num"><?php echo number_format($totalCount); ?></strong>
                        <?php else: ?>
                            No products
                        <?php endif; ?>
                    </p>
                    <nav aria-label="Sort products">
                        <ul class="sf-chips">
                            <?php foreach ($catSorts as $sortKey => $sortLabel): ?>
                                <li>
                                    <a class="sf-chip" href="<?php echo h(ep_cat_url(['sort' => $sortKey, 'page' => 1])); ?>"
                                       <?php echo $sort === $sortKey ? 'aria-current="true"' : ''; ?>>
                                        <?php if ($sort === $sortKey): ?><i class="fas fa-check" aria-hidden="true"></i><?php endif; ?>
                                        <?php echo h($sortLabel); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                </div>

                <?php if (!empty($displayProducts)): ?>
                    <ul class="sf-grid">
                        <?php foreach ($displayProducts as $p): ?>
                            <li><?php echo sf_product_card($p, $catReturn, $catCsrf, ['cat' => $type]); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php echo sf_pagination($page, $totalPages, fn($n) => ep_cat_url(['page' => $n])); ?>
                <?php else: ?>
                    <?php echo sf_empty_state('fa-box-open', 'No products found in ' . $type,
                        'Try adjusting your filters, or check back later for new arrivals.',
                        '<a href="category.php?type=' . h(urlencode($type)) . '" class="sf-btn sf-btn-outline">Clear filters</a>'
                        . '<a href="shop.php" class="sf-btn sf-btn-primary">Browse all products</a>'); ?>
                <?php endif; ?>
            </section>

        </div>
    </div>
</main>

<script>
    // Typed max price: clamp to the category's range, copy into the slider and filter.
    function epApplyMaxPrice(box) {
        var range = document.getElementById('epPriceMaxRange');
        if (!range) return;
        var lo = Number(range.min), hi = Number(range.max);
        var n = Math.round(Number(String(box.value).trim()));
        if (String(box.value).trim() === '' || !isFinite(n)) n = hi;
        n = Math.min(hi, Math.max(lo, n));
        box.value = n;
        if (String(n) === range.value) return;
        range.value = n;
        range.form.submit();
    }
</script>

<?php include __DIR__ . '/ep_footer.php'; ?>
