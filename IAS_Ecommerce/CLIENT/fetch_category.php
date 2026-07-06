<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

header('Content-Type: application/json');

$db = getDbConnection();

$allowed = ['Laptops', 'Desktop', 'Mobile', 'Cameras', 'Accessories'];
$type = isset($_GET['type']) ? $_GET['type'] : '';

if (!in_array($type, $allowed)) {
    echo json_encode([]);
    exit;
}

$stmt = $db->prepare(
    "SELECT p.id, p.name, p.price, p.image_url AS image, u.name AS seller_name
     FROM products p
     JOIN users u ON p.seller_id = u.id
     WHERE p.category = ?
     ORDER BY p.id DESC"
);
$stmt->bind_param("s", $type);
$stmt->execute();
$result = $stmt->get_result();

$products = [];
while ($row = $result->fetch_assoc()) {
    // Attach a fresh CSRF token so each Add-to-Cart form is protected
    $row['csrf'] = generateCsrfToken();
    $products[] = $row;
}

echo json_encode($products);
