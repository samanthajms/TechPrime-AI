<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
if (is_file(__DIR__ . '/../includes/client_shop_taxonomy.php')) {
    require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
}

$query       = isset($_GET['q']) ? trim($_GET['q']) : '';
$searchQuery = $query;
$isLoggedIn  = isset($_SESSION['user_id']);
$activePage  = '';
$pageTitle   = 'Search Results';
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];

$displayProducts = [];
if (!empty($query)) {
    $searchTerm = '%' . $query . '%';
    $vis = ias_client_product_list_sql_condition('p');
    $stmt = $db->prepare(
        "SELECT p.*, u.name as seller_name
         FROM products p
         JOIN users u ON p.seller_id = u.id
         WHERE (p.name LIKE ? OR p.description LIKE ?) AND {$vis}
         ORDER BY p.id DESC
         LIMIT 48"
    );
    $stmt->execute([$searchTerm, $searchTerm]);
    $displayProducts = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if (function_exists('ep_shop_classify_product') && count($displayProducts) < 48) {
        $qLower = mb_strtolower($query);
        $seen = [];
        foreach ($displayProducts as $row) {
            $seen[(int)$row['id']] = true;
        }
        $more = $db->query(
            "SELECT p.*, u.name as seller_name
             FROM products p
             JOIN users u ON p.seller_id = u.id
             WHERE {$vis}
             ORDER BY p.id DESC
             LIMIT 400"
        );
        if ($more) {
            while ($p = $more->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int)$p['id'];
                if (isset($seen[$pid])) {
                    continue;
                }
                $tax = ep_shop_classify_product($p);
                $labels = [
                    (string)($tax['parent_label'] ?? ''),
                    (string)($tax['sub_label'] ?? ''),
                ];
                $hit = false;
                foreach ($labels as $label) {
                    $ll = mb_strtolower(trim($label));
                    if ($ll !== '' && ($ll === $qLower || (mb_strlen($qLower) >= 4 && str_starts_with($ll, $qLower)))) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                $seen[$pid] = true;
                $displayProducts[] = $p;
                if (count($displayProducts) >= 48) {
                    break;
                }
            }
        }
    }
}
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner">

        <div class="ep-page-header-row">
            <a href="index.php" class="ep-back-btn" data-ep-back>
                <i class="fas fa-arrow-left"></i> Back
            </a>
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
                            <a href="products.php?id=<?php echo (int)$p['id']; ?>">
                            <img src="<?php echo h(ias_client_product_image_url($p)); ?>"
                                 class="ep-product-img" alt="<?php echo h($p['name']); ?>"
                                 loading="lazy" decoding="async">
                            </a>
                            <a class="ep-product-name" href="products.php?id=<?php echo (int)$p['id']; ?>"><?php echo h($p['name']); ?></a>
                            <div class="ep-product-cat">Store: <?php echo h($p['seller_name']); ?></div>
                            <div class="ep-product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                            <div class="ep-card-actions">
                                <form action="products.php" method="POST" class="ep-buy-form">
                                    <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo h('search.php?q=' . rawurlencode($query)); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                    <button type="submit" name="add_to_cart" value="1" class="ep-cart-icon" title="Add to cart"><i class="fas fa-shopping-cart"></i></button>
                                    <button type="submit" name="buy_now" value="1" class="ep-buy-btn">BUY NOW</button>
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