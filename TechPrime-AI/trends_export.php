<?php
/**
 * Trend Analysis page downloads (Product Trends or Sales Trends) as CSV, Excel (.xlsx) or PDF.
 * Same filters as the page (query keys from includes/trends_cards.php: df_* for demand, sf_* for sales), and the same
 * figures the page script shows (includes/trends_cards.php ias_forecast_cards_script()), read from the forecast
 * service on the server (includes/trends_client.php).
 * Usage: trends_export.php?view=demand|sales&format=csv|xlsx|pdf&...   (links: includes/staff_trends_page.php)
 */
session_start();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/trends_client.php';   // also loads backend/config/database.php
require_once __DIR__ . '/includes/staff_layout.php';      // staff_role_label()
require_once __DIR__ . '/includes/product_categories.php';
require_once __DIR__ . '/includes/trends_cards.php';    // ias_forecast_selection(); loads retail_reports.php (CSV + file name helpers)
require_once __DIR__ . '/includes/pdf_report.php';
require_once __DIR__ . '/includes/xlsx_writer.php';

checkSessionTimeout();
checkRole(['admin', 'inventory_custodian', 'retail_officer']);

$role = (string)$_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$view = (($_GET['view'] ?? '') === 'sales') ? 'sales' : 'demand';
$format = in_array($_GET['format'] ?? '', ['csv', 'xlsx', 'pdf'], true) ? (string)$_GET['format'] : 'csv';
$fc = ias_forecast_selection(ias_category_groups());
$sel = $view === 'sales' ? $fc['sf'] : $fc['df'];
$name = trim((string)($_SESSION['name'] ?? '') . ' ' . (string)($_SESSION['surname'] ?? ''));
$preparedBy = 'Prepared by ' . ($name !== '' ? $name : 'Staff') . ', ' . staff_role_label($role) . ' on ' . ias_report_datetime(gmdate('Y-m-d H:i:s'));

// Nothing below writes to $_SESSION; a cold forecast service can take ~50 s, so don't hold the user's other tabs.
release_session_lock();
set_time_limit(180);

$query = ['horizon' => $sel['months'], 'from' => $sel['range']['from']->format('Y-m-d'), 'to' => $sel['range']['to']->format('Y-m-d')];
if ($view === 'demand' && $sel['category'] !== '') {
    $query['category'] = $sel['category'];
}
$data = ias_forecast_json($view === 'sales' ? 'revenue' : 'demand', $query + ['top' => 50]);
if ($data === null) {
    // Back to the Trend Analysis page, same filters, with a message (staff_forecast_page() shows it)
    $pages = ['admin' => 'ADMIN/admin_trends.php', 'retail_officer' => 'RETAIL/retail_trends.php', 'inventory_custodian' => 'INVENTORY/inventory_trends.php'];
    $back = array_diff_key($_GET, ['format' => 1]) + ['export_failed' => 1];
    header('Location: ' . $pages[$role] . '?' . http_build_query($back));
    exit;
}
// Stock figures are optional, exactly like on the page (no stock snapshot on the service = no inventory columns)
$acts = $view === 'demand' ? ias_forecast_json('actions', $query + ['top' => 300]) : null;

