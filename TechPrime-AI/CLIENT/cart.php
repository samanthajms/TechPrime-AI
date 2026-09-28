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
foreach ($cart_items as $it) {
    if (!empty($it['selected'])) {
        $selectedTotal += $it['subtotal'];
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
                <form method="post" id="cartForm">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

                    <label class="cart-select-all">
                        <input type="checkbox" id="cartSelectAll">
                        <span>Select All</span>
                    </label>

                    <div id="cartItems">
                        <?php foreach ($cart_items as $item): ?>
                        <div class="cart-item-row" data-id="<?php echo (int)$item['id']; ?>" data-price="<?php echo h((string)$item['price']); ?>" data-stock="<?php echo (int)$item['stock']; ?>">
                            <label class="cart-select">
                                <input type="checkbox" class="cart-item-check" name="selected[]" value="<?php echo (int)$item['id']; ?>" <?php echo !empty($item['selected']) ? 'checked' : ''; ?>>
                            </label>
                            <a class="cart-item-thumb" href="products.php?id=<?php echo (int)$item['id']; ?>">
                                <img src="<?php echo h(ias_client_product_image_url($item)); ?>" alt="<?php echo h($item['name']); ?>">
                            </a>
                            <div class="cart-item-meta">
                                <a class="cart-item-title" href="products.php?id=<?php echo (int)$item['id']; ?>"><?php echo h($item['name']); ?></a>
                                <div class="cart-item-price">₱<?php echo number_format((float)$item['price'], 2); ?></div>
                            </div>
                            <div class="cart-row-controls">
                                <input type="number" class="cart-qty-input" name="qty[<?php echo (int)$item['id']; ?>]" value="<?php echo (int)$item['qty']; ?>" min="1" max="<?php echo max(1, (int)$item['stock']); ?>">
                                <div class="cart-item-subtotal">₱<?php echo number_format((float)$item['subtotal'], 2); ?></div>
                                <a href="cart.php?remove=<?php echo (int)$item['id']; ?>" class="cart-remove-link" title="Remove">&times;</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="cart-summary">
                        <div class="summary-row">
                            <span class="summary-label">Selected Total</span>
                            <strong class="summary-total" id="cartSelectedTotal">₱<?php echo number_format($selectedTotal, 2); ?></strong>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Cart Total</span>
                            <strong id="cartGrandTotal">₱<?php echo number_format($total, 2); ?></strong>
                        </div>
                        <div class="cart-summary-actions">
                            <button type="submit" name="update_cart" class="primary-btn" hidden>Update Quantities</button>
                            <button type="button" class="primary-btn" id="cartCheckoutBtn">Checkout Now</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </section>
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

    function refreshTotals() {
        var grand = 0;
        var selected = 0;
        rows().forEach(function (row) {
            var price = parseFloat(row.getAttribute('data-price')) || 0;
            var qty = parseInt(row.querySelector('.cart-qty-input').value, 10) || 0;
            var sub = price * qty;
            grand += sub;
            row.querySelector('.cart-item-subtotal').textContent = money(sub);
            if (row.querySelector('.cart-item-check').checked) selected += sub;
        });
        var selEl = document.getElementById('cartSelectedTotal');
        var grandEl = document.getElementById('cartGrandTotal');
        if (selEl) selEl.textContent = money(selected);
        if (grandEl) grandEl.textContent = money(grand);
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
