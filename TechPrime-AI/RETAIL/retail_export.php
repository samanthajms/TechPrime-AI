<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/retail_reports.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

$retailId = (int)$_SESSION['user_id'];
$type = $_GET['type'] ?? 'sales';           // sales | delivery
$format = $_GET['format'] ?? 'csv';         // csv | excel
$range = ias_resolve_report_range($_GET);
$from = $range['from'];
$to = $range['to'];
$rangeLabel = $from->format('M d, Y') . ' - ' . $to->format('M d, Y');

if ($type === 'delivery') {
    $filters = [
        'customer' => trim($_GET['customer'] ?? ''),
        'product' => trim($_GET['product'] ?? ''),
    ];
    $rows = ias_fetch_delivery_rows($db, $retailId, $from, $to, $filters);

    $headers = ['Shipment #', 'Order #', 'Customer', 'Email', 'Products', 'Category', 'Carrier', 'Order Total (PHP)', 'Delivered On'];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            $r['shipment_id'],
            $r['order_id'],
            trim($r['customer_name'] . ' ' . $r['customer_surname']),
            $r['customer_email'],
            $r['products'],
            $r['categories'],
            $r['carrier'],
            number_format((float)$r['total'], 2, '.', ''),
            $r['updated_at'],
        ];
    }
    logActivity($db, $retailId, 'export_delivery_history', "Exported delivery history ($format) for $rangeLabel");

    $filename = ias_export_filename('delivery_history', $format === 'excel' ? 'xls' : 'csv');
    if ($format === 'excel') {
        ias_emit_excel($filename, 'Delivery History — ' . $rangeLabel, $headers, $out);
    }
    ias_emit_csv($filename, $headers, $out);
}

// Sales report (default)
$filters = [
    'category' => trim($_GET['category'] ?? ''),
    'customer' => trim($_GET['customer'] ?? ''),
];
$rows = ias_fetch_sales_rows($db, $retailId, $from, $to, $filters);

$headers = ['Order #', 'Date', 'Customer', 'Email', 'Product', 'Category', 'Qty', 'Unit Price (PHP)', 'Line Total (PHP)'];
$out = [];
foreach ($rows as $r) {
    $out[] = [
        $r['order_id'],
        $r['created_at'],
        trim($r['customer_name'] . ' ' . $r['customer_surname']),
        $r['customer_email'],
        $r['product_name'],
        $r['category'],
        $r['quantity'],
        number_format((float)$r['price'], 2, '.', ''),
        number_format((float)$r['price'] * (int)$r['quantity'], 2, '.', ''),
    ];
}
logActivity($db, $retailId, 'export_sales_report', "Exported sales report ($format) for $rangeLabel");

$filename = ias_export_filename('sales_report', $format === 'excel' ? 'xls' : 'csv');
if ($format === 'excel') {
    ias_emit_excel($filename, 'Sales Report — ' . $rangeLabel, $headers, $out);
}
ias_emit_csv($filename, $headers, $out);
