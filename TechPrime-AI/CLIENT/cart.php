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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart | EasyPC</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="ep-body ep-cart-layout">
    <header class="top-header ep-header full-width">
        <div class="logo ep-logo" onclick="location.href='index.php'" style="cursor:pointer;">
            <img src="../assets/easypc-logo-transparent.png" alt="EasyPC" class="ep-logo-img">
        </div>
        <div class="search-wrap">
            <form action="search.php" method="GET">
                <input name="q" type="text" placeholder="Search products...">
                <button type="submit" class="search-icon"><i class="fas fa-search"></i></button>
            </form>
        </div>
        <div class="header-icons">
            <button class="icon-badge-btn" title="Wishlist" onclick="this.classList.toggle('active')">
                <i class="far fa-heart"></i>
            </button>
            <button id="notifBtn" class="icon-badge-btn" title="Notifications"
                    onclick="document.getElementById('notificationsPanel').classList.toggle('hidden')">
                <i class="far fa-bell"></i>
            </button>
            <button id="cartBtn" class="icon-badge-btn" title="Cart" onclick="location.href='cart.php'">
                <i class="fas fa-shopping-bag"></i>
                <?php if (!empty($_SESSION['cart'])): ?>
                    <span class="badge"><?php echo count($_SESSION['cart']); ?></span>
                <?php endif; ?>
            </button>
            <button id="profileBtn" class="icon-badge-btn profile-outline-btn ep-account-btn"
                    onclick="location.href='<?php echo $isLoggedIn ? 'user_dashboard.php' : '../login.php'; ?>'">
                <i class="far fa-user"></i>
                <span class="ep-account-label">
                    <?php if ($isLoggedIn): ?>My Account<?php else: ?>Login /<br>Sign In<?php endif; ?>
                </span>
            </button>
        </div>
        <div id="notificationsPanel" class="notifications-panel hidden">
            <strong>Notifications</strong>
            <ul>
                <li>Welcome to EasyPC, <?php echo $userName; ?>!</li>
                <li>Track your orders anytime from your dashboard.</li>
            </ul>
        </div>
    </header>

    <section class="ep-nav-bar full-width">
        <nav class="ep-nav">
            <a href="index.php" class="ep-nav-link">HOME</a>
            <a href="category.php?type=Desktop" class="ep-nav-link">DESKTOP</a>
            <a href="category.php?type=Laptops" class="ep-nav-link">LAPTOP</a>
            <a href="messages.php" class="ep-nav-link">EASYFIX</a>
            <a href="products.php" class="ep-nav-link">BRANDS</a>
        </nav>
    </section>

    <main class="ep-main ep-cart-main">
        <div class="ep-page-header-row">
            <button class="back-home-btn" onclick="location.href='index.php'">← Back to Home</button>
            <h2 class="ep-page-title">My Cart</h2>
        </div>

        <section class="profile-card ep-cart-panel">
            <?php if (empty($cart_items)): ?>
                <div style="text-align: center; padding: 40px;">
                    <h3 style="color: #999; margin-bottom: 20px;">Your cart is empty</h3>
                    <button class="primary-btn" onclick="location.href='index.php'" style="width: auto; padding: 10px 30px;">Start Shopping</button>
                </div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

                    <div id="cartItems">
                        <?php foreach ($cart_items as $item): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #eee;">
                            <div style="flex: 1;">
                                <div style="font-weight: 700; font-size: 16px;"><?php echo h($item['name']); ?></div>
                                <div style="color: #0998a8; font-weight: 600;">₱<?php echo number_format($item['price'], 2); ?></div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 20px;">
                                <input type="number" name="qty[<?php echo $item['id']; ?>]" value="<?php echo $item['qty']; ?>" min="1"
                                       style="width: 55px; padding: 8px; border: 1px solid #ddd; border-radius: 8px; text-align: center;">
                                <div style="font-weight: 800; width: 100px; text-align: right;">₱<?php echo number_format($item['subtotal'], 2); ?></div>
                                <a href="cart.php?remove=<?php echo $item['id']; ?>" style="color: #ff4d4d; text-decoration: none; font-size: 20px; padding-left: 10px;">&times;</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="cart-summary" style="margin-top: 20px; border-top: 2px solid #f4f6f9; padding-top: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <span style="font-size: 14px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Total Amount</span>
                            <strong style="color: #0998a8; font-size: 24px;">₱<?php echo number_format($total, 2); ?></strong>
                        </div>

                        <div style="display: flex; gap: 15px;">
                            <button type="submit" name="update_cart" class="primary-btn" style="background: #f1f3f5; color: #495057; flex: 1;">Update Quantities</button>
                            <button type="button" class="primary-btn" onclick="location.href='checkout.php'" style="flex: 2;">Checkout Now</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>

    <footer class="full-width">© 2026 IAS. All Rights Reserved.</footer>

<?php ias_alert_footer(); ?>
<?php include __DIR__ . '/ep_footer.php'; ?>
