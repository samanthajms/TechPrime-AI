<?php
/**
 * PC Build Report — the Build a PC recommendation report (Admin, Retail Officer).
 * What customers built and saved in Build a PC over a chosen period: how many builds, for which
 * purpose, the parts picked most, parts customers wanted that are out of stock now, and every saved
 * build with its price then and now. Data: saved_builds (one row per saved build, parts in
 * components_json) and the saved_build_to_cart log. The recommender's suggestions themselves are not
 * stored, so the report covers the builds customers kept.
 * Prints with the formal report layout (includes/staff_print.css) and downloads as CSV, Excel or PDF.
 * ADMIN/admin_build_report.php and RETAIL/retail_build_report.php are thin wrappers (guards first,
 * then staff_build_report_page()). Read-only.
 */
require_once __DIR__ . '/retail_reports.php';      // date range presets, CSV + file name helpers
require_once __DIR__ . '/inventory_alerts.php';    // inv_stock_status_label()
require_once __DIR__ . '/timezone_helpers.php';    // sql_utc_to_local()
require_once __DIR__ . '/pc_recommend.php';        // slot and add-on names, pc_purposes()
require_once __DIR__ . '/pc_saved_builds.php';     // prices then and now
require_once __DIR__ . '/stock_movement_report.php';   // staff_report_filters_script(), smr_stock_pill()
require_once __DIR__ . '/staff_app_layout.php';    // staff_report_user_name()

function pbr_pages(): array
{
    return ['admin' => 'admin_build_report.php', 'retail_officer' => 'retail_build_report.php'];
}

/** Part name per Build a PC slot, add-ons included. */
function pbr_slot_labels(): array
{
    $labels = [];
    foreach (pc_recommend_slot_map() + pc_recommend_addon_map() as $slot => $s) {
        $labels[$slot] = $s['label'];
    }
    return $labels + ['extras' => 'Add-on'];
}

/** A build with the core parts a working PC needs (graphics can be built into the processor). */
function pbr_has_core(array $comps): bool
{
    $has = fn(string $slot) => is_array($comps[$slot] ?? null) && (int)($comps[$slot]['id'] ?? 0) > 0;
    return $has('processor') && $has('motherboard') && $has('memory') && $has('psu') && $has('case')
        && ($has('ssd') || $has('ssd_sata') || $has('hdd'));
}

/**
 * Saved builds whose branch-local save date is in [$from, $to], newest first, with prices then and now.
 *
 * @return array{builds:list<array>,to_cart:int}
 */
function pbr_data(PDO $db, string $from, string $to): array
{
    $local = sql_utc_to_local('b.created_at');
    $stmt = $db->prepare(
        "SELECT b.id, b.user_id, b.build_name, b.components_json, b.total_price, b.created_at,
                NULLIF(TRIM(COALESCE(u.name, '') || ' ' || COALESCE(u.surname, '')), '') AS customer
         FROM saved_builds b
         LEFT JOIN users u ON u.id = b.user_id
         WHERE DATE($local) BETWEEN ? AND ?
         ORDER BY b.created_at DESC, b.id DESC"
    );
    $stmt->execute([$from, $to]);
    $purposes = pc_purposes();
    $builds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $comps = json_decode((string)$b['components_json'], true);
        $comps = is_array($comps) ? $comps : [];
        $parts = [];
        foreach ($comps as $slot => $item) {
            if (is_array($item) && (int)($item['id'] ?? 0) > 0) {
                $parts[(string)$slot] = $item;
            }
        }
        $now = pc_saved_build_products($db, $parts);
        $check = pc_saved_build_price_check($parts, $now);
        $purpose = (string)($comps['_purpose'] ?? '');
        $builds[] = [
            'id' => (int)$b['id'],
            'user_id' => (int)$b['user_id'],
            'customer' => (string)($b['customer'] ?? 'Customer #' . (int)$b['user_id']),
            'name' => trim((string)$b['build_name']) !== '' ? (string)$b['build_name'] : 'Untitled build',
            'created_at' => (string)$b['created_at'],
            'purpose' => $purpose,
            'purpose_label' => $purposes[$purpose]['label'] ?? 'Not recorded',
            'parts' => $parts,
            'now' => $now,
            'saved_total' => round((float)$b['total_price'], 2),
            'current_total' => $check['current_total'],
            'changed' => (bool)$check['expired'],
            'sold_out' => $check['sold_out'],
            'missing' => $check['missing'],
            'core' => pbr_has_core($parts),
        ];
    }
    // Builds the customer sent to the cart in the same period ("Build #12 → cart (9 items)")
    $logLocal = sql_utc_to_local('created_at');
    $cart = $db->prepare("SELECT details FROM logs WHERE action = 'saved_build_to_cart' AND DATE($logLocal) BETWEEN ? AND ?");
    $cart->execute([$from, $to]);
    $toCart = [];
    foreach ($cart->fetchAll(PDO::FETCH_COLUMN) as $d) {
        if (preg_match('/#(\d+)/', (string)$d, $m)) {
            $toCart[(int)$m[1]] = true;
        }
    }
    return ['builds' => $builds, 'to_cart' => count($toCart)];
}

