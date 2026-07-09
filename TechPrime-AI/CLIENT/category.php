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

$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];

$stmt = $db->prepare(
    "SELECT p.*, u.name as seller_name
     FROM products p
     JOIN users u ON p.seller_id = u.id
     WHERE p.category = ? AND " . ias_client_product_list_sql_condition('p') . "
     ORDER BY p.id DESC"
);
$stmt->bind_param('s', $type);
$stmt->execute();
$productResult   = $stmt->get_result();
$displayProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : []
);
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner">

        <div class="ep-page-header-row">
            <button class="ep-back-btn" onclick="location.href='index.php'">
                <i class="fas fa-arrow-left"></i> Back to Home
            </button>
            <h2 class="ep-page-title"><?php echo h($type); ?></h2>
        </div>

        <section class="ep-products-section">
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
                                    <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="add_to_cart" value="1">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <span class="ep-cart-icon"><i class="fas fa-shopping-cart"></i></span>
                                    <button type="submit" class="ep-buy-btn">BUY NOW</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="ep-empty-state">
                    <i class="fas fa-box-open" style="font-size:48px;color:#ccc;margin-bottom:14px;"></i>
                    <h3>No products found in <?php echo h($type); ?>.</h3>
                    <p>Check back later for new arrivals!</p>
                    <a href="index.php" class="ep-back-link">← Back to Home</a>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>