/* ---------------------------------------------------------------- shared labels */
$rangeText = $sel['range']['label'] . ' (' . $sel['range']['from']->format('M j, Y') . ' – ' . $sel['range']['to']->format('M j, Y') . ')';
$windowText = 'Next ' . $sel['months'] . ($sel['months'] === 1 ? ' month' : ' months');
$filterLines = ['Sales history shown: ' . $rangeText, 'Projection window: ' . $windowText];
if ($view === 'demand') {
    $filterLines[] = 'Category: ' . ($sel['category'] !== '' ? $sel['category'] : 'All products');
}
$title = $view === 'sales' ? 'Sales Trend Analysis Report' : 'Product Trend Analysis Report';
$fileBase = $view === 'sales' ? 'sales_trends' : 'product_trends';
$pct = fn($v) => ($v === null || $v === '') ? '' : (((float)$v > 0 ? '+' : '') . rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.') . '%');
$peso = fn($v) => 'PHP ' . number_format((float)$v, 2);
$whole = fn($v) => number_format((float)$v, (floor((float)$v) == (float)$v) ? 0 : 1);
$pctOrNull = fn($v) => ($v === null || $v === '') ? null : (float)$v;
$X = 'IasXlsxWriter';
$xPct = fn($v) => $v === null || $v === '' ? '' : ['v' => round((float)$v / 100, 4), 's' => $X::STYLE_PERCENT];
$xHead = fn(array $names) => array_map(fn($n) => ['v' => $n, 's' => $X::STYLE_HEADER], $names);

logActivity(getDbConnection(), $userId, 'export_forecast',
    staff_role_label($role) . ' exported the ' . ($view === 'sales' ? 'sales' : 'product') . " trends ($format)");

if ($view === 'demand') {
    /* ------------------------------------------------------------ Product demand */
    $rows = $data['products'] ?? [];
    $total = $data['total'] ?? [];
    $byMsku = [];
    foreach (($acts['products'] ?? []) as $a) {
        $byMsku[$a['msku']] = $a;
    }
    $hasActs = $acts !== null;
    $first = $total[0] ?? [];
    $units = array_sum(array_column($total, 'predicted_units'));
    $revenue = array_sum(array_column($total, 'predicted_revenue'));
    $need = $urgent = 0;
    $risk = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    $items = [];
    foreach ($rows as $p) {
        $a = $byMsku[$p['msku']] ?? null;
        if ($a && in_array($a['action'] ?? '', ['order_now', 'reorder'], true)) {
            $need++;
            $urgent += ($a['action'] === 'order_now') ? 1 : 0;
        }
        $riskText = $a ? trim(($risk[$a['stockout_risk'] ?? ''] ?? '') . (!empty($a['overstock']) ? ' · Overstock' : ''), ' ·') : '';
        $order = '';
        if ($a && (float)($a['recommended_order_qty'] ?? 0) > 0) {
            $order = (float)$a['recommended_order_qty'];
        } elseif ($a && (float)($a['on_order'] ?? 0) > 0) {
            $order = 'On order: ' . $whole($a['on_order']);
        }
        $items[] = [
            'product' => (string)$p['product_name'], 'category' => (string)($p['category'] ?? ''),
            'sold' => $p['range_units'] ?? null, 'units' => (float)($p['forecast_units'] ?? 0),
            'low' => (float)($p['forecast_units_low'] ?? 0), 'high' => (float)($p['forecast_units_high'] ?? 0),
            'revenue' => (float)($p['forecast_revenue'] ?? 0), 'trend' => $pctOrNull($p['trend_pct'] ?? null),
            'confidence' => trim(($p['confidence'] ?? '') . ' · ' . ($p['model_reliability'] ?? 'unknown'), ' ·'),
            'stock' => $a['stock_on_hand'] ?? null, 'days' => $a['days_of_inventory'] ?? null,
            'safety' => $a['safety_stock'] ?? null, 'reorder' => $a['reorder_point'] ?? null,
            'risk' => $riskText, 'order' => $order,
        ];
    }
    $kpis = [
        ['Projected units (' . strtolower($windowText) . ')', $whole($units)],
        ['Projected revenue (' . strtolower($windowText) . ')', $peso($revenue)],
        ['Units MoM (' . ($first['month'] ?? '—') . ' vs last actual month)', $pct($first['units_mom_pct'] ?? null) ?: '—'],
    ];
    if ($hasActs) {
        $kpis[] = ['Need ordering', $need . ' (' . $urgent . ' urgent)'];
    }
    $stockNote = 'Stock levels are not connected to the trend analysis yet, so stock, reorder point and order quantity are not included.';
    if ($hasActs) {
        try {   // ISO time from the service, shown in store time like on the page
            $asOf = (new DateTime((string)($acts['stock_as_of'] ?? 'invalid')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y g:i A');
        } catch (Throwable $e) {
            $asOf = 'unknown';
        }
        $stockNote = 'Stock as of ' . $asOf
            . ' · lead time assumed ' . ($acts['assumptions']['lead_time_days_default'] ?? '?') . ' days (no supplier data yet)'
            . ' · order quantity covers ' . ($acts['assumptions']['review_days'] ?? '?') . ' days of demand.';
    }
    $baseHead = ['Product', 'Category', 'Units sold (date range)', 'Projected units', 'Likely low', 'Likely high', 'Projected revenue (PHP)', 'Trend', 'Confidence'];
    $invHead = ['Stock', 'Days left', 'Safety stock', 'Reorder point', 'Stockout risk', 'Order qty'];
    $monthHead = ['Month', 'Projected units', 'Projected revenue (PHP)', 'Units MoM'];

    if ($format === 'csv') {
        $csvRows = [];
        foreach ($items as $it) {
            $r = [$it['product'], $it['category'], $it['sold'], $it['units'], $it['low'], $it['high'], round($it['revenue'], 2), $pct($it['trend']), $it['confidence']];
            if ($hasActs) {
                array_push($r, $it['stock'], $it['days'], $it['safety'], $it['reorder'], $it['risk'], $it['order']);
            }
            $csvRows[] = $r;
        }
        $csvMonths = array_map(fn($m) => [$m['month'], $m['predicted_units'], round((float)$m['predicted_revenue'], 2), $pct($m['units_mom_pct'] ?? null)], $total);
        ias_emit_csv_sections(ias_export_filename($fileBase, 'csv'), array_merge([[$title]], array_map(fn($l) => [$l], $filterLines), [[$preparedBy]]), [
            ['title' => 'Summary', 'headers' => ['Metric', 'Value'], 'rows' => $kpis],
            ['title' => 'Projected by month', 'headers' => $monthHead, 'rows' => $csvMonths],
            ['title' => 'Products (top ' . count($items) . ' by projected units)', 'headers' => $hasActs ? array_merge($baseHead, $invHead) : $baseHead, 'rows' => $csvRows],
            ['title' => 'Notes', 'headers' => [$stockNote], 'rows' => [['Likely low / high = the range the projection expects actual units to fall in.']]],
        ]);
    }

    if ($format === 'xlsx') {
        $sum = [[['v' => $title, 's' => $X::STYLE_TITLE]]];
        foreach ($filterLines as $l) {
            $sum[] = [['v' => $l, 's' => $X::STYLE_NOTE]];
        }
        $sum[] = [['v' => $preparedBy, 's' => $X::STYLE_NOTE]];
        $sum[] = [];
        $sum[] = $xHead(['Metric', 'Value', '']);
        $sum[] = ['Projected units (' . strtolower($windowText) . ')', ['v' => $units, 's' => $X::STYLE_INT]];
        $sum[] = ['Projected revenue (' . strtolower($windowText) . ')', ['v' => round($revenue, 2), 's' => $X::STYLE_MONEY]];
        $sum[] = ['Units MoM (' . ($first['month'] ?? '—') . ' vs last actual month)', $xPct($first['units_mom_pct'] ?? null)];
        if ($hasActs) {
            $sum[] = ['Need ordering', ['v' => $need, 's' => $X::STYLE_INT]];
            $sum[] = ['Urgent (stockout risk high)', ['v' => $urgent, 's' => $X::STYLE_INT]];
        }
        $sum[] = [];
        $sum[] = $xHead($monthHead);
        foreach ($total as $m) {
            $sum[] = [(string)$m['month'], ['v' => (float)$m['predicted_units'], 's' => $X::STYLE_INT], ['v' => round((float)$m['predicted_revenue'], 2), 's' => $X::STYLE_MONEY], $xPct($m['units_mom_pct'] ?? null)];
        }
        $sum[] = [];
        $sum[] = [['v' => $stockNote, 's' => $X::STYLE_NOTE]];

        $prod = [$xHead($hasActs ? array_merge($baseHead, $invHead) : $baseHead)];
        foreach ($items as $it) {
            $r = [$it['product'], $it['category'], $it['sold'] === null ? '' : ['v' => (float)$it['sold'], 's' => $X::STYLE_INT],
                ['v' => $it['units'], 's' => $X::STYLE_INT], ['v' => $it['low'], 's' => $X::STYLE_INT], ['v' => $it['high'], 's' => $X::STYLE_INT],
                ['v' => round($it['revenue'], 2), 's' => $X::STYLE_MONEY], $xPct($it['trend']), $it['confidence']];
            if ($hasActs) {
                foreach (['stock', 'days', 'safety', 'reorder'] as $k) {
                    $r[] = $it[$k] === null ? '' : ['v' => (float)$it[$k], 's' => $X::STYLE_INT];
                }
                $r[] = $it['risk'];
                $r[] = is_float($it['order']) ? ['v' => $it['order'], 's' => $X::STYLE_INT] : $it['order'];
            }
            $prod[] = $r;
        }
        $cols = count($prod[0]);
        $x = new IasXlsxWriter();
        $x->addSheet('Summary', $sum, ['widths' => [44, 18, 22, 14]]);
        $x->addSheet('Products', $prod, ['widths' => array_slice([48, 16, 14, 12, 10, 10, 18, 9, 16, 9, 10, 11, 12, 16, 14], 0, $cols),
            'freeze' => 1, 'filter' => 'A1:' . IasXlsxWriter::col($cols - 1) . max(2, count($prod))]);
        $x->output(ias_export_filename($fileBase, 'xlsx'));
    }

    // PDF: landscape, like the printed report (14 columns)
    $pdf = new IasPdfReport($title, array_merge($filterLines, [$preparedBy]), 'L');
    $pdf->summary($kpis);
    $pdf->heading('Projected by month', $windowText, 80);
    $pdf->table($monthHead, array_map(fn($m) => [(string)$m['month'], $whole($m['predicted_units']), $peso($m['predicted_revenue']), $pct($m['units_mom_pct'] ?? null) ?: '—'], $total),
        ['widths' => [1, 1, 1.4, 1], 'align' => ['L', 'R', 'R', 'R']]);
    $pdf->heading('Products', 'Top ' . count($items) . ' by projected units · likely low – high = the range the projection expects actual units to fall in');
    $pdfRows = [];
    foreach ($items as $it) {
        $r = [$it['product'], $it['category'], $it['sold'] === null ? '—' : $whole($it['sold']), $whole($it['units']),
            $whole($it['low']) . ' – ' . $whole($it['high']), $peso($it['revenue']), $pct($it['trend']) ?: '—', $it['confidence']];
        if ($hasActs) {
            array_push($r, $it['stock'] === null ? '—' : $whole($it['stock']), $it['days'] === null ? '—' : $whole($it['days']) . ' d',
                $it['safety'] === null ? '—' : $whole($it['safety']), $it['reorder'] === null ? '—' : $whole($it['reorder']),
                $it['risk'] ?: '—', $it['order'] === '' ? '—' : (is_float($it['order']) ? $whole($it['order']) : $it['order']));
        }
        $pdfRows[] = $r;
    }
    $pdfHead = ['Product', 'Category', 'Units sold', 'Projected units', 'Likely range', 'Projected revenue', 'Trend', 'Confidence'];
    if ($hasActs) {
        $pdf->table(array_merge($pdfHead, ['Stock', 'Days left', 'Safety stock', 'Reorder point', 'Stockout risk', 'Order qty']), $pdfRows, [
            'widths' => [2.8, 1.1, 0.6, 0.75, 1.15, 1.45, 0.7, 1.45, 0.55, 0.6, 0.6, 0.65, 0.8, 0.6],
            'align' => ['L', 'L', 'R', 'R', 'R', 'R', 'R', 'L', 'R', 'R', 'R', 'R', 'L', 'R'], 'font_size' => 7.5]);
    } else {
        $pdf->table($pdfHead, $pdfRows, ['widths' => [3.6, 1.3, 0.9, 1, 1, 1.3, 0.8, 1.2], 'align' => ['L', 'L', 'R', 'R', 'R', 'R', 'R', 'L'], 'font_size' => 8]);
    }
    if (!$items) {
        $pdf->paragraph((string)($data['message'] ?? 'No projection available for this selection.'));
    }
    $pdf->paragraph($stockNote, 8);
    $pdf->output(ias_export_filename($fileBase, 'pdf'));
}

/* ---------------------------------------------------------------- Sales */
$total = $data['total'] ?? [];
$hist = $data['history'] ?? [];
$growth = $data['growth'] ?? [];
$first = $total[0] ?? [];
$projected = array_sum(array_column($total, 'predicted_revenue'));
$projUnits = array_sum(array_column($total, 'predicted_units'));
$lastActual = (string)($growth['last_actual_month'] ?? '');
$change = fn($now, $before) => (float)$before > 0 ? round(((float)$now / (float)$before - 1) * 100, 1) : null;

// Months: actual (MoM against the month before), then forecast (MoM from the service)
$months = [];
foreach ($hist as $i => $r) {
    $prev = $i ? $hist[$i - 1] : null;
    $months[] = ['month' => (string)$r['month'], 'type' => 'Actual', 'units' => (float)$r['actual_units'], 'sales' => (float)$r['actual_revenue'],
        'smom' => $prev ? $change($r['actual_revenue'], $prev['actual_revenue']) : null, 'umom' => $prev ? $change($r['actual_units'], $prev['actual_units']) : null,
        'low' => null, 'high' => null];
}
foreach ($total as $r) {
    $months[] = ['month' => (string)$r['month'], 'type' => 'Projected', 'units' => (float)$r['predicted_units'], 'sales' => (float)$r['predicted_revenue'],
        'smom' => $pctOrNull($r['revenue_mom_pct'] ?? null), 'umom' => $pctOrNull($r['units_mom_pct'] ?? null),
        'low' => (float)($r['revenue_confidence_low'] ?? 0), 'high' => (float)($r['revenue_confidence_high'] ?? 0)];
}
// By category over the whole window; MoM from each category's first forecast month
$byCat = [];
foreach (($data['by_category'] ?? []) as $r) {
    $k = &$byCat[$r['category']];
    $k ??= ['category' => (string)$r['category'], 'group' => (string)($r['group'] ?? ''), 'units' => 0.0, 'sales' => 0.0, 'month' => null, 'smom' => null, 'umom' => null];
    $k['units'] += (float)($r['predicted_units'] ?? 0);
    $k['sales'] += (float)($r['predicted_revenue'] ?? 0);
    if ($k['month'] === null || $r['month'] < $k['month']) {
        [$k['month'], $k['smom'], $k['umom']] = [$r['month'], $pctOrNull($r['revenue_mom_pct'] ?? null), $pctOrNull($r['units_mom_pct'] ?? null)];
    }
    unset($k);
}
usort($byCat, fn($a, $b) => $b['sales'] <=> $a['sales']);

$kpis = [
    ['Projected sales (' . strtolower($windowText) . ')', $peso($projected)],
    ['Projected units', $whole($projUnits)],
    ['Sales MoM (' . ($first['month'] ?? '—') . ' vs ' . ($lastActual ?: 'last actual month') . ')', $pct($first['revenue_mom_pct'] ?? null) ?: '—'],
    ['Units MoM', $pct($first['units_mom_pct'] ?? null) ?: '—'],
    ['Last actual month', $lastActual !== '' ? $lastActual . (isset($growth['last_actual_revenue']) ? ' · ' . $peso($growth['last_actual_revenue']) : '') : '—'],
];
$monthHead = ['Month', 'Type', 'Units', 'Sales (PHP)', 'Sales MoM', 'Units MoM', 'Likely low (PHP)', 'Likely high (PHP)'];
$catHead = ['Category', 'Group', 'Projected units', 'Projected sales (PHP)', 'Sales MoM (next month)', 'Units MoM (next month)'];
$momNote = 'MoM = change versus the month before. For the first projected month that is the latest actual month. A jump from a very small base can look extreme.';

if ($format === 'csv') {
    ias_emit_csv_sections(ias_export_filename($fileBase, 'csv'), array_merge([[$title]], array_map(fn($l) => [$l], $filterLines), [[$preparedBy]]), [
        ['title' => 'Summary', 'headers' => ['Metric', 'Value'], 'rows' => $kpis],
        ['title' => 'By month (actual, then projected)', 'headers' => $monthHead, 'rows' => array_map(fn($m) => [$m['month'], $m['type'], $m['units'], round($m['sales'], 2),
            $pct($m['smom']), $pct($m['umom']), $m['low'] === null ? '' : round($m['low'], 2), $m['high'] === null ? '' : round($m['high'], 2)], $months)],
        ['title' => 'By category (whole projection window)', 'headers' => $catHead, 'rows' => array_map(fn($c) => [$c['category'], $c['group'], $c['units'], round($c['sales'], 2), $pct($c['smom']), $pct($c['umom'])], $byCat)],
        ['title' => 'Notes', 'headers' => [$momNote], 'rows' => [['Likely low / high = the range the projection expects actual sales to fall in.']]],
    ]);
}

if ($format === 'xlsx') {
    $sum = [[['v' => $title, 's' => $X::STYLE_TITLE]]];
    foreach ($filterLines as $l) {
        $sum[] = [['v' => $l, 's' => $X::STYLE_NOTE]];
    }
    $sum[] = [['v' => $preparedBy, 's' => $X::STYLE_NOTE]];
    $sum[] = [];
    $sum[] = $xHead(['Metric', 'Value']);
    $sum[] = ['Projected sales (' . strtolower($windowText) . ')', ['v' => round($projected, 2), 's' => $X::STYLE_MONEY]];
    $sum[] = ['Projected units', ['v' => $projUnits, 's' => $X::STYLE_INT]];
    $sum[] = ['Sales MoM (' . ($first['month'] ?? '—') . ' vs ' . ($lastActual ?: 'last actual month') . ')', $xPct($first['revenue_mom_pct'] ?? null)];
    $sum[] = ['Units MoM', $xPct($first['units_mom_pct'] ?? null)];
    $sum[] = ['Last actual month', $lastActual];
    if (isset($growth['last_actual_revenue'])) {
        $sum[] = ['Last actual month sales', ['v' => round((float)$growth['last_actual_revenue'], 2), 's' => $X::STYLE_MONEY]];
    }
    $sum[] = [];
    $sum[] = [['v' => $momNote, 's' => $X::STYLE_NOTE]];

    $mon = [$xHead($monthHead)];
    foreach ($months as $m) {
        $mon[] = [$m['month'], $m['type'], ['v' => $m['units'], 's' => $X::STYLE_INT], ['v' => round($m['sales'], 2), 's' => $X::STYLE_MONEY],
            $xPct($m['smom']), $xPct($m['umom']),
            $m['low'] === null ? '' : ['v' => round($m['low'], 2), 's' => $X::STYLE_MONEY], $m['high'] === null ? '' : ['v' => round($m['high'], 2), 's' => $X::STYLE_MONEY]];
    }
    $cat = [$xHead($catHead)];
    foreach ($byCat as $c) {
        $cat[] = [$c['category'], $c['group'], ['v' => $c['units'], 's' => $X::STYLE_INT], ['v' => round($c['sales'], 2), 's' => $X::STYLE_MONEY], $xPct($c['smom']), $xPct($c['umom'])];
    }
    $x = new IasXlsxWriter();
    $x->addSheet('Summary', $sum, ['widths' => [46, 20]]);
    $x->addSheet('By month', $mon, ['widths' => [10, 10, 10, 16, 11, 11, 16, 16], 'freeze' => 1, 'filter' => 'A1:H' . max(2, count($mon))]);
    $x->addSheet('By category', $cat, ['widths' => [26, 16, 14, 20, 20, 20], 'freeze' => 1, 'filter' => 'A1:F' . max(2, count($cat))]);
    $x->output(ias_export_filename($fileBase, 'xlsx'));
}

$pdf = new IasPdfReport($title, array_merge($filterLines, [$preparedBy]), 'P');
$pdf->summary(array_slice($kpis, 0, 2));   // two per row: the peso amounts are long
$pdf->summary(array_slice($kpis, 2, 2));
$pdf->paragraph('Last actual month: ' . $kpis[4][1]);
$pdf->heading('Sales by month', 'Actual months, then projected months (P)', 190);
$compact = function ($v): string {   // short bar labels, same as the retail exports
    $v = (float)$v;
    if ($v >= 1000000) {
        return number_format($v / 1000000, 1) . 'M';
    }
    return $v >= 1000 ? number_format($v / 1000, $v >= 10000 ? 0 : 1) . 'k' : number_format($v);
};
$pdf->barChart(array_map(fn($m) => $m['month'] . ($m['type'] === 'Projected' ? ' (P)' : ''), $months), array_column($months, 'sales'), 180, $compact);
$pdf->table(['Month', 'Type', 'Units', 'Sales', 'Sales MoM', 'Units MoM', 'Likely range'], array_map(fn($m) => [$m['month'], $m['type'], $whole($m['units']), $peso($m['sales']),
    $pct($m['smom']) ?: '—', $pct($m['umom']) ?: '—', $m['low'] === null ? '—' : $peso($m['low']) . ' – ' . $peso($m['high'])], $months),
    ['widths' => [0.8, 0.8, 0.7, 1.3, 0.8, 0.8, 2.2], 'align' => ['L', 'L', 'R', 'R', 'R', 'R', 'R']]);
$pdf->heading('By category', 'Whole projection window');
$pdf->table($catHead, array_map(fn($c) => [$c['category'], $c['group'] ?: '—', $whole($c['units']), $peso($c['sales']), $pct($c['smom']) ?: '—', $pct($c['umom']) ?: '—'], $byCat),
    ['widths' => [1.6, 1.1, 1, 1.4, 1.1, 1.1], 'align' => ['L', 'L', 'R', 'R', 'R', 'R']]);
if (!$months) {
    $pdf->paragraph((string)($data['message'] ?? 'No projection available for this selection.'));
}
$pdf->paragraph($momNote, 8);
$pdf->output(ias_export_filename($fileBase, 'pdf'));
