<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
checkSessionTimeout();

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

ep_ensure_session_cart($db);

if (empty($_SESSION['cart'])) {
    header('Location: products.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$total = 0;
$items = [];

$ids = array_map('intval', array_keys($_SESSION['cart']));
$ids_str = implode(',', $ids);

$res = $db->query("SELECT * FROM products WHERE id IN ($ids_str) AND COALESCE(stock, 0) > 0");
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
    $qty = (int)$_SESSION['cart'][$row['id']];
    $stock = (int)($row['stock'] ?? 0);
    if ($qty > $stock) {
        $qty = $stock;
        $_SESSION['cart'][$row['id']] = $qty;
    }
    if ($qty < 1) {
        continue;
    }
    $row['qty'] = $qty;
    $row['subtotal'] = $row['price'] * $qty;
    $total += $row['subtotal'];
    $items[] = $row;
}

if (empty($items)) {
    header('Location: cart.php?alert=stock');
    exit;
}

$checkoutError = '';

if (isset($_POST['place_order'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // One-time submit token — blocks double-click / refresh resubmit.
    $submitToken = (string)($_POST['checkout_token'] ?? '');
    $expected = (string)($_SESSION['checkout_token'] ?? '');
    if ($submitToken === '' || $expected === '' || !hash_equals($expected, $submitToken)) {
        header('Location: checkout.php?error=duplicate');
        exit;
    }
    unset($_SESSION['checkout_token']);

    $address = trim((string)($_POST['address'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    $orderItems = [];
    foreach ($items as $item) {
        $orderItems[] = [
            'id' => (int)$item['id'],
            'qty' => (int)$item['qty'],
            'price' => (float)$item['price'],
            'name' => (string)$item['name'],
        ];
    }

    $result = ep_place_cod_order($db, $user_id, $orderItems, (float)$total, $address, $phone);
    if (!empty($result['ok'])) {
        $order_id = (int)$result['order_id'];
        header("Location: order_success.php?order_id=$order_id&total=$total");
        exit;
    }

    if (($result['error'] ?? '') === 'stock') {
        header('Location: cart.php?alert=stock');
        exit;
    }
    $checkoutError = 'Could not place your order. Please try again.';
    $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
}

if (empty($_SESSION['checkout_token'])) {
    $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
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

    <form method="POST" class="ep-checkout-grid" id="epCheckoutForm">
        <div class="ep-panel">
            <h3 class="ep-panel-title"><i class="fas fa-map-marker-alt"></i> Shipping Details</h3>
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="checkout_token" value="<?php echo h($_SESSION['checkout_token']); ?>">
            <input type="hidden" name="total" value="<?php echo htmlspecialchars($total, ENT_QUOTES); ?>">

            <?php if ($checkoutError !== ''): ?>
                <div class="ep-info-note" style="color:#c0392b; border-color:#c0392b;">
                    <?php echo h($checkoutError); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['error'])): ?>
                <div class="ep-info-note" style="color:#c0392b; border-color:#c0392b;">
                    <?php
                    $epPayErrors = [
                        'payment_failed' => 'We couldn\'t start the online payment. Please try again.',
                        'not_paid'       => 'Your payment was not confirmed as paid. Please try again.',
                        'cancelled'      => 'The payment was cancelled.',
                        'duplicate'      => 'This order was already submitted. Check My Profile for your order status.',
                    ];
                    echo h($epPayErrors[$_GET['error']] ?? 'Something went wrong. Please try again.');
                    ?>
                </div>
            <?php endif; ?>

            <label class="ep-form-label" for="checkoutPhone">Phone Number</label>
            <input class="ep-form-control" id="checkoutPhone" type="text" name="phone" placeholder="09123456789" required
                   value="<?php echo h($_POST['phone'] ?? ''); ?>">

            <label class="ep-form-label" for="checkoutAddress">Delivery Address</label>
            <textarea class="ep-form-control" id="checkoutAddress" name="address" rows="4" placeholder="House No., Street, City..." required><?php echo h($_POST['address'] ?? ''); ?></textarea>

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
                <button type="submit" name="place_order" id="epPlaceOrderBtn" class="ep-btn ep-btn-primary ep-btn-block">Cash on Delivery</button>
                <button type="submit" name="pay_online" formaction="../backend/api/create_payment.php" class="ep-btn ep-btn-secondary ep-btn-block">Pay Online</button>
            </div>
        </div>
    </form>
</main>
<script>
(function () {
    var form = document.getElementById('epCheckoutForm');
    var btn = document.getElementById('epPlaceOrderBtn');
    if (!form || !btn) return;
    form.addEventListener('submit', function (e) {
        var submitter = e.submitter || document.activeElement;
        if (submitter && submitter.name === 'place_order') {
            if (btn.dataset.locked === '1') {
                e.preventDefault();
                return;
            }
            btn.dataset.locked = '1';
            btn.disabled = true;
            btn.textContent = 'Placing order…';
        }
    });
})();
</script>

<?php include __DIR__ . '/ep_footer.php'; ?>
