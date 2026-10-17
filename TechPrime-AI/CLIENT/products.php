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
        if (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'csrf']);
            exit;
        }
        die('Invalid CSRF token.');
    }

    $product_id = (int)($_POST['product_id'] ?? 0);
    $returnTo = $_POST['return_to'] ?? 'index.php';
    if (!preg_match('#^[a-zA-Z0-9_\-./?=&%+]+$#', $returnTo)) {
        $returnTo = 'index.php';
    }

    $quantity = max(1, min(99, (int)($_POST['quantity'] ?? 1)));

    // Buy Now: stage for checkout only — do NOT add to cart.
    if (isset($_POST['buy_now'])) {
        if (!ep_set_buy_now($db, $product_id, $quantity)) {
            header('Location: products.php?alert=error');
            exit;
        }
        header('Location: checkout.php');
        exit;
    }

    $added = ep_add_product_to_cart($db, $product_id, $quantity);
    if (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $added,
            'cart' => $added ? ep_get_cart_preview($db) : null,
        ]);
        exit;
    }

    if (!$added) {
        header('Location: products.php?alert=error');
        exit;
    }

    header('Location: ' . $returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'added=1');
    exit;
}

$viewId = (int)($_GET['id'] ?? 0);
// Any ?id= shows the detail page; an invalid id (abc, -1) gets its 404 "not available" view.
if (isset($_GET['id'])) {
    require __DIR__ . '/product_detail.php';
    exit;
}

$displayProducts = [];
$productResult = $db->query(
    "SELECT p.*, u.name AS seller_name FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE " . ias_client_product_list_sql_condition('p') . "
     ORDER BY p.id DESC"
);
$displayProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetchAll(PDO::FETCH_ASSOC) : []
);

$isLoggedIn           = isset($_SESSION['user_id']);
$activePage           = 'brands';
$pageTitle            = 'All Products';
$searchQuery          = '';
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<?php $listCsrf = generateCsrfToken(); ?>
<main class="ep-main sf-main" id="main">
    <?php echo sf_page_hero([
        'crumbs' => [['Home', 'index.php'], ['Shop', 'shop.php'], ['All products', '']],
        'eyebrow' => 'Catalog',
        'title' => 'All products',
        'sub' => number_format(count($displayProducts)) . ' products from EasyPC One Oasis. Prices include VAT.',
        'actions' => '<a href="shop.php" class="sf-btn sf-btn-outline"><i class="fas fa-sliders-h" aria-hidden="true"></i> Filter in Shop</a>',
    ]); ?>

    <div class="sf-wrap sf-body">
        <?php if (!empty($displayProducts)): ?>
            <ul class="sf-grid">
                <?php foreach ($displayProducts as $p): ?>
                    <li><?php echo sf_product_card($p, 'products.php', $listCsrf); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <?php echo sf_empty_state('fa-box-open', 'No products found',
                'Check back soon. New stock is added regularly.',
                '<a href="index.php" class="sf-btn sf-btn-primary">Back to home</a>'); ?>
        <?php endif; ?>
    </div>
</main>

<?php ias_alert_footer(); ?>
<?php include __DIR__ . '/ep_footer.php'; ?>