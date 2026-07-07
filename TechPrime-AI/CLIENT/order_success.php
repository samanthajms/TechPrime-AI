<?php
session_start();
require_once __DIR__ . '/../includes/security.php';

// FIX: Cast order_id to int, format total as float — neither can contain malicious scripts
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$total   = isset($_GET['total'])    ? number_format((float)$_GET['total'], 2) : '0.00';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed | IAS</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body>
    <main class="category-page-main">
        <div class="page-card order-confirm-wrapper">
            <div class="card-title">✅ Order Confirmed</div>
            <p class="info-note">Your items are now being prepared for shipment.</p>

            <div class="summary-box">
                <div class="info-row">
                    <span class="label">Order Number</span>
                    <!-- FIX: $orderId is now cast to int — no XSS possible -->
                    <span class="value">#<?php echo $orderId; ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Payment Method</span>
                    <span class="value">Cash on Delivery</span>
                </div>
                <div class="info-row summary-row">
                    <span class="label">Total Paid</span>
                    <!-- FIX: $total is number_format'd float — safe -->
                    <span class="value summary-total">₱<?php echo $total; ?></span>
                </div>
            </div>

            <button class="primary-btn" onclick="location.href='user_dashboard.php?status=To Ship'">Track My Order</button>
            <button class="primary-btn secondary-btn" onclick="location.href='index.php'">Return to Homepage</button>
        </div>
    </main>
</body>
</html>