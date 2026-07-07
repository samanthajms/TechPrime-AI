<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();

// 1. Get the search query from the URL (from index.php form)
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

$displayProducts = [];
if (!empty($query)) {
    // 2. Search both product name AND description using LIKE
    // The % symbols allow for partial matches (e.g., "Lap" matches "Laptop")
    $searchTerm = "%" . $query . "%";
    
    $stmt = $db->prepare("SELECT p.*, u.name as seller_name 
                          FROM products p 
                          JOIN users u ON p.seller_id = u.id 
                          WHERE (p.name LIKE ? OR p.description LIKE ?) AND " . ias_client_product_list_sql_condition('p') . "
                          ORDER BY p.id DESC");
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $resultSet = $stmt->get_result();
    $displayProducts = ias_client_filter_products_for_display(
        $resultSet ? $resultSet->fetch_all(MYSQLI_ASSOC) : []
    );
}

$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results for "<?php echo h($query); ?>" - IAS</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body>
    <header class="top-header full-width">
        <div class="logo" onclick="location.href='index.php'">IAS</div>
        <div class="search-wrap">
            <form action="search.php" method="GET">
                <input name="q" type="text" placeholder="Search again..." value="<?php echo h($query); ?>">
                <button type="submit" class="search-icon">⌕</button>
            </form>
        </div>
        <div class="header-icons">
            <button class="icon-badge-btn" onclick="location.href='index.php'"><span class="icon-main">🏠</span></button>
            <button class="icon-badge-btn" onclick="location.href='cart.php'">
                <span class="icon-main">🛒</span>
                <?php if(!empty($_SESSION['cart'])): ?>
                    <span class="badge"><?php echo count($_SESSION['cart']); ?></span>
                <?php endif; ?>
            </button>
        </div>
    </header>

    <main class="category-page-main">
        <div class="page-header-row">
            <button class="back-home-btn" onclick="history.back()">← Back</button>
            <h2>Search Results</h2>
        </div>

        <div class="search-meta">
            <?php if (!empty($query)): ?>
                Showing results for "<span class="highlight"><?php echo h($query); ?></span>"
                (<?php echo !empty($displayProducts) ? count($displayProducts) : 0; ?> items found)
            <?php else: ?>
                Please enter a search term.
            <?php endif; ?>
        </div>

        <section class="results-section">
            <div class="products-grid">
                <?php if (!empty($displayProducts)): ?>
                    <?php foreach ($displayProducts as $p): ?>
                        <div class="product-card">
                            <div>
                                <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="product-img" alt="">
                                <div class="product-name"><?php echo h($p['name']); ?></div>
                                <div class="seller-tag">Store: <?php echo h($p['seller_name']); ?></div>
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
                <?php elseif(!empty($query)): ?>
                    <div class="empty-state">
                        <h3>No products found matching your search.</h3>
                        <p>Try different keywords or check out our categories.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="full-width">© 2026 IAS. All Rights Reserved.</footer>
</body>
</html>