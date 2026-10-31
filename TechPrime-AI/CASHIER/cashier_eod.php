<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('cashier');

$uid = (int)$_SESSION['user_id'];
$cashierName = trim(($_SESSION['name'] ?? '') . ' ' . ($_SESSION['surname'] ?? '')) ?: 'Cashier';
$today = pos_today_date();
$date = pos_parse_business_date((string)($_GET['date'] ?? '')) ?? $today;
$formError = '';
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_eod') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $date = pos_parse_business_date((string)($_POST['date'] ?? '')) ?? $today;
    $res = pos_eod_save($db, $uid, $cashierName, (string)($_POST['date'] ?? ''), $_POST);
    if (!empty($res['ok'])) {
        header('Location: cashier_eod.php?' . http_build_query(['date' => $date, 'alert' => $res['updated'] ? 'eod_updated' : 'eod_saved']));
        exit;
    }
    $formError = (string)$res['message'];
    $posted = $_POST;
}

$sales = pos_day_sales($db, $uid, $date);
$sum = pos_day_summary($sales);
$available = pos_eod_available($db);
$closing = pos_eod_get($db, $uid, $date);
$recent = pos_eod_recent($db, $uid, 7);
$stale = $closing && ((int)$closing['sales_count'] !== $sum['count'] || (int)$closing['voided_count'] !== $sum['voided']
    || $closing['gross_cents'] !== $sum['gross']);
$openingCents = $closing ? $closing['opening_cents'] : 0;
$expected = pos_eod_expected($sum, $openingCents);
$dateLabel = (new DateTimeImmutable($date))->format('D, M j, Y');
$payIcons = ['cash' => 'ph-money', 'gcash' => 'ph-device-mobile', 'maya' => 'ph-device-mobile', 'card' => 'ph-credit-card'];

/* Form values: what was just posted (on an error), else the saved closing, else blanks.
   Methods with no sales start at 0.00 so the cashier only has to type what was actually taken. */
$fieldOpening = $posted ? (string)($posted['opening_fund'] ?? '') : ($closing ? pos_cents_to_str($closing['opening_cents']) : '');
$fieldCounted = [];
foreach (POS_PAYMENT_METHODS as $m => $label) {
    if ($posted) {
        $fieldCounted[$m] = (string)($posted['counted'][$m] ?? '');
    } elseif ($closing) {
        $fieldCounted[$m] = pos_cents_to_str($closing['counted'][$m]);
    } else {
        $fieldCounted[$m] = ($m !== 'cash' && $sum['methods'][$m]['count'] === 0) ? '0.00' : '';
    }
}
$fieldNotes = $posted ? (string)($posted['notes'] ?? '') : (string)($closing['notes'] ?? '');

