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

$user_id = (int)$_SESSION['user_id'];
$orderPlaced = isset($_GET['placed']) && (int)$_GET['placed'] === 1;
$placedOrderId = (int)($_GET['order_id'] ?? 0);

$buyNowMap = ep_buy_now_map();
$isBuyNow = $buyNowMap !== [];

// Source line items: Buy Now session (not cart) OR selected cart items.
$sourceMap = [];
if ($isBuyNow) {
    $sourceMap = $buyNowMap;
} elseif (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $selectedIds = array_values(array_filter(array_map('intval', (array)($_SESSION['cart_selected'] ?? []))));
    if ($selectedIds) {
        $only = array_values(array_intersect($ids, $selectedIds));
        if ($only) {
            $ids = $only;
        }
    }
    foreach ($ids as $pid) {
        $qty = (int)($_SESSION['cart'][$pid] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $sourceMap[$pid] = $qty;
        }
    }
}

$total = 0.0;
$items = [];
if ($sourceMap) {
    $ids_str = implode(',', array_map('intval', array_keys($sourceMap)));
    $res = $db->query("SELECT * FROM products WHERE id IN ($ids_str) AND COALESCE(stock, 0) > 0");
    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $qty = (int)($sourceMap[$pid] ?? 0);
        $stock = (int)($row['stock'] ?? 0);
        if ($qty > $stock) {
            $qty = $stock;
            if ($isBuyNow) {
                $_SESSION['buy_now'][$pid] = $qty;
            } else {
                $_SESSION['cart'][$pid] = $qty;
            }
        }
        if ($qty < 1) {
            continue;
        }
        $row['qty'] = $qty;
        $row['subtotal'] = (float)$row['price'] * $qty;
        $total += $row['subtotal'];
        $items[] = $row;
    }
}

// Empty checkout only allowed right after a successful place-order (success alert UI).
if (empty($items) && !$orderPlaced) {
    header('Location: ' . ($isBuyNow ? 'products.php' : 'cart.php'));
    exit;
}

$checkoutError = '';

$savedAddress = ep_user_address($db, $user_id);
$addressComplete = ep_address_is_complete($savedAddress);

// Add to Cart from checkout — for Buy Now, move staged items into the cart.
if (isset($_POST['add_to_cart_checkout']) && !$orderPlaced) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    if ($isBuyNow) {
        foreach ($buyNowMap as $pid => $qty) {
            ep_add_product_to_cart($db, (int)$pid, (int)$qty);
        }
        ep_clear_buy_now();
    }
    header('Location: cart.php?alert=cart_added');
    exit;
}

// place_order may arrive as the submit button OR as a hidden field (button is
// disabled on submit for UX, and disabled buttons are not posted by browsers).
$wantsPlaceOrder = !$orderPlaced && !empty($items) && (
    isset($_POST['place_order']) || (string)($_POST['checkout_action'] ?? '') === 'place_order'
);

if ($wantsPlaceOrder) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

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

        $fulfillment = (string)($resolved['fulfillment'] ?? 'delivery');
        if ($fulfillment !== 'pickup' && stripos($resolved['address'], 'Pick Up') === 0) {
            $fulfillment = 'pickup';
        }

        $result = ep_place_unpaid_order(
            $db,
            $user_id,
            $orderItems,
            (float)$total,
            $resolved['address'],
            $phone,
            $fulfillment
        );
        if (!empty($result['ok'])) {
            $order_id = (int)$result['order_id'];
            $verify = $db->prepare(
                "SELECT id FROM orders WHERE id = ? AND user_id = ? AND status = 'to_pay' LIMIT 1"
            );
            $verify->execute([$order_id, $user_id]);
            if ($order_id > 0 && $verify->fetch(PDO::FETCH_ASSOC)) {
                if ($isBuyNow) {
                    ep_clear_buy_now();
                }
                header('Location: checkout.php?placed=1&order_id=' . $order_id);
                exit;
            }
            $checkoutError = 'Order could not be confirmed. Please open Order Summary → To Pay.';
        } elseif (($result['error'] ?? '') === 'stock') {
            header('Location: ' . ($isBuyNow ? 'products.php?alert=error' : 'cart.php?alert=stock'));
            exit;
        } else {
            $checkoutError = 'Could not place your order. Please try again.';
        }
    }
    $_SESSION['checkout_token'] = bin2hex(random_bytes(16));
}

