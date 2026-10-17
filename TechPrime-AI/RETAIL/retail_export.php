<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/retail_reports.php';
require_once __DIR__ . '/../includes/pdf_report.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

$retailId = (int)$_SESSION['user_id'];
$type = $_GET['type'] ?? 'sales';           // sales | delivery | dashboard
$format = $_GET['format'] ?? 'csv';         // csv | excel | pdf
if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
    $format = 'csv';
}
$range = ias_resolve_report_range($_GET);
$from = $range['from'];
$to = $range['to'];
$rangeLabel = $from->format('M d, Y') . ' - ' . $to->format('M d, Y');
$preparedBy = 'Prepared by ' . ($_SESSION['name'] ?? 'Retail Officer') . ' on ' . ias_report_datetime(gmdate('Y-m-d H:i:s'));
$peso = fn($v) => 'PHP ' . number_format((float)$v, 2);
$compact = function ($v): string {   // short axis/bar labels for money charts
    $v = (float)$v;
    if ($v >= 1000000) {
        return number_format($v / 1000000, 1) . 'M';
    }
    return $v >= 1000 ? number_format($v / 1000, $v >= 10000 ? 0 : 1) . 'k' : number_format($v);
};

if ($type === 'dashboard') {
    $dash = ias_retail_dashboard_data($db, $retailId, $_GET);
    $card = $dash['card_summary'];
    $cardRange = $dash['card_range'];
    $cardLabel = $cardRange['label'] . ' (' . $cardRange['from']->format('M d, Y') . ' - ' . $cardRange['to']->format('M d, Y') . ')';
    $period = $dash['range'];
    $periodLabel = $period['label'] . ' (' . $period['from']->format('M d, Y') . ' - ' . $period['to']->format('M d, Y') . ')'
        . ' - ' . ($dash['category'] !== '' ? $dash['category'] : 'All categories');

    $monthlyRows = [];
    foreach ($dash['monthly_sales'] as $m) {
        $monthlyRows[] = [$m['month'], number_format((float)$m['total_sales'], 2, '.', ''), (int)$m['transactions'], (int)$m['units_sold'], number_format((float)$m['avg_transaction'], 2, '.', '')];
    }
    $productRows = [];
    foreach ($dash['by_group'] as $group => $items) {
        foreach ($items as $it) {
            $productRows[] = [$group, $it['product'], (int)$it['units']];
        }
    }
    $trendRows = [];
    foreach ($dash['trend_labels'] as $i => $label) {
        $trendRows[] = [$label, (int)$dash['trend_data'][$i]];
    }

    logActivity($db, $retailId, 'export_dashboard', "Exported retail dashboard ($format)");

    if ($format === 'pdf') {
        $pdf = new IasPdfReport('Retail Dashboard', ['Summary: ' . $cardLabel, $preparedBy], 'P');
        $pdf->heading('Summary', $cardLabel);
        $pdf->summary([
            ['Total Sales', $peso($card['total_sales'])],
            ['Transactions', number_format($card['transactions'])],
            ['Units Sold', number_format($card['units_sold'])],
            ['Avg. Transaction', $peso($card['avg_transaction'])],
        ]);
        $pdf->heading('Monthly Sales Report', 'Revenue by month, last ' . $dash['ms_months'] . ' months', 184);
        $pdf->barChart(array_column($dash['monthly_sales'], 'month'), array_column($dash['monthly_sales'], 'total_sales'), 180, $compact);
        $tot = ['sales' => 0.0, 'tx' => 0, 'units' => 0];
        $msTable = [];
        foreach ($dash['monthly_sales'] as $m) {
            $tot['sales'] += (float)$m['total_sales'];
            $tot['tx'] += (int)$m['transactions'];
            $tot['units'] += (int)$m['units_sold'];
            $msTable[] = [$m['month'], $peso($m['total_sales']), number_format($m['transactions']), number_format($m['units_sold']), $peso($m['avg_transaction'])];
        }
        $msTable[] = ['Total', $peso($tot['sales']), number_format($tot['tx']), number_format($tot['units']), $peso($tot['tx'] > 0 ? $tot['sales'] / $tot['tx'] : 0)];
        $pdf->table(['Month', 'Total Sales', 'Transactions', 'Units Sold', 'Avg. Transaction'], $msTable,
            ['widths' => [1.2, 1.4, 1, 1, 1.4], 'align' => ['L', 'R', 'R', 'R', 'R'], 'total_row' => true]);

        $pdf->heading('Sales by Category', 'Units delivered · ' . $periodLabel, 174);
        $pdf->barChart($dash['trend_labels'], $dash['trend_data'], 170);
        $pdf->table(['Category Group', 'Product', 'Units'],
            array_map(fn($r) => [$r[0], $r[1], number_format($r[2])], $productRows),
            ['widths' => [1.2, 3.6, 0.8], 'align' => ['L', 'L', 'R']]);
        $pdf->output(ias_export_filename('retail_dashboard', 'pdf'));
    }

    ias_emit_csv_sections(ias_export_filename('retail_dashboard', 'csv'), [
        ['Retail Dashboard'],
        [$preparedBy],
    ], [
        ['title' => 'Summary - ' . $cardLabel, 'headers' => ['Metric', 'Value'], 'rows' => [
            ['Total Sales (PHP)', number_format($card['total_sales'], 2, '.', '')],
            ['Transactions', $card['transactions']],
            ['Units Sold', $card['units_sold']],
            ['Avg. Transaction (PHP)', number_format($card['avg_transaction'], 2, '.', '')],
        ]],
        ['title' => 'Monthly Sales Report - last ' . $dash['ms_months'] . ' months', 'headers' => ['Month', 'Total Sales (PHP)', 'Transactions', 'Units Sold', 'Avg. Transaction (PHP)'], 'rows' => $monthlyRows],
        ['title' => 'Sales by Category - units delivered per product - ' . $periodLabel, 'headers' => ['Category Group', 'Product', 'Units'], 'rows' => $productRows],
        ['title' => 'Units delivered per ' . ($dash['trend_by_month'] ? 'month' : 'day') . ' - ' . $periodLabel, 'headers' => [$dash['trend_by_month'] ? 'Month' : 'Date', 'Units'], 'rows' => $trendRows],
    ]);
}

