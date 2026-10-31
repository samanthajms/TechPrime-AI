<?php
/**
 * Cashier Closings — the manager view of End of Day (Admin, Retail Officer).
 * Every cashier's saved closing with its short / over result, plus cashier days with POS sales
 * that were never closed. ADMIN/admin_closings.php and RETAIL/retail_closings.php are thin
 * wrappers (guards first, then staff_eod_review_page()), like staff_profile_page().
 * Read-only: managers see and export closings; only the cashier saves them (CASHIER/cashier_eod.php).
 */
require_once __DIR__ . '/pos_helpers.php';
require_once __DIR__ . '/retail_reports.php';
require_once __DIR__ . '/staff_app_layout.php';   // staff_report_user_name() for the printed signature block

function staff_eod_review_pages(): array
{
    return ['admin' => 'admin_closings.php', 'retail_officer' => 'retail_closings.php'];
}

function staff_eod_review_page(PDO $db, string $role): void
{
    $self = staff_eod_review_pages()[$role] ?? null;
    if ($self === null) {
        http_response_code(403);
        exit('Forbidden');
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $today = pos_today_date();

    $range = ias_resolve_report_range($_GET);
    $to = min($range['to']->format('Y-m-d'), $today);
    $from = min($range['from']->format('Y-m-d'), $to);
    $cashiers = pos_cashier_options($db);
    $cashierId = (int)($_GET['cashier'] ?? 0);
    if (!isset($cashiers[$cashierId])) {
        $cashierId = 0;
    }
    $results = [
        'all' => 'All results',
        'attention' => 'Needs attention',
        'short' => 'Short',
        'over' => 'Over',
        'balanced' => 'Balanced',
        'open' => 'Not closed',
    ];
    $result = (string)($_GET['result'] ?? 'all');
    if (!isset($results[$result])) {
        $result = 'all';
    }
    $filters = ['preset' => $range['preset'], 'cashier' => $cashierId ?: null, 'result' => $result !== 'all' ? $result : null];
    if ($range['preset'] === 'custom') {
        $filters['date_from'] = $from;
        $filters['date_to'] = $to;
    }
    $filters = array_filter($filters, fn($v) => $v !== null);
    $listUrl = $self . '?' . http_build_query($filters);

    $detailId = (int)($_GET['id'] ?? 0);
    if ($detailId > 0) {
        staff_eod_review_detail($db, $role, $detailId, $listUrl);
        return;
    }

    $all = pos_eod_review_rows($db, $from, $to, $cashierId);
    $kpi = ['closed' => 0, 'open' => 0, 'short' => 0, 'short_n' => 0, 'over' => 0, 'over_n' => 0];
    foreach ($all as $r) {
        if ($r['status'] === 'open') {
            $kpi['open']++;
            continue;
        }
        $kpi['closed']++;
        $d = $r['closing']['difference'];
        if ($d < 0) {
            $kpi['short'] += -$d;
            $kpi['short_n']++;
        } elseif ($d > 0) {
            $kpi['over'] += $d;
            $kpi['over_n']++;
        }
    }
    $rows = array_values(array_filter($all, function ($r) use ($result) {
        if ($result === 'all') {
            return true;
        }
        if ($result === 'attention') {
            return $r['status'] !== 'balanced' || $r['stale'];
        }
        return $r['status'] === $result;
    }));
    $rangeText = (new DateTimeImmutable($from))->format('M j, Y') . ' – ' . (new DateTimeImmutable($to))->format('M j, Y');
    $scopeText = ($cashierId ? $cashiers[$cashierId] : 'All cashiers') . ' · ' . $results[$result];

    /* ---- Export: CSV = flat rows, Excel = workbook with totals ---- */
    $export = (string)($_GET['export'] ?? '');
    if (in_array($export, ['csv', 'xlsx'], true)) {
        logActivity($db, $uid, 'pos_eod_review_export', 'Exported cashier closings ' . $from . ' to ' . $to . ' (' . $export . ')');
        $statusLabel = fn($r) => $r['status'] === 'open' ? 'Not closed' : pos_eod_result($r['closing']['difference'])['label'];
        $money = fn(?int $c) => $c === null ? '' : number_format($c / 100, 2, '.', '');
        $line = function ($r) use ($money, $statusLabel) {
            $c = $r['closing'];
            return [
                $r['business_date'], $r['cashier_name'], $r['sales_count'], $money($r['gross_cents']),
                $c ? $money($c['opening_cents']) : '', $c ? $money($c['expected']['cash']) : '', $c ? $money($c['counted']['cash']) : '',
                $c ? $money($c['cash_difference']) : '', $c ? $money(array_sum($c['expected'])) : '', $c ? $money(array_sum($c['counted'])) : '',
                $c ? $money($c['difference']) : '', $statusLabel($r), $r['stale'] ? 'Yes' : '',
                $c ? pos_format_datetime($c['updated_at'], 'Y-m-d H:i:s') : '', (string)($c['notes'] ?? ''),
            ];
        };
        $headers = ['Business day', 'Cashier', 'Sales', 'Gross sales', 'Opening fund', 'Expected cash', 'Counted cash', 'Cash difference',
            'Expected (all payments)', 'Counted (all payments)', 'Difference (all payments)', 'Result', 'Sales changed after closing',
            'Saved (PHT)', 'Notes'];
        $base = 'cashier_closings_' . $from . '_to_' . $to;
        if ($export === 'csv') {
            ias_emit_csv($base . '.csv', $headers, array_map($line, $rows));
        }
        $X = 'IasXlsxWriter';
        require_once __DIR__ . '/xlsx_writer.php';
        $sheet = [
            [['v' => 'Cashier Closings — EasyPC One Oasis Branch', 's' => $X::STYLE_TITLE]],
            [['v' => $rangeText . ' · ' . $scopeText . ' · generated ' . pos_format_datetime(gmdate('Y-m-d H:i:s'), 'M j, Y g:i A'), 's' => $X::STYLE_NOTE]],
            [['v' => 'Closings saved: ' . $kpi['closed'] . ' · Not closed: ' . $kpi['open'] . ' · Total short: ' . pos_peso($kpi['short'] / 100)
                . ' · Total over: ' . pos_peso($kpi['over'] / 100), 's' => $X::STYLE_BOLD]],
            [],
            array_map(fn($n) => ['v' => $n, 's' => $X::STYLE_HEADER], $headers),
        ];
        $first = count($sheet) + 1;
        $moneyCols = [3, 4, 5, 6, 7, 8, 9, 10];
        foreach ($rows as $r) {
            $cells = $line($r);
            foreach ($cells as $i => $v) {
                if (in_array($i, $moneyCols, true) && $v !== '') {
                    $cells[$i] = ['v' => (float)$v, 's' => $X::STYLE_MONEY];
                } elseif ($i === 2) {
                    $cells[$i] = ['v' => (int)$v, 's' => $X::STYLE_INT];
                } elseif ($i === 14 && $v !== '') {
                    $cells[$i] = ['v' => $v, 's' => $X::STYLE_WRAP];
                }
            }
            $sheet[] = $cells;
        }
        $last = count($sheet);
        if ($rows) {
            $total = [['v' => 'Total', 's' => $X::STYLE_TOTAL_LABEL], ['v' => count($rows) . ' rows', 's' => $X::STYLE_TOTAL_LABEL],
                ['f' => "SUM(C$first:C$last)", 's' => $X::STYLE_TOTAL_INT]];
            foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] as $col) {
                $total[] = ['f' => "SUM($col$first:$col$last)", 's' => $X::STYLE_TOTAL_MONEY];
            }
            for ($i = 0; $i < 4; $i++) {
                $total[] = ['v' => '', 's' => $X::STYLE_TOTAL_LABEL];
            }
            $sheet[] = $total;
        }
        $xlsx = new IasXlsxWriter();
        $xlsx->addSheet('Closings', $sheet, ['widths' => [13, 22, 8, 14, 13, 14, 14, 14, 16, 16, 16, 18, 12, 20, 40],
            'freeze' => $first - 1, 'filter' => 'A' . ($first - 1) . ':O' . max($first, $last)]);
        $xlsx->output($base . '.xlsx');
    }

    $perPage = 25;
    $totalRows = count($rows);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
    $pageUrl = fn(int $p) => $self . '?' . http_build_query($filters + ['page' => $p]);
    $exportUrl = fn(string $f) => h($self . '?' . http_build_query($filters + ['export' => $f]));
    $presets = ias_report_date_presets();

    staff_page_start([
        'role' => $role,
        'title' => 'Cashier Closings',
        'active' => 'closings',
        'heading' => 'Cashier Closings',
        'subtitle' => 'End-of-day cash counts from every cashier · short, over and days not closed',
        'actions' => '<a class="cx-btn cx-btn-outline" href="' . $exportUrl('csv') . '"><i class="ph ph-file-csv" aria-hidden="true"></i> CSV</a>'
            . '<a class="cx-btn cx-btn-outline" href="' . $exportUrl('xlsx') . '"><i class="ph ph-file-xls" aria-hidden="true"></i> Excel</a>'
            . '<button type="button" class="cx-btn cx-btn-outline" id="eodrPrint"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>',
        'report' => [
            'title' => 'Cashier Closings Report',
            'lines' => [
                'Period covered' => $range['label'] . ' (' . $rangeText . ')',
                'Cashier' => $cashierId ? $cashiers[$cashierId] : 'All cashiers',
                'Result' => $results[$result],
            ],
        ],
    ]);
    ?>
        <div class="eodr">

            <?php if (!pos_eod_available($db)): ?>
                <div class="alert alert-warn no-print"><i class="ph ph-info" aria-hidden="true"></i> Closings can't be saved yet: the End of Day database update has not been run. Days with sales are listed as not closed.</div>
            <?php endif; ?>

            <form class="eodr-filters cx-card no-print" method="get" action="<?php echo h($self); ?>" id="eodrFilters">
                <label class="sort-wrap">
                    <span>Period</span>
                    <select name="preset" id="eodrPreset">
                        <?php foreach ($presets as $k => $p): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $range['preset'] === $k ? 'selected' : ''; ?>><?php echo h($p['label']); ?></option>
                        <?php endforeach; ?>
                        <option value="custom" <?php echo $range['preset'] === 'custom' ? 'selected' : ''; ?>>Custom range</option>
                    </select>
                </label>
                <span class="eodr-dates" id="eodrDates" <?php echo $range['preset'] === 'custom' ? '' : 'hidden'; ?>>
                    <label class="cx-sr" for="eodrFrom">From</label>
                    <input type="date" class="form-control" id="eodrFrom" name="date_from" value="<?php echo h($from); ?>" max="<?php echo h($today); ?>">
                    <span aria-hidden="true">–</span>
                    <label class="cx-sr" for="eodrTo">To</label>
                    <input type="date" class="form-control" id="eodrTo" name="date_to" value="<?php echo h($to); ?>" max="<?php echo h($today); ?>">
                </span>
                <label class="sort-wrap">
                    <span>Cashier</span>
                    <select name="cashier">
                        <option value="0">All cashiers</option>
                        <?php foreach ($cashiers as $id => $name): ?>
                            <option value="<?php echo (int)$id; ?>" <?php echo $cashierId === $id ? 'selected' : ''; ?>><?php echo h($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="sort-wrap">
                    <span>Result</span>
                    <select name="result">
                        <?php foreach ($results as $k => $label): ?>
                            <option value="<?php echo h($k); ?>" <?php echo $result === $k ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="cx-btn cx-btn-outline" id="eodrApply">Apply</button>
                <?php if ($filters !== ['preset' => 'this_month']): ?>
                    <a class="cx-btn cx-btn-ghost" href="<?php echo h($self); ?>"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Reset</a>
                <?php endif; ?>
            </form>

            <section class="cx-card" aria-labelledby="eodrSumTitle">
                <div class="cx-card-head">
                    <h2 id="eodrSumTitle"><i class="ph ph-calculator" aria-hidden="true"></i> <?php echo h($range['label']); ?></h2>
                    <span class="cx-card-meta cx-num"><?php echo h($rangeText); ?> · <?php echo h($cashierId ? $cashiers[$cashierId] : 'All cashiers'); ?></span>
                </div>
                <div class="kpis">
                    <div class="kpi kpi-hero">
                        <span class="kpi-label">Closings saved</span>
                        <strong class="kpi-value cx-num"><?php echo (int)$kpi['closed']; ?></strong>
                        <span class="kpi-sub">of <?php echo (int)($kpi['closed'] + $kpi['open']); ?> cashier day<?php echo $kpi['closed'] + $kpi['open'] === 1 ? '' : 's'; ?> with sales</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Not closed</span>
                        <strong class="kpi-value cx-num<?php echo $kpi['open'] ? ' eodr-bad' : ''; ?>"><?php echo (int)$kpi['open']; ?></strong>
                        <span class="kpi-sub">days with sales, no count</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Total short</span>
                        <strong class="kpi-value cx-num<?php echo $kpi['short'] ? ' eodr-bad' : ''; ?>"><?php echo h(pos_peso($kpi['short'] / 100)); ?></strong>
                        <span class="kpi-sub">in <?php echo (int)$kpi['short_n']; ?> closing<?php echo $kpi['short_n'] === 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Total over</span>
                        <strong class="kpi-value cx-num<?php echo $kpi['over'] ? ' eodr-warn' : ''; ?>"><?php echo h(pos_peso($kpi['over'] / 100)); ?></strong>
                        <span class="kpi-sub">in <?php echo (int)$kpi['over_n']; ?> closing<?php echo $kpi['over_n'] === 1 ? '' : 's'; ?></span>
                    </div>
                </div>
            </section>

            <section class="cx-card" aria-labelledby="eodrListTitle">
                <div class="cx-card-head">
                    <div>
                        <h2 id="eodrListTitle"><i class="ph ph-list-checks" aria-hidden="true"></i> Closings</h2>
                        <p>Newest day first · the difference covers every payment type (cash, GCash, Maya, card)</p>
                    </div>
                    <span class="cx-count"><i class="ph ph-rows" aria-hidden="true"></i> <?php echo number_format($totalRows); ?> row<?php echo $totalRows === 1 ? '' : 's'; ?></span>
                </div>
                <?php if (!$rows): ?>
                    <div class="cx-empty">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-calculator"></i></span>
                        <strong><?php echo $all ? 'No closings match this result' : 'No cashier sales in this period'; ?></strong>
                        <span><?php echo $all ? 'Try another result or cashier.' : 'Pick a longer period.'; ?></span>
                    </div>
                <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table eodr-table">
                            <thead><tr>
                                <th scope="col">Day</th>
                                <th scope="col">Cashier</th>
                                <th scope="col" class="num">Sales</th>
                                <th scope="col" class="num">Expected</th>
                                <th scope="col" class="num">Counted</th>
                                <th scope="col">Result</th>
                                <th scope="col" class="eodr-act"><span class="cx-sr">Details</span></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $n => $r):
                                $offPage = $n < ($page - 1) * $perPage || $n >= $page * $perPage; /* printed, not shown on this screen page */
                                $c = $r['closing'];
                                $res = $c ? pos_eod_result($c['difference']) : ['key' => 'open', 'label' => 'Not closed', 'icon' => 'ph-clock']; ?>
                                <tr class="is-<?php echo h($res['key']); ?><?php echo $offPage ? ' cx-print-row' : ''; ?>">
                                    <td data-label="Day" class="eodr-day">
                                        <strong><?php echo h((new DateTimeImmutable($r['business_date']))->format('D, M j')); ?></strong>
                                        <span class="cx-num"><?php echo h((new DateTimeImmutable($r['business_date']))->format('Y')); ?></span>
                                    </td>
                                    <td data-label="Cashier" class="eodr-cashier">
                                        <strong><?php echo h($r['cashier_name']); ?></strong>
                                        <?php if ($c && (string)($c['notes'] ?? '') !== ''): ?>
                                            <span class="eodr-note no-print" title="<?php echo h($c['notes']); ?>"><i class="ph ph-note" aria-hidden="true"></i> <?php echo h(mb_strimwidth((string)$c['notes'], 0, 70, '…')); ?></span>
                                            <div class="eodr-note cx-print-only">Notes: <?php echo h((string)$c['notes']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Sales" class="num cx-num">
                                        <?php echo (int)$r['sales_count']; ?>
                                        <span class="eodr-sub"><?php echo h(pos_peso($r['gross_cents'] / 100)); ?></span>
                                    </td>
                                    <td data-label="Expected" class="num cx-num"><?php echo $c ? h(pos_peso(array_sum($c['expected']) / 100)) : '—'; ?></td>
                                    <td data-label="Counted" class="num cx-num"><?php echo $c ? h(pos_peso(array_sum($c['counted']) / 100)) : '—'; ?></td>
                                    <td data-label="Result">
                                        <span class="eod-pill is-<?php echo h($res['key']); ?>"><i class="ph-fill <?php echo h($res['icon']); ?>" aria-hidden="true"></i> <?php echo h($res['label']); ?></span>
                                        <?php if ($c && $c['cash_difference'] !== $c['difference']): ?>
                                            <span class="eodr-sub">Cash: <?php echo h(pos_eod_result($c['cash_difference'])['label']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($r['stale']): ?>
                                            <span class="eodr-flag"><i class="ph-fill ph-warning" aria-hidden="true"></i> Sales changed after closing</span>
                                        <?php elseif ($c && $c['updated_at'] !== $c['created_at']): ?>
                                            <span class="eodr-flag is-muted" title="Last updated <?php echo h(pos_format_datetime($c['updated_at'])); ?>"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Edited after saving</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="eodr-act">
                                        <?php if ($c): ?>
                                            <a class="cx-btn cx-btn-outline cx-btn-sm" href="<?php echo h($self . '?' . http_build_query($filters + ['id' => (int)$c['id']])); ?>"
                                               aria-label="View closing of <?php echo h($r['cashier_name'] . ', ' . $r['business_date']); ?>">View</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php staff_pagination($page, $totalPages, $totalRows, $perPage, $pageUrl, 'Closings pages'); ?>
                <?php endif; ?>
            </section>
        </div>
    <?php
    staff_page_end(<<<'SCRIPT'
<script>
(function () {
    'use strict';
    var form = document.getElementById('eodrFilters');
    var preset = document.getElementById('eodrPreset');
    var dates = document.getElementById('eodrDates');
    document.getElementById('eodrApply').hidden = true;   /* the filters submit on change */
    form.addEventListener('change', function (e) {
        if (e.target === preset && preset.value === 'custom') {
            dates.hidden = false;
            document.getElementById('eodrFrom').focus();
            return;
        }
        if (preset.value === 'custom') {
            var f = document.getElementById('eodrFrom').value, t = document.getElementById('eodrTo').value;
            if (!f || !t) return;
        } else {
            dates.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
        }
        form.submit();
    });
    document.getElementById('eodrPrint').addEventListener('click', function () { window.print(); });
})();
</script>
SCRIPT);
}

/** One closing in full: per-payment breakdown, notes and the cashier's sales that day. */
function staff_eod_review_detail(PDO $db, string $role, int $id, string $listUrl): void
{
    $c = pos_eod_get_by_id($db, $id);
    $sales = $c && $c['cashier_id'] ? pos_day_sales($db, (int)$c['cashier_id'], $c['business_date']) : [];
    $sum = pos_day_summary($sales);
    $stale = $c && ((int)$c['sales_count'] !== $sum['count'] || (int)$c['voided_count'] !== $sum['voided'] || $c['gross_cents'] !== $sum['gross']);
    $dayLabel = $c ? (new DateTimeImmutable($c['business_date']))->format('D, M j, Y') : '';
    $payIcons = ['cash' => 'ph-money', 'gcash' => 'ph-device-mobile', 'maya' => 'ph-device-mobile', 'card' => 'ph-credit-card'];

    staff_page_start([
        'role' => $role,
        'title' => 'Cashier Closings',
        'active' => 'closings',
        'heading' => $c ? 'Closing · ' . $c['cashier_name'] : 'Closing not found',
        'subtitle' => $c ? $dayLabel . ' · saved ' . pos_format_datetime($c['created_at'], 'M j, g:i A')
            . ($c['updated_at'] !== $c['created_at'] ? ' · updated ' . pos_format_datetime($c['updated_at'], 'M j, g:i A') : '') : '',
        'actions' => '<a class="cx-btn cx-btn-ghost" href="' . h($listUrl) . '"><i class="ph ph-arrow-left" aria-hidden="true"></i> All closings</a>'
            . ($c ? '<button type="button" class="cx-btn cx-btn-outline" id="eodrPrint"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>' : ''),
        'report' => $c ? [
            'title' => 'End of Day Closing Report',
            'lines' => [
                'Business day' => $dayLabel,
                'Cashier' => $c['cashier_name'],
                'Saved' => pos_format_datetime($c['created_at'], 'M j, Y g:i A')
                    . ($c['updated_at'] !== $c['created_at'] ? ' (updated ' . pos_format_datetime($c['updated_at'], 'M j, Y g:i A') . ')' : ''),
                'Result' => pos_eod_result($c['difference'])['label'],
            ],
            'sign' => [
                ['role' => 'Prepared by', 'name' => $c['cashier_name'], 'note' => 'Cashier'],
                ['role' => 'Checked by', 'name' => staff_report_user_name(), 'note' => staff_role_label($role)],
            ],
        ] : null,
    ]);
    if (!$c): ?>
        <div class="cx-card">
            <div class="cx-empty">
                <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-calculator"></i></span>
                <strong>This closing does not exist</strong>
                <span>It may have been removed. Go back to the list of closings.</span>
                <a class="cx-btn cx-btn-primary" href="<?php echo h($listUrl); ?>"><i class="ph ph-arrow-left" aria-hidden="true"></i> All closings</a>
            </div>
        </div>
    <?php
        staff_page_end('');
        return;
    endif;
    $res = pos_eod_result($c['difference']);
    ?>
        <div class="eodr">
            <?php if ($stale): ?>
                <div class="alert alert-warn"><i class="ph ph-warning" aria-hidden="true"></i> Sales were added or voided after this closing was saved, so the counted totals below may not cover every sale. The cashier can count again and update it.</div>
            <?php endif; ?>

            <section class="cx-card" aria-labelledby="eodrDTitle">
                <div class="cx-card-head">
                    <h2 id="eodrDTitle"><i class="ph ph-chart-line-up" aria-hidden="true"></i> Summary</h2>
                    <span class="eod-pill is-<?php echo h($res['key']); ?>"><i class="ph-fill <?php echo h($res['icon']); ?>" aria-hidden="true"></i> <?php echo h($res['label']); ?></span>
                </div>
                <div class="kpis">
                    <div class="kpi kpi-hero">
                        <span class="kpi-label">Gross sales</span>
                        <strong class="kpi-value cx-num"><?php echo h(pos_peso($c['gross_cents'] / 100)); ?></strong>
                        <span class="kpi-sub"><?php echo (int)$c['sales_count']; ?> sale<?php echo (int)$c['sales_count'] === 1 ? '' : 's'; ?><?php echo (int)$c['voided_count'] ? ' · ' . (int)$c['voided_count'] . ' voided' : ''; ?></span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Opening fund</span>
                        <strong class="kpi-value cx-num"><?php echo h(pos_peso($c['opening_cents'] / 100)); ?></strong>
                        <span class="kpi-sub">change money in the drawer</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Cash</span>
                        <strong class="kpi-value cx-num"><?php echo h(pos_eod_result($c['cash_difference'])['label']); ?></strong>
                        <span class="kpi-sub">counted <?php echo h(pos_peso($c['counted']['cash'] / 100)); ?></span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">All payments</span>
                        <strong class="kpi-value cx-num"><?php echo h($res['label']); ?></strong>
                        <span class="kpi-sub">counted <?php echo h(pos_peso(array_sum($c['counted']) / 100)); ?></span>
                    </div>
                </div>
            </section>

            <section class="cx-card" aria-labelledby="eodrMTitle">
                <div class="cx-card-head">
                    <div>
                        <h2 id="eodrMTitle"><i class="ph ph-calculator" aria-hidden="true"></i> Count per payment type</h2>
                        <p>Expected cash includes the opening fund · amounts as saved by the cashier</p>
                    </div>
                </div>
                <div class="cx-table-wrap">
                    <table class="cx-table eodr-methods">
                        <thead><tr>
                            <th scope="col">Payment</th>
                            <th scope="col" class="num">Expected</th>
                            <th scope="col" class="num">Counted</th>
                            <th scope="col" class="num">Difference</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (POS_PAYMENT_METHODS as $m => $label):
                            $d = $c['counted'][$m] - $c['expected'][$m];
                            $dr = pos_eod_result($d); ?>
                            <tr>
                                <th scope="row"><span class="pay-chip pay-<?php echo h($m); ?>"><i class="ph <?php echo h($payIcons[$m]); ?>" aria-hidden="true"></i> <?php echo h($label); ?></span></th>
                                <td class="num cx-num" data-label="Expected"><?php echo h(pos_peso($c['expected'][$m] / 100)); ?></td>
                                <td class="num cx-num" data-label="Counted"><?php echo h(pos_peso($c['counted'][$m] / 100)); ?></td>
                                <td class="num cx-num" data-label="Difference"><span class="eodr-diff is-<?php echo h($dr['key']); ?>"><?php echo $d === 0 ? h(pos_peso(0)) : ($d < 0 ? '−' : '+') . h(pos_peso(abs($d) / 100)); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr>
                            <th scope="row">Total</th>
                            <td class="num cx-num" data-label="Expected"><?php echo h(pos_peso(array_sum($c['expected']) / 100)); ?></td>
                            <td class="num cx-num" data-label="Counted"><?php echo h(pos_peso(array_sum($c['counted']) / 100)); ?></td>
                            <td class="num cx-num" data-label="Difference"><span class="eodr-diff is-<?php echo h($res['key']); ?>"><?php echo $c['difference'] === 0 ? h(pos_peso(0)) : ($c['difference'] < 0 ? '−' : '+') . h(pos_peso(abs($c['difference']) / 100)); ?></span></td>
                        </tr></tfoot>
                    </table>
                </div>
                <div class="eodr-notes">
                    <strong>Cashier's notes</strong>
                    <p><?php echo (string)($c['notes'] ?? '') !== '' ? nl2br(h((string)$c['notes'])) : '<span class="eodr-sub">No notes.</span>'; ?></p>
                </div>
            </section>

            <section class="cx-card" aria-labelledby="eodrTTitle">
                <div class="cx-card-head">
                    <div>
                        <h2 id="eodrTTitle"><i class="ph ph-receipt" aria-hidden="true"></i> Sales that day</h2>
                        <p><?php echo count($sales); ?> sale<?php echo count($sales) === 1 ? '' : 's'; ?> by <?php echo h($c['cashier_name']); ?> · as they are now</p>
                    </div>
                </div>
                <?php if (!$sales): ?>
                    <div class="cx-empty cx-empty-sm">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-receipt"></i></span>
                        <strong>No sales on this day</strong>
                    </div>
                <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table eodr-sales">
                            <thead><tr>
                                <th scope="col">Invoice</th>
                                <th scope="col">Time</th>
                                <th scope="col" class="num">Items</th>
                                <th scope="col">Payment</th>
                                <th scope="col" class="num">Total</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($sales as $s):
                                $pm = (string)$s['payment_method']; ?>
                                <tr<?php echo $s['status'] === 'voided' ? ' class="is-voided"' : ''; ?>>
                                    <td data-label="Invoice" class="cx-mono"><?php echo h($s['invoice_no']); ?><?php if ($s['status'] === 'voided'): ?> <span class="cx-badge cx-badge-out">Voided</span><?php endif; ?></td>
                                    <td data-label="Time" class="cx-num"><?php echo h(pos_format_datetime($s['created_at'], 'g:i A')); ?></td>
                                    <td data-label="Items" class="num cx-num"><?php echo (int)$s['units']; ?></td>
                                    <td data-label="Payment">
                                        <span class="pay-chip pay-<?php echo h($pm); ?>"><i class="ph <?php echo h($payIcons[$pm] ?? 'ph-wallet'); ?>" aria-hidden="true"></i> <?php echo h(pos_payment_label($pm)); ?></span>
                                        <?php if ((string)($s['payment_ref'] ?? '') !== ''): ?><span class="eodr-sub cx-mono">Ref <?php echo h($s['payment_ref']); ?></span><?php endif; ?>
                                    </td>
                                    <td data-label="Total" class="num cx-num"><?php echo h(pos_peso($s['total'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    <?php
    staff_page_end('<script>document.getElementById("eodrPrint").addEventListener("click", function () { window.print(); });</script>');
}
