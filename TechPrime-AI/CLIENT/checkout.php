<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/address_helpers.php';

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
$selectedIds = array_values(array_filter(array_map('intval', (array)($_SESSION['cart_selected'] ?? []))));
if ($selectedIds) {
    $only = array_values(array_intersect($ids, $selectedIds));
    if ($only) {
        $ids = $only;
    }
}
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
// Address/phone problems from the Pay Online handler (backend/api/create_payment.php).
if (!empty($_SESSION['checkout_flash_error'])) {
    $checkoutError = (string)$_SESSION['checkout_flash_error'];
    unset($_SESSION['checkout_flash_error']);
}

$savedAddress = ep_user_address($db, $user_id);
$addressComplete = ep_address_is_complete($savedAddress);

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

    $phone = trim((string)($_POST['phone'] ?? ''));
    $resolved = ep_checkout_resolve_address($db, $user_id, $_POST);
    $phoneError = ep_phone_error($phone);

    if ($phoneError !== '') {
        $checkoutError = $phoneError;
    } elseif (!$resolved['ok']) {
        $checkoutError = $resolved['error'];
    } else {
        $orderItems = [];
        foreach ($items as $item) {
            $orderItems[] = [
                'id' => (int)$item['id'],
                'qty' => (int)$item['qty'],
                'price' => (float)$item['price'],
                'name' => (string)$item['name'],
            ];
        }

        $result = ep_place_cod_order($db, $user_id, $orderItems, (float)$total, $resolved['address'], $phone);
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
    }
    $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
}

// Form state: after a failed submit keep what the customer entered.
$posted = isset($_POST['place_order']);
$addressMode = $addressComplete
    ? (($posted && ($_POST['address_mode'] ?? '') === 'custom') ? 'custom' : 'saved')
    : 'custom';
$customAddress = $posted && $addressMode === 'custom'
    ? ep_address_from_input($_POST)['fields'] + $savedAddress
    : $savedAddress;
$phoneValue = $posted ? (string)($_POST['phone'] ?? '') : $savedAddress['phone'];

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
            <input class="ep-form-control" id="checkoutPhone" type="tel" name="phone" placeholder="09123456789" required
                   autocomplete="tel" maxlength="30" value="<?php echo h($phoneValue); ?>">

            <span class="ep-form-label">Deliver To</span>
            <?php if ($addressComplete): ?>
                <div class="ep-deliver-options" role="radiogroup" aria-label="Delivery address">
                    <label class="ep-deliver-option">
                        <input type="radio" name="address_mode" value="saved" <?php echo $addressMode === 'saved' ? 'checked' : ''; ?>>
                        <span class="ep-deliver-radio" aria-hidden="true"></span>
                        <span class="ep-deliver-body">
                            <strong><i class="fas fa-home"></i> My saved address</strong>
                            <span><?php echo h(ep_address_format($savedAddress)); ?></span>
                            <a href="user_dashboard.php?settings=1#delivery" class="ep-deliver-edit">Edit in profile</a>
                        </span>
                    </label>
                    <label class="ep-deliver-option">
                        <input type="radio" name="address_mode" value="custom" <?php echo $addressMode === 'custom' ? 'checked' : ''; ?>>
                        <span class="ep-deliver-radio" aria-hidden="true"></span>
                        <span class="ep-deliver-body">
                            <strong><i class="fas fa-map-signs"></i> Deliver to a different address</strong>
                            <span>For this order only. Your saved address won't change.</span>
                        </span>
                    </label>
                </div>
            <?php else: ?>
                <input type="hidden" name="address_mode" value="custom">
                <div class="ep-address-legacy">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>
                        Please enter your complete delivery address.
                        <a href="user_dashboard.php?settings=1#delivery">Save it to your profile</a> so it's filled in automatically next time.
                    </span>
                </div>
            <?php endif; ?>

            <fieldset class="ep-deliver-custom" id="epCustomAddress" <?php echo $addressMode === 'custom' ? '' : 'hidden disabled'; ?>>
                <legend class="sr-only">Delivery address for this order</legend>
                <?php ep_render_address_fields($customAddress, '../assets/data/psgc', 'checkoutAddr_'); ?>
            </fieldset>

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
<script src="../assets/js/ph-address.js"></script>
<script>
(function () {
    // Saved vs. one-off address. The one-off fields sit in a <fieldset>; disabling it
    // means they are neither validated nor submitted while the saved address is used.
    var custom = document.getElementById('epCustomAddress');
    var radios = document.querySelectorAll('input[name=address_mode][type=radio]');
    radios.forEach(function (r) {
        r.addEventListener('change', function () {
            var useCustom = r.value === 'custom' && r.checked;
            custom.hidden = !useCustom;
            custom.disabled = !useCustom;
            if (useCustom) {
                var first = custom.querySelector('input');
                if (first) first.focus();
            }
        });
    });
})();

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
