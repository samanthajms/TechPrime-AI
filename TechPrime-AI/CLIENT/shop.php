<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_shop_taxonomy.php';

$db = getDbConnection();
checkSessionTimeout();
ep_ensure_session_wishlist($db);

$isLoggedIn = isset($_SESSION['user_id']);
$activePage = 'shop';
$pageTitle  = 'Shop Now';
$searchQuery = trim((string)($_GET['q'] ?? ''));
$bodyClass  = 'ep-shop-page-body';

$taxonomy = ep_shop_taxonomy();
$priceFloor = 0;
$priceCeiling = 100000;

$selectedParent = isset($_GET['cat']) ? trim((string)$_GET['cat']) : '';
if ($selectedParent !== '' && !ep_shop_parent_valid($selectedParent)) {
    $selectedParent = '';
}
$selectedSub = isset($_GET['sub']) ? trim((string)$_GET['sub']) : '';
if ($selectedParent === '' || !ep_shop_sub_valid($selectedParent, $selectedSub)) {
    $selectedSub = '';
}

$selectedSection = isset($_GET['section']) ? trim((string)$_GET['section']) : '';
$validSections = array_keys(ep_shop_special_sections());
if ($selectedSection !== '' && !in_array($selectedSection, $validSections, true)) {
    $selectedSection = '';
}

$selectedMinPrice = isset($_GET['price_min']) && $_GET['price_min'] !== ''
    ? (float)$_GET['price_min'] : $priceFloor;
$selectedMaxPrice = isset($_GET['price_max']) && $_GET['price_max'] !== ''
    ? (float)$_GET['price_max'] : $priceCeiling;
if ($selectedMinPrice < $priceFloor) $selectedMinPrice = $priceFloor;
if ($selectedMaxPrice > $priceCeiling) $selectedMaxPrice = $priceCeiling;
if ($selectedMinPrice > $selectedMaxPrice) {
    $tmp = $selectedMinPrice;
    $selectedMinPrice = $selectedMaxPrice;
    $selectedMaxPrice = $tmp;
}

$selectedRating = isset($_GET['rating']) && $_GET['rating'] !== '' ? (int)$_GET['rating'] : 0;
if ($selectedRating < 1 || $selectedRating > 5) {
    $selectedRating = 0;
}

$selectedBrand = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
$shopQuery = $searchQuery;

$perPage = 12;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$vis = ias_client_product_list_sql_condition('p');
$allStmt = $db->query(
    "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url, p.description, u.name AS seller_name
     FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE {$vis}
     ORDER BY p.id DESC"
);
$allProducts = ep_shop_attach_taxonomy($allStmt ? $allStmt->fetchAll(PDO::FETCH_ASSOC) : []);