$posted = $wantsPlaceOrder || isset($_POST['place_order']);
$postedMode = (string)($_POST['address_mode'] ?? '');
$allowedModes = ['saved', 'custom', 'pickup'];
if ($posted && in_array($postedMode, $allowedModes, true)) {
    $addressMode = $postedMode;
} elseif ($addressComplete) {
    $addressMode = 'saved';
} else {
    $addressMode = 'custom';
}
if ($addressMode === 'saved' && !$addressComplete) {
    $addressMode = 'custom';
}

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
$showDeliveryNotice = in_array($addressMode, ['saved', 'custom'], true);
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-header-row">
        <?php if (!$orderPlaced): ?>
            <a href="<?php echo $isBuyNow ? 'shop.php' : 'cart.php'; ?>" class="ep-back-link"<?php echo $isBuyNow ? '' : ' data-ep-back'; ?>>
                <i class="fas fa-arrow-left"></i> <?php echo $isBuyNow ? 'Back to Shop' : 'Back to Cart'; ?>
            </a>
        <?php else: ?>
            <span class="ep-back-link" style="visibility:hidden;">&nbsp;</span>
        <?php endif; ?>
        <h2 class="ep-page-title">Secure Checkout</h2>
    </div>

    <?php if ($orderPlaced): ?>
        <div class="ep-place-success" id="epPlaceSuccess" role="alertdialog" aria-labelledby="epPlaceSuccessTitle">
            <button type="button" class="ep-place-success-close" id="epPlaceSuccessClose" aria-label="Close">&times;</button>
            <div class="ep-place-success-icon" aria-hidden="true"><i class="fas fa-check-circle"></i></div>
            <h3 class="ep-place-success-title" id="epPlaceSuccessTitle">Order placed successfully</h3>
            <p class="ep-place-success-msg">
                <?php if ($placedOrderId > 0): ?>
                    Your order <strong>#ORD-<?php echo (int)$placedOrderId; ?></strong> has been placed.
                <?php else: ?>
                    Your order has been placed.
                <?php endif; ?>
            </p>
            <div class="ep-place-success-actions">
                <a href="user_dashboard.php?status=to_pay" class="ep-btn ep-btn-primary">View Shipping Details</a>
                <a href="shop.php" class="ep-btn ep-btn-yellow">Shop More</a>
            </div>
        </div>
        <div class="ep-panel ep-checkout-empty" id="epCheckoutEmpty" hidden>
            <p class="ep-info-note" style="margin:0;">Checkout is empty.</p>
        </div>
    <?php else: ?>
    <form method="POST" class="ep-checkout-grid" id="epCheckoutForm">
        <div class="ep-panel">
            <h3 class="ep-panel-title"><i class="fas fa-map-marker-alt"></i> Shipping Details</h3>
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="checkout_token" value="<?php echo h($_SESSION['checkout_token']); ?>">
            <input type="hidden" name="total" value="<?php echo htmlspecialchars((string)$total, ENT_QUOTES); ?>">
            <input type="hidden" name="checkout_action" id="epCheckoutAction" value="">

            <?php if ($checkoutError !== ''): ?>
                <div class="ep-info-note" style="color:#c0392b; border-color:#c0392b;">
                    <?php echo h($checkoutError); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['error'])): ?>
                <div class="ep-info-note" style="color:#c0392b; border-color:#c0392b;">
                    <?php
                    $epPayErrors = [
                        'duplicate' => 'This order was already submitted. Check My Profile for your order status.',
                    ];
                    echo h($epPayErrors[$_GET['error']] ?? 'Something went wrong. Please try again.');
                    ?>
                </div>
            <?php endif; ?>

            <label class="ep-form-label" for="checkoutPhone">Phone Number</label>
            <input class="ep-form-control" id="checkoutPhone" type="tel" name="phone" placeholder="09123456789" required
                   autocomplete="tel" maxlength="30" value="<?php echo h($phoneValue); ?>">

            <span class="ep-form-label">Order method</span>
            <div class="ep-deliver-options" role="radiogroup" aria-label="Order method">
                <label class="ep-deliver-option<?php echo !$addressComplete ? ' is-disabled' : ''; ?>">
                    <input type="radio" name="address_mode" value="saved"
                           <?php echo $addressMode === 'saved' ? 'checked' : ''; ?>
                           <?php echo !$addressComplete ? 'disabled' : ''; ?>
                           data-delivery="1">
                    <span class="ep-deliver-radio" aria-hidden="true"></span>
                    <span class="ep-deliver-body">
                        <strong><i class="fas fa-home"></i> My Saved Address</strong>
                        <?php if ($addressComplete): ?>
                            <span><?php echo h(ep_address_format($savedAddress)); ?></span>
                            <a href="user_dashboard.php?settings=1#delivery" class="ep-deliver-edit">Edit in profile</a>
                        <?php else: ?>
                            <span>No complete saved address yet.
                                <a href="user_dashboard.php?settings=1#delivery" class="ep-deliver-edit">Add one in profile</a>
                            </span>
                        <?php endif; ?>
                    </span>
                </label>
                <label class="ep-deliver-option">
                    <input type="radio" name="address_mode" value="custom" <?php echo $addressMode === 'custom' ? 'checked' : ''; ?> data-delivery="1">
                    <span class="ep-deliver-radio" aria-hidden="true"></span>
                    <span class="ep-deliver-body">
                        <strong><i class="fas fa-map-signs"></i> Deliver to a Different Address</strong>
                        <span>For this order only. Your saved address won't change.</span>
                    </span>
                </label>
                <label class="ep-deliver-option">
                    <input type="radio" name="address_mode" value="pickup" <?php echo $addressMode === 'pickup' ? 'checked' : ''; ?> data-delivery="0">
                    <span class="ep-deliver-radio" aria-hidden="true"></span>
                    <span class="ep-deliver-body">
                        <strong><i class="fas fa-store"></i> Pick Up Order</strong>
                        <span>Pick up at EasyPC One Oasis Branch, Rosario, Pasig.</span>
                    </span>
                </label>
            </div>

            <div class="ep-lalamove-notice" id="epLalamoveNotice" role="status" <?php echo $showDeliveryNotice ? '' : 'hidden'; ?>>
                <div class="ep-lalamove-notice-body">
                    <i class="fas fa-truck" aria-hidden="true"></i>
                    <p>Delivery is only available through Lalamove.</p>
                </div>
                <button type="button" class="ep-lalamove-notice-close" id="epLalamoveClose" aria-label="Close notice">&times;</button>
            </div>

            <fieldset class="ep-deliver-custom" id="epCustomAddress" <?php echo $addressMode === 'custom' ? '' : 'hidden disabled'; ?>>
                <legend class="sr-only">Delivery address for this order</legend>
                <?php ep_render_address_fields($customAddress, '../assets/data/psgc', 'checkoutAddr_'); ?>
            </fieldset>

            <div class="ep-info-note">Place the order now, then pay from Order Summary → To Pay.</div>
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
                <button type="submit" name="place_order" id="epPlaceOrderBtn" class="ep-btn ep-btn-primary ep-btn-block">Place Order</button>
                <button type="submit" name="add_to_cart_checkout" id="epAddCartBtn" class="ep-btn ep-btn-yellow ep-btn-block" formnovalidate>Add to Cart</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</main>
