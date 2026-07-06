<?php
session_start();
// Security and Database connection includes
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();

// 1. Get the category from the URL (e.g., category.php?type=Laptops)
// If no type is provided, default to Laptops
$type = isset($_GET['type']) ? $_GET['type'] : 'Laptops';

// 2. Fetch products only for this specific category from the database
$stmt = $db->prepare("SELECT p.*, u.name as seller_name 
                      FROM products p 
                      JOIN users u ON p.seller_id = u.id 
                      WHERE p.category = ? AND " . ias_client_product_list_sql_condition('p') . "
                      ORDER BY p.id DESC");
$stmt->bind_param("s", $type);
$stmt->execute();
$productResult = $stmt->get_result();
$displayProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : []
);

// Check if user is logged in for the header profile icon logic
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($type); ?> - IAS Products</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body>
    <header class="top-header full-width">
        <div class="logo" onclick="location.href='index.php'">IAS</div>
        <div class="search-wrap">
            <input type="text" placeholder="Search in <?php echo h($type); ?>...">
            <span class="search-icon">⌕</span>
        </div>
        <div class="header-icons">
            <button class="icon-badge-btn" onclick="location.href='cart.php'">
                <span class="icon-main">🛒</span>
                <?php if(isset($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
                    <span class="badge"><?php echo count($_SESSION['cart']); ?></span>
                <?php endif; ?>
            </button>
            <button class="icon-badge-btn profile-outline-btn" onclick="location.href='<?php echo $isLoggedIn ? 'user_dashboard.php' : '../login.html'; ?>'">
                <span class="icon-main">👤</span>
            </button>
        </div>
    </header>

    <main id="categoryRoot" class="category-page-main" data-category="<?php echo h($type); ?>">
        <div class="page-header-row">
            <button class="back-home-btn" onclick="location.href='index.php'">← Back to Home</button>
            <h2><?php echo h($type); ?></h2>
        </div>

        <section class="discover-section">
            <div class="products-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px;">
                <?php if (!empty($displayProducts)): ?>
                    <?php foreach ($displayProducts as $p): ?>
                        <div class="product-card">
                            <div>
                                <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="product-img" alt="">
                                <div class="product-name"><?php echo h($p['name']); ?></div>
                                <div class="seller-info">Store: <?php echo h($p['seller_name']); ?></div>
                                <div class="product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                            </div>
                            
                            <form action="products.php" method="POST">
                                <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                <input type="hidden" name="add_to_cart" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <button type="submit" class="add-btn">Add to Cart</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>No products found in <?php echo h($type); ?>.</h3>
                        <p>Check back later for new arrivals!</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="full-width">© 2026 IAS. All Rights Reserved.</footer>
</body>
</html>