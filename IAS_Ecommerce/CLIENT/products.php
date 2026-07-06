<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();

// --- LOGIC: Handle Add to Cart ---
if (isset($_POST['add_to_cart'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
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

    // Initialize session cart if not exists
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]++;
    } else {
        $_SESSION['cart'][$product_id] = 1;
    }

    // Sync with database if logged in
    if (isset($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];

        $chk = $db->prepare("SELECT id FROM cart WHERE user_id = ? AND product_id = ?");
        $chk->bind_param("ii", $uid, $product_id);
        $chk->execute();

        if ($chk->get_result()->num_rows > 0) {
            // FIX: Use prepared statement instead of raw query
            $stmt = $db->prepare("UPDATE cart SET quantity = quantity + 1 WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $uid, $product_id);
            $stmt->execute();
        } else {
            // FIX: Use prepared statement instead of raw query
            $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1)");
            $stmt->bind_param("ii", $uid, $product_id);
            $stmt->execute();
        }
    }

    // REDIRECT back to index.php so user doesn't see this page logic
    header('Location: index.php?added=1');
    exit;
}

// Fetch all products to display if user visits this page directly
$productResult = $db->query(
    "SELECT p.*, u.name AS seller_name FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE " . ias_client_product_list_sql_condition('p') . "
     ORDER BY p.id DESC"
);
$displayProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : []
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products | IAS Marketplace</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body>

<main class="category-page-main">
    <div class="page-header-row">
        <button class="back-home-btn" onclick="location.href='index.php'">← Back to Home</button>
        <h1>All Products</h1>
        <div></div>
    </div>

    <div class="products-grid">
        <?php if (!empty($displayProducts)): ?>
            <?php foreach ($displayProducts as $p): ?>
            <div class="product-card">
                <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="product-img" alt="">
                <div class="product-body">
                    <div class="product-name"><?php echo htmlspecialchars($p['name']); ?></div>
                    <div class="seller-name">By: <?php echo htmlspecialchars($p['seller_name']); ?></div>
                    <div class="product-price">₱<?php echo number_format($p['price'], 2); ?></div>

                    <form method="POST">
                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <button type="submit" name="add_to_cart" class="add-btn">Add to Cart</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align: center; grid-column: 1/-1; color: #999;">No products found.</p>
        <?php endif; ?>
    </div>
</div>

<footer>© 2026 IAS Marketplace. All Rights Reserved.</footer>
<?php ias_alert_footer(); ?>
</body>
</html>