<?php if (!$orderPlaced): ?>
<script src="../assets/js/ph-address.js"></script>
<script>
(function () {
    var custom = document.getElementById('epCustomAddress');
    var notice = document.getElementById('epLalamoveNotice');
    var closeBtn = document.getElementById('epLalamoveClose');
    var radios = document.querySelectorAll('input[name=address_mode][type=radio]');
    var noticeDismissed = false;

    function syncMode() {
        var selected = document.querySelector('input[name=address_mode][type=radio]:checked');
        var mode = selected ? selected.value : 'custom';
        var useCustom = mode === 'custom';
        if (custom) {
            custom.hidden = !useCustom;
            custom.disabled = !useCustom;
        }
        var isDelivery = mode === 'saved' || mode === 'custom';
        if (notice) {
            if (isDelivery && !noticeDismissed) {
                notice.hidden = false;
            } else if (!isDelivery) {
                notice.hidden = true;
                noticeDismissed = false;
            } else {
                notice.hidden = true;
            }
        }
    }

    radios.forEach(function (r) {
        r.addEventListener('change', function () {
            noticeDismissed = false;
            syncMode();
            if (r.value === 'custom' && r.checked && custom) {
                var first = custom.querySelector('input');
                if (first) first.focus();
            }
        });
    });
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            noticeDismissed = true;
            if (notice) notice.hidden = true;
        });
    }
    syncMode();
})();

(function () {
    var form = document.getElementById('epCheckoutForm');
    var btn = document.getElementById('epPlaceOrderBtn');
    var action = document.getElementById('epCheckoutAction');
    if (!form || !btn) return;
    form.addEventListener('submit', function (e) {
        var submitter = e.submitter || document.activeElement;
        if (submitter && submitter.name === 'place_order') {
            if (btn.dataset.locked === '1') {
                e.preventDefault();
                return;
            }
            // Disabled submit buttons are omitted from POST — keep the action in a hidden field.
            if (action) action.value = 'place_order';
            btn.dataset.locked = '1';
            btn.disabled = true;
            btn.textContent = 'Placing order…';
        } else if (action) {
            action.value = '';
        }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php if ($orderPlaced): ?>
<script>
(function () {
    var box = document.getElementById('epPlaceSuccess');
    var empty = document.getElementById('epCheckoutEmpty');
    var closeBtn = document.getElementById('epPlaceSuccessClose');
    if (!box || !closeBtn) return;
    closeBtn.addEventListener('click', function () {
        box.hidden = true;
        if (empty) empty.hidden = false;
    });
})();
</script>
<?php else: ?>
<?php ias_alert_footer(); ?>
<?php endif; ?>