/* ---- Export (CSV = plain rows for other tools, Excel = formatted workbook) ---- */
$export = (string)($_GET['export'] ?? '');
if (in_array($export, ['csv', 'xlsx'], true)) {
    require_once __DIR__ . '/../includes/retail_reports.php';
    require_once __DIR__ . '/../includes/xlsx_writer.php';
    $generated = pos_format_datetime(gmdate('Y-m-d H:i:s'), 'M j, Y g:i A');
    $status = !$closing ? 'Not closed yet' : ($stale ? 'Closed, but sales changed after the closing was saved' : 'Closed ' . pos_format_datetime($closing['updated_at']));
    $money = fn(int $c) => number_format($c / 100, 2, '.', '');
    logActivity($db, $uid, 'pos_eod_export', 'Exported end of day ' . $date . ' (' . $export . ')');

    if ($export === 'csv') {
        $methodRows = [];
        foreach (POS_PAYMENT_METHODS as $m => $label) {
            $methodRows[] = [$label, $sum['methods'][$m]['count'], $money($expected[$m]),
                $closing ? $money($closing['counted'][$m]) : '', $closing ? $money($closing['counted'][$m] - $expected[$m]) : ''];
        }
        $methodRows[] = ['Total', $sum['count'], $money(array_sum($expected)),
            $closing ? $money(array_sum($closing['counted'])) : '', $closing ? $money(array_sum($closing['counted']) - array_sum($expected)) : ''];
        $txRows = [];
        foreach ($sales as $s) {
            $txRows[] = [pos_format_datetime($s['created_at'], 'Y-m-d H:i:s'), $s['invoice_no'], (int)$s['units'],
                pos_payment_label((string)$s['payment_method']), (string)($s['payment_ref'] ?? ''),
                $s['amount_tendered'] !== null ? number_format((float)$s['amount_tendered'], 2, '.', '') : '',
                number_format((float)$s['change_due'], 2, '.', ''), number_format((float)$s['total'], 2, '.', ''), ucfirst((string)$s['status'])];
        }
        ias_emit_csv_sections('end_of_day_' . $date . '.csv', [
            ['End of Day Report - EasyPC One Oasis Branch'],
            ['Business day', $date],
            ['Cashier', $cashierName],
            ['Status', $status],
            ['Generated (PHT)', $generated],
            ['Gross sales (PHP)', $money($sum['gross'])],
            ['Transactions', $sum['count']],
            ['Units sold', $sum['units']],
            ['Voided sales', $sum['voided']],
            ['Opening fund (PHP)', $money($openingCents)],
            ['Notes', (string)($closing['notes'] ?? '')],
        ], [
            ['title' => 'Payment totals (expected cash includes the opening fund; difference = counted - expected, negative = short)',
             'headers' => ['Payment', 'Transactions', 'Expected (PHP)', 'Counted (PHP)', 'Difference (PHP)'], 'rows' => $methodRows],
            ['title' => 'Transactions', 'headers' => ['Time (PHT)', 'Invoice', 'Items', 'Payment', 'Reference', 'Tendered (PHP)', 'Change (PHP)', 'Total (PHP)', 'Status'],
             'rows' => $txRows],
        ]);
    }

    $X = 'IasXlsxWriter';
    $hdr = fn(array $names) => array_map(fn($n) => ['v' => $n, 's' => $X::STYLE_HEADER], $names);
    $m2 = fn(int $c) => ['v' => round($c / 100, 2), 's' => $X::STYLE_MONEY];
    $sheet = [
        [['v' => 'End of Day Report — EasyPC One Oasis Branch', 's' => $X::STYLE_TITLE]],
        [['v' => $dateLabel . ' · ' . $cashierName . ' · generated ' . $generated, 's' => $X::STYLE_NOTE]],
        [['v' => $status, 's' => $X::STYLE_NOTE]],
        [],
        $hdr(['Summary', 'Value', '', '', '']),
        ['Gross sales (completed)', $m2($sum['gross'])],
        ['Transactions', ['v' => $sum['count'], 's' => $X::STYLE_INT]],
        ['Units sold', ['v' => $sum['units'], 's' => $X::STYLE_INT]],
        ['Voided sales', ['v' => $sum['voided'], 's' => $X::STYLE_INT]],
        ['Opening fund', $m2($openingCents)],
        [],
        $hdr(['Payment', 'Transactions', 'Expected', 'Counted', 'Difference']),
    ];
    $first = count($sheet) + 1;
    foreach (POS_PAYMENT_METHODS as $m => $label) {
        $r = count($sheet) + 1;
        $sheet[] = [$label, ['v' => $sum['methods'][$m]['count'], 's' => $X::STYLE_INT], $m2($expected[$m]),
            $closing ? $m2($closing['counted'][$m]) : '', $closing ? ['f' => "D$r-C$r", 's' => $X::STYLE_MONEY] : ''];
    }
    $last = count($sheet);
    $sheet[] = [['v' => 'Total', 's' => $X::STYLE_TOTAL_LABEL], ['f' => "SUM(B$first:B$last)", 's' => $X::STYLE_TOTAL_INT],
        ['f' => "SUM(C$first:C$last)", 's' => $X::STYLE_TOTAL_MONEY],
        $closing ? ['f' => "SUM(D$first:D$last)", 's' => $X::STYLE_TOTAL_MONEY] : ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
        $closing ? ['f' => "SUM(E$first:E$last)", 's' => $X::STYLE_TOTAL_MONEY] : ['v' => '', 's' => $X::STYLE_TOTAL_LABEL]];
    $sheet[] = [['v' => 'Expected cash includes the opening fund. Difference = counted − expected; a negative amount is short.', 's' => $X::STYLE_NOTE]];
    if ($closing) {
        $sheet[] = [];
        $sheet[] = [['v' => 'Cash: ' . pos_eod_result($closing['counted']['cash'] - $expected['cash'])['label']
            . ' · All payments: ' . pos_eod_result(array_sum($closing['counted']) - array_sum($expected))['label'], 's' => $X::STYLE_BOLD]];
        if ((string)($closing['notes'] ?? '') !== '') {
            $sheet[] = ['Notes', ['v' => (string)$closing['notes'], 's' => $X::STYLE_WRAP]];
        }
    }

    $tx = [$hdr(['Time', 'Invoice', 'Items', 'Payment', 'Reference', 'Tendered', 'Change', 'Total', 'Status'])];
    foreach ($sales as $s) {
        $tx[] = [
            ['v' => $X::excelDate(pos_format_datetime($s['created_at'], 'Y-m-d H:i:s')), 's' => $X::STYLE_DATETIME],
            (string)$s['invoice_no'],
            ['v' => (int)$s['units'], 's' => $X::STYLE_INT],
            pos_payment_label((string)$s['payment_method']),
            (string)($s['payment_ref'] ?? ''),
            $s['amount_tendered'] !== null ? ['v' => round((float)$s['amount_tendered'], 2), 's' => $X::STYLE_MONEY] : '',
            ['v' => round((float)$s['change_due'], 2), 's' => $X::STYLE_MONEY],
            ['v' => round((float)$s['total'], 2), 's' => $X::STYLE_MONEY],
            ucfirst((string)$s['status']),
        ];
    }
    $lastTx = count($tx);
    if ($lastTx > 1) {
        $tx[] = [['v' => 'Total (completed)', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
            ['f' => "SUMIF(I2:I$lastTx,\"Completed\",C2:C$lastTx)", 's' => $X::STYLE_TOTAL_INT],
            ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
            ['v' => '', 's' => $X::STYLE_TOTAL_LABEL],
            ['f' => "SUMIF(I2:I$lastTx,\"Completed\",H2:H$lastTx)", 's' => $X::STYLE_TOTAL_MONEY], ['v' => '', 's' => $X::STYLE_TOTAL_LABEL]];
    }

    $xlsx = new IasXlsxWriter();
    $xlsx->addSheet('End of Day', $sheet, ['widths' => [26, 14, 16, 16, 16]]);
    $xlsx->addSheet('Transactions', $tx, ['widths' => [21, 22, 8, 10, 18, 13, 12, 14, 11], 'freeze' => 1, 'filter' => 'A1:I' . max(2, $lastTx)]);
    $xlsx->output('end_of_day_' . $date . '.xlsx');
}

$prevDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
$dateQs = 'date=' . urlencode($date);
$moneyPattern = '\d{1,3}(,?\d{3})*(\.\d{1,2})?';

staff_page_start([
    'role' => 'cashier',
    'title' => 'End of Day',
    'active' => 'eod',
    'heading' => 'End of Day',
    'subtitle' => 'Your transactions, payment totals and cash count for the day',
    'actions' => '<a class="cx-btn cx-btn-outline" href="cashier_eod.php?' . h($dateQs) . '&amp;export=csv"><i class="ph ph-file-csv" aria-hidden="true"></i> CSV</a>'
        . '<a class="cx-btn cx-btn-outline" href="cashier_eod.php?' . h($dateQs) . '&amp;export=xlsx"><i class="ph ph-file-xls" aria-hidden="true"></i> Excel</a>'
        . '<button type="button" class="cx-btn cx-btn-primary" id="eodPrint"><i class="ph ph-printer" aria-hidden="true"></i> Print</button>',
    'report' => [
        'title' => 'End of Day Report',
        'lines' => [
            'Business day' => $dateLabel,
            'Status' => !$closing ? 'Not closed yet' : ($stale ? 'Sales changed after closing' : 'Closed ' . pos_format_datetime($closing['updated_at'], 'M j, Y g:i A')),
        ],
        'sign' => [
            ['role' => 'Prepared by', 'name' => $cashierName, 'note' => 'Cashier'],
            ['role' => 'Checked by', 'name' => '', 'note' => 'Signature over printed name'],
        ],
    ],
]);
?>
        <div class="eod">
            <div class="eod-bar no-print">
                <form class="eod-date" method="get" action="cashier_eod.php" id="eodDateForm">
                    <a class="cx-btn cx-btn-outline eod-step" href="cashier_eod.php?date=<?php echo h($prevDate); ?>" aria-label="Previous day">
                        <i class="ph ph-caret-left" aria-hidden="true"></i>
                    </a>
                    <div class="eod-cal-wrap">
                        <button type="button" class="cx-btn cx-btn-outline eod-cal-btn" id="eodCalBtn" aria-haspopup="dialog"
                                aria-expanded="false" aria-controls="eodCal" hidden>
                            <i class="ph ph-calendar-blank" aria-hidden="true"></i>
                            <span><span class="cx-sr">Business day: </span><?php echo h($dateLabel); ?></span>
                            <i class="ph ph-caret-down eod-cal-caret" aria-hidden="true"></i>
                        </button>
                        <label class="cx-sr" for="eodDate">Business day</label>
                        <input type="date" id="eodDate" name="date" class="form-control" value="<?php echo h($date); ?>"
                               min="2020-01-01" max="<?php echo h($today); ?>" required>
                        <div class="eod-cal-scrim" id="eodCalScrim" hidden></div>
                        <div class="eod-cal" id="eodCal" role="dialog" aria-label="Pick a business day" tabindex="-1" hidden></div>
                    </div>
                    <?php if ($date < $today): ?>
                        <a class="cx-btn cx-btn-outline eod-step" href="cashier_eod.php?date=<?php echo h($nextDate); ?>" aria-label="Next day">
                            <i class="ph ph-caret-right" aria-hidden="true"></i>
                        </a>
                        <a class="cx-btn cx-btn-ghost" href="cashier_eod.php">Today</a>
                    <?php endif; ?>
                    <button type="submit" class="cx-btn cx-btn-outline eod-show">Show</button>
                </form>
                <?php if (!$closing): ?>
                    <span class="eod-chip"><i class="ph ph-clock" aria-hidden="true"></i> Not closed yet</span>
                <?php elseif ($stale): ?>
                    <span class="eod-chip is-warn"><i class="ph-fill ph-warning" aria-hidden="true"></i> Sales changed after closing</span>
                <?php else: ?>
                    <span class="eod-chip is-ok"><i class="ph-fill ph-check-circle" aria-hidden="true"></i> Closed <?php echo h(pos_format_datetime($closing['updated_at'], 'M j, g:i A')); ?></span>
                <?php endif; ?>
            </div>

            <section class="cx-card" aria-labelledby="sumTitle">
                <div class="cx-card-head">
                    <h2 id="sumTitle"><i class="ph ph-chart-line-up" aria-hidden="true"></i> Day summary</h2>
                    <span class="cx-card-meta"><?php echo h($dateLabel); ?> · completed sales only</span>
                </div>
                <div class="kpis">
                    <div class="kpi kpi-hero">
                        <span class="kpi-label">Gross sales</span>
                        <strong class="kpi-value cx-num"><?php echo h(pos_peso($sum['gross'] / 100)); ?></strong>
                        <span class="kpi-sub">VAT-inclusive</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Transactions</span>
                        <strong class="kpi-value cx-num"><?php echo (int)$sum['count']; ?></strong>
                        <span class="kpi-sub"><?php echo $sum['voided'] > 0 ? (int)$sum['voided'] . ' voided, not counted' : 'sale' . ($sum['count'] === 1 ? '' : 's') . ' completed'; ?></span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Units sold</span>
                        <strong class="kpi-value cx-num"><?php echo (int)$sum['units']; ?></strong>
                        <span class="kpi-sub">item<?php echo $sum['units'] === 1 ? '' : 's'; ?> scanned out</span>
                    </div>
                    <div class="kpi">
                        <span class="kpi-label">Average sale</span>
                        <strong class="kpi-value cx-num"><?php echo h(pos_peso($sum['count'] > 0 ? $sum['gross'] / 100 / $sum['count'] : 0)); ?></strong>
                        <span class="kpi-sub">per transaction</span>
                    </div>
                </div>
            </section>

            <div class="eod-grid">
                <section class="cx-card eod-recon" aria-labelledby="reconTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="reconTitle"><i class="ph ph-calculator" aria-hidden="true"></i> Count and close</h2>
                            <p>Count the cash drawer, read the totals from the card terminal, GCash and Maya, then save.</p>
                        </div>
                    </div>

                    <?php if ($formError !== ''): ?>
                        <div class="alert alert-error eod-pad" role="alert"><i class="ph ph-warning-circle" aria-hidden="true"></i> <?php echo h($formError); ?></div>
                    <?php endif; ?>
                    <?php if (!$available): ?>
                        <div class="alert alert-warn eod-pad no-print"><i class="ph ph-info" aria-hidden="true"></i> Closings can't be saved yet. Ask the administrator to run the End of Day database update. You can still view, export and print the day's totals.</div>
                    <?php elseif ($stale): ?>
                        <div class="alert alert-warn eod-pad no-print"><i class="ph ph-warning" aria-hidden="true"></i> Sales were added or voided after this closing was saved. Count again and save to include them.</div>
                    <?php endif; ?>

                    <form method="post" action="cashier_eod.php?<?php echo h($dateQs); ?>" id="eodForm">
                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCsrfToken()); ?>">
                        <input type="hidden" name="action" value="save_eod">
                        <input type="hidden" name="date" value="<?php echo h($date); ?>">

                        <div class="form-group eod-pad eod-fund">
                            <label class="form-label" for="eodOpening">Opening fund</label>
                            <div class="eod-money">
                                <span aria-hidden="true">₱</span>
                                <input type="text" id="eodOpening" name="opening_fund" class="form-control eod-input" inputmode="decimal"
                                       autocomplete="off" pattern="<?php echo h($moneyPattern); ?>" placeholder="0.00"
                                       value="<?php echo h($fieldOpening); ?>" aria-describedby="eodOpeningHint">
                            </div>
                            <div class="form-hint" id="eodOpeningHint">Change money in the drawer at the start of the day. It is added to the expected cash.</div>
                        </div>

                        <div class="cx-table-wrap">
                            <table class="cx-table eod-table">
                                <thead><tr>
                                    <th scope="col">Payment</th>
                                    <th scope="col" class="num">Sales</th>
                                    <th scope="col" class="num">Expected</th>
                                    <th scope="col" class="num">Counted</th>
                                    <th scope="col" class="num">Difference</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach (POS_PAYMENT_METHODS as $m => $label): ?>
                                    <tr data-method="<?php echo h($m); ?>">
                                        <th scope="row" data-label="Payment">
                                            <span class="pay-chip pay-<?php echo h($m); ?>"><i class="ph <?php echo h($payIcons[$m] ?? 'ph-wallet'); ?>" aria-hidden="true"></i> <?php echo h($label); ?></span>
                                            <?php if ($m === 'cash'): ?><span class="eod-sub">includes opening fund</span><?php endif; ?>
                                        </th>
                                        <td class="num cx-num" data-label="Sales">
                                            <?php echo (int)$sum['methods'][$m]['count']; ?>
                                            <span class="eod-sub"><?php echo h(pos_peso($sum['methods'][$m]['total'] / 100)); ?></span>
                                        </td>
                                        <td class="num cx-num eod-expected" data-label="Expected" data-expected><?php echo h(pos_peso($expected[$m] / 100)); ?></td>
                                        <td class="num" data-label="Counted">
                                            <label class="cx-sr" for="cnt-<?php echo h($m); ?>">Counted <?php echo h($label); ?></label>
                                            <div class="eod-money">
                                                <span aria-hidden="true">₱</span>
                                                <input type="text" id="cnt-<?php echo h($m); ?>" name="counted[<?php echo h($m); ?>]" class="form-control eod-input"
                                                       inputmode="decimal" autocomplete="off" pattern="<?php echo h($moneyPattern); ?>" placeholder="0.00" required
                                                       value="<?php echo h($fieldCounted[$m]); ?>">
                                            </div>
                                        </td>
                                        <td class="num" data-label="Difference"><span class="eod-diff cx-num" data-diff>—</span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr>
                                    <th scope="row">Total</th>
                                    <td class="num cx-num" data-label="Sales"><?php echo (int)$sum['count']; ?></td>
                                    <td class="num cx-num" data-label="Expected" id="totExpected"><?php echo h(pos_peso(array_sum($expected) / 100)); ?></td>
                                    <td class="num cx-num" data-label="Counted" id="totCounted">—</td>
                                    <td class="num" data-label="Difference"><span class="eod-diff cx-num" id="totDiff">—</span></td>
                                </tr></tfoot>
                            </table>
                        </div>

                        <div class="eod-result" id="eodResult" role="status" aria-live="polite">
                            <i class="ph ph-calculator" aria-hidden="true"></i>
                            <div>
                                <strong id="eodResultTitle">Enter the counted totals</strong>
                                <span id="eodResultText">The difference shows here as you type.</span>
                            </div>
                        </div>

                        <div class="form-group eod-pad eod-notes">
                            <label class="form-label" for="eodNotes">Notes <span class="eod-optional">(optional)</span></label>
                            <textarea id="eodNotes" name="notes" class="form-control" rows="2" maxlength="500"
                                      placeholder="e.g. ₱20 short, gave extra change on one sale"><?php echo h($fieldNotes); ?></textarea>
                        </div>

                        <div class="eod-actions eod-pad no-print">
                            <button type="submit" class="cx-btn cx-btn-primary cx-btn-lg" <?php echo $available ? '' : 'disabled'; ?>>
                                <i class="ph ph-floppy-disk" aria-hidden="true"></i> <?php echo $closing ? 'Update closing' : 'Save closing'; ?>
                            </button>
                            <?php if ($closing): ?>
                                <span class="form-hint">Saved <?php echo h(pos_format_datetime($closing['created_at'], 'M j, g:i A')); ?><?php
                                    echo $closing['updated_at'] !== $closing['created_at'] ? ' · last updated ' . h(pos_format_datetime($closing['updated_at'], 'M j, g:i A')) : ''; ?>. Every change is recorded in the activity log.</span>
                            <?php endif; ?>
                        </div>
                    </form>

                </section>

                <section class="cx-card eod-history no-print" aria-labelledby="histTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="histTitle"><i class="ph ph-clock-counter-clockwise" aria-hidden="true"></i> Recent closings</h2>
                            <p>Your latest saved days · pick one to open it</p>
                        </div>
                    </div>
                    <?php if (!$recent): ?>
                        <div class="cx-empty cx-empty-sm">
                            <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-calculator"></i></span>
                            <strong>No closings yet</strong>
                            <span>Saved closings appear here.</span>
                        </div>
                    <?php else: ?>
                        <ul class="eod-hist">
                        <?php foreach ($recent as $r):
                            $res = pos_eod_result($r['cash_difference']); ?>
                            <li>
                                <a class="eod-hist-item<?php echo $r['business_date'] === $date ? ' is-current' : ''; ?>" href="cashier_eod.php?date=<?php echo h($r['business_date']); ?>"
                                   <?php echo $r['business_date'] === $date ? 'aria-current="page"' : ''; ?>>
                                    <span class="eod-hist-main">
                                        <strong><?php echo h((new DateTimeImmutable($r['business_date']))->format('D, M j')); ?></strong>
                                        <span class="cx-num"><?php echo (int)$r['sales_count']; ?> sale<?php echo (int)$r['sales_count'] === 1 ? '' : 's'; ?> · <?php echo h(pos_peso($r['gross_cents'] / 100)); ?></span>
                                    </span>
                                    <span class="eod-pill is-<?php echo h($res['key']); ?>"><i class="ph-fill <?php echo h($res['icon']); ?>" aria-hidden="true"></i> <span class="cx-sr">Cash </span><?php echo h($res['label']); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>

            <section class="cx-card" aria-labelledby="txTitle">
                <div class="cx-card-head">
                    <div>
                        <h2 id="txTitle"><i class="ph ph-receipt" aria-hidden="true"></i> Transactions</h2>
                        <p><?php echo count($sales); ?> sale<?php echo count($sales) === 1 ? '' : 's'; ?> on <?php echo h($dateLabel); ?> · oldest first</p>
                    </div>
                </div>
                <?php if (!$sales): ?>
                    <div class="cx-empty">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-receipt"></i></span>
                        <strong>No sales on this day</strong>
                        <span>Pick another day, or start a sale from checkout.</span>
                        <?php if ($date === $today): ?>
                            <a href="cashier_pos.php" class="cx-btn cx-btn-primary no-print"><i class="ph ph-cash-register" aria-hidden="true"></i> Start a sale</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="cx-table-wrap">
                        <table class="cx-table tx-table eod-tx">
                            <thead><tr>
                                <th scope="col">Invoice</th>
                                <th scope="col" class="num">Items</th>
                                <th scope="col">Payment</th>
                                <th scope="col" class="num">Total</th>
                                <th scope="col" class="tx-action"><span class="cx-sr">Receipt</span></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($sales as $s):
                                $pm = (string)$s['payment_method'];
                                $voided = $s['status'] === 'voided'; ?>
                                <tr<?php echo $voided ? ' class="is-voided"' : ''; ?>>
                                    <td data-label="Invoice">
                                        <span class="tx-inv mono"><?php echo h($s['invoice_no']); ?></span>
                                        <span class="tx-time"><?php echo h(pos_format_datetime($s['created_at'], 'g:i A')); ?></span>
                                        <?php if ($voided): ?><span class="cx-badge cx-badge-out">Voided</span><?php endif; ?>
                                    </td>
                                    <td data-label="Items" class="num cx-num"><?php echo (int)$s['units']; ?></td>
                                    <td data-label="Payment">
                                        <span class="pay-chip pay-<?php echo h($pm); ?>"><i class="ph <?php echo h($payIcons[$pm] ?? 'ph-wallet'); ?>" aria-hidden="true"></i> <?php echo h(pos_payment_label($pm)); ?></span>
                                        <?php if ((string)($s['payment_ref'] ?? '') !== ''): ?><span class="tx-time mono">Ref <?php echo h($s['payment_ref']); ?></span><?php endif; ?>
                                    </td>
                                    <td data-label="Total" class="num tx-total cx-num"><?php echo h(pos_peso($s['total'])); ?></td>
                                    <td class="num tx-action">
                                        <a class="cx-btn cx-btn-outline cx-btn-sm" href="cashier_receipt.php?id=<?php echo (int)$s['id']; ?>&amp;print=1" data-print-receipt
                                           aria-label="Reprint receipt <?php echo h($s['invoice_no']); ?>">
                                            <i class="ph ph-printer" aria-hidden="true"></i> Reprint
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
<?php
$eodJson = json_encode([
    'sales' => array_map(fn($row) => $row['total'], $sum['methods']),
    'cal' => ['days' => (object)pos_eod_calendar($db, $uid), 'date' => $date, 'today' => $today, 'min' => '2020-01-01'],
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$script = '<script src="receipt_print.js?v=2"></script>'
    . '<script>var EOD_DATA = ' . $eodJson . ';</script>'
    . <<<'SCRIPT'
<script>
(function () {
    'use strict';
    var form = document.getElementById('eodForm');
    var opening = document.getElementById('eodOpening');
    var rows = Array.prototype.slice.call(form.querySelectorAll('tr[data-method]'));
    var result = document.getElementById('eodResult');
    var resultTitle = document.getElementById('eodResultTitle');
    var resultText = document.getElementById('eodResultText');
    var MONEY = /^\d{1,9}(\.\d{1,2})?$/;

    /* Same rules as pos_parse_money_input(): null = empty, NaN = not an amount */
    function parse(v) {
        v = String(v || '').replace(/,/g, '').trim();
        if (v === '') return null;
        return MONEY.test(v) ? Math.round(parseFloat(v) * 100) : NaN;
    }
    function peso(c) {
        return '₱' + (Math.abs(c) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function verdict(d) {
        if (d === 0) return { key: 'balanced', icon: 'ph-check-circle', label: 'Balanced' };
        return d < 0 ? { key: 'short', icon: 'ph-arrow-down', label: 'Short ' + peso(d) }
                     : { key: 'over', icon: 'ph-arrow-up', label: 'Over ' + peso(d) };
    }
    function showDiff(el, d) {
        el.classList.remove('is-balanced', 'is-short', 'is-over', 'is-invalid');
        if (d === null) { el.textContent = '—'; return; }
        if (isNaN(d)) { el.textContent = 'Check amount'; el.classList.add('is-invalid'); return; }
        el.textContent = d === 0 ? peso(0) : (d < 0 ? '−' : '+') + peso(d);
        el.classList.add('is-' + verdict(d).key);
    }

    function recompute() {
        var open = parse(opening.value);
        opening.setAttribute('aria-invalid', isNaN(open) ? 'true' : 'false');
        var fund = open === null || isNaN(open) ? 0 : open;
        var totExp = 0, totCnt = 0, missing = false, invalid = isNaN(open), cashDiff = null;
        rows.forEach(function (tr) {
            var m = tr.getAttribute('data-method');
            var exp = (EOD_DATA.sales[m] || 0) + (m === 'cash' ? fund : 0);
            var input = tr.querySelector('input');
            var cnt = parse(input.value);
            tr.querySelector('[data-expected]').textContent = peso(exp);
            input.setAttribute('aria-invalid', isNaN(cnt) ? 'true' : 'false');
            var d = cnt === null || isNaN(cnt) ? cnt : cnt - exp;
            showDiff(tr.querySelector('[data-diff]'), d);
            totExp += exp;
            if (cnt === null) missing = true;
            else if (isNaN(cnt)) invalid = true;
            else totCnt += cnt;
            if (m === 'cash') cashDiff = d;
        });
        document.getElementById('totExpected').textContent = peso(totExp);
        document.getElementById('totCounted').textContent = missing || invalid ? '—' : peso(totCnt);
        showDiff(document.getElementById('totDiff'), missing ? null : (invalid ? NaN : totCnt - totExp));

        result.className = 'eod-result';
        var icon = result.querySelector('i');
        if (invalid) {
            result.classList.add('is-invalid');
            icon.className = 'ph-fill ph-warning-circle';
            resultTitle.textContent = 'Check the amounts';
            resultText.textContent = 'Use numbers like 1500 or 1,500.50.';
        } else if (missing) {
            icon.className = 'ph ph-calculator';
            resultTitle.textContent = 'Enter the counted totals';
            resultText.textContent = 'Fill in every payment type (0 if none). The difference shows here as you type.';
        } else {
            var all = verdict(totCnt - totExp);
            var cash = verdict(cashDiff);
            result.classList.add('is-' + all.key);
            icon.className = 'ph-fill ' + all.icon;
            resultTitle.textContent = all.key === 'balanced' ? 'Balanced: the count matches the sales' : all.label + ' in total';
            resultText.textContent = 'Cash: ' + cash.label + ' · counted ' + peso(totCnt) + ' vs expected ' + peso(totExp);
        }
    }

    form.addEventListener('input', recompute);
    recompute();

    form.addEventListener('submit', function (e) {
        recompute();
        if (result.classList.contains('is-invalid') || !form.checkValidity()) {
            e.preventDefault();
            form.reportValidity();
        }
    });

    /* ---- Business-day calendar: replaces the native date field and marks the days with sales ---- */
    var CAL = EOD_DATA.cal;
    var dateForm = document.getElementById('eodDateForm');
    var dateInput = document.getElementById('eodDate');
    var calBtn = document.getElementById('eodCalBtn');
    var cal = document.getElementById('eodCal');
    var scrim = document.getElementById('eodCalScrim');
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var WEEK = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var view = null, focusKey = CAL.date;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function toKey(dt) { return dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()); }
    function toDate(k) { var p = k.split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }   /* noon: no DST edge */
    function clamp(k) { return k < CAL.min ? CAL.min : (k > CAL.today ? CAL.today : k); }
    function addDays(k, n) { var dt = toDate(k); dt.setDate(dt.getDate() + n); return toKey(dt); }
    function addMonths(k, n) {
        var dt = toDate(k), y = dt.getFullYear(), m = dt.getMonth() + n;
        var last = new Date(y, m + 1, 0, 12).getDate();
        return toKey(new Date(y, m, Math.min(dt.getDate(), last), 12));
    }
    function monthIndex(k) { var dt = toDate(k); return dt.getFullYear() * 12 + dt.getMonth(); }
    function hasSales(info) { return !!info && (info.n > 0 || info.v > 0); }

    var latest = null;
    Object.keys(CAL.days).forEach(function (k) { if (hasSales(CAL.days[k]) && (!latest || k > latest)) latest = k; });

    function describe(k) {
        var dt = toDate(k), info = CAL.days[k];
        var s = WEEK[dt.getDay()] + ', ' + MONTHS[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear();
        if (k > CAL.today) return s;
        if (!hasSales(info)) s += ': no sales';
        else {
            s += ': ' + info.n + ' sale' + (info.n === 1 ? '' : 's') + ', ' + peso(info.g);
            if (info.v) s += ', ' + info.v + ' voided';
        }
        if (info && info.c) s += info.s ? ', closed, but sales changed after closing' : ', closed, ' + info.c;
        else if (hasSales(info) && k < CAL.today) s += ', not closed';
        return s;
    }

    cal.innerHTML =
        '<div class="eod-cal-head">'
        + '<button type="button" class="eod-cal-nav" data-nav="-1" aria-label="Previous month"><i class="ph ph-caret-left" aria-hidden="true"></i></button>'
        + '<div class="eod-cal-title"><strong id="eodCalTitle" aria-live="polite"></strong><span id="eodCalSum"></span></div>'
        + '<button type="button" class="eod-cal-nav" data-nav="1" aria-label="Next month"><i class="ph ph-caret-right" aria-hidden="true"></i></button>'
        + '</div>'
        + '<table class="eod-cal-grid" role="grid" aria-labelledby="eodCalTitle"><thead><tr>'
        + WEEK.map(function (w) { return '<th scope="col" abbr="' + w + '">' + w.slice(0, 2) + '</th>'; }).join('')
        + '</tr></thead><tbody id="eodCalBody"></tbody></table>'
        + '<ul class="eod-cal-legend">'
        + '<li><span class="eod-cal-count" aria-hidden="true">3</span> Sales that day</li>'
        + '<li><i class="ph-fill ph-check-circle is-ok" aria-hidden="true"></i> Closed</li>'
        + '<li><i class="ph-fill ph-warning-circle is-warn" aria-hidden="true"></i> Short, over or changed</li>'
        + '</ul>'
        + '<div class="eod-cal-foot">'
        + (latest ? '<button type="button" class="cx-btn cx-btn-ghost cx-btn-sm" data-go="' + latest + '"><i class="ph ph-clock-counter-clockwise" aria-hidden="true"></i> Last sale: '
            + MONTHS[toDate(latest).getMonth()].slice(0, 3) + ' ' + toDate(latest).getDate() + '</button>' : '')
        + '<button type="button" class="cx-btn cx-btn-outline cx-btn-sm" data-go="' + CAL.today + '">Today</button>'
        + '</div>';
    var body = document.getElementById('eodCalBody');
    var prevBtn = cal.querySelector('[data-nav="-1"]');
    var nextBtn = cal.querySelector('[data-nav="1"]');

    function render() {
        var y = Math.floor(view / 12), m = view % 12;
        var lead = new Date(y, m, 1, 12).getDay();
        var count = new Date(y, m + 1, 0, 12).getDate();
        var cells = [], days = 0, gross = 0;
        for (var i = 0; i < lead; i++) cells.push('<td role="gridcell"></td>');
        for (var d = 1; d <= count; d++) {
            var k = y + '-' + pad(m + 1) + '-' + pad(d), info = CAL.days[k], sales = hasSales(info);
            var cls = 'eod-cal-day', mark = '';
            if (sales) { cls += ' has-sales'; days++; gross += info.g; }
            if (k === CAL.date) cls += ' is-selected';
            if (k === CAL.today) cls += ' is-today';
            if (info && info.c) {
                var ok = info.c === 'balanced' && !info.s;
                cls += ok ? ' is-closed' : ' is-flagged';
                mark = '<i class="ph-fill ' + (ok ? 'ph-check-circle' : 'ph-warning-circle') + ' eod-cal-flag" aria-hidden="true"></i>';
            }
            var label = describe(k);
            cells.push('<td role="gridcell"' + (k === CAL.date ? ' aria-selected="true"' : '') + '>'
                + '<button type="button" class="' + cls + '" data-date="' + k + '" tabindex="' + (k === focusKey ? '0' : '-1') + '"'
                + (k < CAL.min || k > CAL.today ? ' disabled' : '') + (k === CAL.today ? ' aria-current="date"' : '')
                + ' aria-label="' + label + '" title="' + label + '">'
                + '<span class="eod-cal-num" aria-hidden="true">' + d + '</span>'
                + (sales ? '<span class="eod-cal-count" aria-hidden="true">' + info.n + '</span>' : '') + mark
                + '</button></td>');
        }
        while (cells.length % 7) cells.push('<td role="gridcell"></td>');
        var rows = '';
        for (var r = 0; r < cells.length; r += 7) rows += '<tr>' + cells.slice(r, r + 7).join('') + '</tr>';
        body.innerHTML = rows;
        document.getElementById('eodCalTitle').textContent = MONTHS[m] + ' ' + y;
        document.getElementById('eodCalSum').textContent = days
            ? days + ' day' + (days === 1 ? '' : 's') + ' with sales · ' + peso(gross)
            : 'No sales this month';
        prevBtn.disabled = view <= monthIndex(CAL.min);
        nextBtn.disabled = view >= monthIndex(CAL.today);
    }

    function focusDay(k) {
        focusKey = clamp(k);
        if (monthIndex(focusKey) !== view) { view = monthIndex(focusKey); render(); }
        else {
            body.querySelectorAll('[data-date]').forEach(function (b) {
                b.tabIndex = b.getAttribute('data-date') === focusKey ? 0 : -1;
            });
        }
        var btn = body.querySelector('[data-date="' + focusKey + '"]');
        if (btn) btn.focus();
    }

    function open() {
        focusKey = CAL.date;
        view = monthIndex(CAL.date);
        render();
        cal.hidden = false;
        scrim.hidden = false;
        calBtn.setAttribute('aria-expanded', 'true');
        focusDay(CAL.date);
    }
    function close(refocus) {
        if (cal.hidden) return;
        cal.hidden = true;
        scrim.hidden = true;
        calBtn.setAttribute('aria-expanded', 'false');
        if (refocus) calBtn.focus();
    }
    function choose(k) {
        k = clamp(k);
        if (k === CAL.date) { close(true); return; }
        dateInput.value = k;
        dateForm.submit();
    }

    calBtn.addEventListener('click', function () { if (cal.hidden) open(); else close(true); });
    scrim.addEventListener('click', function () { close(false); });
    cal.addEventListener('click', function (e) {
        var t = e.target.closest('button');
        if (!t || t.disabled) return;
        if (t.hasAttribute('data-nav')) {
            var step = +t.getAttribute('data-nav');
            focusKey = clamp(addMonths(focusKey, step));
            view += step;
            render();
        } else if (t.hasAttribute('data-date')) {
            choose(t.getAttribute('data-date'));
        } else if (t.hasAttribute('data-go')) {
            choose(t.getAttribute('data-go'));
        }
    });
    cal.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
        var k = e.target.getAttribute && e.target.getAttribute('data-date');
        if (!k) return;
        var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
        var dow = toDate(k).getDay(), next = null;
        if (step) next = addDays(k, step);
        else if (e.key === 'Home') next = addDays(k, -dow);
        else if (e.key === 'End') next = addDays(k, 6 - dow);
        else if (e.key === 'PageUp') next = addMonths(k, e.shiftKey ? -12 : -1);
        else if (e.key === 'PageDown') next = addMonths(k, e.shiftKey ? 12 : 1);
        if (next) { e.preventDefault(); focusDay(next); }
    });
    cal.addEventListener('focusout', function (e) {
        if (!e.relatedTarget || (!cal.contains(e.relatedTarget) && e.relatedTarget !== calBtn)) {
            /* focus moved away (Tab, or a click outside); a click inside lands on the dialog itself */
            setTimeout(function () { if (!cal.contains(document.activeElement)) close(false); }, 0);
        }
    });
    document.addEventListener('mousedown', function (e) {
        if (!cal.hidden && !cal.contains(e.target) && !calBtn.contains(e.target)) close(false);
    });

    calBtn.hidden = false;
    dateInput.hidden = true;
    document.querySelector('.eod-show').hidden = true;   /* the calendar submits the form itself */

    document.getElementById('eodPrint').addEventListener('click', function () { window.print(); });
})();
</script>
SCRIPT;

staff_page_end($script);
