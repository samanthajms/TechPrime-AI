<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();

// ── Handle Add to Cart ────────────────────────────────────────────────────
if (isset($_POST['add_to_cart'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    $product_id = (int)$_POST['product_id'];
    $chk = $db->prepare(
        'SELECT p.* FROM products p WHERE p.id = ? AND ' . ias_client_product_list_sql_condition('p') . ' LIMIT 1'
    );
    $chk->bind_param('i', $product_id);
    $chk->execute();
    $productRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$productRow || ias_client_product_image_url($productRow) === '') {
        header('Location: products.php?alert=error');
        exit;
    }

    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]++;
    } else {
        $_SESSION['cart'][$product_id] = 1;
    }

    if (isset($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        $chk = $db->prepare('SELECT id FROM cart WHERE user_id = ? AND product_id = ?');
        $chk->bind_param('ii', $uid, $product_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $stmt = $db->prepare('UPDATE cart SET quantity = quantity + 1 WHERE user_id = ? AND product_id = ?');
            $stmt->bind_param('ii', $uid, $product_id);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1)');
            $stmt->bind_param('ii', $uid, $product_id);
            $stmt->execute();
        }
    }

    header('Location: index.php?added=1');
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
                                <button type="button" class="ep-heart-btn"
                                        onclick="this.classList.toggle('active')" aria-label="Save">
                                    <i class="far fa-heart"></i>
                                </button>
                                <form method="POST" class="ep-buy-form">
                                    <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <span class="ep-cart-icon"><i class="fas fa-shopping-cart"></i></span>
                                    <button type="submit" name="add_to_cart" class="ep-buy-btn">BUY NOW</button>
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