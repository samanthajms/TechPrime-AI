<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['cart'])) {
    header("Location: ../../CLIENT/index.php");
    exit;
}

$db = getDbConnection();
$userId = $_SESSION['user_id'];
$total = 0;

foreach ($_SESSION['cart'] as $item) {
    $total += $item['price'] * $item['quantity'];
}

// 1. Create the main Order
$stmt = $db->prepare("INSERT INTO orders (user_id, total_price, status) VALUES (?, ?, 'Pending')");
$stmt->bind_param("id", $userId, $total);

if ($stmt->execute()) {
    $orderId = $db->insert_id;

    // 2. Save each product into order_items
    $itemStmt = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, ?)");
    foreach ($_SESSION['cart'] as $pId => $details) {
        $itemStmt->bind_param("iiid", $orderId, $pId, $details['quantity'], $details['price']);
        $itemStmt->execute();
    }

    // 3. Clear cart and go to success page
    unset($_SESSION['cart']);
    header("Location: ../../CLIENT/order_success.php?order_id=$orderId&total=$total");
    exit;
}