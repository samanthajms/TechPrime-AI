<?php
/**
 * Ensure useful indexes for existing client product listing queries.
 * Safe / idempotent. Does not alter product data.
 */
require_once dirname(__DIR__) . '/backend/config/database.php';
$db = getDbConnection();

$indexes = [
    'idx_products_category' => 'CREATE INDEX IF NOT EXISTS idx_products_category ON products (category)',
    'idx_products_seller_stock' => 'CREATE INDEX IF NOT EXISTS idx_products_seller_stock ON products (seller_id, stock)',
    'idx_products_created_at' => 'CREATE INDEX IF NOT EXISTS idx_products_created_at ON products (created_at DESC)',
    'idx_products_price' => 'CREATE INDEX IF NOT EXISTS idx_products_price ON products (price)',
];

foreach ($indexes as $name => $sql) {
    $db->exec($sql);
    echo "OK $name\n";
}
