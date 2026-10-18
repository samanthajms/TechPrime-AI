<?php
/**
 * Build a PC — fetch real products for a build slot (JSON), scored for the customer's
 * purpose and budget. action=review checks the parts already picked instead and
 * rates them for the purpose (Build Score + Performance Balance, pc_rating.php);
 * action=budget_guide returns suggested budgets for the budget dialog;
 * action=auto_build fills a whole build for the purpose and budget ("Build it for me").
 * Used by the in-page component picker and build check (tech_match.js).
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_shop_taxonomy.php';
require_once __DIR__ . '/../includes/pc_compatibility.php';
require_once __DIR__ . '/../includes/pc_recommend.php';
require_once __DIR__ . '/../includes/pc_rating.php';

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
$ctx = pc_recommend_context($payload);
/* One connection for the whole request. */
$db = getDbConnection();

if (($payload['action'] ?? $_GET['action'] ?? '') === 'budget_guide') {
    echo json_encode(['ok' => true] + pc_recommend_budget_guide($db), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($payload['action'] ?? '') === 'auto_build') {
    pc_recommend_catalog($db);
    $keep = pc_recommend_build_map($db, is_array($payload['build'] ?? null) ? $payload['build'] : []);
    echo json_encode(['ok' => true] + pc_recommend_auto_build($db, $ctx, $keep), JSON_UNESCAPED_UNICODE);
    exit;
}

if (($payload['action'] ?? '') === 'review') {
    pc_recommend_catalog($db);
    $buildMap = pc_recommend_build_map($db, is_array($payload['build'] ?? null) ? $payload['build'] : []);
    echo json_encode(
        ['ok' => true] + pc_recommend_review($buildMap, $ctx) + ['rating' => pc_build_rating($buildMap, $ctx['purpose'])],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

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
$products = pc_recommend_products_for_slot($db, $slot);
if ($q !== '') {
    $products = array_values(array_filter($products, static function (array $p) use ($q): bool {
        $hay = $p['name'] . ' ' . $p['brand'] . ' ' . $p['type'];
        return mb_stripos($hay, $q) !== false;
    }));
}

$buildMap = pc_recommend_build_map($db, is_array($payload['build'] ?? null) ? $payload['build'] : []);
$products = pc_recommend_apply($slot, $products, $buildMap, $ctx);

echo json_encode([
    'ok' => true,
    'slot' => $slot,
    'label' => $meta['label'],
    'products' => $products,
], JSON_UNESCAPED_UNICODE);
