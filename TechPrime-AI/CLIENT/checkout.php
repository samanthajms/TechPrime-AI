<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();

// Redirect if not logged in or cart is empty
if (empty($_SESSION['user_id']) || empty($_SESSION['cart'])) {
    header('Location: products.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$total = 0;
$items = [];

// FIX: All IDs already cast to int — safe to use in IN()
$ids = array_map('intval', array_keys($_SESSION['cart']));
$ids_str = implode(',', $ids);

$res = $db->query("SELECT * FROM products WHERE id IN ($ids_str) AND COALESCE(stock, 0) > 0");
while ($row = $res->fetch_assoc()) {
    $row['qty'] = (int)$_SESSION['cart'][$row['id']];
    $row['subtotal'] = $row['price'] * $row['qty'];
    $total += $row['subtotal'];
    $items[] = $row;
}

if (empty($items)) {
    header('Location: cart.php');
    exit;
}

if (isset($_POST['place_order'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    // FIX: Use prepared statement instead of real_escape_string
    $stmt = $db->prepare("INSERT INTO orders (user_id, total, status, shipping_address, customer_phone) VALUES (?, ?, 'to_ship', ?, ?)");
    $address = $_POST['address'];
    $phone   = $_POST['phone'];
    $stmt->bind_param("idss", $user_id, $total, $address, $phone);

    if ($stmt->execute()) {
        $order_id = $stmt->insert_id;

        foreach ($items as $item) {
            $stmt_item = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt_item->bind_param("iiid", $order_id, $item['id'], $item['qty'], $item['price']);
            $stmt_item->execute();

            // FIX: Cast to int before using in raw query
            $qty = (int)$item['qty'];
            $pid = (int)$item['id'];
            $db->query("UPDATE products SET stock = stock - $qty WHERE id = $pid");
        }

        unset($_SESSION['cart']);

        // FIX: Use prepared statement
        $stmt_del = $db->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt_del->bind_param("i", $user_id);
        $stmt_del->execute();

        header("Location: order_success.php?order_id=$order_id&total=$total");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Secure Checkout | IAS</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<main class="category-page-main">
    <div class="page-header-row">
        <h1>Checkout</h1>
        <button type="button" class="back-home-btn" onclick="location.href='cart.php'">← Back to Cart</button>
    </div>

    <form method="POST" class="grid-2">
        <div class="page-card">
            <div class="card-title">📍 Shipping Details</div>
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

            <label class="auth-label">Phone Number</label>
            <input class="form-control" type="text" name="phone" placeholder="09123456789" required>

            <label class="auth-label">Delivery Address</label>
            <textarea class="form-control" name="address" placeholder="House No., Street, City..." required style="min-height: 100px;"></textarea>

            <div class="info-note">Choose your payment method below.</div>
        </div>

        <div class="page-card">
            <div class="card-title">🛍️ Order Summary</div>
            <div class="order-summary">
                <?php foreach ($items as $item): ?>
                <div class="order-summary item">
                    <span><b><?php echo h($item['name']); ?></b> (x<?php echo $item['qty']; ?>)</span>
                    <span>₱<?php echo number_format($item['subtotal'], 2); ?></span>
                </div>
                <?php endforeach; ?>

                <div class="order-summary total-box">
                    <span style="font-weight:700; color:#888;">Total Amount</span>
                    <span style="font-size:24px; font-weight:800; color:var(--ias-teal);">₱<?php echo number_format($total, 2); ?></span>
                </div>
            </div>

            <input type="hidden" name="total" value="<?php echo $total; ?>">

            <button type="submit" name="place_order" class="primary-btn" style="margin-bottom:10px;">
                💵 Cash on Delivery
            </button>

            <button type="submit" name="pay_online" formaction="../backend/api/create_payment.php" class="primary-btn" style="background:#5046e5;">
                💳 Pay Online (GCash / Card)
            </button>
        </div>
    </form>
</main>
</body>
</html>
