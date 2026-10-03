<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
ep_ensure_session_cart($db);

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
if (!isset($_SESSION['cart_selected']) || !is_array($_SESSION['cart_selected'])) {
    $_SESSION['cart_selected'] = array_map('intval', array_keys($_SESSION['cart']));
}

function ep_cart_wants_json(): bool
{
    if (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1') {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json');
}

function ep_cart_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/* ---- Auto-save quantity ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_qty') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        if (ep_cart_wants_json()) {
            ep_cart_json(['ok' => false, 'error' => 'csrf'], 400);
        }
        die('Invalid CSRF token.');
    }
    $id = (int)($_POST['product_id'] ?? 0);
    $q = (int)($_POST['qty'] ?? 0);
    $result = ep_set_cart_quantity($db, $id, $q);
    if (!empty($result['ok']) && (int)$result['qty'] === 0) {
        $_SESSION['cart_selected'] = array_values(array_filter(
            array_map('intval', $_SESSION['cart_selected'] ?? []),
            static fn($pid) => $pid !== $id
        ));
    }
    $preview = ep_get_cart_preview($db);
    if (ep_cart_wants_json()) {
        ep_cart_json([
            'ok' => !empty($result['ok']),
            'qty' => (int)($result['qty'] ?? 0),
            'error' => $result['error'] ?? '',
            'cart' => $preview,
        ]);
    }
    header('Location: cart.php?updated=1');
    exit;
}

/* ---- Persist selected items ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_items') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        if (ep_cart_wants_json()) {
            ep_cart_json(['ok' => false, 'error' => 'csrf'], 400);
        }
        die('Invalid CSRF token.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected'] ?? [])))));
    $valid = [];
    foreach ($ids as $pid) {
        if ($pid > 0 && isset($_SESSION['cart'][$pid])) {
            $valid[] = $pid;
        }
    }
    $_SESSION['cart_selected'] = $valid;
    if (ep_cart_wants_json()) {
        ep_cart_json(['ok' => true, 'selected' => $valid]);
    }
    header('Location: cart.php');
    exit;
}

/* ---- Bulk quantity form (no-JS fallback) ---- */
if (isset($_POST['update_cart'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    foreach ((array)($_POST['qty'] ?? []) as $id => $q) {
        ep_set_cart_quantity($db, (int)$id, (int)$q);
    }
    logActivity($db, $uid ?: null, 'update_cart', 'Cart quantities updated');
    header('Location: cart.php?updated=1');
    exit;
}

if (isset($_GET['remove'])) {
    $remove_id = (int)$_GET['remove'];
    ep_set_cart_quantity($db, $remove_id, 0);
    logActivity($db, $uid ?: null, 'remove_from_cart', "Product ID $remove_id removed from cart");
    header('Location: cart.php?removed=1');
    exit;
}

$cart_items = [];
$total = 0;
$selectedSet = array_fill_keys(array_map('intval', $_SESSION['cart_selected'] ?? []), true);
if (!empty($_SESSION['cart'])) {
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $ids_str = implode(',', $ids);
    $res = $db->query("SELECT * FROM products WHERE id IN ($ids_str) AND COALESCE(stock, 0) > 0");
    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $row['qty'] = (int)$_SESSION['cart'][$pid];
        $stock = (int)($row['stock'] ?? 0);
        if ($row['qty'] > $stock) {
            $row['qty'] = $stock;
            ep_set_cart_quantity($db, $pid, $stock);
        }
        $row['subtotal'] = (float)$row['price'] * $row['qty'];
        $row['selected'] = isset($selectedSet[$pid]);
        $total += $row['subtotal'];
        $cart_items[] = $row;
    }
}

if ($cart_items && empty($_SESSION['cart_selected'])) {
    $_SESSION['cart_selected'] = array_map(static fn($r) => (int)$r['id'], $cart_items);
    foreach ($cart_items as &$it) {
        $it['selected'] = true;
    }
    unset($it);
}

$selectedTotal = 0;
$selectedUnits = 0;
$totalUnits    = 0;
foreach ($cart_items as $it) {
    $totalUnits += (int)$it['qty'];
    if (!empty($it['selected'])) {
        $selectedTotal += $it['subtotal'];
        $selectedUnits += (int)$it['qty'];
    }
}

$isLoggedIn           = isset($_SESSION['user_id']);
$userName             = $isLoggedIn ? h($_SESSION['name'] ?? 'Customer') : 'Guest';
$activePage           = 'cart';
$pageTitle            = 'My Cart';
$searchQuery          = '';
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];
$bodyClass            = 'ep-cart-layout';
$csrf                 = generateCsrfToken();
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main ep-cart-main">
        <div class="ep-page-header-row epc-header">
            <a href="index.php" class="ep-back-link"><i class="fas fa-arrow-left"></i> Continue Shopping</a>
            <h2 class="ep-page-title">
                My Cart
                <?php if ($cart_items): ?>
                    <span class="epc-title-count" id="cartItemCount"><?php echo $totalUnits; ?> item<?php echo $totalUnits === 1 ? '' : 's'; ?></span>
                <?php endif; ?>
            </h2>
        </div>

        <?php if (empty($cart_items)): ?>
            <section class="epc-empty">
                <div class="epc-empty-icon" aria-hidden="true"><i class="fas fa-shopping-bag"></i></div>
                <h3>Your cart is empty</h3>
                <p>Looks like you haven't added anything yet. Browse our PCs, parts and accessories to get started.</p>
                <div class="epc-empty-actions">
                    <a href="shop.php" class="epc-btn epc-btn-primary"><i class="fas fa-store"></i> Shop Now</a>
                    <a href="build_a_pc.php" class="epc-btn epc-btn-ghost"><i class="fas fa-desktop"></i> Build a PC</a>
                </div>
            </section>
        <?php else: ?>
            <form method="post" id="cartForm" class="epc-grid">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

                <section class="epc-card epc-items" aria-label="Cart items">
                    <div class="epc-items-head">
                        <label class="epc-check-label">
                            <input type="checkbox" id="cartSelectAll" class="epc-check">
                            <span>Select All (<span id="cartRowCount"><?php echo count($cart_items); ?></span>)</span>
                        </label>
                        <span class="epc-col epc-col-price">Unit Price</span>
                        <span class="epc-col epc-col-qty">Quantity</span>
                        <span class="epc-col epc-col-total">Total</span>
                        <span class="epc-col epc-col-action" aria-hidden="true"></span>
                    </div>

                    <div id="cartItems">
                        <?php foreach ($cart_items as $item):
                            $pid      = (int)$item['id'];
                            $stock    = (int)$item['stock'];
                            $category = trim((string)($item['category'] ?? ''));
                            $imgUrl   = ias_client_product_image_url($item);
                        ?>
                        <div class="cart-item-row<?php echo !empty($item['selected']) ? ' is-selected' : ''; ?>" data-id="<?php echo $pid; ?>" data-price="<?php echo h((string)$item['price']); ?>" data-stock="<?php echo $stock; ?>">
                            <label class="epc-row-check">
                                <input type="checkbox" class="cart-item-check epc-check" name="selected[]" value="<?php echo $pid; ?>" <?php echo !empty($item['selected']) ? 'checked' : ''; ?> aria-label="Select <?php echo h($item['name']); ?>">
                            </label>
                            <div class="epc-product">
                                <a class="epc-thumb" href="products.php?id=<?php echo $pid; ?>">
                                    <?php if ($imgUrl !== ''): ?>
                                        <img src="<?php echo h($imgUrl); ?>" alt="<?php echo h($item['name']); ?>" loading="lazy">
                                    <?php else: ?>
                                        <i class="fas fa-image" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </a>
                                <div class="epc-info">
                                    <?php if ($category !== ''): ?>
                                        <span class="epc-category"><?php echo h($category); ?></span>
                                    <?php endif; ?>
                                    <a class="epc-name" href="products.php?id=<?php echo $pid; ?>"><?php echo h($item['name']); ?></a>
                                    <?php if ($stock <= 5): ?>
                                        <span class="epc-stock epc-stock-low"><i class="fas fa-exclamation-circle"></i> Only <?php echo $stock; ?> left</span>
                                    <?php else: ?>
                                        <span class="epc-stock"><i class="fas fa-check-circle"></i> In stock</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="epc-price">
                                <span class="epc-mobile-label">Unit Price</span>
                                ₱<?php echo number_format((float)$item['price'], 2); ?>
                            </div>
                            <div class="epc-qty">
                                <div class="epc-stepper">
                                    <button type="button" class="epc-step" data-step="-1" aria-label="Decrease quantity"><i class="fas fa-minus"></i></button>
                                    <input type="number" class="cart-qty-input" name="qty[<?php echo $pid; ?>]" value="<?php echo (int)$item['qty']; ?>" min="1" max="<?php echo max(1, $stock); ?>" aria-label="Quantity">
                                    <button type="button" class="epc-step" data-step="1" aria-label="Increase quantity"><i class="fas fa-plus"></i></button>
                                </div>
                                <span class="epc-max">Max <?php echo $stock; ?></span>
                            </div>
                            <div class="epc-total cart-item-subtotal">₱<?php echo number_format((float)$item['subtotal'], 2); ?></div>
                            <div class="epc-action">
                                <a href="cart.php?remove=<?php echo $pid; ?>" class="epc-remove" title="Remove from cart" aria-label="Remove <?php echo h($item['name']); ?>"><i class="far fa-trash-alt"></i><span>Remove</span></a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <aside class="epc-card epc-summary" aria-label="Order summary">
                    <h3 class="epc-summary-title"><i class="fas fa-receipt"></i> Order Summary</h3>

                    <div class="epc-sum-row">
                        <span>Subtotal (<span id="cartSelectedCount"><?php echo $selectedUnits; ?></span> selected)</span>
                        <strong id="cartSelectedTotal">₱<?php echo number_format($selectedTotal, 2); ?></strong>
                    </div>
                    <div class="epc-sum-row epc-sum-cart">
                        <span>Cart total (all items)</span>
                        <span id="cartGrandTotal">₱<?php echo number_format($total, 2); ?></span>
                    </div>

                    <div class="epc-sum-total">
                        <span>Total</span>
                        <strong id="cartPayTotal">₱<?php echo number_format($selectedTotal, 2); ?></strong>
                    </div>
                    <p class="epc-vat">Prices are VAT-inclusive</p>

                    <button type="submit" name="update_cart" class="primary-btn" hidden>Update Quantities</button>
                    <button type="button" class="epc-btn epc-btn-primary epc-btn-block" id="cartCheckoutBtn">
                        <i class="fas fa-lock"></i> <span>Checkout (<span id="cartCheckoutCount"><?php echo $selectedUnits; ?></span>)</span>
                    </button>
                    <a href="shop.php" class="epc-btn epc-btn-ghost epc-btn-block">Continue Shopping</a>

                    <ul class="epc-perks">
                        <li><i class="fas fa-money-bill-wave"></i> Cash on Delivery available</li>
                        <li><i class="fas fa-credit-card"></i> Secure online payment</li>
                        <li><i class="fas fa-store-alt"></i> EasyPC One Oasis, Rosario, Pasig</li>
                    </ul>
                </aside>
            </form>
        <?php endif; ?>
    </main>

