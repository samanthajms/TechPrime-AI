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
$address = $db->real_escape_string($order['address']);
$phone   = $db->real_escape_string($order['phone']);

$stmt = $db->prepare("INSERT INTO orders (user_id, total, status, shipping_address, customer_phone) VALUES (?, ?, 'to_ship', ?, ?)");
$stmt->bind_param("idss", $userId, $total, $address, $phone);

if ($stmt->execute()) {
    $orderId = $stmt->insert_id;

    foreach ($_SESSION['cart'] as $productId => $qty) {
        $qty = (int) $qty;
        $productId = (int) $productId;
        $res = $db->query("SELECT price FROM products WHERE id = $productId");
        $product = $res->fetch_assoc();
        $price = $product['price'];

        $itemStmt = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $itemStmt->bind_param("iiid", $orderId, $productId, $qty, $price);
        $itemStmt->execute();

        $db->query("UPDATE products SET stock = stock - $qty WHERE id = $productId");
    }

    unset($_SESSION['cart'], $_SESSION['pending_order'], $_SESSION['paymongo_link_id']);
    $db->query("DELETE FROM cart WHERE user_id = $userId");

    header("Location: ../../CLIENT/order_success.php?order_id=$orderId&total=$total");
    exit;
}