$ratings = [];
if ($selectedRating > 0 && !empty($allProducts)) {
    try {
        $ids = array_column($allProducts, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rst = $db->prepare("SELECT product_id, AVG(rating)::float AS avg_rating FROM reviews WHERE product_id IN ({$ph}) GROUP BY product_id");
        $rst->execute($ids);
        while ($row = $rst->fetch(PDO::FETCH_ASSOC)) {
            $ratings[(int)$row['product_id']] = (float)$row['avg_rating'];
        }
    } catch (Throwable $e) {
        $ratings = [];
    }
}

$availableBrands = [];
foreach ($allProducts as $p) {
    if ($p['brand'] !== '') {
        $availableBrands[$p['brand']] = true;
    }
}
$availableBrands = array_keys($availableBrands);
sort($availableBrands, SORT_STRING | SORT_FLAG_CASE);
if ($selectedBrand !== '' && !in_array($selectedBrand, $availableBrands, true)
    && !in_array($selectedBrand, ['RAKK', 'EasyFix'], true)) {
    $selectedBrand = '';
}

$filtered = [];
foreach ($allProducts as $p) {
    $pid = (int)$p['id'];
    $p['avg_rating'] = $ratings[$pid] ?? null;

    if ($selectedSection === 'power-stations' && empty($p['is_power_station'])) {
        continue;
    }
    if ($selectedSection === 'desktop' && (($p['tax_sub'] ?? '') === 'ssd' || !ep_shop_matches_desktop_section($p))) {
        continue;
    }
    if ($selectedSection === 'laptop' && $p['tax_parent'] !== 'laptops-mobile') {
        continue;
    }
    if ($selectedSection === 'rakk' && stripos((string)$p['name'], 'RAKK') === false) {
        continue;
    }
    if ($selectedSection === 'easyfix' && stripos((string)$p['name'], 'EasyFix') === false
        && stripos((string)$p['name'], 'Easy Fix') === false) {
        continue;
    }
    if ($selectedSection === 'brands' && $selectedBrand === '') {
        // Brand directory is handled separately; keep products for counts.
    }

    if ($selectedParent !== '') {
        if ($selectedParent === 'desktop') {
            if (($p['tax_sub'] ?? '') === 'ssd' || !ep_shop_matches_desktop_section($p)) {
                continue;
            }
        } elseif ($p['tax_parent'] !== $selectedParent) {
            continue;
        }
    }
    if ($selectedSub !== '' && $p['tax_sub'] !== $selectedSub) {
        continue;
    }
    $price = (float)$p['price'];
    if ($price < $selectedMinPrice || $price > $selectedMaxPrice) {
        continue;
    }
    if ($selectedRating > 0 && (float)($p['avg_rating'] ?? 0) < $selectedRating) {
        continue;
    }
    if ($selectedBrand !== '' && strcasecmp((string)$p['brand'], $selectedBrand) !== 0) {
        continue;
    }
    if ($shopQuery !== '') {
        $hay = mb_strtolower($p['name'] . ' ' . $p['brand'] . ' ' . $p['tax_parent_label'] . ' ' . $p['tax_sub_label']);
        if (!str_contains($hay, mb_strtolower($shopQuery))) {
            continue;
        }
    }
    $filtered[] = $p;
}

$brandCounts = [];
foreach ($allProducts as $p) {
    if ($p['brand'] === '') {
        continue;
    }
    $brandCounts[$p['brand']] = ($brandCounts[$p['brand']] ?? 0) + 1;
}
ksort($brandCounts, SORT_STRING | SORT_FLAG_CASE);
$brandLogos = ep_shop_brand_logos($db);
$displayBrandLogos = [];
$seenBrandLogos = [];
foreach ($brandCounts as $brandName => $_cnt) {
    $brandImg = ep_shop_brand_logo_lookup($brandLogos, $brandName);
    if ($brandImg === '') {
        continue;
    }
    $rel = preg_replace('#^\.\./#', '', str_replace('\\', '/', $brandImg));
    $abs = dirname(__DIR__) . '/' . $rel;
    if (!is_file($abs) || !is_readable($abs)) {
        continue;
    }
    $logoKey = hash_file('sha1', $abs) ?: strtolower($brandImg);
    if (isset($seenBrandLogos[$logoKey])) {
        continue;
    }
    $seenBrandLogos[$logoKey] = true;
    $displayBrandLogos[] = ['name' => $brandName, 'img' => $brandImg];
}

$resultCount = count($filtered);
$totalPages = max(1, (int)ceil($resultCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$displayProducts = array_slice($filtered, ($page - 1) * $perPage, $perPage);

$filtersActive = $selectedParent !== ''
    || $selectedSub !== ''
    || $selectedSection !== ''
    || $selectedBrand !== ''
    || $selectedRating > 0
    || $shopQuery !== ''
    || $selectedMinPrice > $priceFloor
    || $selectedMaxPrice < $priceCeiling;

$isBrandDirectory = $selectedSection === 'brands';

$returnQuery = array_filter([
    'cat' => $selectedParent !== '' ? $selectedParent : null,
    'sub' => $selectedSub !== '' ? $selectedSub : null,
    'section' => $selectedSection !== '' ? $selectedSection : null,
    'q' => $shopQuery !== '' ? $shopQuery : null,
    'price_min' => $selectedMinPrice > $priceFloor ? (string)(int)$selectedMinPrice : null,
    'price_max' => $selectedMaxPrice < $priceCeiling ? (string)(int)$selectedMaxPrice : null,
    'rating' => $selectedRating > 0 ? (string)$selectedRating : null,
    'brand' => $selectedBrand !== '' ? $selectedBrand : null,
    'page' => $page > 1 ? (string)$page : null,
], fn($v) => $v !== null);
$returnTo = 'shop.php' . ($returnQuery ? ('?' . http_build_query($returnQuery)) : '');

function ep_shop_pagination_range(int $current, int $total): array
{
    if ($total <= 1) {
        return [1];
    }
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

$minPct = ($priceCeiling > $priceFloor)
    ? (($selectedMinPrice - $priceFloor) / ($priceCeiling - $priceFloor)) * 100
    : 0;
$maxPct = ($priceCeiling > $priceFloor)
    ? (($selectedMaxPrice - $priceFloor) / ($priceCeiling - $priceFloor)) * 100
    : 100;

$parentCounts = [];
$subCounts = [];
foreach ($allProducts as $p) {
    $parentCounts[$p['tax_parent']] = ($parentCounts[$p['tax_parent']] ?? 0) + 1;
    if (!empty($p['is_display']) && $p['tax_parent'] !== 'desktop') {
        $parentCounts['desktop'] = ($parentCounts['desktop'] ?? 0) + 1;
    }
    if ($p['tax_sub'] !== '') {
        $subCounts[$p['tax_parent'] . ':' . $p['tax_sub']] = ($subCounts[$p['tax_parent'] . ':' . $p['tax_sub']] ?? 0) + 1;
    }
}
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <?php $epShopSpecials = ep_shop_special_sections(); ?>
    <nav class="ep-shop-cat-nav" aria-label="Shop categories">
        <div class="ep-shop-cat-nav-inner">
            <div class="ep-mega-wrap" id="epMegaWrap">
                <button type="button" class="ep-shop-cat-btn ep-mega-trigger<?php echo ($selectedParent !== '' && $selectedSection === '') ? ' active' : ''; ?>"
                        id="epMegaBtn" aria-haspopup="true" aria-expanded="false" aria-controls="epMegaMenu">
                    <i class="fas fa-th-large" aria-hidden="true"></i>
                    <span>Products</span>
                    <i class="fas fa-chevron-down ep-mega-caret" aria-hidden="true"></i>
                </button>
                <div class="ep-mega-menu" id="epMegaMenu" hidden>
                    <?php
                    $megaDefault = ($selectedParent !== '' && isset($taxonomy[$selectedParent]))
                        ? $selectedParent
                        : array_key_first($taxonomy);
                    ?>
                    <ul class="ep-mega-cats" role="tablist" aria-label="Product categories">
                        <?php foreach ($taxonomy as $parentSlug => $parent):
                            $hasSubs = !empty($parent['subs']);
                            $isOn = $parentSlug === $megaDefault;
                            ?>
                            <li>
                                <button type="button"
                                        class="ep-mega-cat<?php echo $isOn ? ' active' : ''; ?>"
                                        id="epMegaTab-<?php echo h($parentSlug); ?>"
                                        role="tab"
                                        aria-selected="<?php echo $isOn ? 'true' : 'false'; ?>"
                                        aria-controls="epMegaPanel-<?php echo h($parentSlug); ?>"
                                        data-mega-cat="<?php echo h($parentSlug); ?>">
                                    <span><?php echo h($parent['label']); ?></span>
                                    <?php if ($hasSubs): ?>
                                        <i class="fas fa-chevron-right" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="ep-mega-panels">
                        <?php foreach ($taxonomy as $parentSlug => $parent):
                            $isOn = $parentSlug === $megaDefault;
                            ?>
                            <div class="ep-mega-panel<?php echo $isOn ? ' active' : ''; ?>"
                                 id="epMegaPanel-<?php echo h($parentSlug); ?>"
                                 role="tabpanel"
                                 aria-labelledby="epMegaTab-<?php echo h($parentSlug); ?>"
                                 <?php echo $isOn ? '' : 'hidden'; ?>>
                                <h3 class="ep-mega-panel-title"><?php echo h($parent['label']); ?></h3>
                                <?php if (!empty($parent['subs'])): ?>
                                    <ul class="ep-mega-subs">
                                        <?php foreach ($parent['subs'] as $subSlug => $subLabel): ?>
                                            <li>
                                                <a href="<?php echo h(ep_shop_url(['cat' => $parentSlug, 'sub' => $subSlug, 'section' => null, 'brand' => null])); ?>">
                                                    <?php echo h($subLabel); ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <a class="ep-mega-viewall" href="<?php echo h(ep_shop_url(['cat' => $parentSlug, 'sub' => null, 'section' => null, 'brand' => null])); ?>">
                                        View all <?php echo h($parent['label']); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php foreach ($epShopSpecials as $secSlug => $sec):
                $secActive = $selectedSection === $secSlug;
                ?>
                <a class="ep-shop-cat-btn<?php echo $secActive ? ' active' : ''; ?>"
                   href="shop.php?section=<?php echo h(rawurlencode($secSlug)); ?>">
                    <i class="fas <?php echo h($sec['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo h($sec['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <div class="ep-page-inner ep-shop-page">

        <form class="ep-shop-searchbar" method="GET" action="shop.php" role="search">
            <?php if ($selectedParent !== ''): ?><input type="hidden" name="cat" value="<?php echo h($selectedParent); ?>"><?php endif; ?>
            <?php if ($selectedSub !== ''): ?><input type="hidden" name="sub" value="<?php echo h($selectedSub); ?>"><?php endif; ?>
            <?php if ($selectedSection !== ''): ?><input type="hidden" name="section" value="<?php echo h($selectedSection); ?>"><?php endif; ?>
            <label class="sr-only" for="epShopSearch">Search this catalog</label>
            <i class="fas fa-search" aria-hidden="true"></i>
            <input id="epShopSearch" type="search" name="q" value="<?php echo h($shopQuery); ?>"
                   placeholder="Search products, brands, or categories...">
            <button type="submit" class="ep-btn ep-btn-primary">Search</button>
        </form>

        <?php if ($selectedSection === 'brands'): ?>
            <section class="ep-shop-brands" aria-label="Brands">
                <div class="ep-shop-brand-grid">
                    <?php foreach ($displayBrandLogos as $brandRow):
                        $brandName = $brandRow['name'];
                        $brandImg = $brandRow['img'];
                        ?>
                        <div class="ep-shop-brand-tile">
                            <span class="ep-shop-brand-logo<?php echo (stripos($brandImg, 'ortizan') !== false) ? ' is-light-logo' : ''; ?>">
                                <img src="<?php echo h($brandImg); ?>" alt="<?php echo h($brandName); ?>" loading="lazy" decoding="async">
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!$isBrandDirectory): ?>
        <div class="ep-shop-layout">
            <aside class="ep-shop-sidebar" aria-label="Product filters">
                <form method="GET" action="shop.php" class="ep-shop-filter-card" id="epShopFilters">
                    <?php if ($selectedSub !== ''): ?>
                        <input type="hidden" name="sub" value="<?php echo h($selectedSub); ?>">
                    <?php endif; ?>
                    <?php if ($selectedSection !== ''): ?>
                        <input type="hidden" name="section" value="<?php echo h($selectedSection); ?>">
                    <?php endif; ?>
                    <?php if ($shopQuery !== ''): ?>
                        <input type="hidden" name="q" value="<?php echo h($shopQuery); ?>">
                    <?php endif; ?>

                    <div class="ep-shop-filter-head">
                        <h3><i class="fas fa-sliders-h" aria-hidden="true"></i> Filters</h3>
                        <p>Refine by category, price, rating, and brand.</p>
                    </div>

                    <div class="ep-shop-filter-group">
                        <label for="epShopCatSelect">Product Categories</label>
                        <select id="epShopCatSelect" class="ep-shop-select" name="cat" aria-label="Product Categories">
                            <option value="" data-href="shop.php"<?php echo $selectedParent === '' ? ' selected' : ''; ?>>All products</option>
                            <?php foreach ($taxonomy as $slug => $info): ?>
                                <option value="<?php echo h($slug); ?>"
                                        data-href="<?php echo h(ep_shop_url(['cat' => $slug, 'sub' => null, 'section' => null])); ?>"
                                        <?php echo $selectedParent === $slug ? ' selected' : ''; ?>>
                                    <?php echo h($info['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ep-shop-filter-group">
                        <label>Price Range</label>
                        <div class="ep-shop-price-current" id="epShopPriceLabel">
                            ₱<?php echo number_format((int)$selectedMinPrice); ?> — ₱<?php echo number_format((int)$selectedMaxPrice); ?>
                        </div>
                        <div class="ep-shop-slider"
                             style="--ep-min-pct: <?php echo h(number_format($minPct, 2, '.', '')); ?>%; --ep-max-pct: <?php echo h(number_format($maxPct, 2, '.', '')); ?>%;">
                            <div class="ep-shop-slider-track" aria-hidden="true"></div>
                            <div class="ep-shop-slider-range" id="epShopSliderRange" aria-hidden="true"></div>
                            <input type="range" id="epShopRangeMin"
                                   min="<?php echo (int)$priceFloor; ?>"
                                   max="<?php echo (int)$priceCeiling; ?>"
                                   step="500"
                                   value="<?php echo (int)$selectedMinPrice; ?>"
                                   aria-label="Minimum price">
                            <input type="range" id="epShopRangeMax"
                                   min="<?php echo (int)$priceFloor; ?>"
                                   max="<?php echo (int)$priceCeiling; ?>"
                                   step="500"
                                   value="<?php echo (int)$selectedMaxPrice; ?>"
                                   aria-label="Maximum price">
                        </div>
                        <div class="ep-shop-price-bounds">
                            <span>₱0</span>
                            <span>₱100,000</span>
                        </div>
                        <input type="hidden" name="price_min" id="epShopPriceMin" value="<?php echo (int)$selectedMinPrice; ?>">
                        <input type="hidden" name="price_max" id="epShopPriceMax" value="<?php echo (int)$selectedMaxPrice; ?>">
                    </div>

                    <div class="ep-shop-filter-group">
                        <label>Brand</label>
                        <div class="ep-shop-brand-list" role="radiogroup" aria-label="Brand">
                            <label class="ep-shop-brand-option<?php echo $selectedBrand === '' ? ' active' : ''; ?>">
                                <input type="radio" name="brand" value="" <?php echo $selectedBrand === '' ? 'checked' : ''; ?>>
                                <span>All Brands</span>
                            </label>
                            <?php foreach ($availableBrands as $brand): ?>
                                <label class="ep-shop-brand-option<?php echo $selectedBrand === $brand ? ' active' : ''; ?>">
                                    <input type="radio" name="brand" value="<?php echo h($brand); ?>"
                                           <?php echo $selectedBrand === $brand ? 'checked' : ''; ?>>
                                    <span><?php echo h($brand); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="ep-shop-filter-group">
                        <label>Rating</label>
                        <div class="ep-shop-rating-list" role="radiogroup" aria-label="Minimum rating">
                            <label class="ep-shop-rating-option<?php echo $selectedRating === 0 ? ' active' : ''; ?>">
                                <input type="radio" name="rating" value="" <?php echo $selectedRating === 0 ? 'checked' : ''; ?>>
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
                                    <span class="ep-shop-rating-text"><?php echo $stars; ?> Star<?php echo $stars === 1 ? '' : 's'; ?>+</span>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="ep-shop-filter-actions">
                        <a href="shop.php" class="ep-btn ep-shop-reset">Reset Filter</a>
                        <button type="submit" class="ep-btn ep-btn-primary ep-shop-apply">Apply Filter</button>
                    </div>
                </form>
            </aside>

            <section class="ep-shop-products" aria-label="Products">
                <?php if (!empty($displayProducts) && !$isBrandDirectory): ?>
                    <div class="ep-products-grid ep-shop-grid">
                        <?php foreach ($displayProducts as $p): ?>
                            <div class="ep-product-card ep-grid-card">
                                <div class="ep-shop-card-media">
                                    <img src="<?php echo h(ias_client_product_image_url($p)); ?>"
                                         class="ep-product-img" alt="<?php echo h($p['name']); ?>"
                                         loading="lazy" decoding="async">
                                    <form method="POST" action="wishlist.php" class="ep-wish-form ep-wish-overlay">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                        <input type="hidden" name="return_to" value="<?php echo h($returnTo); ?>">
                                        <button type="submit" name="toggle_wishlist" value="1"
                                                class="ep-wish-btn<?php echo ep_wishlist_has((int)$p['id']) ? ' is-on' : ''; ?>"
                                                title="<?php echo ep_wishlist_has((int)$p['id']) ? 'Remove from wishlist' : 'Add to wishlist'; ?>">
                                            <i class="<?php echo ep_wishlist_has((int)$p['id']) ? 'fas' : 'far'; ?> fa-heart" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                                <div class="ep-shop-card-body">
                                    <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                                    <div class="ep-product-cat">
                                        <?php echo h($p['tax_parent_label']); ?>
                                        <?php if ($p['tax_sub_label'] !== ''): ?>
                                            · <?php echo h($p['tax_sub_label']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($p['avg_rating'] !== null): ?>
                                        <div class="ep-shop-card-rating" title="<?php echo number_format($p['avg_rating'], 1); ?> / 5">
                                            <?php
                                            $rounded = (int)round($p['avg_rating']);
                                            for ($i = 1; $i <= 5; $i++):
                                            ?>
                                                <i class="<?php echo $i <= $rounded ? 'fas' : 'far'; ?> fa-star" aria-hidden="true"></i>
                                            <?php endfor; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="ep-product-price">₱<?php echo number_format((float)$p['price'], 2); ?></div>
                                    <div class="ep-card-actions">
                                        <form method="POST" action="products.php" class="ep-buy-form">
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
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="ep-pagination" aria-label="Pagination">
                            <a class="ep-page-link ep-page-nav<?php echo $page <= 1 ? ' disabled' : ''; ?>"
                               href="<?php echo h(ep_shop_url(['page' => max(1, $page - 1)])); ?>">
                                <i class="fas fa-arrow-left"></i> Previous
                            </a>
                            <?php foreach (ep_shop_pagination_range($page, $totalPages) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="ep-page-ellipsis">…</span>
                                <?php else: ?>
                                    <a class="ep-page-link<?php echo $item === $page ? ' active' : ''; ?>"
                                       href="<?php echo h(ep_shop_url(['page' => $item])); ?>"><?php echo (int)$item; ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <a class="ep-page-link ep-page-nav<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"
                               href="<?php echo h(ep_shop_url(['page' => min($totalPages, $page + 1)])); ?>">
                                Next <i class="fas fa-arrow-right"></i>
                            </a>
                        </nav>
                    <?php endif; ?>
                <?php elseif (!$isBrandDirectory): ?>
                    <div class="ep-empty-state ep-shop-empty">
                        <i class="fas fa-box-open" aria-hidden="true"></i>
                        <h3>No products match your filters</h3>
                        <p>Try another category, brand, or search, or reset filters to see the full catalog.</p>
                        <a href="shop.php" class="ep-btn ep-btn-primary">Reset Filters</a>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php endif; ?>
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
    function formatPeso(n) { return '₱' + Number(n).toLocaleString('en-PH'); }
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

    var catSelect = document.getElementById('epShopCatSelect');
    if (catSelect) {
        catSelect.addEventListener('change', function () {
            var opt = catSelect.options[catSelect.selectedIndex];
            var href = opt ? opt.getAttribute('data-href') : '';
            if (href) {
                window.location.href = href;
            }
        });
    }
})();

(function () {
    var megaWrap = document.getElementById('epMegaWrap');
    var megaBtn = document.getElementById('epMegaBtn');
    var megaMenu = document.getElementById('epMegaMenu');
    if (!megaWrap || !megaBtn || !megaMenu) return;

    function isOpen() {
        return !megaMenu.hidden;
    }
    function inMega(el) {
        return !!(el && (megaWrap.contains(el) || megaMenu.contains(el)));
    }
    function closeMega() {
        megaMenu.hidden = true;
        megaWrap.classList.remove('open');
        megaBtn.setAttribute('aria-expanded', 'false');
    }
    function openMega() {
        megaMenu.hidden = false;
        megaWrap.classList.add('open');
        megaBtn.setAttribute('aria-expanded', 'true');
    }
    function showPanel(slug) {
        if (!slug) return;
        megaMenu.querySelectorAll('.ep-mega-cat').forEach(function (btn) {
            var on = btn.getAttribute('data-mega-cat') === slug;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        megaMenu.querySelectorAll('.ep-mega-panel').forEach(function (panel) {
            var on = panel.id === 'epMegaPanel-' + slug;
            panel.classList.toggle('active', on);
            panel.hidden = !on;
        });
    }

    megaBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (isOpen()) closeMega(); else openMega();
    });

    megaMenu.addEventListener('click', function (e) {
        e.stopPropagation();
        var tab = e.target.closest('[data-mega-cat]');
        if (!tab) return;
        e.preventDefault();
        showPanel(tab.getAttribute('data-mega-cat'));
    });

    document.addEventListener('click', function (e) {
        if (!isOpen()) return;
        if (inMega(e.target)) return;
        closeMega();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMega();
    });
})();
</script>
SCRIPTS;
?>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
