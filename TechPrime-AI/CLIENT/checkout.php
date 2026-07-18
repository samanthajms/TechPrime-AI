<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();

if (empty($_SESSION['user_id']) || empty($_SESSION['cart'])) {
    header('Location: products.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$total = 0;
$items = [];

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
        die('Invalid CSRF token.');
    }

    $stmt = $db->prepare("INSERT INTO orders (user_id, total, status, shipping_address, customer_phone) VALUES (?, ?, 'to_ship', ?, ?)");
    $address = $_POST['address'];
    $phone   = $_POST['phone'];
    $stmt->bind_param('idss', $user_id, $total, $address, $phone);

    if ($stmt->execute()) {
        $order_id = $stmt->insert_id;

        foreach ($items as $item) {
            $stmt_item = $db->prepare('INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
            $stmt_item->bind_param('iiid', $order_id, $item['id'], $item['qty'], $item['price']);
            $stmt_item->execute();

            $qty = (int)$item['qty'];
            $pid = (int)$item['id'];
            $db->query("UPDATE products SET stock = stock - $qty WHERE id = $pid");
        }

        unset($_SESSION['cart']);

        $stmt_del = $db->prepare('DELETE FROM cart WHERE user_id = ?');
        $stmt_del->bind_param('i', $user_id);
        $stmt_del->execute();

        header("Location: order_success.php?order_id=$order_id&total=$total");
        exit;
    }
}

$isLoggedIn = true;
$activePage = '';
$pageTitle  = 'Checkout';
$bodyClass  = 'ep-checkout-layout';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-header-row">
        <a href="cart.php" class="ep-back-link"><i class="fas fa-arrow-left"></i> Back to Cart</a>
        <h2 class="ep-page-title">Secure Checkout</h2>
    </div>

    <form method="POST" class="ep-checkout-grid">
        <div class="ep-panel">
            <h3 class="ep-panel-title"><i class="fas fa-map-marker-alt"></i> Shipping Details</h3>
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="total" value="<?php echo htmlspecialchars($total, ENT_QUOTES); ?>">

            <?php if (!empty($_GET['error'])): ?>
                <div class="ep-info-note" style="color:#c0392b; border-color:#c0392b;">
                    <?php
                    $epPayErrors = [
                        'payment_failed' => 'We couldn\'t start the online payment. Please try again.',
                        'not_paid'       => 'Your payment was not confirmed as paid. Please try again.',
                        'cancelled'      => 'The payment was cancelled.',
                    ];
                    echo h($epPayErrors[$_GET['error']] ?? 'Something went wrong with your payment. Please try again.');
                    ?>
                </div>
            <?php endif; ?>

            <label class="ep-form-label" for="checkoutPhone">Phone Number</label>
            <input class="ep-form-control" id="checkoutPhone" type="text" name="phone" placeholder="09123456789" required>

            <label class="ep-form-label" for="checkoutAddress">Delivery Address</label>
            <textarea class="ep-form-control" id="checkoutAddress" name="address" rows="4" placeholder="House No., Street, City..." required></textarea>

            <div class="ep-info-note">Choose your payment method on the right.</div>
        </div>

        <div class="ep-panel">
            <h3 class="ep-panel-title"><i class="fas fa-shopping-bag"></i> Order Summary</h3>
            <?php foreach ($items as $item): ?>
                <div class="ep-order-line">
                    <span><strong><?php echo h($item['name']); ?></strong> (×<?php echo (int)$item['qty']; ?>)</span>
                    <span>₱<?php echo number_format($item['subtotal'], 2); ?></span>
                </div>
            <?php endforeach; ?>

            <div class="ep-order-total">
                <span>Total Amount</span>
                <strong>₱<?php echo number_format($total, 2); ?></strong>
            </div>

            <div class="ep-checkout-actions">
                <button type="submit" name="place_order" class="ep-btn ep-btn-primary ep-btn-block">Cash on Delivery</button>
                <button type="submit" name="pay_online" formaction="../backend/api/create_payment.php" class="ep-btn ep-btn-secondary ep-btn-block">Pay Online</button>
            </div>
        </div>
    </form>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>
