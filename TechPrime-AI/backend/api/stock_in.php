<?php
/**
 * Cashier: receive stock for a scanned product (stock-in).
 * POST JSON {csrf_token, product_id, qty, supplier?, reference_no?}
 * → {ok, product, stock_before, stock_after, quantity}
 */
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/pos_helpers.php';

$input = pos_api_require_cashier('POST');

$qtyRaw = (string)($input['qty'] ?? '');
if (!preg_match('/^\d{1,6}$/', $qtyRaw)) {
    pos_json(200, ['ok' => false, 'error' => 'invalid_quantity', 'message' => 'Quantity must be a whole number.']);
}

$db = getDbConnection();
$result = pos_stock_in(
    $db,
    (int)$_SESSION['user_id'],
    (int)($input['product_id'] ?? 0),
    (int)$qtyRaw,
    (string)($input['supplier'] ?? ''),
    (string)($input['reference_no'] ?? '')
);

if ($result['ok']) {
    pos_json(200, $result);
}
pos_json($result['error'] === 'exception' ? 500 : 200, $result);
