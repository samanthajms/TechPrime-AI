<?php
/**
 * Cashier: complete a POS sale (stock-out).
 * POST JSON {csrf_token, client_ref, items:[{product_id, qty}], payment:{method, tendered?, ref?}, expected_total}
 * → {ok, sale_id, invoice_no, total, change, duplicate}
 */
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/pos_helpers.php';

$input = pos_api_require_cashier('POST');

$items = is_array($input['items'] ?? null) ? array_values($input['items']) : [];
$payment = is_array($input['payment'] ?? null) ? $input['payment'] : [];
$expected = isset($input['expected_total']) ? pos_parse_money_input($input['expected_total']) : null;

$cashierName = trim(($_SESSION['name'] ?? '') . ' ' . ($_SESSION['surname'] ?? ''));

$db = getDbConnection();
$result = pos_complete_sale(
    $db,
    (int)$_SESSION['user_id'],
    $cashierName,
    $items,
    $payment,
    (string)($input['client_ref'] ?? ''),
    $expected
);

if ($result['ok']) {
    pos_json(200, $result);
}
// Business rejections (stock, payment, prices) are 200 with ok:false; only a server failure is 500.
pos_json($result['error'] === 'exception' ? 500 : 200, $result);
