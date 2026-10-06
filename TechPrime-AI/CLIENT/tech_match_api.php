<?php
/**
 * Build a PC — fetch real products for a build slot (JSON).
 * Used by the in-page component picker (tech_match.js).
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
require_once __DIR__ . '/../includes/pc_compatibility.php';
require_once __DIR__ . '/../includes/product_categories.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$slot = trim((string)($_GET['slot'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

/*
 * Slot => shop taxonomy subcategories (includes/client_shop_taxonomy.php).
 * products.category is too coarse (most parts are "Others"), and loose keyword
 * matches pulled laptops ("RTX 4060") and APUs ("Radeon Graphics") into the GPU
 * slot — so every product is classified from its name, like the shop does.
 */
$slotMap = [
    'processor' => ['label' => 'Processor', 'subs' => ['processor-amd', 'processor-intel', 'processor-tray']],
    'motherboard' => ['label' => 'Motherboard', 'subs' => ['motherboard']],
    /* SO-DIMM sticks are laptop memory and do not fit a desktop board. */
    'memory' => ['label' => 'Memory', 'subs' => ['memory'], 'exclude' => '/\bso-?dimm\b/i'],
    'ssd' => ['label' => 'SSD (NVMe / M.2)', 'subs' => ['ssd'], 'storage' => 'nvme'],
    'ssd_sata' => ['label' => 'SSD (SATA)', 'subs' => ['ssd'], 'storage' => 'sata'],
    'hdd' => ['label' => 'Hard Disk', 'subs' => ['hard-disk']],
    'gpu' => ['label' => 'Graphics Card', 'subs' => ['graphics-card']],
    'psu' => ['label' => 'Power Supply', 'subs' => ['power-supply']],
    'case' => ['label' => 'PC Case', 'subs' => ['pc-case']],
    'cooler' => ['label' => 'CPU Cooler', 'subs' => ['cpu-cooling']],
    'case_fan' => ['label' => 'Case Fan', 'subs' => ['chassis-fan']],
];

if ($slot === '' || !isset($slotMap[$slot])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'products' => [], 'error' => 'invalid_slot']);
    exit;
}

$meta = $slotMap[$slot];
$db = getDbConnection();
$vis = ias_client_product_list_sql_condition('p');

$cats = ias_category_expand($meta['categories']);   // old buckets + aligned labels
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
$stmt->execute();
$rows = ias_client_filter_products_for_display($stmt->fetchAll(PDO::FETCH_ASSOC));

$products = [];
$seen = [];
foreach ($rows as $p) {
    $id = (int)($p['id'] ?? 0);
    if ($id <= 0 || isset($seen[$id])) {
        continue;
    }
    $name = (string)($p['name'] ?? '');
    $category = (string)($p['category'] ?? '');
    $description = (string)($p['description'] ?? '');

    $tax = ep_shop_classify_product($p);
    if ($tax['is_power_station'] || !in_array($tax['sub'], $meta['subs'], true)) {
        continue;
    }
    if (!empty($meta['exclude']) && preg_match($meta['exclude'], $name)) {
        continue;
    }
    if (!empty($meta['storage'])) {
        $isSata = (bool)preg_match('/\bsata\b/i', $name) && !preg_match('/\b(nvme|m\.2|pcie)\b/i', $name);
        if (($meta['storage'] === 'sata') !== $isSata) {
            continue;
        }
    }
    if ($q !== '') {
        $hay = $name . ' ' . $tax['brand'] . ' ' . $tax['sub_label'];
        if (mb_stripos($hay, $q) === false) {
            continue;
        }
    }

    $seen[$id] = true;
    $products[] = [
        'id' => $id,
        'name' => $name,
        'price' => (float)($p['price'] ?? 0),
        'stock' => (int)($p['stock'] ?? 0),
        'category' => $category,
        'type' => (string)$tax['sub_label'],
        'brand' => (string)$tax['brand'],
        'description' => $description,
        'image' => ias_client_product_image_url($p),
        'seller' => (string)($p['seller_name'] ?? ''),
        'compat' => ep_pc_compat_tags($name, $description, $category),
    ];
}

echo json_encode([
    'ok' => true,
    'slot' => $slot,
    'label' => $meta['label'],
    'products' => $products,
], JSON_UNESCAPED_UNICODE);
