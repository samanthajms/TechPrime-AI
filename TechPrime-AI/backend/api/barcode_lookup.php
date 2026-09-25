<?php
/**
 * Cashier: look up a product by scanned UPC/EAN barcode.
 * GET ?code=0123456789012  →  {ok, product:{id,name,category,price,stock,status,barcode}}
 */
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../includes/pos_helpers.php';

pos_api_require_cashier('GET');

$code = (string)($_GET['code'] ?? '');
if (strlen($code) > 32) {
    pos_json(200, ['ok' => false, 'error' => 'invalid_barcode', 'message' => 'Barcode is too long.']);
}

$db = getDbConnection();
$result = pos_find_product_by_barcode($db, $code);
// Not found / invalid code are normal scan outcomes: 200 with ok:false (same as inventory_notif_api.php).
pos_json(200, $result);
