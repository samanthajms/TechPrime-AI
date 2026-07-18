<?php
/**
 * Retail Officer reporting helpers — Sales Report & Delivery History modules.
 * Shared by RETAIL/retail_reports.php, RETAIL/retail_history.php and RETAIL/retail_export.php.
 */

/** Preset date ranges. Returns ['from' => Y-m-d, 'to' => Y-m-d, 'label' => string] */
function ias_report_date_presets(): array
{
    $today = new DateTime('today');
    $presets = [];

    $presets['today'] = ['label' => 'Today', 'from' => (clone $today), 'to' => (clone $today)];

    $mon = (clone $today)->modify('monday this week');
    $presets['this_week'] = ['label' => 'This Week', 'from' => $mon, 'to' => (clone $today)];

    $lastWeekEnd = (clone $mon)->modify('-1 day');
    $lastWeekStart = (clone $lastWeekEnd)->modify('-6 days');
    $presets['last_week'] = ['label' => 'Last Week', 'from' => $lastWeekStart, 'to' => $lastWeekEnd];

    $presets['last_7_days'] = ['label' => 'Last 7 Days', 'from' => (clone $today)->modify('-6 days'), 'to' => (clone $today)];

    $presets['this_month'] = ['label' => 'This Month', 'from' => (clone $today)->modify('first day of this month'), 'to' => (clone $today)];

    $lastMonthStart = (clone $today)->modify('first day of last month');
    $lastMonthEnd = (clone $today)->modify('last day of last month');
    $presets['last_month'] = ['label' => 'Last Month', 'from' => $lastMonthStart, 'to' => $lastMonthEnd];

    $q = (int)ceil((int)$today->format('n') / 3);
    $qStartMonth = ($q - 1) * 3 + 1;
    $thisQStart = new DateTime($today->format('Y') . '-' . str_pad((string)$qStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
    $presets['this_quarter'] = ['label' => 'This Quarter', 'from' => $thisQStart, 'to' => (clone $today)];

    $lastQStart = (clone $thisQStart)->modify('-3 months');
    $lastQEnd = (clone $thisQStart)->modify('-1 day');
    $presets['last_quarter'] = ['label' => 'Last Quarter', 'from' => $lastQStart, 'to' => $lastQEnd];

    $presets['this_year'] = ['label' => 'This Year', 'from' => (clone $today)->modify('first day of january this year'), 'to' => (clone $today)];

    $presets['last_year_full'] = [
        'label' => 'Last Year',
        'from' => (clone $today)->modify('first day of january last year'),
        'to' => (clone $today)->modify('last day of december last year'),
    ];

    return $presets;
}

/**
 * Resolve the active date range from $_GET params.
 * Supports: preset=<key> OR date_from & date_to (custom).
 * Falls back to "This Month".
 */
function ias_resolve_report_range(array $params): array
{
    $presets = ias_report_date_presets();
    $preset = $params['preset'] ?? '';

    if ($preset === 'custom' || (!empty($params['date_from']) && !empty($params['date_to']) && empty($preset))) {
        $from = $params['date_from'] ?? '';
        $to = $params['date_to'] ?? '';
        $fromD = DateTime::createFromFormat('Y-m-d', $from) ?: (new DateTime('first day of this month'));
        $toD = DateTime::createFromFormat('Y-m-d', $to) ?: (new DateTime('today'));
        if ($fromD > $toD) {
            [$fromD, $toD] = [$toD, $fromD];
        }
        return ['from' => $fromD, 'to' => $toD, 'label' => 'Custom Range', 'preset' => 'custom'];
    }

    if ($preset !== '' && isset($presets[$preset])) {
        $p = $presets[$preset];
        return ['from' => $p['from'], 'to' => $p['to'], 'label' => $p['label'], 'preset' => $preset];
    }

    $p = $presets['this_month'];
    return ['from' => $p['from'], 'to' => $p['to'], 'label' => $p['label'], 'preset' => 'this_month'];
}

/** Previous period of equal length, immediately preceding $from (for MoM / YoY / period-over-period comparisons) */
function ias_previous_period(DateTime $from, DateTime $to): array
{
    $days = (int)$from->diff($to)->days + 1;
    $prevTo = (clone $from)->modify('-1 day');
    $prevFrom = (clone $prevTo)->modify('-' . ($days - 1) . ' days');
    return ['from' => $prevFrom, 'to' => $prevTo];
}

/** Same date range, one year earlier (for Year-over-Year comparisons) */
function ias_year_ago_period(DateTime $from, DateTime $to): array
{
    return [
        'from' => (clone $from)->modify('-1 year'),
        'to' => (clone $to)->modify('-1 year'),
    ];
}

/**
 * Fetch sales line items for a retail officer's products within a date range.
 * Optional filters: category, product_id, customer (name/email search).
 */
function ias_fetch_sales_rows(mysqli $db, int $sellerId, DateTime $from, DateTime $to, array $filters = []): array
{
    $sql = "SELECT o.id AS order_id, o.created_at, o.status,
                   u.name AS customer_name, u.surname AS customer_surname, u.email AS customer_email,
                   oi.id AS item_id, oi.quantity, oi.price,
                   p.id AS product_id, p.name AS product_name, p.category
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN users u ON u.id = o.user_id
            WHERE p.seller_id = ?
              AND o.created_at >= ? AND o.created_at < ?";
    $types = 'iss';
    $bind = [$sellerId, $from->format('Y-m-d 00:00:00'), (clone $to)->modify('+1 day')->format('Y-m-d 00:00:00')];

    if (!empty($filters['category'])) {
        $sql .= " AND p.category = ?";
        $types .= 's';
        $bind[] = $filters['category'];
    }
    if (!empty($filters['product_id'])) {
        $sql .= " AND p.id = ?";
        $types .= 'i';
        $bind[] = (int)$filters['product_id'];
    }
    if (!empty($filters['customer'])) {
        $sql .= " AND (u.name LIKE ? OR u.surname LIKE ? OR u.email LIKE ?)";
        $needle = '%' . $filters['customer'] . '%';
        $types .= 'sss';
        $bind[] = $needle;
        $bind[] = $needle;
        $bind[] = $needle;
    }

    $sql .= " ORDER BY o.created_at DESC, o.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Aggregate summary metrics from sales line-item rows */
function ias_summarize_sales(array $rows): array
{
    $totalSales = 0.0;
    $unitsSold = 0;
    $orderIds = [];
    foreach ($rows as $r) {
        $totalSales += (float)$r['price'] * (int)$r['quantity'];
        $unitsSold += (int)$r['quantity'];
        $orderIds[$r['order_id']] = true;
    }
    $txCount = count($orderIds);
    return [
        'total_sales' => $totalSales,
        'units_sold' => $unitsSold,
        'transactions' => $txCount,
        'avg_transaction' => $txCount > 0 ? $totalSales / $txCount : 0.0,
    ];
}

/** Group sales rows into per-order transactions with nested line items (for drill-down) */
function ias_group_sales_by_order(array $rows): array
{
    $orders = [];
    foreach ($rows as $r) {
        $oid = (int)$r['order_id'];
        if (!isset($orders[$oid])) {
            $orders[$oid] = [
                'order_id' => $oid,
                'created_at' => $r['created_at'],
                'status' => $r['status'],
                'customer_name' => trim($r['customer_name'] . ' ' . $r['customer_surname']),
                'customer_email' => $r['customer_email'],
                'total' => 0.0,
                'units' => 0,
                'items' => [],
            ];
        }
        $lineTotal = (float)$r['price'] * (int)$r['quantity'];
        $orders[$oid]['total'] += $lineTotal;
        $orders[$oid]['units'] += (int)$r['quantity'];
        $orders[$oid]['items'][] = [
            'product_name' => $r['product_name'],
            'category' => $r['category'],
            'quantity' => (int)$r['quantity'],
            'price' => (float)$r['price'],
            'line_total' => $lineTotal,
        ];
    }
    return array_values($orders);
}

/** Breakdown of sales by product category */
function ias_sales_by_category(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $cat = $r['category'] ?: 'Uncategorized';
        if (!isset($out[$cat])) {
            $out[$cat] = ['category' => $cat, 'sales' => 0.0, 'units' => 0];
        }
        $out[$cat]['sales'] += (float)$r['price'] * (int)$r['quantity'];
        $out[$cat]['units'] += (int)$r['quantity'];
    }
    uasort($out, fn($a, $b) => $b['sales'] <=> $a['sales']);
    return array_values($out);
}

/** Daily sales trend (date => total) for chart rendering, filling gaps with 0 */
function ias_sales_daily_trend(array $rows, DateTime $from, DateTime $to): array
{
    $byDay = [];
    $cursor = clone $from;
    while ($cursor <= $to) {
        $byDay[$cursor->format('Y-m-d')] = 0.0;
        $cursor->modify('+1 day');
    }
    foreach ($rows as $r) {
        $day = substr($r['created_at'], 0, 10);
        if (isset($byDay[$day])) {
            $byDay[$day] += (float)$r['price'] * (int)$r['quantity'];
        }
    }
    return $byDay;
}

/**
 * Fetch completed (delivered) shipments for a retail officer's products within a date range.
 * Optional filters: customer, product_id.
 */
function ias_fetch_delivery_rows(mysqli $db, int $sellerId, DateTime $from, DateTime $to, array $filters = []): array
{
    $sql = "SELECT s.id AS shipment_id, s.carrier, s.shipment_status, s.updated_at, s.created_at AS shipment_created,
                   o.id AS order_id, o.total, o.created_at AS order_created,
                   u.name AS customer_name, u.surname AS customer_surname, u.email AS customer_email,
                   (SELECT GROUP_CONCAT(CONCAT(pr.name, ' x', oi.quantity) SEPARATOR ', ')
                    FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
                    WHERE oi.order_id = o.id AND pr.seller_id = ?) AS products,
                   (SELECT GROUP_CONCAT(DISTINCT pr2.category SEPARATOR ', ')
                    FROM order_items oi2 INNER JOIN products pr2 ON pr2.id = oi2.product_id
                    WHERE oi2.order_id = o.id AND pr2.seller_id = ?) AS categories
            FROM shipments s
            INNER JOIN orders o ON s.order_id = o.id
            INNER JOIN users u ON u.id = o.user_id
            WHERE s.shipment_status = 'delivered'
              AND s.updated_at >= ? AND s.updated_at < ?
              AND EXISTS (
                SELECT 1 FROM order_items oi3 INNER JOIN products p3 ON p3.id = oi3.product_id
                WHERE oi3.order_id = o.id AND p3.seller_id = ?
              )";
    $types = 'iissi';
    $bind = [$sellerId, $sellerId, $from->format('Y-m-d 00:00:00'), (clone $to)->modify('+1 day')->format('Y-m-d 00:00:00'), $sellerId];

    if (!empty($filters['customer'])) {
        $sql .= " AND (u.name LIKE ? OR u.surname LIKE ? OR u.email LIKE ?)";
        $needle = '%' . $filters['customer'] . '%';
        $types .= 'sss';
        $bind[] = $needle;
        $bind[] = $needle;
        $bind[] = $needle;
    }
    if (!empty($filters['product'])) {
        $sql .= " AND EXISTS (
                SELECT 1 FROM order_items oi4 INNER JOIN products p4 ON p4.id = oi4.product_id
                WHERE oi4.order_id = o.id AND p4.seller_id = ? AND p4.name LIKE ?
              )";
        $types .= 'is';
        $bind[] = $sellerId;
        $bind[] = '%' . $filters['product'] . '%';
    }

    $sql .= " ORDER BY s.updated_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Summary metrics for the delivery history module (success rate needs all shipments, not just delivered ones) */
function ias_summarize_deliveries(mysqli $db, int $sellerId, DateTime $from, DateTime $to, int $deliveredCount): array
{
    $sql = "SELECT COUNT(DISTINCT s.id) FROM shipments s
            INNER JOIN orders o ON s.order_id = o.id
            WHERE s.created_at >= ? AND s.created_at < ?
              AND EXISTS (
                SELECT 1 FROM order_items oi INNER JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = o.id AND p.seller_id = ?
              )";
    $stmt = $db->prepare($sql);
    $fromStr = $from->format('Y-m-d 00:00:00');
    $toStr = (clone $to)->modify('+1 day')->format('Y-m-d 00:00:00');
    $stmt->bind_param('ssi', $fromStr, $toStr, $sellerId);
    $stmt->execute();
    $totalInRange = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();

    return [
        'delivered' => $deliveredCount,
        'total_shipments_in_range' => $totalInRange,
        'success_rate' => $totalInRange > 0 ? ($deliveredCount / $totalInRange) * 100 : ($deliveredCount > 0 ? 100.0 : 0.0),
    ];
}

/** Breakdown of deliveries by product category (categories column is a comma-joined list per order) */
function ias_deliveries_by_category(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $cats = array_filter(array_map('trim', explode(',', $r['categories'] ?? '')));
        if (empty($cats)) {
            $cats = ['Uncategorized'];
        }
        foreach ($cats as $cat) {
            if (!isset($out[$cat])) {
                $out[$cat] = ['category' => $cat, 'deliveries' => 0];
            }
            $out[$cat]['deliveries']++;
        }
    }
    uasort($out, fn($a, $b) => $b['deliveries'] <=> $a['deliveries']);
    return array_values($out);
}

/** Percentage change helper; returns null when base is 0 (undefined change) */
function ias_pct_change(float $current, float $previous): ?float
{
    if (abs($previous) < 0.00001) {
        return $current > 0 ? null : 0.0;
    }
    return (($current - $previous) / $previous) * 100;
}

/** Render a small colored +/-% badge for comparative analysis (MoM / YoY). Returns HTML. */
function ias_render_pct_badge(?float $pct): string
{
    if ($pct === null) {
        return '<span class="pct-badge pct-flat">new</span>';
    }
    $cls = $pct > 0.05 ? 'pct-up' : ($pct < -0.05 ? 'pct-down' : 'pct-flat');
    $icon = $pct > 0.05 ? '&#9650;' : ($pct < -0.05 ? '&#9660;' : '&#8213;');
    return '<span class="pct-badge ' . $cls . '">' . $icon . ' ' . number_format(abs($pct), 1) . '%</span>';
}

/**
 * Product demand forecast from historical sales in a date range.
 * Uses average daily units sold × forecast horizon.
 */
function ias_product_demand_forecast(mysqli $db, ?int $sellerId, DateTime $from, DateTime $to, int $forecastDays = 30, array $filters = []): array
{
    $rows = $sellerId !== null
        ? ias_fetch_sales_rows($db, $sellerId, $from, $to, $filters)
        : ias_fetch_all_sales_rows($db, $from, $to, $filters);

    $byProduct = [];
    foreach ($rows as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($byProduct[$pid])) {
            $byProduct[$pid] = [
                'product_id' => $pid,
                'product_name' => $r['product_name'],
                'category' => $r['category'] ?: 'Uncategorized',
                'units_sold' => 0,
                'revenue' => 0.0,
            ];
        }
        $byProduct[$pid]['units_sold'] += (int)$r['quantity'];
        $byProduct[$pid]['revenue'] += (float)$r['price'] * (int)$r['quantity'];
    }

    $periodDays = max(1, (int)$from->diff($to)->days + 1);
    $forecast = [];
    foreach ($byProduct as $p) {
        $avgDaily = $p['units_sold'] / $periodDays;
        $forecastUnits = (int)ceil($avgDaily * $forecastDays);
        $avgPrice = $p['units_sold'] > 0 ? $p['revenue'] / $p['units_sold'] : 0.0;
        $forecast[] = array_merge($p, [
            'avg_daily_units' => round($avgDaily, 2),
            'forecast_units' => $forecastUnits,
            'forecast_revenue' => round($avgPrice * $forecastUnits, 2),
        ]);
    }

    usort($forecast, fn($a, $b) => $b['forecast_units'] <=> $a['forecast_units']);
    return $forecast;
}