<?php ias_alert_footer(); ?>
<?php if (!empty($cart_items)): ?>
<script>
(function () {
    var form = document.getElementById('cartForm');
    var csrf = <?php echo json_encode($csrf); ?>;
    var selectAll = document.getElementById('cartSelectAll');
    var checkoutBtn = document.getElementById('cartCheckoutBtn');
    var timers = {};

    function money(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function rows() { return Array.prototype.slice.call(document.querySelectorAll('.cart-item-row')); }
    function checkedRows() { return rows().filter(function (r) { return r.querySelector('.cart-item-check').checked; }); }

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function refreshTotals() {
        var grand = 0;
        var selected = 0;
        var units = 0;
        var selectedUnits = 0;
        rows().forEach(function (row) {
            var price = parseFloat(row.getAttribute('data-price')) || 0;
            var input = row.querySelector('.cart-qty-input');
            var qty = parseInt(input.value, 10) || 0;
            var max = parseInt(row.getAttribute('data-stock'), 10) || 1;
            var sub = price * qty;
            var isChecked = row.querySelector('.cart-item-check').checked;
            grand += sub;
            units += qty;
            row.querySelector('.cart-item-subtotal').textContent = money(sub);
            row.classList.toggle('is-selected', isChecked);
            row.querySelector('.epc-step[data-step="-1"]').disabled = qty <= 1;
            row.querySelector('.epc-step[data-step="1"]').disabled = qty >= max;
            if (isChecked) {
                selected += sub;
                selectedUnits += qty;
            }
        });
        setText('cartSelectedTotal', money(selected));
        setText('cartPayTotal', money(selected));
        setText('cartGrandTotal', money(grand));
        setText('cartSelectedCount', String(selectedUnits));
        setText('cartCheckoutCount', String(selectedUnits));
        setText('cartItemCount', units + (units === 1 ? ' item' : ' items'));
        if (checkoutBtn) checkoutBtn.classList.toggle('is-disabled', selectedUnits === 0);
        if (selectAll) {
            var all = rows();
            selectAll.checked = all.length > 0 && all.every(function (r) { return r.querySelector('.cart-item-check').checked; });
        }
    }

    function postForm(data) {
        var body = new URLSearchParams(data);
        return fetch('cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function saveSelection() {
        var selected = checkedRows().map(function (r) { return r.getAttribute('data-id'); });
        var body = new URLSearchParams();
        body.set('action', 'select_items');
        body.set('ajax', '1');
        body.set('csrf_token', csrf);
        selected.forEach(function (id) { body.append('selected[]', id); });
        refreshTotals();
        return fetch('cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body.toString()
        }).then(function (r) { return r.json(); }).catch(function () { return null; });
    }

    function saveQty(row) {
        var id = row.getAttribute('data-id');
        var input = row.querySelector('.cart-qty-input');
        var max = parseInt(row.getAttribute('data-stock'), 10) || 1;
        var qty = parseInt(input.value, 10);
        if (isNaN(qty) || qty < 1) qty = 1;
        if (qty > max) qty = max;
        input.value = qty;
        postForm({ action: 'update_qty', ajax: '1', csrf_token: csrf, product_id: id, qty: qty })
            .then(function (data) {
                if (data && data.ok && typeof data.qty === 'number') {
                    input.value = data.qty;
                }
                if (data && data.cart && typeof window.epUpdateCartPreview === 'function') {
                    window.epUpdateCartPreview(data.cart);
                }
                refreshTotals();
            })
            .catch(function () { refreshTotals(); });
    }

    rows().forEach(function (row) {
        var input = row.querySelector('.cart-qty-input');
        var check = row.querySelector('.cart-item-check');
        input.addEventListener('change', function () { saveQty(row); });
        input.addEventListener('input', function () {
            var id = row.getAttribute('data-id');
            if (timers[id]) clearTimeout(timers[id]);
            timers[id] = setTimeout(function () { saveQty(row); }, 400);
            refreshTotals();
        });
        check.addEventListener('change', saveSelection);
        Array.prototype.forEach.call(row.querySelectorAll('.epc-step'), function (btn) {
            btn.addEventListener('click', function () {
                var max = parseInt(row.getAttribute('data-stock'), 10) || 1;
                var next = (parseInt(input.value, 10) || 1) + parseInt(btn.getAttribute('data-step'), 10);
                if (next < 1 || next > max) return;
                input.value = next;
                input.dispatchEvent(new Event('input'));
            });
        });
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rows().forEach(function (row) { row.querySelector('.cart-item-check').checked = selectAll.checked; });
            saveSelection();
        });
    }

    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function () {
            if (!checkedRows().length) {
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Select at least one product to checkout.', 'error', 0);
                return;
            }
            saveSelection().then(function () {
                window.location.href = 'checkout.php';
            });
        });
    }

    refreshTotals();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/ep_footer.php'; ?>