/** Totals: KPIs, purposes, most-picked parts, wanted parts that are out of stock now. */
function pbr_totals(array $builds): array
{
    $labels = pbr_slot_labels();
    $n = count($builds);
    $t = [
        'count' => $n,
        'customers' => count(array_unique(array_column($builds, 'user_id'))),
        'avg' => $n ? array_sum(array_column($builds, 'saved_total')) / $n : 0.0,
        'core' => count(array_filter($builds, fn($b) => $b['core'])),
        'purposes' => [],
        'parts' => [],
    ];
    foreach ($builds as $b) {
        $pk = $b['purpose_label'];
        $t['purposes'][$pk] ??= ['label' => $pk, 'count' => 0, 'value' => 0.0];
        $t['purposes'][$pk]['count']++;
        $t['purposes'][$pk]['value'] += $b['saved_total'];
        foreach ($b['parts'] as $slot => $item) {
            if (!empty($item['owned'])) {
                continue;   // the customer already has it: not a pick from the shop
            }
            $id = (int)$item['id'];
            $p = &$t['parts'][$id];
            $p ??= ['id' => $id, 'name' => (string)($item['name'] ?? 'Product #' . $id), 'slots' => [], 'count' => 0, 'last' => '',
                'price' => $b['now'][$id]['price'] ?? null, 'stock' => $b['now'][$id]['stock'] ?? null];
            $p['count']++;
            $p['slots'][$labels[$slot] ?? ucfirst(str_replace('_', ' ', (string)$slot))] = true;
            $p['last'] = max($p['last'], $b['created_at']);
            unset($p);
        }
    }
    uasort($t['purposes'], fn($a, $b) => $b['count'] <=> $a['count']);
    $t['parts'] = array_values($t['parts']);
    foreach ($t['parts'] as &$p) {
        $p['slot'] = implode(', ', array_keys($p['slots']));
    }
    unset($p);
    usort($t['parts'], fn($a, $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']));
    // Wanted but not available now: out of stock, or no longer in the catalog
    $t['wanted'] = array_values(array_filter($t['parts'], fn($p) => $p['stock'] === null || $p['stock'] <= 0));
    return $t;
}

function pbr_status(array $b): array
{
    if ($b['sold_out'] || $b['missing']) {
        $k = count($b['sold_out']) + count($b['missing']);
        return ['key' => 'out', 'label' => $k . ' part' . ($k === 1 ? '' : 's') . ' unavailable', 'icon' => 'ph-x-circle'];
    }
    if ($b['changed']) {
        return ['key' => 'low', 'label' => 'Prices changed', 'icon' => 'ph-warning'];
    }
    return ['key' => 'ok', 'label' => 'Ready to buy', 'icon' => 'ph-check-circle'];
}

function staff_build_report_page(PDO $db, string $role): void
{
    $self = pbr_pages()[$role] ?? null;
    if ($self === null) {
        http_response_code(403);
        exit('Forbidden');
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $today = date('Y-m-d');
    $range = ias_resolve_report_range(($_GET['preset'] ?? '') === '' && empty($_GET['date_from']) ? ['preset' => 'this_year'] + $_GET : $_GET);
    $to = min($range['to']->format('Y-m-d'), $today);
    $from = min($range['from']->format('Y-m-d'), $to);
    $purposeOptions = ['' => 'All purposes'];
    foreach (pc_purposes() as $k => $p) {
        $purposeOptions[$k] = $p['label'];
    }
    $purposeOptions['none'] = 'Not recorded';
    $purpose = (string)($_GET['purpose'] ?? '');
    if (!isset($purposeOptions[$purpose])) {
        $purpose = '';
    }
    $filters = ['preset' => $range['preset'], 'purpose' => $purpose !== '' ? $purpose : null];
    if ($range['preset'] === 'custom') {
        $filters['date_from'] = $from;
        $filters['date_to'] = $to;
    }
    $filters = array_filter($filters, fn($v) => $v !== null);

    $data = pbr_data($db, $from, $to);
    $builds = array_values(array_filter($data['builds'], fn($b) => $purpose === ''
        || ($purpose === 'none' ? $b['purpose'] === '' || !isset(pc_purposes()[$b['purpose']]) : $b['purpose'] === $purpose)));
    $t = pbr_totals($builds);
    $rangeText = (new DateTimeImmutable($from))->format('M j, Y') . ' – ' . (new DateTimeImmutable($to))->format('M j, Y');

    $export = (string)($_GET['export'] ?? '');
    if (in_array($export, ['csv', 'xlsx', 'pdf'], true)) {
        logActivity($db, $uid, 'export_build_report', 'Exported PC build report ' . $from . ' to ' . $to . ' (' . $export . ')');
        pbr_export($export, $builds, $t, $data['to_cart'], $range['label'] . ' (' . $rangeText . ')', $purposeOptions[$purpose], $role);
    }

    $perPage = 20;
    $partsPer = 15;
    $totalPages = max(1, (int)ceil(count($builds) / $perPage));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
    $partPages = max(1, (int)ceil(count($t['parts']) / $partsPer));
    $pp = min(max(1, (int)($_GET['pp'] ?? 1)), $partPages);
    $pageUrl = fn(int $p) => $self . '?' . http_build_query($filters + ['pp' => $pp, 'page' => $p]) . '#pbrBuilds';
    $ppUrl = fn(int $p) => $self . '?' . http_build_query($filters + ['page' => $page, 'pp' => $p]) . '#pbrParts';
    $exportUrl = fn(string $f) => h($self . '?' . http_build_query($filters + ['export' => $f]));
    $presets = ias_report_date_presets();
    $peso = fn(float $v) => '₱' . number_format($v, 2);

    staff_page_start([
        'role' => $role,
        'title' => 'PC Build Report',
        'active' => 'builds',
        'heading' => 'PC Build Report',
        'subtitle' => 'What customers built and saved in Build a PC · purposes, most-picked parts and parts they could not get',
        'actions' => '<a class="cx-btn cx-btn-outline no-print" href="' . $exportUrl('csv') . '"><i class="ph ph-file-csv" aria-hidden="true"></i> CSV</a>'
            . '<a class="cx-btn cx-btn-outline no-print" href="' . $exportUrl('xlsx') . '"><i class="ph ph-file-xls" aria-hidden="true"></i> Excel</a>'
            . '<a class="cx-btn cx-btn-outline no-print" href="' . $exportUrl('pdf') . '"><i class="ph ph-file-pdf" aria-hidden="true"></i> PDF</a>'
            . '<button type="button" class="cx-btn cx-btn-outline no-print" id="repPrint"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>',
        'report' => [
            'title' => 'Build a PC Recommendation Report',
            'lines' => [
                'Period covered' => $range['label'] . ' (' . $rangeText . ')',
                'Purpose' => $purposeOptions[$purpose],
                'Based on' => 'Builds customers saved in Build a PC',
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
                    <span>Purpose</span>
                    <select name="purpose">
                        <?php foreach ($purposeOptions as $k => $label): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $purpose === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button type="submit" class="cx-btn cx-btn-outline">Apply</button></noscript>
                <?php if ($filters !== ['preset' => 'this_year']): ?>
                    <a class="cx-btn cx-btn-ghost" href="<?php echo h($self); ?>"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Reset</a>
                <?php endif; ?>
            </form>

            <section class="cx-card" aria-labelledby="pbrSumTitle">
                <div class="cx-card-head">
                    <h2 id="pbrSumTitle"><i class="ph ph-desktop-tower" aria-hidden="true"></i> <?php echo h($range['label']); ?></h2>
                    <span class="cx-card-meta cx-num"><?php echo h($rangeText); ?></span>
                </div>
                <div class="kpis">
                    <div class="kpi kpi-hero">
                        <span class="kpi-label">Builds saved</span>
                        <strong class="kpi-value cx-num"><?php echo number_format($t['count']); ?></strong>
                        <span class="kpi-sub">by <?php echo number_format($t['customers']); ?> customer<?php echo $t['customers'] === 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Average build</span>
                        <strong class="kpi-value cx-num"><?php echo h($peso($t['avg'])); ?></strong>
                        <span class="kpi-sub">price when saved</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Sent to cart</span>
                        <strong class="kpi-value cx-num"><?php echo number_format($data['to_cart']); ?></strong>
                        <span class="kpi-sub">saved builds added to a cart</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Complete builds</span>
                        <strong class="kpi-value cx-num"><?php echo number_format($t['core']); ?></strong>
                        <span class="kpi-sub">with every core part</span>
                    </div>
                </div>
            </section>

            <div class="rep-grid">
                <section class="cx-card" aria-labelledby="pbrPurposeTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="pbrPurposeTitle"><i class="ph ph-target" aria-hidden="true"></i> Built for</h2>
                            <p>The purpose customers chose</p>
                        </div>
                    </div>
                    <?php if (!$t['purposes']): ?>
                        <div class="cx-empty"><strong>No saved builds</strong><span>Pick a longer period.</span></div>
                    <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table rep-small-table">
                            <thead><tr><th scope="col">Purpose</th><th scope="col" class="num">Builds</th><th scope="col" class="num">Average build</th></tr></thead>
                            <tbody>
                            <?php foreach ($t['purposes'] as $p): ?>
                                <tr>
                                    <th scope="row"><?php echo h($p['label']); ?></th>
                                    <td class="num cx-num"><?php echo number_format($p['count']); ?></td>
                                    <td class="num cx-num"><?php echo h($peso($p['value'] / max(1, $p['count']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="rep-note">"Not recorded": saved before Build a PC kept the purpose with the build.</p>
                    <?php endif; ?>
                </section>

                <section class="cx-card" aria-labelledby="pbrWantedTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="pbrWantedTitle"><i class="ph ph-warning-circle" aria-hidden="true"></i> Wanted but unavailable</h2>
                            <p>Parts in saved builds that are out of stock now</p>
                        </div>
                    </div>
                    <?php if (!$t['wanted']): ?>
                        <div class="cx-empty"><strong>None</strong><span>Every part customers picked is in stock.</span></div>
                    <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table rep-small-table">
                            <thead><tr><th scope="col">Part</th><th scope="col" class="num">In builds</th><th scope="col">Stock now</th></tr></thead>
                            <tbody>
                            <?php foreach ($t['wanted'] as $p): ?>
                                <tr>
                                    <th scope="row"><?php echo h($p['name']); ?><span class="rep-sub"><?php echo h($p['slot']); ?></span></th>
                                    <td class="num cx-num"><?php echo number_format($p['count']); ?></td>
                                    <td><?php echo smr_stock_pill($p['stock']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>
            </div>

            <section class="cx-card" aria-labelledby="pbrPartsTitle" id="pbrParts">
                <div class="cx-card-head">
                    <div>
                        <h2 id="pbrPartsTitle"><i class="ph ph-cpu" aria-hidden="true"></i> Most-picked parts</h2>
                        <p>How many saved builds use each part · parts the customer already owned are left out</p>
                    </div>
                    <span class="cx-count"><i class="ph ph-rows" aria-hidden="true"></i> <?php echo number_format(count($t['parts'])); ?> part<?php echo count($t['parts']) === 1 ? '' : 's'; ?></span>
                </div>
                <?php if (!$t['parts']): ?>
                    <div class="cx-empty">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-cpu"></i></span>
                        <strong>No parts picked in this period</strong>
                        <span>Pick a longer period.</span>
                    </div>
                <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table rep-table pbr-parts-table">
                            <thead><tr>
                                <th scope="col">Part</th>
                                <th scope="col" class="num">In builds</th>
                                <th scope="col" class="num">Share</th>
                                <th scope="col" class="num">Price now</th>
                                <th scope="col">Stock now</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($t['parts'] as $n => $p):
                                $off = $n < ($pp - 1) * $partsPer || $n >= $pp * $partsPer; ?>
                                <tr<?php echo $off ? ' class="cx-print-row"' : ''; ?>>
                                    <td data-label="Part" class="rep-name"><strong><?php echo h($p['name']); ?></strong><span class="rep-sub"><?php echo h($p['slot']); ?></span></td>
                                    <td data-label="In builds" class="num cx-num"><?php echo number_format($p['count']); ?></td>
                                    <td data-label="Share" class="num cx-num"><?php echo $t['count'] ? round($p['count'] / $t['count'] * 100) . '%' : '—'; ?></td>
                                    <td data-label="Price now" class="num cx-num"><?php echo $p['price'] !== null ? h($peso((float)$p['price'])) : '—'; ?></td>
                                    <td data-label="Stock now"><?php echo smr_stock_pill($p['stock']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php staff_pagination($pp, $partPages, count($t['parts']), $partsPer, $ppUrl, 'Part pages'); ?>
                <?php endif; ?>
            </section>

            <section class="cx-card" aria-labelledby="pbrBuildsTitle" id="pbrBuilds">
                <div class="cx-card-head">
                    <div>
                        <h2 id="pbrBuildsTitle"><i class="ph ph-list-bullets" aria-hidden="true"></i> Saved builds</h2>
                        <p>Newest first · total when saved and at today's prices</p>
                    </div>
                    <span class="cx-count"><i class="ph ph-rows" aria-hidden="true"></i> <?php echo number_format(count($builds)); ?> build<?php echo count($builds) === 1 ? '' : 's'; ?></span>
                </div>
                <?php if (!$builds): ?>
                    <div class="cx-empty">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-desktop-tower"></i></span>
                        <strong>No builds saved in this period</strong>
                        <span>Pick a longer period or another purpose.</span>
                    </div>
                <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table rep-table pbr-builds-table">
                            <thead><tr>
                                <th scope="col">Saved</th>
                                <th scope="col">Build</th>
                                <th scope="col">Purpose</th>
                                <th scope="col" class="num">Parts</th>
                                <th scope="col" class="num">When saved</th>
                                <th scope="col" class="num">Today</th>
                                <th scope="col">Status</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($builds as $n => $b):
                                $off = $n < ($page - 1) * $perPage || $n >= $page * $perPage;
                                $st = pbr_status($b); ?>
                                <tr<?php echo $off ? ' class="cx-print-row"' : ''; ?>>
                                    <td data-label="Saved" class="cx-num rep-when"><?php echo h(ias_report_datetime($b['created_at'], 'M j, Y')); ?><span class="rep-sub"><?php echo h(ias_report_datetime($b['created_at'], 'g:i A')); ?></span></td>
                                    <td data-label="Build" class="rep-name"><strong><?php echo h($b['name']); ?></strong><span class="rep-sub"><?php echo h($b['customer']); ?></span></td>
                                    <td data-label="Purpose"><?php echo h($b['purpose_label']); ?></td>
                                    <td data-label="Parts" class="num cx-num"><?php echo count($b['parts']); ?></td>
                                    <td data-label="When saved" class="num cx-num"><?php echo h($peso($b['saved_total'])); ?></td>
                                    <td data-label="Today" class="num cx-num"><?php echo h($peso($b['current_total'])); ?></td>
                                    <td data-label="Status"><span class="stock-status-pill <?php echo h($st['key']); ?>"><i class="ph-fill <?php echo h($st['icon']); ?>" aria-hidden="true"></i> <?php echo h($st['label']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php staff_pagination($page, $totalPages, count($builds), $perPage, $pageUrl, 'Saved build pages'); ?>
                <?php endif; ?>
            </section>
        </div>
    <?php
    staff_page_end(staff_report_filters_script());
}

/** CSV, Excel or PDF of the filtered report; streams the file and exits. */
function pbr_export(string $format, array $builds, array $t, int $toCart, string $periodText, string $purposeText, string $role): void
{
    $title = 'Build a PC Recommendation Report';
    $preparedBy = 'Prepared by ' . staff_report_user_name() . ', ' . staff_role_label($role) . ' on ' . ias_report_datetime(gmdate('Y-m-d H:i:s'));
    $lines = ['Period covered: ' . $periodText, 'Purpose: ' . $purposeText, 'Based on: builds customers saved in Build a PC'];
    $base = 'pc_build_report';
    $share = fn($p) => $t['count'] ? round($p['count'] / $t['count'] * 100) : 0;
    $stockText = fn($s) => $s === null ? 'Not in catalog' : ($s <= 0 ? 'Out of stock' : (string)$s);
    $kpis = [['Builds saved', $t['count']], ['Customers', $t['customers']], ['Average build (PHP)', round($t['avg'], 2)],
        ['Sent to cart', $toCart], ['Complete builds (every core part)', $t['core']]];
    $buildRow = fn($b) => [ias_report_datetime($b['created_at'], 'Y-m-d H:i'), $b['name'], $b['customer'], $b['purpose_label'], count($b['parts']),
        $b['saved_total'], $b['current_total'], pbr_status($b)['label'],
        implode('; ', array_map(fn($slot, $i) => (pbr_slot_labels()[$slot] ?? $slot) . ': ' . ($i['name'] ?? ''), array_keys($b['parts']), $b['parts']))];
    $buildHead = ['Saved', 'Build', 'Customer', 'Purpose', 'Parts', 'Total when saved (PHP)', 'Total today (PHP)', 'Status', 'Parts list'];
    $partHead = ['Part', 'Component', 'In builds', 'Share of builds (%)', 'Price now (PHP)', 'Stock now'];
    $partRow = fn($p) => [$p['name'], $p['slot'], $p['count'], $share($p), $p['price'], $stockText($p['stock'])];

    if ($format === 'csv') {
        ias_emit_csv_sections(ias_export_filename($base, 'csv'), array_merge([[$title]], array_map(fn($l) => [$l], $lines), [[$preparedBy]]), [
            ['title' => 'Summary', 'headers' => ['Metric', 'Value'], 'rows' => $kpis],
            ['title' => 'Built for', 'headers' => ['Purpose', 'Builds', 'Average build (PHP)'],
                'rows' => array_map(fn($p) => [$p['label'], $p['count'], round($p['value'] / max(1, $p['count']), 2)], array_values($t['purposes']))],
            ['title' => 'Wanted but unavailable', 'headers' => ['Part', 'Component', 'In builds', 'Stock now'],
                'rows' => array_map(fn($p) => [$p['name'], $p['slot'], $p['count'], $stockText($p['stock'])], $t['wanted'])],
            ['title' => 'Most-picked parts', 'headers' => $partHead, 'rows' => array_map($partRow, $t['parts'])],
            ['title' => 'Saved builds', 'headers' => $buildHead, 'rows' => array_map($buildRow, $builds)],
        ]);
    }

    if ($format === 'xlsx') {
        require_once __DIR__ . '/xlsx_writer.php';
        $X = 'IasXlsxWriter';
        $hd = fn(array $names) => array_map(fn($n) => ['v' => $n, 's' => $X::STYLE_HEADER], $names);
        $int = fn($v) => ['v' => (int)$v, 's' => $X::STYLE_INT];
        $money = fn($v) => $v === null ? '' : ['v' => round((float)$v, 2), 's' => $X::STYLE_MONEY];
        $sum = [[['v' => $title . ' — EasyPC One Oasis Branch', 's' => $X::STYLE_TITLE]]];
        foreach (array_merge($lines, [$preparedBy]) as $l) {
            $sum[] = [['v' => $l, 's' => $X::STYLE_NOTE]];
        }
        $sum[] = [];
        $sum[] = $hd(['Metric', 'Value', '']);
        foreach ($kpis as [$k, $v]) {
            $sum[] = [$k, str_contains($k, 'PHP') ? $money($v) : $int($v)];
        }
        $sum[] = [];
        $sum[] = $hd(['Purpose', 'Builds', 'Average build']);
        foreach ($t['purposes'] as $p) {
            $sum[] = [$p['label'], $int($p['count']), $money($p['value'] / max(1, $p['count']))];
        }
        $sum[] = [];
        $sum[] = $hd(['Wanted but unavailable', 'In builds', 'Stock now']);
        foreach ($t['wanted'] as $p) {
            $sum[] = [$p['name'] . ' (' . $p['slot'] . ')', $int($p['count']), $stockText($p['stock'])];
        }
        $parts = [$hd($partHead)];
        foreach ($t['parts'] as $p) {
            $parts[] = [$p['name'], $p['slot'], $int($p['count']), ['v' => $share($p) / 100, 's' => $X::STYLE_PERCENT], $money($p['price']), $stockText($p['stock'])];
        }
        $list = [$hd($buildHead)];
        foreach ($builds as $b) {
            $cells = $buildRow($b);
            $cells[0] = ['v' => IasXlsxWriter::excelDate(ias_report_datetime($b['created_at'], 'Y-m-d H:i:s')) ?? '', 's' => $X::STYLE_DATETIME];
            $cells[4] = $int($cells[4]);
            $cells[5] = $money($cells[5]);
            $cells[6] = $money($cells[6]);
            $cells[8] = ['v' => str_replace('; ', "\n", $cells[8]), 's' => $X::STYLE_WRAP];
            $list[] = $cells;
        }
        $x = new IasXlsxWriter();
        $x->addSheet('Summary', $sum, ['widths' => [52, 14, 16]]);
        $x->addSheet('Most-picked parts', $parts, ['widths' => [56, 22, 10, 12, 14, 14], 'freeze' => 1, 'filter' => 'A1:F' . max(2, count($parts))]);
        $x->addSheet('Saved builds', $list, ['widths' => [20, 26, 24, 20, 8, 16, 16, 22, 70], 'freeze' => 1, 'filter' => 'A1:I' . max(2, count($list))]);
        $x->output(ias_export_filename($base, 'xlsx'));
    }

    require_once __DIR__ . '/pdf_report.php';
    $pesoPdf = fn($v) => $v === null ? '-' : 'PHP ' . number_format((float)$v, 2);
    $pdf = new IasPdfReport($title, array_merge($lines, [$preparedBy]), 'L');
    $pdf->summary([['Builds saved', number_format($t['count']) . ' by ' . number_format($t['customers']) . ' customer' . ($t['customers'] === 1 ? '' : 's')],
        ['Average build', $pesoPdf($t['avg'])], ['Sent to cart', number_format($toCart)], ['Complete builds', number_format($t['core'])]]);
    $pdf->heading('Built for', 'The purpose customers chose · "Not recorded" = saved before the purpose was kept with the build', 100);
    $pdf->table(['Purpose', 'Builds', 'Average build'], array_map(fn($p) => [$p['label'], number_format($p['count']), $pesoPdf($p['value'] / max(1, $p['count']))],
        array_values($t['purposes'])), ['widths' => [2, 1, 1.2], 'align' => ['L', 'R', 'R']]);
    $pdf->heading('Wanted but unavailable', 'Parts in saved builds that are out of stock now', 80);
    if ($t['wanted']) {
        $pdf->table(['Part', 'Component', 'In builds', 'Stock now'], array_map(fn($p) => [$p['name'], $p['slot'], number_format($p['count']), $stockText($p['stock'])], $t['wanted']),
            ['widths' => [4, 1.4, 0.8, 1], 'align' => ['L', 'L', 'R', 'L']]);
    } else {
        $pdf->paragraph('None: every part customers picked is in stock.');
    }
    $pdf->heading('Most-picked parts', 'How many saved builds use each part');
    $pdf->table(['Part', 'Component', 'In builds', 'Share', 'Price now', 'Stock now'], array_map(fn($p) => [$p['name'], $p['slot'], number_format($p['count']),
        $share($p) . '%', $pesoPdf($p['price']), $stockText($p['stock'])], $t['parts']),
        ['widths' => [4, 1.4, 0.7, 0.6, 1.1, 1], 'align' => ['L', 'L', 'R', 'R', 'R', 'L']]);
    $pdf->heading('Saved builds', count($builds) . ' build' . (count($builds) === 1 ? '' : 's') . ' · newest first');
    $pdf->table(['Saved', 'Build', 'Customer', 'Purpose', 'Parts', 'When saved', 'Today', 'Status'], array_map(fn($b) => [
        ias_report_datetime($b['created_at']), $b['name'], $b['customer'], $b['purpose_label'], (string)count($b['parts']),
        $pesoPdf($b['saved_total']), $pesoPdf($b['current_total']), pbr_status($b)['label']], $builds),
        ['widths' => [1.4, 1.8, 1.6, 1.2, 0.5, 1.1, 1.1, 1.3], 'align' => ['L', 'L', 'L', 'L', 'R', 'R', 'R', 'L'], 'font_size' => 8]);
    $pdf->output(ias_export_filename($base, 'pdf'));
}
