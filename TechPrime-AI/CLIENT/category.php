<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();

$type       = isset($_GET['type']) ? trim($_GET['type']) : 'Laptops';
$isLoggedIn = isset($_SESSION['user_id']);
$activePage = strtolower($type) === 'desktop' ? 'desktop' : (strtolower($type) === 'laptops' ? 'laptop' : '');
$pageTitle  = h($type);
$searchQuery = '';
$bodyClass = 'ep-category-layout';

// The big green title shown above the nav (matches CATEGORY.png), rendered by ep_header.php
$categoryHeroTitle = $type;

/* ------------------------------------------------------------------ *
 * Pull the full, display-safe set of products for this category once,
 * then do faceting / filtering / sorting / pagination in PHP so the
 * counts stay consistent with whatever ias_client_filter_products_for_display()
 * decides to hide.
 * ------------------------------------------------------------------ */
$stmt = $db->prepare(
    "SELECT p.*, u.name as seller_name
     FROM products p
     JOIN users u ON p.seller_id = u.id
     WHERE p.category = ? AND " . ias_client_product_list_sql_condition('p')
);
$stmt->bind_param('s', $type);
$stmt->execute();
$baseResult = $stmt->get_result();
$baseRows = $baseResult ? $baseResult->fetch_all(MYSQLI_ASSOC) : [];
$baseProducts = ias_client_filter_products_for_display($baseRows);

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

$perPage = 9; // 3x3 grid, matching CATEGORY.png

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

