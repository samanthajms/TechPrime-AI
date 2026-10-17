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
$epRecent = ep_recent_products($db, 0, 8);

$returnTo = 'index.php';
$homeCsrf = generateCsrfToken();

// Hero facts: how many products are on the shelf right now (same visibility rule as the lists).
$inStockCount = (int)$db->query("SELECT COUNT(*) FROM products p INNER JOIN users u ON p.seller_id = u.id WHERE {$vis}")->fetchColumn();

$extraHead = '<link rel="preload" as="image" href="../assets/home-hero.webp" fetchpriority="high">';
require_once __DIR__ . '/../includes/storefront_components.php';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main sf-main" id="main">
    <section class="sf-hero" aria-labelledby="sfHeroTitle">
        <img class="sf-hero-media" src="../assets/home-hero.webp" alt="" width="1600" height="900" fetchpriority="high" decoding="async">
        <div class="sf-wrap sf-hero-inner">
            <p class="sf-hero-kicker"><i class="fas fa-map-marker-alt" aria-hidden="true"></i> EasyPC One Oasis &middot; Rosario, Pasig City</p>
            <h1 class="sf-hero-title" id="sfHeroTitle">Tech it easy at <span>EasyPC One Oasis</span></h1>
            <p class="sf-hero-sub">Laptops, PC parts and accessories from our Pasig branch. Build a PC with compatibility checks, then pay cash on delivery or online.</p>
            <div class="sf-hero-ctas">
                <a href="shop.php" class="sf-btn sf-btn-lg sf-btn-bright">Shop all products <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                <a href="build_a_pc.php" class="sf-btn sf-btn-lg sf-btn-glass"><i class="fas fa-desktop" aria-hidden="true"></i> Build a PC</a>
            </div>
            <?php if ($inStockCount > 0): ?>
                <ul class="sf-hero-facts">
                    <li><i class="fas fa-check-circle" aria-hidden="true"></i><span><strong class="sf-num"><?php echo number_format($inStockCount); ?></strong> products in stock</span></li>
                    <?php if (!empty($categoryTiles)): ?>
                        <li><i class="fas fa-th-large" aria-hidden="true"></i><span><strong class="sf-num"><?php echo count($categoryTiles); ?></strong> categories</span></li>
                    <?php endif; ?>
                    <li><i class="fas fa-tag" aria-hidden="true"></i><span>Prices include VAT</span></li>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <div class="sf-wrap sf-perks-wrap">
        <ul class="sf-perks" aria-label="Why shop with EasyPC">
            <li>
                <div class="sf-perk">
                    <span class="sf-perk-icon"><i class="fas fa-money-bill-wave" aria-hidden="true"></i></span>
                    <span class="sf-perk-text"><strong>Cash on delivery or online</strong><span>Pay when it arrives, or pay online at checkout.</span></span>
                </div>
            </li>
            <li>
                <div class="sf-perk">
                    <span class="sf-perk-icon"><i class="fas fa-truck" aria-hidden="true"></i></span>
                    <span class="sf-perk-text"><strong>Delivery or store pickup</strong><span>Lalamove delivery, or pick up at One Oasis.</span></span>
                </div>
            </li>
            <li>
                <a class="sf-perk-btn" href="build_a_pc.php">
                    <span class="sf-perk-icon"><i class="fas fa-puzzle-piece" aria-hidden="true"></i></span>
                    <span class="sf-perk-text"><strong>Parts that fit <i class="fas fa-arrow-right" aria-hidden="true"></i></strong><span>Build a PC flags parts that don't work together.</span></span>
                </a>
            </li>
            <li>
                <?php if (!empty($epShowPrimo)): ?>
                    <button type="button" class="sf-perk-btn" data-primo-open aria-controls="primoPanel">
                        <span class="sf-perk-icon"><i class="fas fa-robot" aria-hidden="true"></i></span>
                        <span class="sf-perk-text"><strong>Ask Primo <i class="fas fa-arrow-right" aria-hidden="true"></i></strong><span>Our AI assistant helps you find the right item.</span></span>
                    </button>
                <?php else: ?>
                    <div class="sf-perk">
                        <span class="sf-perk-icon"><i class="fas fa-store" aria-hidden="true"></i></span>
                        <span class="sf-perk-text"><strong>Sold by EasyPC One Oasis</strong><span>Rosario, Pasig City</span></span>
                    </div>
                <?php endif; ?>
            </li>
        </ul>
    </div>

    <?php if (!empty($categoryTiles)): ?>
    <section class="sf-wrap sf-section" aria-labelledby="sfCatsTitle">
        <?php echo sf_section_head('sfCatsTitle', 'Browse the store', 'Shop by category', '', 'sfCatsRail', 'shop.php', 'View all products'); ?>
        <div class="sf-rail is-cats" data-sf-rail>
            <ul class="sf-rail-track" id="sfCatsRail" data-sf-rail-track>
                <?php foreach ($categoryTiles as $tile): ?>
                    <li>
                        <a href="<?php echo h($tile['url']); ?>" class="sf-tile">
                            <span class="sf-tile-media">
                                <?php if ($tile['image'] !== ''): ?>
                                    <img src="<?php echo h($tile['image']); ?>" alt="" width="140" height="140" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <i class="fas fa-box" aria-hidden="true"></i>
                                <?php endif; ?>
                            </span>
                            <span class="sf-tile-name"><?php echo h($tile['label']); ?></span>
                            <span class="sf-tile-count sf-num"><?php echo (int)$tile['count']; ?> item<?php echo (int)$tile['count'] === 1 ? '' : 's'; ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($epRecent)): ?>
    <section class="sf-wrap sf-section" aria-labelledby="sfRecentTitle">
        <?php echo sf_section_head('sfRecentTitle', 'Pick up where you left off', 'Recently viewed', '', 'sfRecentRail'); ?>
        <div class="sf-rail" data-sf-rail>
            <ul class="sf-rail-track" id="sfRecentRail" data-sf-rail-track>
                <?php foreach ($epRecent as $p): ?>
                    <li><?php echo sf_product_card($p, $returnTo, $homeCsrf); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

    <section class="sf-wrap sf-section" aria-labelledby="sfFeaturedTitle">
        <?php echo sf_section_head('sfFeaturedTitle', 'Curated for you', 'Featured products',
            'A selection from the EasyPC One Oasis catalog, in stock and ready to order.',
            !empty($topSellers) ? 'sfFeaturedRail' : '', 'shop.php', 'Shop all'); ?>
        <?php if (!empty($topSellers)): ?>
            <div class="sf-rail" data-sf-rail>
                <ul class="sf-rail-track" id="sfFeaturedRail" data-sf-rail-track>
                    <?php foreach ($topSellers as $p): ?>
                        <li><?php echo sf_product_card($p, $returnTo, $homeCsrf); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <div class="sf-empty">
                <span class="sf-empty-icon"><i class="fas fa-box-open" aria-hidden="true"></i></span>
                <span class="sf-empty-text"><strong>No products available yet</strong><span>Check back soon. New stock is added regularly.</span></span>
            </div>
        <?php endif; ?>
    </section>

    <section class="sf-wrap sf-section" aria-label="Featured collections">
        <div class="sf-promos">
            <div class="sf-promo is-dark">
                <div class="sf-promo-copy">
                    <p class="sf-eyebrow">Build a PC</p>
                    <h2 class="sf-promo-title">Plan your next build, part by part</h2>
                    <p class="sf-promo-text">Pick a processor, motherboard, memory and more from what's in stock.</p>
                    <ul class="sf-promo-list">
                        <li><i class="fas fa-check" aria-hidden="true"></i>Flags parts that don't fit together</li>
                        <li><i class="fas fa-check" aria-hidden="true"></i>Running total as you go</li>
                        <li><i class="fas fa-check" aria-hidden="true"></i>Save builds to your account</li>
                    </ul>
                </div>
                <a href="build_a_pc.php" class="sf-btn sf-btn-lg sf-btn-bright">Start a build <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                <img class="sf-promo-art" src="../assets/desktop.png" alt="" width="300" height="300" loading="lazy" decoding="async">
            </div>
            <div class="sf-promo">
                <div class="sf-promo-copy">
                    <p class="sf-eyebrow">Accessories</p>
                    <h2 class="sf-promo-title">Gear for work and play</h2>
                    <p class="sf-promo-text">Keyboards, mice, headsets and the small things that finish a setup.</p>
                </div>
                <a href="category.php?type=Accessories" class="sf-btn sf-btn-outline">Browse accessories <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                <img class="sf-promo-art" src="../assets/headset.png" alt="" width="210" height="210" loading="lazy" decoding="async">
            </div>
        </div>
    </section>

    <section class="sf-wrap sf-section" aria-labelledby="sfNewTitle">
        <?php echo sf_section_head('sfNewTitle', 'Just in', 'New arrivals', 'Stock added to EasyPC One Oasis in the last 7 days.',
            !empty($newArrivals) ? 'sfNewRail' : '', !empty($newArrivals) ? 'shop.php' : '', 'Shop all'); ?>
        <?php if (!empty($newArrivals)): ?>
            <div class="sf-rail" data-sf-rail>
                <ul class="sf-rail-track" id="sfNewRail" data-sf-rail-track>
                    <?php foreach ($newArrivals as $p): ?>
                        <li><?php echo sf_product_card($p, $returnTo, $homeCsrf); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <div class="sf-empty">
                <span class="sf-empty-icon"><i class="fas fa-box-open" aria-hidden="true"></i></span>
                <span class="sf-empty-text"><strong>No new arrivals this week</strong><span>Fresh stock shows up here as soon as it's added. The full catalog is one tap away.</span></span>
                <a href="shop.php" class="sf-btn sf-btn-outline">Browse all products</a>
            </div>
        <?php endif; ?>
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

});
</script>
SCRIPTS;
?>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