if ($type === 'delivery') {
    $filters = [
        'customer' => trim($_GET['customer'] ?? ''),
        'product' => trim($_GET['product'] ?? ''),
    ];
    $rows = ias_fetch_delivery_rows($db, $retailId, $from, $to, $filters);
    logActivity($db, $retailId, 'export_delivery_history', "Exported delivery history ($format) for $rangeLabel");

    if ($format === 'pdf') {
        $stats = ias_summarize_deliveries($db, $retailId, $from, $to, count($rows));
        $total = array_sum(array_map(fn($r) => (float)$r['total'], $rows));
        $pdf = new IasPdfReport('Sales History', ['Completed deliveries · ' . $rangeLabel, $preparedBy], 'L');
        $pdf->summary([
            ['Total Deliveries', number_format($stats['delivered'])],
            ['Shipments in Range', number_format($stats['total_shipments_in_range'])],
            ['Success Rate', number_format($stats['success_rate'], 1) . '%'],
            ['Delivered Order Value', $peso($total)],
        ]);
        $table = [];
        foreach ($rows as $r) {
            $table[] = [
                '#' . $r['shipment_id'],
                '#' . $r['order_id'],
                trim($r['customer_name'] . ' ' . $r['customer_surname']) . ($r['customer_email'] ? "\n" . $r['customer_email'] : ''),
                (string)$r['products'],
                implode(', ', ias_category_groups_in($r['categories'] ?? '')),
                $r['carrier'],
                $peso($r['total']),
                ias_report_datetime($r['updated_at']),
            ];
        }
        if ($table) {
            $table[] = ['Total', '', count($rows) . ' deliveries', '', '', '', $peso($total), ''];
        }
        $pdf->heading('Completed Deliveries', count($rows) . ' record' . (count($rows) === 1 ? '' : 's'));
        $pdf->table(['Shipment', 'Order', 'Customer', 'Products', 'Category', 'Carrier', 'Order Total', 'Delivered On'], $table,
            ['widths' => [0.7, 0.6, 1.9, 3.2, 1.1, 0.9, 1.1, 1.3], 'align' => ['L', 'L', 'L', 'L', 'L', 'L', 'R', 'L'], 'total_row' => (bool)$table]);
        $pdf->output(ias_export_filename('sales_history', 'pdf'));
    }

    $items = ias_fetch_order_items_by_order($db, array_column($rows, 'order_id'));
    $unitsOf = fn(array $r): int => array_sum(array_map(fn($it) => (int)$it['quantity'], $items[(int)$r['order_id']] ?? []));
    $local = fn(?string $utc): string => ias_report_datetime($utc, 'Y-m-d H:i:s');

    if ($format === 'excel') {
        // Excel = formatted workbook for reading/analysis: Summary, Deliveries and per-product Items sheets.
        $X = 'IasXlsxWriter';
        $stats = ias_summarize_deliveries($db, $retailId, $from, $to, count($rows));
        $total = array_sum(array_map(fn($r) => (float)$r['total'], $rows));
        $byCat = [];
        $byCarrier = [];
        foreach ($rows as $r) {
            foreach (ias_category_groups_in($r['categories'] ?? '') ?: ['Uncategorized'] as $g) {
                $byCat[$g] = [($byCat[$g][0] ?? 0) + 1, ($byCat[$g][1] ?? 0) + (float)$r['total']];
            }
            $byCarrier[$r['carrier']] = [($byCarrier[$r['carrier']][0] ?? 0) + 1, ($byCarrier[$r['carrier']][1] ?? 0) + (float)$r['total']];
        }
        uasort($byCat, fn($a, $b) => $b[0] <=> $a[0]);
        uasort($byCarrier, fn($a, $b) => $b[0] <=> $a[0]);

        $sum = [
            [['v' => 'Sales History — Completed Deliveries', 's' => $X::STYLE_TITLE]],
            [['v' => $rangeLabel . ' · ' . $preparedBy, 's' => $X::STYLE_NOTE]],
            [],
            [['v' => 'Metric', 's' => $X::STYLE_HEADER], ['v' => 'Value', 's' => $X::STYLE_HEADER], ['v' => '', 's' => $X::STYLE_HEADER]],
            ['Total Deliveries', ['v' => (int)$stats['delivered'], 's' => $X::STYLE_INT]],
            ['Shipments in Range', ['v' => (int)$stats['total_shipments_in_range'], 's' => $X::STYLE_INT]],
            ['Success Rate', ['v' => round($stats['success_rate'] / 100, 4), 's' => $X::STYLE_PERCENT]],
            ['Delivered Order Value', ['v' => round($total, 2), 's' => $X::STYLE_MONEY]],
            ['Average Order Value', ['v' => $rows ? round($total / count($rows), 2) : 0, 's' => $X::STYLE_MONEY]],
            [],
            [['v' => 'Category', 's' => $X::STYLE_HEADER], ['v' => 'Deliveries', 's' => $X::STYLE_HEADER], ['v' => 'Order Value', 's' => $X::STYLE_HEADER]],
        ];
        foreach ($byCat as $g => [$cnt, $val]) {
            $sum[] = [$g, ['v' => $cnt, 's' => $X::STYLE_INT], ['v' => round($val, 2), 's' => $X::STYLE_MONEY]];
        }
        $sum[] = [['v' => 'An order with products from several categories is counted under each of them.', 's' => $X::STYLE_NOTE]];
        $sum[] = [];
        $sum[] = [['v' => 'Carrier', 's' => $X::STYLE_HEADER], ['v' => 'Deliveries', 's' => $X::STYLE_HEADER], ['v' => 'Order Value', 's' => $X::STYLE_HEADER]];
        foreach ($byCarrier as $c => [$cnt, $val]) {
            $sum[] = [$c, ['v' => $cnt, 's' => $X::STYLE_INT], ['v' => round($val, 2), 's' => $X::STYLE_MONEY]];
        }

        $hdr = fn(array $names) => array_map(fn($n) => ['v' => $n, 's' => $X::STYLE_HEADER], $names);
        $del = [$hdr(['Shipment #', 'Order #', 'Customer', 'Email', 'Products', 'Category', 'Carrier', 'Units', 'Order Total', 'Order Placed', 'Delivered On'])];
        foreach ($rows as $r) {
            $products = array_map(fn($it) => $it['product_name'] . ' ×' . (int)$it['quantity'], $items[(int)$r['order_id']] ?? []);
            $del[] = [
                (int)$r['shipment_id'],
                (int)$r['order_id'],
                trim($r['customer_name'] . ' ' . $r['customer_surname']),
                (string)$r['customer_email'],
                ['v' => $products ? implode("\n", $products) : (string)$r['products'], 's' => $X::STYLE_WRAP],
                implode(', ', ias_category_groups_in($r['categories'] ?? '')),
                $r['carrier'],
                ['v' => $unitsOf($r), 's' => $X::STYLE_INT],
                ['v' => round((float)$r['total'], 2), 's' => $X::STYLE_MONEY],
                ['v' => $X::excelDate($local($r['order_created'])), 's' => $X::STYLE_DATETIME],
                ['v' => $X::excelDate($local($r['updated_at'])), 's' => $X::STYLE_DATETIME],
            ];
        }
        $last = count($del);
        if ($rows) {
            $del[] = [['v' => 'Total', 's' => $X::STYLE_TOTAL_LABEL], ['v' => count($rows) . ' deliveries', 's' => $X::STYLE_TOTAL_LABEL],
                ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
                ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
                ['f' => "SUM(H2:H$last)", 's' => $X::STYLE_TOTAL_INT], ['f' => "SUM(I2:I$last)", 's' => $X::STYLE_TOTAL_MONEY],
                ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL]];
        }

        $itm = [$hdr(['Shipment #', 'Order #', 'Delivered On', 'Customer', 'Product', 'Category Group', 'Category', 'Qty', 'Unit Price', 'Line Total'])];
        foreach ($rows as $r) {
            foreach ($items[(int)$r['order_id']] ?? [] as $it) {
                $n = count($itm) + 1;
                $itm[] = [
                    (int)$r['shipment_id'],
                    (int)$r['order_id'],
                    ['v' => $X::excelDate($local($r['updated_at'])), 's' => $X::STYLE_DATETIME],
                    trim($r['customer_name'] . ' ' . $r['customer_surname']),
                    (string)$it['product_name'],
                    ias_category_group_of((string)$it['category']),
                    (string)$it['category'],
                    ['v' => (int)$it['quantity'], 's' => $X::STYLE_INT],
                    ['v' => round((float)$it['price'], 2), 's' => $X::STYLE_MONEY],
                    ['f' => "H$n*I$n", 's' => $X::STYLE_MONEY],
                ];
            }
        }
        $lastItem = count($itm);
        if ($lastItem > 1) {
            $itm[] = [['v' => 'Total', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
                ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => ($lastItem - 1) . ' line items', 's' => $X::STYLE_TOTAL_LABEL],
                ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
                ['f' => "SUM(H2:H$lastItem)", 's' => $X::STYLE_TOTAL_INT], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
                ['f' => "SUM(J2:J$lastItem)", 's' => $X::STYLE_TOTAL_MONEY]];
        }

        $xlsx = new IasXlsxWriter();
        $xlsx->addSheet('Summary', $sum, ['widths' => [34, 16, 18]]);
        $xlsx->addSheet('Deliveries', $del, ['widths' => [11, 9, 22, 30, 50, 22, 13, 8, 14, 21, 21], 'freeze' => 1, 'filter' => 'A1:K' . max(2, $last)]);
        $xlsx->addSheet('Items', $itm, ['widths' => [11, 9, 21, 22, 50, 16, 18, 7, 13, 14], 'freeze' => 1, 'filter' => 'A1:J' . max(2, $lastItem)]);
        $xlsx->output(ias_export_filename('sales_history', 'xlsx'));
    }

    // CSV = raw data for importing into other tools: one flat row per delivery, plain numbers, sortable local timestamps.
    $headers = ['Shipment ID', 'Order ID', 'Customer', 'Email', 'Products', 'Category', 'Carrier', 'Units', 'Order Total (PHP)', 'Order Placed (PHT)', 'Delivered On (PHT)'];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            $r['shipment_id'],
            $r['order_id'],
            trim($r['customer_name'] . ' ' . $r['customer_surname']),
            $r['customer_email'],
            $r['products'],
            implode(', ', ias_category_groups_in($r['categories'] ?? '')),
            $r['carrier'],
            $unitsOf($r),
            number_format((float)$r['total'], 2, '.', ''),
            $local($r['order_created']),
            $local($r['updated_at']),
        ];
    }
    ias_emit_csv(ias_export_filename('sales_history', 'csv'), $headers, $out);
}

