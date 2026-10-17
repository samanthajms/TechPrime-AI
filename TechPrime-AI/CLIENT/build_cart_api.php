<?php
/**
 * Build a PC — put the current build in the cart, or stage it for Buy Now (JSON).
 * Customers only: guests can build, but tech_match.js asks them to log in first.
 * Parts are re-read from the database (visibility, stock, compatibility); only the
 * slot => product id pairs from the browser are used.
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/pc_compatibility.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bc_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

checkSessionTimeout();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bc_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    bc_json(['ok' => false, 'error' => 'auth_required', 'message' => 'Please log in to your customer account to buy this build.'], 401);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}
if (!verifyCsrfToken((string)($payload['csrf_token'] ?? ''))) {
    bc_json(['ok' => false, 'error' => 'csrf', 'message' => 'Invalid security token. Refresh and try again.'], 403);
}

$action = (string)($payload['action'] ?? '');
if (!in_array($action, ['add_to_cart', 'buy_now'], true)) {
    bc_json(['ok' => false, 'error' => 'invalid_action'], 400);
}

// Same slots as tech_match_api.php / tech_match.js.
$slots = ['processor', 'motherboard', 'memory', 'ssd', 'ssd_sata', 'hdd', 'gpu', 'psu', 'case', 'cooler', 'case_fan'];
$picked = [];
foreach ((array)($payload['components'] ?? []) as $slot => $item) {
    $pid = (int)(is_array($item) ? ($item['id'] ?? 0) : $item);
    if (in_array((string)$slot, $slots, true) && $pid > 0) {
        $picked[(string)$slot] = $pid;
    }
}
if (!$picked) {
    bc_json(['ok' => false, 'error' => 'empty_build', 'message' => 'Select at least one component first.']);
}

$db = getDbConnection();
$ids = array_values(array_unique($picked));
$stmt = $db->prepare(
    'SELECT p.* FROM products p WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
       AND ' . ias_client_product_list_sql_condition('p')
);
$stmt->execute($ids);
$rows = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rows[(int)$row['id']] = $row;
}

$components = [];
$available = [];   // slot => product row
$unavailable = []; // product names (or a placeholder for hidden products)
foreach ($picked as $slot => $pid) {
    $row = $rows[$pid] ?? null;
    if (!$row) {
        $unavailable[] = 'A selected ' . str_replace('_', ' ', $slot) . ' is no longer sold';
        continue;
    }
    $components[$slot] = $row;
    if ((int)($row['stock'] ?? 0) < 1 || ias_client_product_image_url($row) === '') {
        $unavailable[] = (string)$row['name'];
    } else {
        $available[$slot] = $row;
    }
}

$compat = ep_pc_compat_validate_build($components);
if (!$compat['ok']) {
    bc_json([
        'ok' => false,
        'error' => 'incompatible',
        'message' => "This build has incompatible components:\n• " . implode("\n• ", array_slice($compat['issues'], 0, 5)),
    ]);
}

if ($action === 'buy_now') {
    if ($unavailable) {
        bc_json([
            'ok' => false,
            'error' => 'unavailable',
            'message' => "Some parts are out of stock. Swap or remove them, then try again:\n• " . implode("\n• ", array_slice($unavailable, 0, 5)),
        ]);
    }
    // Staged for checkout only (like a product's Buy Now); the cart is left untouched.
    $buyNow = [];
    foreach ($available as $row) {
        $pid = (int)$row['id'];
        $buyNow[$pid] = min(($buyNow[$pid] ?? 0) + 1, (int)$row['stock']);
    }
    $_SESSION['buy_now'] = $buyNow;
    bc_json(['ok' => true, 'redirect' => 'checkout.php']);
}

if (!$available) {
    bc_json([
        'ok' => false,
        'error' => 'unavailable',
        'message' => 'None of the components in this build are currently available to add to cart.',
    ]);
}

$added = 0;
foreach ($available as $row) {
    if (ep_add_product_to_cart($db, (int)$row['id'], 1)) {
        $added++;
    }
}
$msg = $added . ' item' . ($added === 1 ? '' : 's') . ' added to cart.';
if ($unavailable) {
    $msg .= "\nNot added (out of stock):\n• " . implode("\n• ", array_slice($unavailable, 0, 5));
}
bc_json(['ok' => true, 'added' => $added, 'message' => $msg, 'cart' => ep_get_cart_preview($db)]);
