<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_shop_taxonomy.php';

$db = getDbConnection();

$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? h($_SESSION['name']) : 'Guest';
$activePage = 'home';
$isHomePage = true;

$categories = []; // unused on homepage render; avoid large unused array work

$vis = ias_client_product_list_sql_condition('p');
$listCols = 'p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url, u.name AS seller_name';

$productQuery = "SELECT {$listCols}
                 FROM products p
                 INNER JOIN users u ON p.seller_id = u.id
                 WHERE {$vis}
                 ORDER BY p.id DESC
                 LIMIT 8";
$productResult = $db->query($productQuery);
$topSellers = $productResult ? $productResult->fetchAll(PDO::FETCH_ASSOC) : [];

// New Arrivals: products added within the last 7 days (uses existing created_at)
$newArrivalsQuery = "SELECT {$listCols}
                     FROM products p
                     INNER JOIN users u ON p.seller_id = u.id
                     WHERE {$vis}
                       AND p.created_at >= (NOW() - INTERVAL '7 day')
                     ORDER BY p.created_at DESC
                     LIMIT 6";
$newArrivalsResult = $db->query($newArrivalsQuery);
$newArrivals = $newArrivalsResult ? $newArrivalsResult->fetchAll(PDO::FETCH_ASSOC) : [];

$categoryTiles = ep_shop_category_tiles($db);

$returnTo = 'index.php';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main ep-home-main">
    <?php if (!empty($categoryTiles)): ?>
    <section class="ep-section ep-cat-rail-section" aria-labelledby="epCatRailTitle">
        <div class="ep-cat-rail-head">
            <div>
                <p class="ep-featured-kicker">Browse the store</p>
                <h3 id="epCatRailTitle">Shop by Category</h3>
            </div>
            <a href="shop.php" class="ep-cat-rail-all">View all products <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        </div>

        <div class="ep-cat-rail" data-cat-rail>
            <button type="button" class="ep-cat-rail-arrow is-prev" data-cat-rail-prev aria-label="Previous categories" disabled>
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </button>
            <ul class="ep-cat-rail-track" data-cat-rail-track>
                <?php foreach ($categoryTiles as $tile): ?>
                    <li class="ep-catrail-tile">
                        <a href="<?php echo h($tile['url']); ?>" class="ep-catrail-tile-link">
                            <span class="ep-catrail-tile-media">
                                <?php if ($tile['image'] !== ''): ?>
                                    <img src="<?php echo h($tile['image']); ?>" alt="" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <i class="fas fa-box" aria-hidden="true"></i>
                                <?php endif; ?>
                            </span>
                            <span class="ep-catrail-tile-name"><?php echo h($tile['label']); ?></span>
                            <span class="ep-catrail-tile-count"><?php echo (int)$tile['count']; ?> item<?php echo (int)$tile['count'] === 1 ? '' : 's'; ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="ep-cat-rail-arrow is-next" data-cat-rail-next aria-label="More categories">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
        </div>
    </section>
    <?php endif; ?>

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
                                <a href="products.php?id=<?php echo (int)$p['id']; ?>">
                                <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="ep-product-img" alt="<?php echo h($p['name']); ?>" loading="lazy" decoding="async">
                                </a>
                            </div>
                            <div class="ep-featured-card-body">
                                <a class="ep-product-name" href="products.php?id=<?php echo (int)$p['id']; ?>"><?php echo h($p['name']); ?></a>
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
                        <a href="products.php?id=<?php echo (int)$p['id']; ?>">
                        <img src="<?php echo h(ias_client_product_image_url($p)); ?>"
                             alt="<?php echo h($p['name']); ?>" loading="lazy" decoding="async">
                        <div class="ep-new-arrival-card-body">
                            <div class="ep-new-arrival-name"><?php echo h($p['name']); ?></div>
                            <div class="ep-new-arrival-price">₱<?php echo number_format((float) $p['price'], 2); ?></div>
                        </div>
                        </a>
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
</main>

<?php
$extraScripts = <<<'SCRIPTS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('added') && typeof IAS_UI !== 'undefined') {
        IAS_UI.alert('Added to cart!', 'success');
    }
    if (window.location.hash === '#ep-tech-match') {
        window.location.replace('build_a_pc.php');
    }

    /* Shop by Category: arrows page by the visible width and disable at either end. */
    document.querySelectorAll('[data-cat-rail]').forEach(function (rail) {
        var track = rail.querySelector('[data-cat-rail-track]');
        var prev = rail.querySelector('[data-cat-rail-prev]');
        var next = rail.querySelector('[data-cat-rail-next]');
        if (!track || !prev || !next) return;
        function update() {
            var max = track.scrollWidth - track.clientWidth - 2;
            prev.disabled = track.scrollLeft <= 2;
            next.disabled = track.scrollLeft >= max;
            rail.classList.toggle('has-prev', !prev.disabled);
            rail.classList.toggle('has-next', !next.disabled);
        }
        function page(dir) {
            track.scrollBy({ left: dir * Math.max(track.clientWidth * 0.85, 160), behavior: 'smooth' });
        }
        prev.addEventListener('click', function () { page(-1); });
        next.addEventListener('click', function () { page(1); });
        track.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    });
});
</script>
SCRIPTS;
?>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
