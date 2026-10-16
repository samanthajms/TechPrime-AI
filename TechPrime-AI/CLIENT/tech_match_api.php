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
require_once __DIR__ . '/../includes/pc_recommend.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$payload = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    if (!verifyCsrfToken((string)($payload['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'products' => [], 'error' => 'csrf']);
        exit;
    }
}

$slot = trim((string)($payload['slot'] ?? $_GET['slot'] ?? ''));
$q = trim((string)($payload['q'] ?? $_GET['q'] ?? ''));

/*
 * Slot lists use the shop taxonomy. products.category is too coarse, so each
 * product is classified from its name before it is offered for a build slot.
 */
$slotMap = pc_recommend_slot_map();

if ($slot === '' || !isset($slotMap[$slot])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'products' => [], 'error' => 'invalid_slot']);
    exit;
}

$meta = $slotMap[$slot];
$db = getDbConnection();
$products = pc_recommend_products_for_slot($db, $slot);
if ($q !== '') {
    $products = array_values(array_filter($products, static function (array $p) use ($q): bool {
        $hay = $p['name'] . ' ' . $p['brand'] . ' ' . $p['type'];
        return mb_stripos($hay, $q) !== false;
    }));
}

$buildMap = pc_recommend_build_map($db, is_array($payload['build'] ?? null) ? $payload['build'] : []);
foreach ($products as &$product) {
    $scored = pc_recommend_score($slot, $product, $buildMap);
    if ($scored === null) {
        continue;
    }
    $product['recommend_score'] = $scored['score'];
    if ($scored['recommended']) {
        $product['recommended'] = true;
        $product['recommend_reason'] = $scored['reason'];
    }
}
unset($product);

echo json_encode([
    'ok' => true,
    'slot' => $slot,
    'label' => $meta['label'],
    'products' => $products,
], JSON_UNESCAPED_UNICODE);