/**
 * Sales forecast: historical daily trend plus projected days using recent moving average.
 */
function ias_sales_forecast(mysqli $db, ?int $sellerId, DateTime $from, DateTime $to, int $forecastDays = 14, array $filters = []): array
{
    $rows = $sellerId !== null
        ? ias_fetch_sales_rows($db, $sellerId, $from, $to, $filters)
        : ias_fetch_all_sales_rows($db, $from, $to, $filters);

    $historical = ias_sales_daily_trend($rows, $from, $to);
    $values = array_values($historical);
    $window = min(7, max(1, count($values)));
    $recent = array_slice($values, -$window);
    $avgDaily = count($recent) > 0 ? array_sum($recent) / count($recent) : 0.0;

    $forecast = [];
    $cursor = (clone $to)->modify('+1 day');
    for ($i = 0; $i < $forecastDays; $i++) {
        $forecast[$cursor->format('Y-m-d')] = round($avgDaily, 2);
        $cursor->modify('+1 day');
    }

    return [
        'historical' => $historical,
        'forecast' => $forecast,
        'avg_daily' => round($avgDaily, 2),
    ];
}

/** Fetch all sales rows (admin scope — no seller filter). */
function ias_fetch_all_sales_rows(mysqli $db, DateTime $from, DateTime $to, array $filters = []): array
{
    $sql = "SELECT o.id AS order_id, o.created_at, o.status,
                   u.name AS customer_name, u.surname AS customer_surname, u.email AS customer_email,
                   oi.id AS item_id, oi.quantity, oi.price,
                   p.id AS product_id, p.name AS product_name, p.category
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN users u ON u.id = o.user_id
            WHERE o.created_at >= ? AND o.created_at < ?";
    $types = 'ss';
    $bind = [$from->format('Y-m-d 00:00:00'), (clone $to)->modify('+1 day')->format('Y-m-d 00:00:00')];

    if (!empty($filters['category'])) {
        $sql .= ' AND p.category = ?';
        $types .= 's';
        $bind[] = $filters['category'];
    }
    if (!empty($filters['customer'])) {
        $sql .= ' AND (u.name LIKE ? OR u.surname LIKE ? OR u.email LIKE ?)';
        $needle = '%' . $filters['customer'] . '%';
        $types .= 'sss';
        $bind[] = $needle;
        $bind[] = $needle;
        $bind[] = $needle;
    }

    $sql .= ' ORDER BY o.created_at DESC, o.id DESC';
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Resolve a named report section's date range from GET params. */
function ias_resolve_section_range(array $params, string $prefix, string $defaultPreset = 'this_month'): array
{
    $sectionParams = [
        'preset' => $params[$prefix . '_preset'] ?? $defaultPreset,
        'date_from' => $params[$prefix . '_from'] ?? '',
        'date_to' => $params[$prefix . '_to'] ?? '',
    ];
    return ias_resolve_report_range($sectionParams);
}

/** Monthly sales breakdown from order line items (real-time). */
function ias_monthly_sales_report(mysqli $db, ?int $sellerId, int $monthsBack = 12): array
{
    $out = [];
    $today = new DateTime('today');

    for ($i = $monthsBack - 1; $i >= 0; $i--) {
        $monthStart = (new DateTime('first day of this month'))->modify("-$i months");
        $monthEnd = (clone $monthStart)->modify('last day of this month');
        if ($monthEnd > $today) {
            $monthEnd = clone $today;
        }

        $rows = $sellerId !== null
            ? ias_fetch_sales_rows($db, $sellerId, $monthStart, $monthEnd)
            : ias_fetch_all_sales_rows($db, $monthStart, $monthEnd);
        $summary = ias_summarize_sales($rows);

        $out[] = [
            'month' => $monthStart->format('M Y'),
            'month_key' => $monthStart->format('Y-m'),
            'from' => $monthStart->format('Y-m-d'),
            'to' => $monthEnd->format('Y-m-d'),
            'total_sales' => $summary['total_sales'],
            'transactions' => $summary['transactions'],
            'units_sold' => $summary['units_sold'],
            'avg_transaction' => $summary['avg_transaction'],
            'orders' => ias_group_sales_by_order($rows),
        ];
    }

    return $out;
}

/** Build dashboard query string while preserving unrelated GET params. */
function ias_dashboard_qs(array $overrides = []): string
{
    return h(http_build_query(array_merge($_GET, $overrides)));
}

/* -------------------------------------------------------------------------
 * Export emitters — CSV (native) and Excel (HTML-table served as .xls,
 * which Microsoft Excel opens natively; no external library required).
 * ------------------------------------------------------------------------- */

function ias_export_filename(string $base, string $ext): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base);
    return $safe . '_' . date('Ymd_His') . '.' . $ext;
}

/** Stream a CSV file to the browser and exit. $headers is a flat list, $rows a list of flat arrays matching header order. */
function ias_emit_csv(string $filename, array $headers, array $rows): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel/Sheets read peso signs & accents correctly
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/** Stream an Excel-compatible file (HTML table, .xls) to the browser and exit. */
function ias_emit_excel(string $filename, string $title, array $headers, array $rows): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    echo '<tr><td colspan="' . count($headers) . '"><b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b></td></tr>';
    echo '<tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars((string)$h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}
