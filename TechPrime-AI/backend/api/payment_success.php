<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paymongo.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['pending_order'])) {
    header('Location: ../../CLIENT/products.php');
    exit;
}

// Verify payment status with PayMongo
$linkId  = $_SESSION['paymongo_link_id'] ?? '';
$verified = false;

if ($linkId) {
    $result = paymongoRequest('GET', '/links/' . $linkId);
    $status = $result['data']['attributes']['status'] ?? '';
    $verified = ($status === 'paid');
}

if (!$verified) {
    header('Location: ../../CLIENT/checkout.php?error=not_paid');
    exit;
}

// Payment confirmed — save the order
$db      = getDbConnection();
$userId  = (int) $_SESSION['user_id'];
$order   = $_SESSION['pending_order'];
$total   = $order['total'];
$address = $order['address'];
$phone   = $order['phone'];

$stmt = $db->prepare("INSERT INTO orders (user_id, total, status, shipping_address, customer_phone) VALUES (?, ?, 'to_ship', ?, ?)");

if ($stmt->execute([$userId, $total, $address, $phone])) {
    $orderId = $db->lastInsertId();

    foreach ($_SESSION['cart'] as $productId => $qty) {
        $qty = (int) $qty;
        $productId = (int) $productId;
        $priceStmt = $db->prepare("SELECT price FROM products WHERE id = ?");
        $priceStmt->execute([$productId]);
        $product = $priceStmt->fetch(PDO::FETCH_ASSOC);
        $price = $product['price'];

        $itemStmt = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $itemStmt->execute([$orderId, $productId, $qty, $price]);

        $stockStmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $stockStmt->execute([$qty, $productId]);
    }

    unset($_SESSION['cart'], $_SESSION['pending_order'], $_SESSION['paymongo_link_id']);
    $delCart = $db->prepare("DELETE FROM cart WHERE user_id = ?");
    $delCart->execute([$userId]);

    header("Location: ../../CLIENT/order_success.php?order_id=$orderId&total=$total");
    exit;
}
