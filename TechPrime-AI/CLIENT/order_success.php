<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$total   = isset($_GET['total']) ? number_format((float)$_GET['total'], 2) : '0.00';

$isLoggedIn = isset($_SESSION['user_id']);
$activePage = '';
$pageTitle  = 'Order Confirmed';
$bodyClass  = 'ep-success-layout';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-panel ep-success-card">
        <div class="ep-success-icon"><i class="fas fa-check"></i></div>
        <h2 class="ep-page-title">Order Confirmed</h2>
        <p class="ep-info-note" style="margin-bottom:20px;">Your items are now being prepared for shipment.</p>

        <div class="ep-panel" style="text-align:left;margin-bottom:0;">
            <div class="ep-order-line">
                <span>Order Number</span>
                <strong>#<?php echo $orderId; ?></strong>
            </div>
            <div class="ep-order-line">
                <span>Payment Method</span>
                <strong>Cash on Delivery</strong>
            </div>
            <div class="ep-order-total" style="border-top:1px solid var(--ep-border);">
                <span>Total Paid</span>
                <strong>₱<?php echo $total; ?></strong>
            </div>
        </div>

        <div class="ep-success-actions">
            <a href="user_dashboard.php?status=To Ship" class="ep-btn ep-btn-primary">Track My Order</a>
            <a href="index.php" class="ep-btn ep-btn-yellow">Return to Homepage</a>
        </div>
    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>
