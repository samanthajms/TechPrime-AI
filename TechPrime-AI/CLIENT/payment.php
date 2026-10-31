<?php
/**
 * Hardcoded payment placeholder (school/project only — not a real gateway).
 * Client → Order Summary → To Pay → Pay → this page → Pay Now → mark paid.
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$userId = (int)$_SESSION['user_id'];
$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$error = '';

if ($orderId <= 0) {
    header('Location: user_dashboard.php?status=to_pay&alert=error');
    exit;
}

$load = $db->prepare(
    "SELECT o.id, o.total, o.status, o.payment_status, o.payment_method, o.created_at,
            o.shipping_address, o.customer_phone, o.fulfillment_type,
            (SELECT STRING_AGG(pr.name || ' ×' || oi.quantity::text, ', ')
             FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
             WHERE oi.order_id = o.id) AS products
     FROM orders o
     WHERE o.id = ? AND o.user_id = ?
     LIMIT 1"
);
$load->execute([$orderId, $userId]);
$order = $load->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: user_dashboard.php?status=to_pay&alert=error');
    exit;
}

$payStatus = strtolower((string)($order['payment_status'] ?? 'unpaid'));
$status = (string)($order['status'] ?? '');

if ($status !== 'to_pay') {
    header('Location: user_dashboard.php?status=' . rawurlencode($status));
    exit;
}

if ($payStatus === 'paid') {
    header('Location: user_dashboard.php?status=to_pay&alert=already_paid');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_now') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $paymentRef = 'placeholder_' . $orderId . '_' . $userId . '_' . bin2hex(random_bytes(6));
    $result = ep_mark_order_paid($db, $userId, $orderId, $paymentRef);
    if (!empty($result['ok'])) {
        header('Location: user_dashboard.php?status=to_pay&alert=paid&order_id=' . (int)$orderId);
        exit;
    }
    $error = match ($result['error'] ?? '') {
        'already_paid' => 'This order was already paid.',
        'not_payable' => 'This order can no longer be paid.',
        default => 'Could not complete payment. Please try again.',
    };
}

$isLoggedIn = true;
$activePage = 'account';
$pageTitle = 'Pay Order';
$bodyClass = '';
include __DIR__ . '/ep_header.php';
?>

<main class="ep-main sf-main" id="main">
    <?php echo sf_page_hero([
        'back' => ['Back to To Pay', 'user_dashboard.php?status=to_pay'],
        'eyebrow' => 'Payment',
        'title' => 'Pay order #ORD-' . (int)$order['id'],
        'sub' => 'Check the details below, then confirm the payment.',
        'size' => 'sm',
        'width' => 'xnarrow',
    ]); ?>
    <div class="sf-wrap sf-body is-xnarrow">
        <div class="ep-panel sf-pay-card">
            <p class="sf-note is-warn"><i class="fas fa-info-circle" aria-hidden="true"></i>
                <span>School/project placeholder — this is not a real payment gateway.</span></p>

            <?php if ($error !== ''): ?>
                <p class="sf-note is-error" role="alert"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><span><?php echo h($error); ?></span></p>
            <?php endif; ?>

            <h2 class="ep-panel-title"><i class="fas fa-receipt" aria-hidden="true"></i> Order details</h2>
            <div class="ep-order-line">
                <span>Order</span>
                <strong>#ORD-<?php echo (int)$order['id']; ?></strong>
            </div>
            <div class="ep-order-line">
                <span>Placed</span>
                <strong><?php echo h(format_utc_datetime((string)$order['created_at'], 'M d, Y g:i A')); ?></strong>
            </div>
            <div class="ep-order-line is-top">
                <span>Items</span>
                <strong class="sf-pay-value"><?php echo h((string)($order['products'] ?: '—')); ?></strong>
            </div>
            <?php if (trim((string)($order['shipping_address'] ?? '')) !== ''): ?>
                <div class="ep-order-line is-top">
                    <span><?php echo strtolower((string)($order['fulfillment_type'] ?? '')) === 'pickup' ? 'Pickup' : 'Ship to'; ?></span>
                    <strong class="sf-pay-value"><?php echo h((string)$order['shipping_address']); ?></strong>
                </div>
            <?php endif; ?>
            <div class="ep-order-total">
                <span>Amount due</span>
                <strong>₱<?php echo number_format((float)$order['total'], 2); ?></strong>
            </div>

            <form method="post" class="sf-pay-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <input type="hidden" name="action" value="pay_now">
                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                <button type="submit" class="ep-btn ep-btn-primary ep-btn-block sf-btn-lg">
                    <i class="fas fa-lock" aria-hidden="true"></i> Pay ₱<?php echo number_format((float)$order['total'], 2); ?>
                </button>
            </form>
            <a href="user_dashboard.php?status=to_pay" class="ep-btn ep-btn-outline ep-btn-block">Back to To Pay</a>
        </div>
    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>
