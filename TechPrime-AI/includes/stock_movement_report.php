<?php
/**
 * Stock Movements — the inventory movement report (Inventory Custodian, Admin).
 * Every stock change in a chosen period, from every source:
 *   - receiving (custodian Stock In) and POS sales: the stock_movements ledger, written with each change
 *   - online orders: order items of orders whose stock was deducted, dated by the delivered shipment
 *   - manual edits on the Stocks page ("stock X → Y" in the edit_product log) and a new product's
 *     opening stock (add_product log)
 * The online-order and manual-edit rows follow the same rules as the "Stock Out" view of the
 * custodian Activity page (INVENTORY/inventory_audit.php).
 * Shows totals per source and per product plus the full movement log, prints with the formal
 * report layout (includes/staff_print.css) and downloads as CSV, Excel or PDF.
 * INVENTORY/inventory_movements.php and ADMIN/admin_movements.php are thin wrappers (guards first,
 * then staff_stock_movement_page()). Read-only.
 */
require_once __DIR__ . '/retail_reports.php';      // date range presets, CSV + file name helpers
require_once __DIR__ . '/inventory_alerts.php';    // inv_stock_status_label()
require_once __DIR__ . '/timezone_helpers.php';    // sql_utc_to_local()
require_once __DIR__ . '/staff_app_layout.php';    // staff_report_user_name()

function smr_pages(): array
{
    return ['inventory_custodian' => 'inventory_movements.php', 'admin' => 'admin_movements.php'];
}

/** Where a movement came from, in the order the report lists them. */
function smr_sources(): array
{
    return [
        'receiving' => 'Stock-in (receiving)',
        'pos' => 'POS sale',
        'order' => 'Online order',
        'manual' => 'Manual stock edit',
        'new' => 'New product',
    ];
}

/**
 * Every stock movement whose branch-local date is in [$from, $to] (Y-m-d), newest first.
 *
 * @return list<array{at:string,dir:string,source:string,product_id:?int,product:string,category:string,qty:int,
 *                    before:?int,after:?int,ref:string,by:string,stock_now:?int,key:string}>
 */