function ep_pagination_range(int $current, int $total): array
{
    if ($total <= 1) return [1];
    $delta = 1;
    $range = [];
    for ($i = 1; $i <= $total; $i++) {
        if ($i === 1 || $i === $total || ($i >= $current - $delta && $i <= $current + $delta)) {
            $range[] = $i;
        }
    }
    $withDots = [];
    $last = null;
    foreach ($range as $i) {
        if ($last !== null) {
            if ($i - $last === 2) {
                $withDots[] = $last + 1;
            } elseif ($i - $last > 1) {
                $withDots[] = '...';
            }
        }
        $withDots[] = $i;
        $last = $i;
    }
    return $withDots;
}
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner">

        <div class="ep-category-body">

            <!-- ============ Sidebar filters ============ -->
            <aside class="ep-filters">
                <form method="GET" action="category.php" id="epFilterForm">
                    <input type="hidden" name="type" value="<?php echo h($type); ?>">
                    <input type="hidden" name="sort" value="<?php echo h($sort); ?>">

                    <div class="ep-filter-group">
                        <button type="button" class="ep-filter-head" onclick="epToggleFilterGroup(this)">
                            Brands <i class="fas fa-chevron-up"></i>
                        </button>
                        <div class="ep-filter-body">
                            <label class="ep-filter-option">
                                <input type="radio" name="brand" value="" onchange="this.form.submit()" <?php echo $selectedBrand === '' ? 'checked' : ''; ?>>
                                All Brands
                            </label>
                            <?php foreach ($availableBrands as $brand): ?>
                                <label class="ep-filter-option">
                                    <input type="radio" name="brand" value="<?php echo h($brand); ?>" onchange="this.form.submit()" <?php echo $selectedBrand === $brand ? 'checked' : ''; ?>>
                                    <?php echo h($brand); ?>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($availableBrands)): ?>
                                <p style="font-size:12.5px;color:#999;margin:0;">No sellers yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ep-filter-group">
                        <button type="button" class="ep-filter-head" onclick="epToggleFilterGroup(this)">
                            Price <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="ep-filter-body">
                            <div class="ep-price-range">
                                <div class="ep-price-range-label">
                                    <span>₱<?php echo number_format($categoryMinPrice); ?></span>
                                    <span id="epPriceMaxLabel">₱<?php echo number_format($selectedMaxPrice); ?></span>
                                </div>
                                <input type="range" name="price_max"
                                       min="<?php echo (int) $categoryMinPrice; ?>"
                                       max="<?php echo (int) $categoryMaxPrice; ?>"
                                       value="<?php echo (int) $selectedMaxPrice; ?>"
                                       oninput="document.getElementById('epPriceMaxLabel').textContent = '₱' + Number(this.value).toLocaleString();"
                                       onchange="this.form.submit()">
                            </div>
                        </div>
                    </div>

                    <div class="ep-filter-group">
                        <button type="button" class="ep-filter-head" onclick="epToggleFilterGroup(this)">
                            Availability <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="ep-filter-body">
                            <label class="ep-filter-option">
                                <input type="radio" name="availability" value="" onchange="this.form.submit()" <?php echo $selectedAvailability === '' ? 'checked' : ''; ?>>
                                All
                            </label>
                            <label class="ep-filter-option">
                                <input type="radio" name="availability" value="in_stock" onchange="this.form.submit()" <?php echo $selectedAvailability === 'in_stock' ? 'checked' : ''; ?>>
                                In Stock
                            </label>
                            <label class="ep-filter-option">
                                <input type="radio" name="availability" value="out_of_stock" onchange="this.form.submit()" <?php echo $selectedAvailability === 'out_of_stock' ? 'checked' : ''; ?>>
                                Out of Stock
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="ep-filter-apply">APPLY FILTERS</button>
                    <a href="category.php?type=<?php echo urlencode($type); ?>" class="ep-filter-clear">Clear all</a>
                </form>
            </aside>

            <!-- ============ Products + sort + pagination ============ -->
            <section class="ep-products-section">
                <div class="ep-toolbar">
                    <a class="ep-sort-btn<?php echo $sort === 'price_asc' ? ' active' : ''; ?>" href="<?php echo h(ep_cat_url(['sort' => 'price_asc', 'page' => 1])); ?>">
                        <?php if ($sort === 'price_asc'): ?><i class="fas fa-check"></i><?php endif; ?> Price ascending
                    </a>
                    <a class="ep-sort-btn<?php echo $sort === 'price_desc' ? ' active' : ''; ?>" href="<?php echo h(ep_cat_url(['sort' => 'price_desc', 'page' => 1])); ?>">
                        <?php if ($sort === 'price_desc'): ?><i class="fas fa-check"></i><?php endif; ?> Price descending
                    </a>
                    <a class="ep-sort-btn<?php echo $sort === 'rating' ? ' active' : ''; ?>" href="<?php echo h(ep_cat_url(['sort' => 'rating', 'page' => 1])); ?>">
                        <?php if ($sort === 'rating'): ?><i class="fas fa-check"></i><?php endif; ?> Rating
                    </a>
                </div>

                <?php if (!empty($displayProducts)): ?>
                    <div class="ep-products-grid">
                        <?php foreach ($displayProducts as $p): ?>
                            <div class="ep-product-card ep-grid-card">
                                <img src="<?php echo h(ias_client_product_image_url($p)); ?>"
                                     class="ep-product-img" alt="<?php echo h($p['name']); ?>">
                                <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                                <div class="ep-product-cat">Store: <?php echo h($p['seller_name']); ?></div>
                                <div class="ep-product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                                <div class="ep-card-actions">
                                    <button type="button" class="ep-heart-btn"
                                            onclick="this.classList.toggle('active')" aria-label="Save">
                                        <i class="far fa-heart"></i>
                                    </button>
                                    <form action="products.php" method="POST" class="ep-buy-form">
                                        <input type="hidden" name="product_id" value="<?php echo (int) $p['id']; ?>">
                                        <input type="hidden" name="add_to_cart" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <span class="ep-cart-icon"><i class="fas fa-shopping-cart"></i></span>
                                        <button type="submit" class="ep-buy-btn">BUY NOW</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="ep-pagination" aria-label="Pagination">
                            <a class="ep-page-link ep-page-nav<?php echo $page <= 1 ? ' disabled' : ''; ?>"
                               href="<?php echo h(ep_cat_url(['page' => max(1, $page - 1)])); ?>">
                                <i class="fas fa-arrow-left"></i> Previous
                            </a>
                            <?php foreach (ep_pagination_range($page, $totalPages) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="ep-page-ellipsis">…</span>
                                <?php else: ?>
                                    <a class="ep-page-link<?php echo $item === $page ? ' active' : ''; ?>"
                                       href="<?php echo h(ep_cat_url(['page' => $item])); ?>"><?php echo (int) $item; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <a class="ep-page-link ep-page-nav<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"
                               href="<?php echo h(ep_cat_url(['page' => min($totalPages, $page + 1)])); ?>">
                                Next <i class="fas fa-arrow-right"></i>
                            </a>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="ep-empty-state">
                        <i class="fas fa-box-open" style="font-size:48px;color:#ccc;margin-bottom:14px;"></i>
                        <h3>No products found in <?php echo h($type); ?>.</h3>
                        <p>Try adjusting your filters, or check back later for new arrivals!</p>
                    </div>
                <?php endif; ?>
            </section>

        </div>
    </div>
</main>

<script>
    function epToggleFilterGroup(btn) {
        var group = btn.closest('.ep-filter-group');
        if (group) group.classList.toggle('collapsed');
    }
</script>

<?php include __DIR__ . '/ep_footer.php'; ?>
