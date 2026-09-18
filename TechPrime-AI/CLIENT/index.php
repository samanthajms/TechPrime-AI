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
$epCsrf = $isLoggedIn ? generateCsrfToken() : '';
$extraHead = '<link rel="stylesheet" href="primo.css"><link rel="stylesheet" href="tech_match.css">';

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
    $productResult ? $productResult->fetchAll(PDO::FETCH_ASSOC) : [],
    12
);
$topSellers = array_slice($allDisplayProducts, 0, 8);

// New Arrivals: products added within the last 7 days (uses existing created_at)
$newArrivalsQuery = "SELECT p.*, u.name AS seller_name
                     FROM products p
                     INNER JOIN users u ON p.seller_id = u.id
                     WHERE " . ias_client_product_list_sql_condition('p') . "
                       AND p.created_at >= (NOW() - INTERVAL '7 day')
                     ORDER BY p.created_at DESC
                     LIMIT 12";
$newArrivalsResult = $db->query($newArrivalsQuery);
$newArrivals = ias_client_filter_products_for_display(
    $newArrivalsResult ? $newArrivalsResult->fetchAll(PDO::FETCH_ASSOC) : [],
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
</main>

<!-- Tech & Match: PC builder inside centered panel (opened from Primo) -->
<div id="epTechMatchModal" class="ep-tm-modal" hidden aria-hidden="true">
    <div class="ep-tm-modal-backdrop" id="epTechMatchBackdrop" tabindex="-1"></div>
    <div class="ep-tm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="epTechMatchTitle">
        <header class="ep-tm-modal-header">
            <div>
                <h2 id="epTechMatchTitle">Tech &amp; Match</h2>
                <p>Build easy. Match smart.</p>
            </div>
            <button type="button" class="ep-tm-modal-close" id="epTechMatchClose" aria-label="Close Tech &amp; Match">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </header>
        <div class="ep-tm-modal-body" id="ep-tech-match">
            <!-- BUILDER VIEW -->
            <div class="tm-builder" id="tmBuilderView">
                <nav class="tm-steps" id="tmSteps" aria-label="Build stages"></nav>
                <div class="tm-workspace">
                    <aside class="tm-left" aria-label="Component selection">
                        <div id="tmSlotList"></div>
                    </aside>
                    <section class="tm-center" aria-label="PC build visualization">
                        <div class="tm-viz">
                            <div class="tm-pc" id="tmPcViz" aria-hidden="true">
                                <div class="tm-pc-mobo"></div>
                                <div class="tm-pc-cpu"></div>
                                <div class="tm-pc-ram"></div>
                                <div class="tm-pc-gpu"></div>
                                <div class="tm-pc-ssd"></div>
                                <div class="tm-pc-psu"></div>
                            </div>
                        </div>
                        <div class="tm-progress-wrap">
                            <div class="tm-progress-meta">
                                <span id="tmComponentCount">0 / 11 components</span>
                            </div>
                            <div class="tm-bar"><span id="tmComponentBar"></span></div>
                        </div>
                        <div class="tm-power-card">
                            <i class="fas fa-bolt" aria-hidden="true"></i>
                            <div>
                                <strong>Estimated Power Draw</strong>
                                <span id="tmPowerDraw">~0W</span>
                            </div>
                            <div class="tm-bar" style="flex:1;margin-left:8px;"><span id="tmPowerBar"></span></div>
                        </div>
                    </section>
                    <aside class="tm-right" aria-label="Build information">
                        <div class="tm-card">
                            <h3>Build Score</h3>
                            <div class="tm-score">
                                <span class="tm-score-num" id="tmBuildScore">0</span><span class="tm-score-den">/100</span>
                            </div>
                            <div class="tm-score-actions" aria-hidden="true">
                                <span><i class="fas fa-info"></i></span>
                                <span><i class="fas fa-share-alt"></i></span>
                                <span><i class="fas fa-ellipsis-h"></i></span>
                            </div>
                        </div>
                        <div class="tm-card">
                            <h3>Performance Balance</h3>
                            <div class="tm-radar-wrap">
                                <svg id="tmRadarSvg" viewBox="0 0 180 180" role="img" aria-label="Performance radar chart"></svg>
                            </div>
                        </div>
                        <div class="tm-card">
                            <h3>Build Summary</h3>
                            <div class="tm-summary-row">
                                <span>Components</span>
                                <span id="tmSummaryCount">0 / 11</span>
                            </div>
                            <div class="tm-bar"><span id="tmSummaryBar"></span></div>
                            <div class="tm-summary-total">
                                <span>Total</span>
                                <strong id="tmSummaryTotal">₱0</strong>
                            </div>
                        </div>
                        <button type="button" class="tm-btn-primary" id="tmAddMissing">
                            <i class="fas fa-cogs" aria-hidden="true"></i> Add Missing Components
                        </button>
                        <button type="button" class="tm-btn-secondary" id="tmSaveBuild">Save Build</button>
                    </aside>
                </div>
            </div>

            <!-- PRODUCT PICKER VIEW (same frame, no redirect) -->
            <div class="tm-picker" id="tmPickerView" hidden>
                <div class="tm-picker-toolbar">
                    <div>
                        <h3 class="tm-picker-title" id="tmPickerTitle">Select Component</h3>
                        <p class="tm-picker-sub" id="tmPickerSub">Choose a product for your build.</p>
                    </div>
                    <div class="tm-picker-search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="search" id="tmPickerSearch" placeholder="Search products…" autocomplete="off">
                    </div>
                    <button type="button" class="tm-back-btn" id="tmPickerBack">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Build
                    </button>
                </div>
                <div class="tm-product-grid" id="tmProductGrid"></div>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script>window.EP_CSRF = ' . json_encode($epCsrf) . ';</script>' . <<<'SCRIPTS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('added') && typeof IAS_UI !== 'undefined') {
        IAS_UI.alert('Added to cart!', 'success');
    }

    const tmModal = document.getElementById('epTechMatchModal');
    const tmClose = document.getElementById('epTechMatchClose');
    const tmBackdrop = document.getElementById('epTechMatchBackdrop');

    function openTechMatchModal() {
        if (!tmModal) return;
        tmModal.hidden = false;
        tmModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ep-tm-modal-open');
        if (typeof window.epTechMatchLoadPending === 'function') window.epTechMatchLoadPending();
        if (typeof window.epTechMatchRefresh === 'function') window.epTechMatchRefresh();
        if (tmClose) tmClose.focus();
    }
    function closeTechMatchModal() {
        if (!tmModal) return;
        tmModal.hidden = true;
        tmModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ep-tm-modal-open');
    }

    window.epOpenTechMatch = openTechMatchModal;
    window.epCloseTechMatch = closeTechMatchModal;

    if (tmClose) tmClose.addEventListener('click', closeTechMatchModal);
    if (tmBackdrop) tmBackdrop.addEventListener('click', closeTechMatchModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && tmModal && !tmModal.hidden) {
            var nameOverlay = document.getElementById('tmSaveNameOverlay');
            if (nameOverlay) return;
            e.preventDefault();
            closeTechMatchModal();
        }
    });

    if (window.location.hash === '#ep-tech-match') {
        openTechMatchModal();
    }
});
</script>
<script src="tech_match.js" defer></script>
<script src="primo.js" defer></script>
SCRIPTS;
?>

<?php include __DIR__ . '/primo.php'; ?>
<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