function smr_rows(PDO $db, string $from, string $to): array
{
    $local = sql_utc_to_local('m.at');
    $sql = <<<'SQL'
SELECT m.* FROM (
    SELECT sm.created_at AS at,
           CASE WHEN sm.movement_type = 'stock_in' THEN 'in' ELSE 'out' END AS dir,
           CASE WHEN sm.movement_type = 'stock_in' THEN 'receiving' ELSE 'pos' END AS source,
           sm.product_id AS product_id, sm.product_name AS product, sm.quantity AS qty,
           sm.stock_before AS stock_before, sm.stock_after AS stock_after,
           CASE WHEN sm.movement_type = 'stock_in'
                THEN CONCAT_WS(' · ', 'Supplier: ' || NULLIF(TRIM(sm.supplier), ''), 'Ref ' || NULLIF(TRIM(sm.reference_no), ''))
                ELSE ps.invoice_no || CASE WHEN ps.status = 'voided' THEN ' (voided sale)' ELSE '' END END AS ref,
           COALESCE(NULLIF(TRIM(COALESCE(u.name, '') || ' ' || COALESCE(u.surname, '')), ''), ps.cashier_name) AS by_name
    FROM stock_movements sm
    LEFT JOIN pos_sales ps ON ps.id = sm.sale_id
    LEFT JOIN users u ON u.id = sm.user_id
    WHERE sm.movement_type IN ('stock_in', 'stock_out')
    UNION ALL
    SELECT COALESCE(sh.updated_at, o.paid_at, o.created_at), 'out', 'order',
           oi.product_id, COALESCE(pr.name, 'Product #' || oi.product_id), oi.quantity, NULL, NULL,
           'Order #' || o.id,
           NULLIF(TRIM(COALESCE(cu.name, '') || ' ' || COALESCE(cu.surname, '')), '') || ' (customer)'
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id AND o.stock_deducted = TRUE
    LEFT JOIN products pr ON pr.id = oi.product_id
    LEFT JOIN LATERAL (SELECT s.updated_at FROM shipments s WHERE s.order_id = o.id ORDER BY s.id DESC LIMIT 1) sh ON TRUE
    LEFT JOIN users cu ON cu.id = o.user_id
    WHERE oi.quantity > 0
    UNION ALL
    SELECT l.created_at, CASE WHEN mm[2]::int > mm[1]::int THEN 'in' ELSE 'out' END, 'manual',
           NULLIF(SUBSTRING(l.details FROM '#(\d+)'), '')::int,
           COALESCE(SUBSTRING(l.details FROM '"([^"]{1,200})"'), 'Product'),
           ABS(mm[2]::int - mm[1]::int), mm[1]::int, mm[2]::int, NULL,
           NULLIF(TRIM(COALESCE(u.name, '') || ' ' || COALESCE(u.surname, '')), '')
    FROM logs l
    CROSS JOIN LATERAL regexp_match(l.details, 'stock\s+(\d+)\s*(?:→|->)\s*(\d+)') AS mm
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.action = 'edit_product' AND mm[1]::int <> mm[2]::int
    UNION ALL
    SELECT l.created_at, 'in', 'new', NULL::int,
           COALESCE(SUBSTRING(l.details FROM '^Added product: (.*) \[[^\]]*\] \(stock: \d+\)$'), 'Product'),
           SUBSTRING(l.details FROM '\(stock: (\d+)\)$')::int, 0, SUBSTRING(l.details FROM '\(stock: (\d+)\)$')::int, NULL,
           NULLIF(TRIM(COALESCE(u.name, '') || ' ' || COALESCE(u.surname, '')), '')
    FROM logs l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.action = 'add_product' AND l.details ~ '\(stock: [1-9]\d*\)$'
) m
SQL;
    $stmt = $db->prepare($sql . " WHERE DATE($local) BETWEEN ? AND ? ORDER BY m.at DESC");
    $stmt->execute([$from, $to]);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Category and stock on hand now, by id (and by name for a new product's opening stock, which has no id in its log)
    $byId = [];
    $byName = [];
    foreach ($db->query('SELECT id, name, category, stock FROM products')->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $byId[(int)$p['id']] = $p;
        $byName[mb_strtolower(trim((string)$p['name']))] = $p;
    }
    $rows = [];
    foreach ($raw as $r) {
        $id = $r['product_id'] !== null ? (int)$r['product_id'] : null;
        $p = $id !== null ? ($byId[$id] ?? null) : ($byName[mb_strtolower(trim((string)$r['product']))] ?? null);
        if ($id === null && $p !== null) {
            $id = (int)$p['id'];
        }
        $rows[] = [
            'at' => (string)$r['at'],
            'dir' => (string)$r['dir'],
            'source' => (string)$r['source'],
            'product_id' => $id,
            'product' => (string)$r['product'],
            'category' => (string)($p['category'] ?? ''),
            'qty' => (int)$r['qty'],
            'before' => $r['stock_before'] !== null ? (int)$r['stock_before'] : null,
            'after' => $r['stock_after'] !== null ? (int)$r['stock_after'] : null,
            'ref' => trim((string)($r['ref'] ?? '')),
            'by' => trim((string)($r['by_name'] ?? '')),
            'stock_now' => $p !== null ? (int)$p['stock'] : null,
            'key' => $id !== null ? 'id' . $id : 'n:' . mb_strtolower((string)$r['product']),
        ];
    }
    return $rows;
}

/** Totals for KPIs, per source and per product. */
function smr_totals(array $rows): array
{
    $t = ['in' => 0, 'out' => 0, 'count' => count($rows), 'sources' => [], 'products' => []];
    foreach (smr_sources() as $k => $label) {
        $t['sources'][$k] = ['label' => $label, 'count' => 0, 'in' => 0, 'out' => 0];
    }
    foreach ($rows as $r) {
        $t[$r['dir']] += $r['qty'];
        $t['sources'][$r['source']]['count']++;
        $t['sources'][$r['source']][$r['dir']] += $r['qty'];
        $p = &$t['products'][$r['key']];
        $p ??= ['product' => $r['product'], 'category' => $r['category'], 'in' => 0, 'out' => 0, 'count' => 0, 'stock_now' => $r['stock_now']];
        $p[$r['dir']] += $r['qty'];
        $p['count']++;
        unset($p);
    }
    $t['products'] = array_values($t['products']);
    usort($t['products'], fn($a, $b) => ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']) ?: strcasecmp($a['product'], $b['product']));
    $t['net'] = $t['in'] - $t['out'];
    return $t;
}

function smr_local_time(string $utc, string $format = 'M j, Y g:i A'): string
{
    return ias_report_datetime($utc, $format);
}

function smr_stock_pill(?int $stock): string
{
    if ($stock === null) {
        return '<span class="rep-sub">Not in catalog</span>';
    }
    $status = inv_stock_status_label($stock);
    $labels = ['out' => 'Out of stock', 'critical' => 'Critical', 'low' => 'Low', 'ok' => 'In stock'];
    $icons = ['out' => 'ph-x-circle', 'critical' => 'ph-warning-circle', 'low' => 'ph-warning', 'ok' => 'ph-check-circle'];
    return '<span class="stock-status-pill ' . $status . '"><i class="ph-fill ' . $icons[$status] . '" aria-hidden="true"></i> '
        . (int)$stock . ' · ' . $labels[$status] . '</span>';
}

function staff_stock_movement_page(PDO $db, string $role): void
{
    $self = smr_pages()[$role] ?? null;
    if ($self === null) {
        http_response_code(403);
        exit('Forbidden');
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $today = date('Y-m-d');
    $range = ias_resolve_report_range($_GET);
    $to = min($range['to']->format('Y-m-d'), $today);
    $from = min($range['from']->format('Y-m-d'), $to);
    $dirs = ['all' => 'In and out', 'in' => 'Stock in only', 'out' => 'Stock out only'];
    $dir = (string)($_GET['dir'] ?? 'all');
    if (!isset($dirs[$dir])) {
        $dir = 'all';
    }
    $sources = smr_sources();
    $source = (string)($_GET['source'] ?? '');
    if (!isset($sources[$source])) {
        $source = '';
    }
    $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));

    $filters = ['preset' => $range['preset'], 'dir' => $dir !== 'all' ? $dir : null, 'source' => $source ?: null, 'q' => $q !== '' ? $q : null];
    if ($range['preset'] === 'custom') {
        $filters['date_from'] = $from;
        $filters['date_to'] = $to;
    }
    $filters = array_filter($filters, fn($v) => $v !== null);

    $rows = array_values(array_filter(smr_rows($db, $from, $to), function ($r) use ($dir, $source, $q) {
        return ($dir === 'all' || $r['dir'] === $dir)
            && ($source === '' || $r['source'] === $source)
            && ($q === '' || mb_stripos($r['product'], $q) !== false);
    }));
    $t = smr_totals($rows);
    $rangeText = (new DateTimeImmutable($from))->format('M j, Y') . ' – ' . (new DateTimeImmutable($to))->format('M j, Y');
    $scope = [$dirs[$dir], $source !== '' ? $sources[$source] : 'All sources'];
    if ($q !== '') {
        $scope[] = 'Products matching "' . $q . '"';
    }
    $scopeText = implode(' · ', $scope);

    $export = (string)($_GET['export'] ?? '');
    if (in_array($export, ['csv', 'xlsx', 'pdf'], true)) {
        logActivity($db, $uid, 'export_stock_movements', 'Exported stock movements ' . $from . ' to ' . $to . ' (' . $export . ')');
        smr_export($export, $rows, $t, $range['label'] . ' (' . $rangeText . ')', $scopeText, $role);
    }

    $perPage = 25;
    $productsPer = 15;
    $totalPages = max(1, (int)ceil(count($rows) / $perPage));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
    $prodPages = max(1, (int)ceil(count($t['products']) / $productsPer));
    $pp = min(max(1, (int)($_GET['pp'] ?? 1)), $prodPages);
    $pageUrl = fn(int $p) => $self . '?' . http_build_query($filters + ['pp' => $pp, 'page' => $p]) . '#smrLog';
    $ppUrl = fn(int $p) => $self . '?' . http_build_query($filters + ['page' => $page, 'pp' => $p]) . '#smrProducts';
    $exportUrl = fn(string $f) => h($self . '?' . http_build_query($filters + ['export' => $f]));
    $presets = ias_report_date_presets();
    $isCustodian = $role === 'inventory_custodian';

    staff_page_start([
        'role' => $role,
        'title' => 'Stock Movements',
        'active' => $isCustodian ? 'stocks' : 'movements',
        'active_child' => $isCustodian ? 'movements' : '',
        'heading' => 'Stock Movements',
        'subtitle' => 'Every stock in and stock out for a period · receiving, POS sales, online orders and manual edits',
        'actions' => '<a class="cx-btn cx-btn-outline no-print" href="' . $exportUrl('csv') . '"><i class="ph ph-file-csv" aria-hidden="true"></i> CSV</a>'
            . '<a class="cx-btn cx-btn-outline no-print" href="' . $exportUrl('xlsx') . '"><i class="ph ph-file-xls" aria-hidden="true"></i> Excel</a>'
            . '<a class="cx-btn cx-btn-outline no-print" href="' . $exportUrl('pdf') . '"><i class="ph ph-file-pdf" aria-hidden="true"></i> PDF</a>'
            . '<button type="button" class="cx-btn cx-btn-outline no-print" id="repPrint"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>',
        'report' => [
            'title' => 'Inventory Movement Report',
            'lines' => [
                'Period covered' => $range['label'] . ' (' . $rangeText . ')',
                'Movements' => $dirs[$dir],
                'Source' => $source !== '' ? $sources[$source] : 'All sources',
                'Product search' => $q !== '' ? $q : 'All products',
            ],
        ],
    ]);
    ?>
        <div class="rep">
            <form class="eodr-filters cx-card no-print" method="get" action="<?php echo h($self); ?>" id="repFilters">
                <label class="sort-wrap">
                    <span>Period</span>
                    <select name="preset" id="repPreset">
                        <?php foreach ($presets as $k => $p): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $range['preset'] === $k ? 'selected' : ''; ?>><?php echo h($p['label']); ?></option>
                        <?php endforeach; ?>
                        <option value="custom" <?php echo $range['preset'] === 'custom' ? 'selected' : ''; ?>>Custom range</option>
                    </select>
                </label>
                <span class="eodr-dates" id="repDates" <?php echo $range['preset'] === 'custom' ? '' : 'hidden'; ?>>
                    <label class="cx-sr" for="repFrom">From</label>
                    <input type="date" class="form-control" id="repFrom" name="date_from" value="<?php echo h($from); ?>" max="<?php echo h($today); ?>">
                    <span aria-hidden="true">–</span>
                    <label class="cx-sr" for="repTo">To</label>
                    <input type="date" class="form-control" id="repTo" name="date_to" value="<?php echo h($to); ?>" max="<?php echo h($today); ?>">
                </span>
                <label class="sort-wrap">
                    <span>Show</span>
                    <select name="dir">
                        <?php foreach ($dirs as $k => $label): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $dir === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="sort-wrap">
                    <span>Source</span>
                    <select name="source">
                        <option value="">All sources</option>
                        <?php foreach ($sources as $k => $label): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $source === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="rep-search">
                    <span class="cx-sr">Product name</span>
                    <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" class="form-control" name="q" value="<?php echo h($q); ?>" placeholder="Search product" maxlength="80">
                </label>
                <button type="submit" class="cx-btn cx-btn-outline">Apply</button>
                <?php if ($filters !== ['preset' => 'this_month']): ?>
                    <a class="cx-btn cx-btn-ghost" href="<?php echo h($self); ?>"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Reset</a>
                <?php endif; ?>
            </form>

            <section class="cx-card" aria-labelledby="smrSumTitle">
                <div class="cx-card-head">
                    <h2 id="smrSumTitle"><i class="ph ph-arrows-down-up" aria-hidden="true"></i> <?php echo h($range['label']); ?></h2>
                    <span class="cx-card-meta cx-num"><?php echo h($rangeText); ?></span>
                </div>
                <div class="kpis">
                    <div class="kpi kpi-hero">
                        <span class="kpi-label">Units in</span>
                        <strong class="kpi-value cx-num mv-qty is-in">+<?php echo number_format($t['in']); ?></strong>
                        <span class="kpi-sub">received or added</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Units out</span>
                        <strong class="kpi-value cx-num mv-qty is-out">−<?php echo number_format($t['out']); ?></strong>
                        <span class="kpi-sub">sold or taken off</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Net change</span>
                        <strong class="kpi-value cx-num"><?php echo ($t['net'] > 0 ? '+' : ($t['net'] < 0 ? '−' : '')) . number_format(abs($t['net'])); ?></strong>
                        <span class="kpi-sub">units in minus units out</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Products moved</span>
                        <strong class="kpi-value cx-num"><?php echo number_format(count($t['products'])); ?></strong>
                        <span class="kpi-sub"><?php echo number_format($t['count']); ?> movement<?php echo $t['count'] === 1 ? '' : 's'; ?></span>
                    </div>
                </div>
            </section>

            <section class="cx-card" aria-labelledby="smrSrcTitle">
                <div class="cx-card-head">
                    <div>
                        <h2 id="smrSrcTitle"><i class="ph ph-tray-arrow-down" aria-hidden="true"></i> By source</h2>
                        <p>Where the stock came from and where it went</p>
                    </div>
                </div>
                <div class="cx-table-wrap">
                    <table class="cx-table rep-small-table">
                        <thead><tr><th scope="col">Source</th><th scope="col" class="num">Movements</th><th scope="col" class="num">Units in</th><th scope="col" class="num">Units out</th></tr></thead>
                        <tbody>
                        <?php foreach ($t['sources'] as $s): ?>
                            <tr>
                                <th scope="row"><?php echo h($s['label']); ?></th>
                                <td class="num cx-num"><?php echo number_format($s['count']); ?></td>
                                <td class="num cx-num"><?php echo $s['in'] ? '<span class="mv-qty is-in">+' . number_format($s['in']) . '</span>' : '—'; ?></td>
                                <td class="num cx-num"><?php echo $s['out'] ? '<span class="mv-qty is-out">−' . number_format($s['out']) . '</span>' : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr>
                            <th scope="row">Total</th>
                            <td class="num cx-num"><?php echo number_format($t['count']); ?></td>
                            <td class="num cx-num">+<?php echo number_format($t['in']); ?></td>
                            <td class="num cx-num">−<?php echo number_format($t['out']); ?></td>
                        </tr></tfoot>
                    </table>
                </div>
            </section>

            <section class="cx-card" aria-labelledby="smrProdTitle" id="smrProducts">
                <div class="cx-card-head">
                    <div>
                        <h2 id="smrProdTitle"><i class="ph ph-package" aria-hidden="true"></i> By product</h2>
                        <p>Most moved first · stock now is today's count</p>
                    </div>
                    <span class="cx-count"><i class="ph ph-rows" aria-hidden="true"></i> <?php echo number_format(count($t['products'])); ?> product<?php echo count($t['products']) === 1 ? '' : 's'; ?></span>
                </div>
                <?php if (!$t['products']): ?>
                    <div class="cx-empty">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-package"></i></span>
                        <strong>No stock movements in this period</strong>
                        <span>Pick a longer period or clear the filters.</span>
                    </div>
                <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table rep-table mv-prod-table">
                            <thead><tr>
                                <th scope="col">Product</th>
                                <th scope="col" class="num">In</th>
                                <th scope="col" class="num">Out</th>
                                <th scope="col" class="num">Net</th>
                                <th scope="col">Stock now</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($t['products'] as $n => $p):
                                $off = $n < ($pp - 1) * $productsPer || $n >= $pp * $productsPer;
                                $net = $p['in'] - $p['out']; ?>
                                <tr<?php echo $off ? ' class="cx-print-row"' : ''; ?>>
                                    <td data-label="Product" class="rep-name">
                                        <strong><?php echo h($p['product']); ?></strong>
                                        <?php if ($p['category'] !== ''): ?><span class="rep-sub"><?php echo h($p['category']); ?></span><?php endif; ?>
                                    </td>
                                    <td data-label="In" class="num cx-num"><?php echo $p['in'] ? '<span class="mv-qty is-in">+' . number_format($p['in']) . '</span>' : '—'; ?></td>
                                    <td data-label="Out" class="num cx-num"><?php echo $p['out'] ? '<span class="mv-qty is-out">−' . number_format($p['out']) . '</span>' : '—'; ?></td>
                                    <td data-label="Net" class="num cx-num"><?php echo ($net > 0 ? '+' : ($net < 0 ? '−' : '')) . number_format(abs($net)); ?></td>
                                    <td data-label="Stock now"><?php echo smr_stock_pill($p['stock_now']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php staff_pagination($pp, $prodPages, count($t['products']), $productsPer, $ppUrl, 'Product pages'); ?>
                <?php endif; ?>
            </section>

            <section class="cx-card" aria-labelledby="smrLogTitle" id="smrLog">
                <div class="cx-card-head">
                    <div>
                        <h2 id="smrLogTitle"><i class="ph ph-list-bullets" aria-hidden="true"></i> Movement log</h2>
                        <p>Newest first · stock before → after where it was recorded</p>
                    </div>
                    <span class="cx-count"><i class="ph ph-rows" aria-hidden="true"></i> <?php echo number_format(count($rows)); ?> row<?php echo count($rows) === 1 ? '' : 's'; ?></span>
                </div>
                <?php if (!$rows): ?>
                    <div class="cx-empty">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-list-bullets"></i></span>
                        <strong>Nothing to list</strong>
                        <span>No stock moved in this period with these filters.</span>
                    </div>
                <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table rep-table mv-log-table">
                            <thead><tr>
                                <th scope="col">When</th>
                                <th scope="col">Product</th>
                                <th scope="col">Movement</th>
                                <th scope="col" class="num">Qty</th>
                                <th scope="col" class="num">Stock</th>
                                <th scope="col">Reference</th>
                                <th scope="col">By</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $n => $r):
                                $off = $n < ($page - 1) * $perPage || $n >= $page * $perPage;
                                $in = $r['dir'] === 'in'; ?>
                                <tr class="is-<?php echo $in ? 'in' : 'out'; ?><?php echo $off ? ' cx-print-row' : ''; ?>">
                                    <td data-label="When" class="cx-num rep-when">
                                        <?php echo h(smr_local_time($r['at'], 'M j, Y')); ?>
                                        <span class="rep-sub"><?php echo h(smr_local_time($r['at'], 'g:i A')); ?></span>
                                    </td>
                                    <td data-label="Product" class="rep-name">
                                        <strong><?php echo h($r['product']); ?></strong>
                                        <?php if ($r['category'] !== ''): ?><span class="rep-sub"><?php echo h($r['category']); ?></span><?php endif; ?>
                                    </td>
                                    <td data-label="Movement">
                                        <span class="mv-dir is-<?php echo $in ? 'in' : 'out'; ?>"><i class="ph-fill <?php echo $in ? 'ph-arrow-circle-down' : 'ph-arrow-circle-up'; ?>" aria-hidden="true"></i> <?php echo $in ? 'Stock in' : 'Stock out'; ?></span>
                                        <span class="rep-sub"><?php echo h($sources[$r['source']]); ?></span>
                                    </td>
                                    <td data-label="Qty" class="num cx-num"><span class="mv-qty is-<?php echo $in ? 'in' : 'out'; ?>"><?php echo ($in ? '+' : '−') . number_format($r['qty']); ?></span></td>
                                    <td data-label="Stock" class="num cx-num"><?php echo $r['before'] !== null ? (int)$r['before'] . ' → ' . (int)$r['after'] : '—'; ?></td>
                                    <td data-label="Reference" class="cx-mono rep-ref"><?php echo $r['ref'] !== '' ? h($r['ref']) : '—'; ?></td>
                                    <td data-label="By"><?php echo $r['by'] !== '' ? h($r['by']) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php staff_pagination($page, $totalPages, count($rows), $perPage, $pageUrl, 'Movement log pages'); ?>
                <?php endif; ?>
            </section>
        </div>
    <?php
    staff_page_end(staff_report_filters_script());
}

/** Filter form behaviour shared by the report pages: a period or select change applies at once; the search applies with Enter or Apply. */
function staff_report_filters_script(): string
{
    return <<<'SCRIPT'
<script>
(function () {
    'use strict';
    var form = document.getElementById('repFilters');
    var preset = document.getElementById('repPreset');
    var dates = document.getElementById('repDates');
    if (form) {
        form.addEventListener('change', function (e) {
            if (e.target.type === 'search') return;
            if (e.target === preset && preset.value === 'custom') {
                dates.hidden = false;
                document.getElementById('repFrom').focus();
                return;
            }
            if (preset.value === 'custom') {
                var f = document.getElementById('repFrom').value, t = document.getElementById('repTo').value;
                if (!f || !t) return;
            } else {
                dates.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
            }
            form.submit();
        });
    }
    var print = document.getElementById('repPrint');
    if (print) print.addEventListener('click', function () { window.print(); });
})();
</script>
SCRIPT;
}

/** CSV, Excel or PDF of the filtered report; streams the file and exits. */
function smr_export(string $format, array $rows, array $t, string $periodText, string $scopeText, string $role): void
{
    $title = 'Inventory Movement Report';
    $preparedBy = 'Prepared by ' . staff_report_user_name() . ', ' . staff_role_label($role) . ' on ' . ias_report_datetime(gmdate('Y-m-d H:i:s'));
    $lines = ['Period covered: ' . $periodText, 'Showing: ' . $scopeText];
    $sources = smr_sources();
    $base = 'stock_movements';
    $logRow = fn($r) => [
        smr_local_time($r['at'], 'Y-m-d H:i'), $r['product'], $r['category'], $r['dir'] === 'in' ? 'Stock in' : 'Stock out',
        $sources[$r['source']], $r['dir'] === 'in' ? $r['qty'] : -$r['qty'], $r['before'], $r['after'], $r['ref'], $r['by'],
    ];
    $logHead = ['Date and time', 'Product', 'Category', 'Movement', 'Source', 'Qty', 'Stock before', 'Stock after', 'Reference', 'By'];
    $prodHead = ['Product', 'Category', 'Units in', 'Units out', 'Net', 'Stock now'];
    $prodRow = fn($p) => [$p['product'], $p['category'], $p['in'], $p['out'], $p['in'] - $p['out'], $p['stock_now']];
    $kpis = [['Units in', $t['in']], ['Units out', $t['out']], ['Net change', $t['net']], ['Products moved', count($t['products'])], ['Movements', $t['count']]];

    if ($format === 'csv') {
        ias_emit_csv_sections(ias_export_filename($base, 'csv'), array_merge([[$title]], array_map(fn($l) => [$l], $lines), [[$preparedBy]]), [
            ['title' => 'Summary', 'headers' => ['Metric', 'Value'], 'rows' => $kpis],
            ['title' => 'By source', 'headers' => ['Source', 'Movements', 'Units in', 'Units out'],
                'rows' => array_map(fn($s) => [$s['label'], $s['count'], $s['in'], $s['out']], array_values($t['sources']))],
            ['title' => 'By product', 'headers' => $prodHead, 'rows' => array_map($prodRow, $t['products'])],
            ['title' => 'Movement log', 'headers' => $logHead, 'rows' => array_map($logRow, $rows)],
        ]);
    }

    if ($format === 'xlsx') {
        require_once __DIR__ . '/xlsx_writer.php';
        $X = 'IasXlsxWriter';
        $hd = fn(array $names) => array_map(fn($n) => ['v' => $n, 's' => $X::STYLE_HEADER], $names);
        $int = fn($v) => $v === null ? '' : ['v' => (int)$v, 's' => $X::STYLE_INT];
        $sum = [[['v' => $title . ' — EasyPC One Oasis Branch', 's' => $X::STYLE_TITLE]]];
        foreach (array_merge($lines, [$preparedBy]) as $l) {
            $sum[] = [['v' => $l, 's' => $X::STYLE_NOTE]];
        }
        $sum[] = [];
        $sum[] = $hd(['Metric', 'Value']);
        foreach ($kpis as [$k, $v]) {
            $sum[] = [$k, $int($v)];
        }
        $sum[] = [];
        $sum[] = $hd(['Source', 'Movements', 'Units in', 'Units out']);
        foreach ($t['sources'] as $s) {
            $sum[] = [$s['label'], $int($s['count']), $int($s['in']), $int($s['out'])];
        }
        $prod = [$hd($prodHead)];
        foreach ($t['products'] as $p) {
            $prod[] = array_map(fn($v, $i) => $i >= 2 ? $int($v) : $v, $prodRow($p), array_keys($prodRow($p)));
        }
        $log = [$hd($logHead)];
        foreach ($rows as $r) {
            $cells = $logRow($r);
            $cells[0] = ['v' => IasXlsxWriter::excelDate(smr_local_time($r['at'], 'Y-m-d H:i:s')) ?? '', 's' => $X::STYLE_DATETIME];
            foreach ([5, 6, 7] as $i) {
                $cells[$i] = $int($cells[$i]);
            }
            $log[] = $cells;
        }
        $x = new IasXlsxWriter();
        $x->addSheet('Summary', $sum, ['widths' => [28, 14, 12, 12]]);
        $x->addSheet('By product', $prod, ['widths' => [52, 20, 10, 10, 10, 10], 'freeze' => 1, 'filter' => 'A1:F' . max(2, count($prod))]);
        $x->addSheet('Movement log', $log, ['widths' => [20, 52, 20, 11, 20, 8, 12, 12, 30, 26], 'freeze' => 1, 'filter' => 'A1:J' . max(2, count($log))]);
        $x->output(ias_export_filename($base, 'xlsx'));
    }

    require_once __DIR__ . '/pdf_report.php';
    $signed = fn(int $v) => ($v > 0 ? '+' : ($v < 0 ? '-' : '')) . number_format(abs($v));
    $pdf = new IasPdfReport($title, array_merge($lines, [$preparedBy]), 'L');
    $pdf->summary([['Units in', '+' . number_format($t['in'])], ['Units out', '-' . number_format($t['out'])],
        ['Net change', $signed($t['net'])], ['Products moved', number_format(count($t['products'])) . ' (' . number_format($t['count']) . ' movements)']]);
    $pdf->heading('By source', 'Where the stock came from and where it went', 120);
    $pdf->table(['Source', 'Movements', 'Units in', 'Units out'], array_map(fn($s) => [$s['label'], number_format($s['count']),
        $s['in'] ? '+' . number_format($s['in']) : '-', $s['out'] ? '-' . number_format($s['out']) : '-'], array_values($t['sources'])),
        ['widths' => [2.4, 1, 1, 1], 'align' => ['L', 'R', 'R', 'R']]);
    $pdf->heading('By product', 'Most moved first · stock now is the count when this file was made');
    $pdf->table($prodHead, array_map(fn($p) => [$p['product'], $p['category'] ?: '-', $p['in'] ? '+' . number_format($p['in']) : '-',
        $p['out'] ? '-' . number_format($p['out']) : '-', $signed($p['in'] - $p['out']), $p['stock_now'] === null ? '-' : number_format($p['stock_now'])], $t['products']),
        ['widths' => [4, 1.6, 0.8, 0.8, 0.8, 0.9], 'align' => ['L', 'L', 'R', 'R', 'R', 'R']]);
    if (!$t['products']) {
        $pdf->paragraph('No stock movements in this period.');
    }
    $pdf->heading('Movement log', count($rows) . ' movement' . (count($rows) === 1 ? '' : 's') . ' · newest first');
    $pdf->table(['Date and time', 'Product', 'Movement', 'Source', 'Qty', 'Stock', 'Reference', 'By'], array_map(fn($r) => [
        smr_local_time($r['at']), $r['product'], $r['dir'] === 'in' ? 'Stock in' : 'Stock out', $sources[$r['source']],
        ($r['dir'] === 'in' ? '+' : '-') . number_format($r['qty']), $r['before'] !== null ? $r['before'] . ' -> ' . $r['after'] : '-',
        $r['ref'] ?: '-', $r['by'] ?: '-'], $rows),
        ['widths' => [1.3, 3, 0.9, 1.2, 0.6, 0.8, 1.6, 1.4], 'align' => ['L', 'L', 'L', 'L', 'R', 'R', 'L', 'L'], 'font_size' => 8]);
    $pdf->output(ias_export_filename($base, 'pdf'));
}
