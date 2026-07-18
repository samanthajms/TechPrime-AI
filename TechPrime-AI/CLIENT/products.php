<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();

require_once __DIR__ . '/../includes/client_helpers.php';

// ── Handle Add to Cart / Buy Now ───────────────────────────────────────────
if (isset($_POST['add_to_cart']) || isset($_POST['buy_now'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    $product_id = (int)($_POST['product_id'] ?? 0);
    $returnTo = $_POST['return_to'] ?? 'index.php';
    if (!preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $returnTo)) {
        $returnTo = 'index.php';
    }

    if (!ep_add_product_to_cart($db, $product_id, 1)) {
        header('Location: products.php?alert=error');
        exit;
    }

    if (isset($_POST['buy_now'])) {
        header('Location: checkout.php');
        exit;
    }

    header('Location: ' . $returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'added=1');
    exit;
}

// ── Fetch all products ────────────────────────────────────────────────────
$productResult = $db->query(
    "SELECT p.*, u.name AS seller_name FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE " . ias_client_product_list_sql_condition('p') . "
     ORDER BY p.id DESC"
);
$displayProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : []
);

$isLoggedIn           = isset($_SESSION['user_id']);
$activePage           = 'brands';
$pageTitle            = 'All Products';
$searchQuery          = '';
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner">

        <div class="ep-page-header-row">
            <a href="index.php" class="ep-back-link">← Back to Home</a>
            <h2 class="ep-page-title">All Products</h2>
        </div>

        <section class="ep-products-section">
            <?php if (!empty($displayProducts)): ?>
                <div class="ep-products-grid">
                    <?php foreach ($displayProducts as $p): ?>
                        <div class="ep-product-card ep-grid-card">
                            <img src="<?php echo h(ias_client_product_image_url($p)); ?>"
                                 class="ep-product-img" alt="<?php echo h($p['name']); ?>">
                            <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                            <div class="ep-product-cat">By: <?php echo h($p['seller_name']); ?></div>
                            <div class="ep-product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                            <div class="ep-card-actions">
                                <form method="POST" action="products.php" class="ep-buy-form">
                                    <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="return_to" value="products.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <button type="submit" name="add_to_cart" value="1" class="ep-cart-icon" title="Add to cart"><i class="fas fa-shopping-cart"></i></button>
                                    <button type="submit" name="buy_now" value="1" class="ep-buy-btn">BUY NOW</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="ep-empty-state">
                    <i class="fas fa-box-open" style="font-size:48px;color:#ccc;margin-bottom:14px;"></i>
                    <p>No products found.</p>
                    <a href="index.php" class="ep-back-link">← Back to Home</a>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<?php ias_alert_footer(); ?>
<?php include __DIR__ . '/ep_footer.php'; ?>