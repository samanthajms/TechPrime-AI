<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$total   = isset($_GET['total']) ? number_format((float)$_GET['total'], 2) : '0.00';

$isLoggedIn = isset($_SESSION['user_id']);
$activePage = '';
$pageTitle  = 'Order Confirmed';
$bodyClass  = '';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main sf-main" id="main">
    <?php echo sf_page_hero([
        'eyebrow' => 'Thank you',
        'title' => 'Order confirmed',
        'sub' => 'Your order is waiting for payment under My orders → To Pay.',
        'size' => 'sm',
        'width' => 'xnarrow',
    ]); ?>
    <div class="sf-wrap sf-body is-xnarrow">
        <div class="ep-panel ep-success-card">
            <span class="sf-success-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
            <h2 class="ep-panel-title">Order #<?php echo $orderId; ?></h2>
            <div class="ep-order-line">
                <span>Order number</span>
                <strong>#<?php echo $orderId; ?></strong>
            </div>
            <div class="ep-order-line">
                <span>Status</span>
                <strong>To Pay</strong>
            </div>
            <div class="ep-order-total">
                <span>Amount due</span>
                <strong>₱<?php echo $total; ?></strong>
            </div>

            <div class="ep-success-actions">
                <a href="payment.php?order_id=<?php echo (int)$orderId; ?>" class="ep-btn ep-btn-primary">Pay now</a>
                <a href="user_dashboard.php?status=to_pay" class="ep-btn ep-btn-outline">Go to To Pay</a>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
