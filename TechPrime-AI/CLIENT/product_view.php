<?php
/**
 * JSON product payload for the Client product-view modal.
 * Reuses the same catalog visibility and fields as products.php.
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
if (is_file(__DIR__ . '/../includes/client_shop_taxonomy.php')) {
    require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid']);
    exit;
}

$db = getDbConnection();
checkSessionTimeout();
ep_ensure_session_wishlist($db);

$one = $db->prepare(
    "SELECT p.*, u.name AS seller_name FROM products p
     INNER JOIN users u ON p.seller_id = u.id
     WHERE p.id = ? AND " . ias_client_product_list_sql_condition('p') . "
     LIMIT 1"
);
$one->execute([$id]);
$row = $one->fetch(PDO::FETCH_ASSOC) ?: null;
$image = $row ? ias_client_product_image_url($row) : '';
if (!$row || $image === '') {
    echo json_encode(['ok' => false, 'error' => 'unavailable']);
    exit;
}

$tax = function_exists('ep_shop_classify_product') ? ep_shop_classify_product($row) : null;
$parent = $tax ? (string)($tax['parent_label'] ?? '') : '';
$sub = $tax ? (string)($tax['sub_label'] ?? '') : '';
$category = $parent !== '' ? $parent : (string)($row['category'] ?? 'Uncategorized');
$brand = ias_client_product_brand($row);
$stk = (int)($row['stock'] ?? 0);
$specs = '';
foreach (['specifications', 'specification', 'specs', 'features'] as $key) {
    if (!empty($row[$key]) && trim((string)$row[$key]) !== '') {
        $specs = trim((string)$row[$key]);
        break;
    }
}

echo json_encode([
    'ok' => true,
    'csrf' => generateCsrfToken(),
    'product' => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'image' => $image,
        'category' => $category,
        'sub_category' => $sub,
        'brand' => $brand,
        'price' => (float)$row['price'],
        'price_fmt' => '₱' . number_format((float)$row['price'], 2),
        'stock' => $stk,
        'stock_label' => $stk > 0 ? ('In stock · ' . $stk . ' available') : 'Out of stock',
        'in_stock' => $stk > 0,
        'description' => trim((string)($row['description'] ?? '')),
        'specifications' => $specs,
        'sku' => trim((string)($row['sku'] ?? '')),
        'seller_name' => (string)($row['seller_name'] ?? ''),
        'in_wishlist' => ep_wishlist_has((int)$row['id']),
    ],
]);
