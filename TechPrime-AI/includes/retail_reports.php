<?php
/**
 * Retail Officer reporting helpers — Sales Report & Delivery History modules.
 * Shared by RETAIL/retail_reports.php, RETAIL/retail_history.php and RETAIL/retail_export.php.
 */

require_once __DIR__ . '/product_categories.php';
// Report ranges are Manila calendar days; DB timestamps are UTC (see local_day_range_to_utc()).
require_once __DIR__ . '/timezone_helpers.php';

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
 * Fetch sales line items for the retail officer's reports within a date range.
 * Shop-wide (all products are listed under the inventory custodian, so a per-user seller filter
 * would match nothing): completed online orders plus completed cashier POS sales.
 * $sellerId is kept for call-site compatibility and ignored.
 * Optional filters: category, product_id, customer (name/email search).
 */
function ias_fetch_sales_rows(PDO $db, int $sellerId, DateTime $from, DateTime $to, array $filters = []): array
{
    return ias_fetch_all_sales_rows($db, $from, $to, $filters);
}

/**
 * Completed cashier POS sales as sales rows (same shape as online order rows).
 * order_id is the negative POS sale id so it never collides with an online order id;
 * 'ref' is the label to display ("#12" for online, the invoice number for POS).
 */
function ias_fetch_pos_sales_rows(PDO $db, DateTime $from, DateTime $to, array $filters = []): array
{
    // POS sales have no customer account, so a customer search can never match them.
    if (!empty($filters['customer'])) {
        return [];
    }
    $sql = "SELECT -ps.id AS order_id, ps.invoice_no AS ref, ps.created_at, 'completed' AS status,
                   'Walk-in' AS customer_name, '' AS customer_surname, '' AS customer_email,
                   psi.id AS item_id, psi.quantity, psi.unit_price AS price,
                   psi.product_id, psi.product_name, p.category
            FROM pos_sale_items psi
            INNER JOIN pos_sales ps ON ps.id = psi.sale_id
            LEFT JOIN products p ON p.id = psi.product_id
            WHERE ps.status = 'completed'
              AND ps.created_at >= ? AND ps.created_at < ?";
    $bind = local_day_range_to_utc($from, $to);
    if (!empty($filters['category'])) {
        $sql .= ' AND ' . ias_category_in_sql('p.category', (string)$filters['category'], $bind);
    }
    if (!empty($filters['product_id'])) {
        $sql .= ' AND psi.product_id = ?';
        $bind[] = (int)$filters['product_id'];
    }
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];   // POS tables not installed
    }
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
                'ref' => $r['ref'] ?? ('#' . $oid),
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
        $day = utc_datetime_local_date((string)$r['created_at']);
        if (isset($byDay[$day])) {
            $byDay[$day] += (float)$r['price'] * (int)$r['quantity'];
        }
    }
    return $byDay;
}

/**
 * Fetch completed (delivered) shipments for a retail officer's products within a date range.
 * Optional filters: customer, product, and limit/offset for one page (Sales History).
 */
function ias_fetch_delivery_rows(PDO $db, int $sellerId, DateTime $from, DateTime $to, array $filters = []): array
{
    $sql = "SELECT s.id AS shipment_id,
                   (CASE WHEN LOWER(COALESCE(o.fulfillment_type, '')) = 'pickup' THEN 'Store Pickup' ELSE 'Lalamove' END) AS carrier,
                   s.shipment_status, s.updated_at, s.created_at AS shipment_created,
                   o.id AS order_id, o.total, o.created_at AS order_created,
                   u.name AS customer_name, u.surname AS customer_surname, u.email AS customer_email,
                   (SELECT STRING_AGG(pr.name || ' x' || oi.quantity::text, ', ')
                    FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
                    WHERE oi.order_id = o.id) AS products,
                   (SELECT STRING_AGG(DISTINCT pr2.category, ', ')
                    FROM order_items oi2 INNER JOIN products pr2 ON pr2.id = oi2.product_id
                    WHERE oi2.order_id = o.id) AS categories
            FROM shipments s
            INNER JOIN orders o ON s.order_id = o.id
            INNER JOIN users u ON u.id = o.user_id
            WHERE s.shipment_status = 'delivered'
              AND s.updated_at >= ? AND s.updated_at < ?";
    $types = 'ss';
    $bind = local_day_range_to_utc($from, $to);

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
                WHERE oi4.order_id = o.id AND p4.name LIKE ?
              )";
        $types .= 's';
        $bind[] = '%' . $filters['product'] . '%';
    }

    $sql .= " ORDER BY s.updated_at DESC, s.id DESC";
    if (!empty($filters['limit'])) {
        $sql .= ' LIMIT ' . max(1, (int)$filters['limit']) . ' OFFSET ' . max(0, (int)($filters['offset'] ?? 0));
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows;
}

