<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_shop_taxonomy.php';

$db = getDbConnection();
checkSessionTimeout();
ep_ensure_session_wishlist($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_wishlist'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $pid = (int)($_POST['product_id'] ?? 0);
    ep_toggle_wishlist($db, $pid);
    $returnTo = $_POST['return_to'] ?? 'wishlist.php';
    if (!preg_match('#^[a-zA-Z0-9_\-./?=&%]+$#', $returnTo)) {
        $returnTo = 'wishlist.php';
    }
    header('Location: ' . $returnTo);
    exit;
}

$ids = ep_wishlist_ids();
$items = [];
if (!empty($ids)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare(
        "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url, u.name AS seller_name
         FROM products p
         INNER JOIN users u ON p.seller_id = u.id
         WHERE p.id IN ({$ph}) AND " . ias_client_product_list_sql_condition('p')
    );
    $st->execute($ids);
    $items = ep_shop_attach_taxonomy($st->fetchAll(PDO::FETCH_ASSOC));
}

$isLoggedIn = isset($_SESSION['user_id']);
$activePage = 'wishlist';
$pageTitle = 'Wishlist';
$searchQuery = '';
$bodyClass = 'ep-shop-page-body';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner ep-shop-page">
        <div class="ep-shop-header">
            <div>
                <p class="ep-shop-kicker">Saved items</p>
                <h2 class="ep-page-title">Wishlist</h2>
                <p class="ep-shop-subtitle">Products you saved stay here so you can return to them later.</p>
            </div>
            <p class="ep-shop-count"><?php echo count($items); ?> item<?php echo count($items) === 1 ? '' : 's'; ?></p>
        </div>

        <?php if (!empty($items)): ?>
            <div class="ep-products-grid ep-shop-grid">
                <?php foreach ($items as $p): ?>
                    <div class="ep-product-card ep-grid-card">
                        <div class="ep-shop-card-media">
                            <img src="<?php echo h(ias_client_product_image_url($p)); ?>"
                                 class="ep-product-img" alt="<?php echo h($p['name']); ?>"
                                 loading="lazy" decoding="async">
                        </div>
                        <div class="ep-shop-card-body">
                            <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                            <div class="ep-product-cat"><?php echo h($p['tax_sub_label'] ?: $p['tax_parent_label']); ?></div>
                            <div class="ep-product-price">₱<?php echo number_format((float)$p['price'], 2); ?></div>
                            <div class="ep-card-actions">
                                <form method="POST" action="products.php" class="ep-buy-form">
                                    <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="return_to" value="wishlist.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <button type="submit" name="add_to_cart" value="1" class="ep-cart-icon" title="Add to cart"><i class="fas fa-shopping-cart"></i></button>
                                    <button type="submit" name="buy_now" value="1" class="ep-buy-btn">BUY NOW</button>
                                </form>
                                <form method="POST" action="wishlist.php" class="ep-wish-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="return_to" value="wishlist.php">
                                    <button type="submit" name="toggle_wishlist" value="1" class="ep-wish-btn is-on" title="Remove from wishlist">
                                        <i class="fas fa-heart" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="ep-empty-state ep-shop-empty">
                <i class="far fa-heart" aria-hidden="true"></i>
                <h3>Your wishlist is empty</h3>
                <p>Browse Shop Now and tap the heart on a product to save it here.</p>
                <a href="shop.php" class="ep-btn ep-btn-primary">Browse products</a>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