// Sales report (default)
$filters = [
    'category' => trim($_GET['category'] ?? ''),
    'customer' => trim($_GET['customer'] ?? ''),
];
$rows = ias_fetch_sales_rows($db, $retailId, $from, $to, $filters);
logActivity($db, $retailId, 'export_sales_report', "Exported sales report ($format) for $rangeLabel");

if ($format === 'pdf') {
    $summary = ias_summarize_sales($rows);
    $filterNote = [];
    if ($filters['category'] !== '') {
        $filterNote[] = 'Category: ' . $filters['category'];
    }
    if ($filters['customer'] !== '') {
        $filterNote[] = 'Customer: ' . $filters['customer'];
    }
    $pdf = new IasPdfReport('Sales Report', [$range['label'] . ' · ' . $rangeLabel . ($filterNote ? ' · ' . implode(' · ', $filterNote) : ''), $preparedBy], 'L');
    $pdf->summary([
        ['Total Sales', $peso($summary['total_sales'])],
        ['Avg. Transaction Value', $peso($summary['avg_transaction'])],
        ['Units Sold', number_format($summary['units_sold'])],
        ['Transactions', number_format($summary['transactions'])],
    ]);

    $compare = $_GET['compare'] ?? 'none';
    if ($compare === 'previous' || $compare === 'yoy') {
        $p = $compare === 'previous' ? ias_previous_period($from, $to) : ias_year_ago_period($from, $to);
        $prev = ias_summarize_sales(ias_fetch_sales_rows($db, $retailId, $p['from'], $p['to'], $filters));
        $chg = function (float $cur, float $old): string {
            $pct = ias_pct_change($cur, $old);
            return $pct === null ? 'new' : (($pct > 0 ? '+' : '') . number_format($pct, 1) . '%');
        };
        $pdf->heading('Comparison', ($compare === 'previous' ? 'Previous period' : 'Same period last year') . ' · ' . $p['from']->format('M d, Y') . ' - ' . $p['to']->format('M d, Y'));
        $pdf->table(['Metric', 'This Period', 'Comparison Period', 'Change'], [
            ['Total Sales', $peso($summary['total_sales']), $peso($prev['total_sales']), $chg($summary['total_sales'], $prev['total_sales'])],
            ['Avg. Transaction Value', $peso($summary['avg_transaction']), $peso($prev['avg_transaction']), $chg($summary['avg_transaction'], $prev['avg_transaction'])],
            ['Units Sold', number_format($summary['units_sold']), number_format($prev['units_sold']), $chg($summary['units_sold'], $prev['units_sold'])],
            ['Transactions', number_format($summary['transactions']), number_format($prev['transactions']), $chg($summary['transactions'], $prev['transactions'])],
        ], ['widths' => [1.4, 1, 1, 0.7], 'align' => ['L', 'R', 'R', 'R']]);
    }

    $byCategory = ias_sales_by_category($rows);
    $pdf->heading('Sales by Category', '', 174);
    $pdf->barChart(array_column($byCategory, 'category'), array_column($byCategory, 'sales'), 170, $compact);
    $catTable = [];
    foreach ($byCategory as $c) {
        $share = $summary['total_sales'] > 0 ? $c['sales'] / $summary['total_sales'] * 100 : 0;
        $catTable[] = [$c['category'], number_format($c['units']), $peso($c['sales']), number_format($share, 1) . '%'];
    }
    $pdf->table(['Category', 'Units', 'Sales', 'Share'], $catTable, ['widths' => [2, 0.8, 1.2, 0.8], 'align' => ['L', 'R', 'R', 'R']]);

    $lines = [];
    foreach ($rows as $r) {
        $lines[] = [
            $r['ref'],
            ias_report_datetime($r['created_at']),
            trim($r['customer_name'] . ' ' . $r['customer_surname']),
            $r['product_name'],
            $r['category'] ?: 'Uncategorized',
            number_format((int)$r['quantity']),
            $peso($r['price']),
            $peso((float)$r['price'] * (int)$r['quantity']),
        ];
    }
    if ($lines) {
        $lines[] = ['Total', '', $summary['transactions'] . ' transactions', '', '', number_format($summary['units_sold']), '', $peso($summary['total_sales'])];
    }
    $pdf->heading('Sales Line Items', count($rows) . ' line item' . (count($rows) === 1 ? '' : 's'));
    $pdf->table(['Order #', 'Date', 'Customer', 'Product', 'Category', 'Qty', 'Unit Price', 'Line Total'], $lines,
        ['widths' => [1.35, 1.55, 1.5, 2.9, 1.1, 0.45, 1, 1.1], 'align' => ['L', 'L', 'L', 'L', 'L', 'R', 'R', 'R'], 'total_row' => (bool)$lines]);
    $pdf->output(ias_export_filename('sales_report', 'pdf'));
}

$headers = ['Order #', 'Date', 'Customer', 'Email', 'Product', 'Category', 'Qty', 'Unit Price (PHP)', 'Line Total (PHP)'];
$out = [];
foreach ($rows as $r) {
    $out[] = [
        $r['ref'],
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

$filename = ias_export_filename('sales_report', $format === 'excel' ? 'xls' : 'csv');
if ($format === 'excel') {
    ias_emit_excel($filename, 'Sales Report — ' . $rangeLabel, $headers, $out);
}
ias_emit_csv($filename, $headers, $out);
