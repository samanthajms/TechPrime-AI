<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();

// --- LOGIC: Handle cart updates (Quantities) ---
if (isset($_POST['update_cart'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    foreach ($_POST['qty'] as $id => $q) {
        // FIX: Cast both to int before using in queries
        $id  = (int)$id;
        $q   = (int)$q;
        $uid = (int)($_SESSION['user_id'] ?? 0);

        if ($q <= 0) {
            unset($_SESSION['cart'][$id]);
            if ($uid > 0) {
                // FIX: Use prepared statement
                $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("ii", $uid, $id);
                $stmt->execute();
            }
        } else {
            $_SESSION['cart'][$id] = $q;
            if ($uid > 0) {
                // FIX: Use prepared statement
                $stmt = $db->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("iii", $q, $uid, $id);
                $stmt->execute();
            }
        }
    }
    logActivity($db, $_SESSION['user_id'] ?? null, 'update_cart', "Cart quantities updated");
    header("Location: cart.php?updated=1");
    exit;
}

// --- LOGIC: Handle Removal ---
if (isset($_GET['remove'])) {
    $remove_id = (int)$_GET['remove'];
    unset($_SESSION['cart'][$remove_id]);
    if (isset($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        // FIX: Use prepared statement
        $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $uid, $remove_id);
        $stmt->execute();
    }
    logActivity($db, $_SESSION['user_id'] ?? null, 'remove_from_cart', "Product ID $remove_id removed from cart");
    header("Location: cart.php?removed=1");
    exit;
}

// --- DATA FETCHING ---
$cart_items = [];
$total = 0;
if (!empty($_SESSION['cart'])) {
    // FIX: All IDs cast to int — safe for IN()
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $ids_str = implode(',', $ids);
    $res = $db->query("SELECT * FROM products WHERE id IN ($ids_str) AND COALESCE(stock, 0) > 0");
    while ($row = $res->fetch_assoc()) {
        $row['qty'] = (int)$_SESSION['cart'][$row['id']];
        $row['subtotal'] = $row['price'] * $row['qty'];
        $total += $row['subtotal'];
        $cart_items[] = $row;
    }
}

$isLoggedIn           = isset($_SESSION['user_id']);
$userName             = $isLoggedIn ? h($_SESSION['name'] ?? 'Customer') : 'Guest';
$activePage           = 'cart';
$pageTitle            = 'My Cart';
$searchQuery          = '';
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];
$bodyClass            = 'ep-cart-layout';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main ep-cart-main">
        <div class="ep-page-header-row">
            <button class="back-home-btn" onclick="location.href='index.php'">← Back to Home</button>
            <h2 class="ep-page-title">My Cart</h2>
        </div>

        <section class="profile-card ep-cart-panel">
            <?php if (empty($cart_items)): ?>
                <div class="empty-state-message">
                    <h3>Your cart is empty</h3>
                    <button type="button" class="primary-btn" onclick="location.href='index.php'">Start Shopping</button>
                </div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                    <div id="cartItems">
                        <?php foreach ($cart_items as $item): ?>
                        <div class="cart-item-row">
                            <div class="cart-item-meta">
                                <div class="cart-item-title"><?php echo h($item['name']); ?></div>
                                <div class="cart-item-price">₱<?php echo number_format($item['price'], 2); ?></div>
                            </div>
                            <div class="cart-row-controls">
                                <input type="number" class="cart-qty-input" name="qty[<?php echo $item['id']; ?>]" value="<?php echo $item['qty']; ?>" min="1">
                                <div class="cart-item-subtotal">₱<?php echo number_format($item['subtotal'], 2); ?></div>
                                <a href="cart.php?remove=<?php echo $item['id']; ?>" class="cart-remove-link" title="Remove">&times;</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="cart-summary">
                        <div class="summary-row">
                            <span class="summary-label">Total Amount</span>
                            <strong class="summary-total">₱<?php echo number_format($total, 2); ?></strong>
                        </div>
                        <div class="cart-summary-actions">
                            <button type="submit" name="update_cart" class="primary-btn">Update Quantities</button>
                            <button type="button" class="primary-btn" onclick="location.href='checkout.php'">Checkout Now</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>

<?php ias_alert_footer(); ?>
<?php include __DIR__ . '/ep_footer.php'; ?>
