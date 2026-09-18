<?php
/**
 * Tech & Match — fetch real products for a build slot (JSON).
 * No page redirects; used inside the Tech & Match modal picker.
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$slot = trim((string)($_GET['slot'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$slotMap = [
    'processor' => [
        'label' => 'Processor',
        'categories' => ['Processor'],
        'keywords' => ['processor', 'cpu', 'ryzen', 'intel', 'core i'],
    ],
    'motherboard' => [
        'label' => 'Motherboard',
        'categories' => ['Motherboard'],
        'keywords' => ['motherboard', 'mainboard', 'b550', 'b650', 'z790'],
    ],
    'memory' => [
        'label' => 'Memory',
        'categories' => ['Memory', 'RAM'],
        'keywords' => ['memory', 'ram', 'ddr4', 'ddr5'],
    ],
    'ssd' => [
        'label' => 'SSD',
        'categories' => ['Solid State Drive'],
        'keywords' => ['ssd', 'nvme', 'm.2'],
    ],
    'ssd_sata' => [
        'label' => 'SSD (SATA)',
        'categories' => ['Solid State Drive'],
        'keywords' => ['sata', 'ssd'],
    ],
    'hdd' => [
        'label' => 'Hard Disk',
        'categories' => ['Hard Disk'],
        'keywords' => ['hdd', 'hard disk', 'hard drive'],
    ],
    'gpu' => [
        'label' => 'Graphics Card',
        'categories' => ['GPU', 'Graphic Card'],
        'keywords' => ['gpu', 'graphics', 'geforce', 'rtx', 'radeon'],
    ],
    'psu' => [
        'label' => 'Power Supply',
        'categories' => ['Power Supply'],
        'keywords' => ['psu', 'power supply', 'watt'],
    ],
    'case' => [
        'label' => 'Case',
        'categories' => ['PC Case'],
        'keywords' => ['case', 'chassis', 'cabinet'],
    ],
    'cooler' => [
        'label' => 'CPU Cooler',
        'categories' => ['Cooling'],
        'keywords' => ['cooler', 'cooling', 'aio', 'fan'],
    ],
    'extras' => [
        'label' => 'Extras',
        'categories' => ['Accessories', 'Others', 'Audio'],
        'keywords' => ['accessory', 'cable', 'headset', 'mouse', 'keyboard'],
    ],
];

if ($slot === '' || !isset($slotMap[$slot])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'products' => [], 'error' => 'invalid_slot']);
    exit;
}

$meta = $slotMap[$slot];
$db = getDbConnection();
$vis = ias_client_product_list_sql_condition('p');

$cats = $meta['categories'];
$catPlaceholders = implode(',', array_fill(0, count($cats), '?'));
$types = str_repeat('s', count($cats));
$params = $cats;

$kwSql = [];
foreach ($meta['keywords'] as $kw) {
    $kwSql[] = 'p.name LIKE ?';
    $kwSql[] = 'COALESCE(p.description, \'\') LIKE ?';
    $types .= 'ss';
    $like = '%' . $kw . '%';
    $params[] = $like;
    $params[] = $like;
}
$kwClause = $kwSql ? (' OR (' . implode(' OR ', $kwSql) . ')') : '';

$sql = "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url, p.description,
               u.name AS seller_name
        FROM products p
        INNER JOIN users u ON u.id = p.seller_id
        WHERE {$vis}
          AND (p.category IN ({$catPlaceholders}){$kwClause})";

if ($q !== '') {
    $sql .= ' AND (p.name LIKE ? OR COALESCE(p.description, \'\') LIKE ? OR COALESCE(p.category, \'\') LIKE ?)';
    $qlike = '%' . $q . '%';
    $types .= 'sss';
    $params[] = $qlike;
    $params[] = $qlike;
    $params[] = $qlike;
}

$sql .= ' ORDER BY p.stock DESC, p.name ASC LIMIT 48';

$stmt = $db->prepare($sql);
if (!$stmt) {
    echo json_encode(['ok' => false, 'products' => [], 'error' => 'query_failed']);
    exit;
}
$stmt->execute($params);
$res = $stmt;
$rows = $res ? $res->fetchAll(PDO::FETCH_ASSOC) : [];
$rows = ias_client_filter_products_for_display($rows);
$products = [];
$seen = [];
foreach ($rows as $p) {
    $id = (int)($p['id'] ?? 0);
    if ($id <= 0 || isset($seen[$id])) {
        continue;
    }
    $seen[$id] = true;
    $products[] = [
        'id' => $id,
        'name' => (string)($p['name'] ?? ''),
        'price' => (float)($p['price'] ?? 0),
        'stock' => (int)($p['stock'] ?? 0),
        'category' => (string)($p['category'] ?? ''),
        'image' => ias_client_product_image_url($p),
        'seller' => (string)($p['seller_name'] ?? ''),
    ];
}

echo json_encode([
    'ok' => true,
    'slot' => $slot,
    'label' => $meta['label'],
    'products' => $products,
], JSON_UNESCAPED_UNICODE);
