<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();

$query       = isset($_GET['q']) ? trim($_GET['q']) : '';
$searchQuery = $query;
$isLoggedIn  = isset($_SESSION['user_id']);
$activePage  = '';
$pageTitle   = 'Search Results';
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];

$displayProducts = [];
if (!empty($query)) {
    $searchTerm = '%' . $query . '%';
    $stmt = $db->prepare(
        "SELECT p.*, u.name as seller_name
         FROM products p
         JOIN users u ON p.seller_id = u.id
         WHERE (p.name LIKE ? OR p.description LIKE ?) AND " . ias_client_product_list_sql_condition('p') . "
         ORDER BY p.id DESC"
    );
    $stmt->bind_param('ss', $searchTerm, $searchTerm);
    $stmt->execute();
    $resultSet = $stmt->get_result();
    $displayProducts = ias_client_filter_products_for_display(
        $resultSet ? $resultSet->fetch_all(MYSQLI_ASSOC) : []
    );
}
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner">

        <div class="ep-page-header-row">
            <button class="ep-back-btn" onclick="history.back()">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <h2 class="ep-page-title">Search Results</h2>
        </div>

        <div class="ep-search-meta">
            <?php if (!empty($query)): ?>
                Showing results for "<span class="ep-highlight"><?php echo h($query); ?></span>"
                &mdash; <?php echo !empty($displayProducts) ? count($displayProducts) : 0; ?> item(s) found
            <?php else: ?>
                Please enter a search term above.
            <?php endif; ?>
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
            <?php elseif (!empty($query)): ?>
                <div class="ep-empty-state">
                    <i class="fas fa-search" style="font-size:48px;color:#ccc;margin-bottom:14px;"></i>
                    <h3>No products found for "<?php echo h($query); ?>"</h3>
                    <p>Try different keywords or browse our categories.</p>
                    <a href="index.php" class="ep-back-link">← Browse Categories</a>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>