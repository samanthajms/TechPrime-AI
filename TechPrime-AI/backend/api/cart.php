<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/security.php';

$db = getDbConnection();

// Handle "Add to Cart"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    // CSRF Check
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'Security token invalid.']);
        exit;
    }

    $productId = (int)$_POST['product_id'];
    
    // Fetch product details from DB to ensure price is accurate
    $stmt = $db->prepare("SELECT id, name, price FROM products WHERE id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if ($product) {
        if (!isset($_SESSION['cart'])) { $_SESSION['cart'] = []; }

        // If item exists, increase quantity; otherwise, add new
        if (isset($_SESSION['cart'][$productId])) {
            $_SESSION['cart'][$productId]['quantity'] += 1;
        } else {
            $_SESSION['cart'][$productId] = [
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => 1
            ];
        }
        
        // Redirect back to the page the user was on
        header("Location: " . $_SERVER['HTTP_REFERER'] . (strpos($_SERVER['HTTP_REFERER'], '?') !== false ? '&' : '?') . "added=1");
        exit;
    }
}