/** Number of delivered shipments in the range (same rows as ias_fetch_delivery_rows() without filters). */
function ias_count_delivery_rows(PDO $db, DateTime $from, DateTime $to): int
{
    $stmt = $db->prepare("SELECT COUNT(*)
                          FROM shipments s
                          INNER JOIN orders o ON s.order_id = o.id
                          INNER JOIN users u ON u.id = o.user_id
                          WHERE s.shipment_status = 'delivered'
                            AND s.updated_at >= ? AND s.updated_at < ?");
    $stmt->execute(local_day_range_to_utc($from, $to));
    return (int)$stmt->fetchColumn();
}

/** Order line items for the given order ids: [order_id => [['product_name', 'category', 'quantity', 'price'], ...]] */
function ias_fetch_order_items_by_order(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
    if (!$orderIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $db->prepare("SELECT oi.order_id, pr.name AS product_name, pr.category, oi.quantity, oi.price
                          FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
                          WHERE oi.order_id IN ($in)
                          ORDER BY oi.order_id, oi.id");
    $stmt->execute($orderIds);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['order_id']][] = $r;
    }
    return $out;
}

/** Summary metrics for the delivery history module (success rate needs all shipments, not just delivered ones) */
function ias_summarize_deliveries(PDO $db, int $sellerId, DateTime $from, DateTime $to, int $deliveredCount): array
{
    $sql = "SELECT COUNT(DISTINCT s.id) FROM shipments s
            INNER JOIN orders o ON s.order_id = o.id
            WHERE s.created_at >= ? AND s.created_at < ?";
    $stmt = $db->prepare($sql);
    [$fromStr, $toStr] = local_day_range_to_utc($from, $to);
    $stmt->execute([$fromStr, $toStr]);
    $totalInRange = (int)($stmt->fetchColumn() ?? 0);
    return [
        'delivered' => $deliveredCount,
        'total_shipments_in_range' => $totalInRange,
        'success_rate' => $totalInRange > 0 ? ($deliveredCount / $totalInRange) * 100 : ($deliveredCount > 0 ? 100.0 : 0.0),
    ];
}

/**
 * Store-wide delivery totals (admin scope — no seller filter).
 * Delivered count uses shipment_status = 'delivered' and updated_at in range.
 *
 * @return array{delivered:int,total_shipments_in_range:int,success_rate:float}
 */
function ias_summarize_deliveries_all(PDO $db, DateTime $from, DateTime $to): array
{
    [$fromStr, $toStr] = local_day_range_to_utc($from, $to);

    $deliveredStmt = $db->prepare(
        "SELECT COUNT(*) FROM shipments
         WHERE shipment_status = 'delivered'
           AND updated_at >= ? AND updated_at < ?"
    );
    $deliveredStmt->execute([$fromStr, $toStr]);
    $delivered = (int)($deliveredStmt->fetchColumn() ?? 0);

    $totalStmt = $db->prepare(
        "SELECT COUNT(*) FROM shipments
         WHERE created_at >= ? AND created_at < ?"
    );
    $totalStmt->execute([$fromStr, $toStr]);
    $totalInRange = (int)($totalStmt->fetchColumn() ?? 0);

    return [
        'delivered' => $delivered,
        'total_shipments_in_range' => $totalInRange,
        'success_rate' => $totalInRange > 0 ? ($delivered / $totalInRange) * 100 : ($delivered > 0 ? 100.0 : 0.0),
    ];
}

/** Breakdown of deliveries by product category (categories column is a comma-joined list per order) */
function ias_deliveries_by_category(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        // one count per distinct Client menu GROUP in the order (Component, Peripherals, ...), same as the forecast filter
        $cats = ias_category_groups_in($r['categories'] ?? '');
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

/**
 * Units delivered per product, grouped by Client menu group, for the delivered shipments in $rows
 * (already filtered by date/customer/product). Returns [group => [['product' => name, 'units' => n], ...]],
 * groups in Client menu order, products by units descending.
 */
function ias_delivered_units_by_group(PDO $db, int $sellerId, array $rows, ?DateTime $from = null, ?DateTime $to = null): array
{
    $byGroup = [];
    $orderIds = array_values(array_unique(array_map(fn($r) => (int)$r['order_id'], $rows)));
    if ($orderIds) {
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $db->prepare("SELECT pr.name, pr.category, SUM(oi.quantity) AS units
                              FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
                              WHERE oi.order_id IN ($in)
                              GROUP BY pr.id, pr.name, pr.category");
        $stmt->execute($orderIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byGroup[ias_category_group_of((string)$r['category'])][$r['name']] = (int)$r['units'];
        }
    }
    // Cashier POS units in the same period are part of the same sales.
    if ($from && $to) {
        foreach (ias_fetch_pos_sales_rows($db, $from, $to) as $r) {
            $g = ias_category_group_of((string)($r['category'] ?? ''));
            $byGroup[$g][$r['product_name']] = ($byGroup[$g][$r['product_name']] ?? 0) + (int)$r['quantity'];
        }
    }
    $out = [];
    foreach (ias_category_groups() as $g) {
        if (!empty($byGroup[$g])) {
            $list = [];
            foreach ($byGroup[$g] as $name => $units) {
                $list[] = ['product' => (string)$name, 'units' => (int)$units];
            }
            usort($list, fn($a, $b) => $b['units'] <=> $a['units']);
            $out[$g] = $list;
        }
    }
    return $out;
}

/**
 * Period keys of the Inventory dashboard's "Products Stocked Per Month" filter (month | last | 3m | year).
 * Unknown keys fall back to 'year'. @return array{key:string,label:string,from:DateTime,to:DateTime}
 */
function ias_sales_period_range(string $key): array
{
    $today = new DateTime('today');
    switch ($key) {
        case 'month':
            return ['key' => 'month', 'label' => 'This Month', 'from' => (clone $today)->modify('first day of this month'), 'to' => $today];
        case 'last':
            return ['key' => 'last', 'label' => 'Last Month', 'from' => (clone $today)->modify('first day of last month'), 'to' => (clone $today)->modify('last day of last month')];
        case '3m':
            return ['key' => '3m', 'label' => 'Last 3 Months', 'from' => (clone $today)->modify('first day of this month')->modify('-2 months'), 'to' => $today];
        default:
            return ['key' => 'year', 'label' => 'This Year', 'from' => (clone $today)->modify('first day of january this year'), 'to' => $today];
    }
}

/** Keep only delivery rows whose order contains a product in the given Client menu group ('' = all rows). */
function ias_filter_delivery_rows_by_group(array $rows, string $group): array
{
    if ($group === '') {
        return $rows;
    }
    return array_values(array_filter($rows, fn($r) => in_array($group, ias_category_groups_in($r['categories'] ?? ''), true)));
}

/**
 * Units delivered per day for the delivered shipments in $rows (dated by shipment updated_at).
 * $group ('' = all) restricts the count to products of that Client menu group. Returns ['Y-m-d' => units].
 */
function ias_delivered_units_by_date(PDO $db, int $sellerId, array $rows, string $group = '', ?DateTime $from = null, ?DateTime $to = null): array
{
    $out = [];
    $dateByOrder = [];
    foreach ($rows as $r) {
        $dateByOrder[(int)$r['order_id']] = utc_datetime_local_date((string)$r['updated_at']);
    }
    if ($dateByOrder) {
        $orderIds = array_keys($dateByOrder);
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $db->prepare("SELECT oi.order_id, pr.category, SUM(oi.quantity) AS units
                              FROM order_items oi INNER JOIN products pr ON pr.id = oi.product_id
                              WHERE oi.order_id IN ($in)
                              GROUP BY oi.order_id, pr.category");
        $stmt->execute($orderIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($group !== '' && ias_category_group_of((string)$r['category']) !== $group) {
                continue;
            }
            $d = $dateByOrder[(int)$r['order_id']];
            $out[$d] = ($out[$d] ?? 0) + (int)$r['units'];
        }
    }
    // Cashier POS units, dated by sale time.
    if ($from && $to) {
        foreach (ias_fetch_pos_sales_rows($db, $from, $to) as $r) {
            if ($group !== '' && ias_category_group_of((string)($r['category'] ?? '')) !== $group) {
                continue;
            }
            $d = utc_datetime_local_date((string)$r['created_at']);
            $out[$d] = ($out[$d] ?? 0) + (int)$r['quantity'];
        }
    }
    ksort($out);
    return $out;
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
function ias_product_demand_forecast(PDO $db, ?int $sellerId, DateTime $from, DateTime $to, int $forecastDays = 30, array $filters = []): array
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
function ias_sales_forecast(PDO $db, ?int $sellerId, DateTime $from, DateTime $to, int $forecastDays = 14, array $filters = []): array
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

/** Fetch all sales rows (shop-wide): completed online orders + completed POS sales. */
function ias_fetch_all_sales_rows(PDO $db, DateTime $from, DateTime $to, array $filters = []): array
{
    $sql = "SELECT o.id AS order_id, ('#' || o.id::text) AS ref, o.created_at, o.status,
                   u.name AS customer_name, u.surname AS customer_surname, u.email AS customer_email,
                   oi.id AS item_id, oi.quantity, oi.price,
                   p.id AS product_id, p.name AS product_name, p.category
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN users u ON u.id = o.user_id
            WHERE o.status = 'to_review'
              AND o.created_at >= ? AND o.created_at < ?";
    $types = 'ss';
    $bind = local_day_range_to_utc($from, $to);

    if (!empty($filters['category'])) {
        $before = count($bind);
        $sql .= ' AND ' . ias_category_in_sql('p.category', (string)$filters['category'], $bind);
        $types .= str_repeat('s', count($bind) - $before);
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
    $stmt->execute($bind);
    $rows = array_merge($stmt->fetchAll(PDO::FETCH_ASSOC), ias_fetch_pos_sales_rows($db, $from, $to, $filters));
    usort($rows, static fn($x, $y) => [$y['created_at'], $y['order_id']] <=> [$x['created_at'], $x['order_id']]);
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
function ias_monthly_sales_report(PDO $db, ?int $sellerId, int $monthsBack = 12): array
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

/**
 * Everything the Retail Dashboard shows, resolved from its GET params (card_*, ms_months, period, category).
 * Shared by RETAIL/retail_dashboard.php and its CSV/PDF export so the files always match the screen.
 */
function ias_retail_dashboard_data(PDO $db, int $retailId, array $params): array
{
    $cardRange = ias_resolve_section_range($params, 'card', 'this_month');
    $cardSummary = ias_summarize_sales(ias_fetch_sales_rows($db, $retailId, $cardRange['from'], $cardRange['to']));

    $msMonths = max(1, min(24, (int)($params['ms_months'] ?? 12)));
    $monthlySales = ias_monthly_sales_report($db, $retailId, $msMonths);

    // Sales by Category: same period + category filter as the Inventory dashboard's "Products Stocked Per Month"
    $range = ias_sales_period_range((string)($params['period'] ?? 'year'));
    $from = $range['from'];
    $to = $range['to'];
    $category = trim((string)($params['category'] ?? ''));
    if (!in_array($category, ias_category_groups(), true)) {
        $category = '';
    }
    $salesRows = ias_filter_delivery_rows_by_group(ias_fetch_delivery_rows($db, $retailId, $from, $to), $category);
    $byGroup = ias_delivered_units_by_group($db, $retailId, $salesRows, $from, $to);
    if ($category !== '') {
        $byGroup = array_intersect_key($byGroup, [$category => true]);
    }

    // Units delivered per day, or per month for long ranges
    $unitsByDate = ias_delivered_units_by_date($db, $retailId, $salesRows, $category, $from, $to);
    $byMonth = $from->diff($to)->days > 62;
    $trendLabels = [];
    $trendData = [];
    $cursor = $byMonth ? (clone $from)->modify('first day of this month') : clone $from;
    while ($cursor <= $to) {
        $key = $cursor->format($byMonth ? 'Y-m' : 'Y-m-d');
        $trendLabels[$key] = $cursor->format($byMonth ? 'M Y' : 'M d');
        $trendData[$key] = 0;
        $cursor->modify($byMonth ? '+1 month' : '+1 day');
    }
    foreach ($unitsByDate as $d => $units) {
        $key = $byMonth ? substr($d, 0, 7) : $d;
        if (isset($trendData[$key])) {
            $trendData[$key] += $units;
        }
    }

    return [
        'card_range' => $cardRange,
        'card_summary' => $cardSummary,
        'ms_months' => $msMonths,
        'monthly_sales' => $monthlySales,
        'range' => $range,
        'category' => $category,
        'by_group' => $byGroup,
        'trend_by_month' => $byMonth,
        'trend_labels' => array_values($trendLabels),
        'trend_data' => array_values($trendData),
    ];
}

/**
 * Print rules shared by the Retail report pages (Print button = window.print()).
 * Hides the app chrome and floating widgets, keeps chart canvases inside the page width
 * and keeps cards/rows from splitting across pages.
 */
function ias_retail_print_css(): string
{
    return <<<'CSS'
.print-header { display: none; }
@media print {
    @page { margin: 12mm; }
    html, body { background: #fff !important; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .sidebar, .topbar, .no-print, .report-toolbar, #cwFab, #cwPanel, #ias-alert-overlay { display: none !important; }
    .main { margin: 0 !important; width: 100% !important; }
    .page-content { padding: 0 !important; max-width: none !important; }
    .print-header {
        display: flex !important; align-items: center; gap: 14px;
        border-bottom: 2px solid #171717; padding-bottom: 12px; margin-bottom: 18px;
    }
    .print-header img { height: 40px; filter: brightness(0); }   /* the sidebar logo is white; print it solid black */
    .print-header h1 { font-size: 20px; margin: 0; }
    .print-header p { margin: 2px 0 0; font-size: 12px; color: #555; }
    .card, .inv-stockin-panel, .stat-card { box-shadow: none !important; break-inside: avoid; }
    .card { border: 1px solid #ddd !important; }
    .stat-num { font-size: 20px !important; overflow-wrap: anywhere; }
    .stat-icon { display: none !important; }
    canvas { max-width: 100% !important; height: auto !important; }
    .chart-wrap, .trend-wrap, .ms-chart-wrap { height: auto !important; }
    .table-wrap, .deliv-wrap { overflow: visible !important; }
    tr { break-inside: avoid; }
    thead { display: table-header-group; }
    a[href]::after { content: none !important; }
}
CSS;
}

/** DB timestamp (UTC, timestamp without time zone) -> branch-local display string. */
function ias_report_datetime(?string $utc, string $format = 'M d, Y g:i A'): string
{
    if ($utc === null || trim($utc) === '') {
        return '';
    }
    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
    } catch (Throwable $e) {
        return $utc;
    }
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

/**
 * Stream a CSV made of titled sections (one per dashboard panel) and exit.
 * $sections: [['title' => string, 'headers' => [...], 'rows' => [[...], ...]], ...]
 */
function ias_emit_csv_sections(string $filename, array $preamble, array $sections): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    foreach ($preamble as $line) {
        fputcsv($out, (array)$line);
    }
    foreach ($sections as $s) {
        fputs($out, "\r\n");
        fputcsv($out, [$s['title']]);
        fputcsv($out, $s['headers']);
        foreach ($s['rows'] as $row) {
            fputcsv($out, $row);
        }
        if (empty($s['rows'])) {
            fputcsv($out, ['No data']);
        }
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
