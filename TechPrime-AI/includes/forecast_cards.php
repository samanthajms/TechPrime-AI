<?php
/**
 * Product Demand + Sales Forecast cards (Admin Forecast page and Retail dashboard).
 *
 * Data comes from the XGBoost forecast service through forecast_api.php (browser -> PHP proxy -> Render), so the API
 * key never reaches the browser. Each card keeps its original filter modal:
 *   - Date Range (preset / custom)  -> which ACTUAL sales months are shown next to the forecast
 *   - Category (optional)           -> Client menu group
 *   - Forecast window               -> 1, 2 or 3 months ahead (the only choices; replaces "forecast days")
 * Query keys: df_preset/df_from/df_to/df_category/df_months (demand), sf_preset/sf_from/sf_to/sf_months (sales).
 *
 * Usage (inside a staff page, Chart.js already loaded in extra_head, openFilterModal/closeFilterModal/toggleDetail
 * defined by the page):
 *     $fc = ias_forecast_selection($categories);
 *     ias_forecast_cards_render($fc, $categories);
 *     staff_page_end(ias_forecast_cards_script($fc) . $pageScripts);
 */
require_once __DIR__ . '/retail_reports.php';

const IAS_FORECAST_MAX_MONTHS = 3;

function ias_forecast_months(string $key): int
{
    return max(1, min(IAS_FORECAST_MAX_MONTHS, (int)($_GET[$key] ?? IAS_FORECAST_MAX_MONTHS)));
}

/** Validated filter state for both cards. Date ranges default to "This Year" so the chart has history to show. */
function ias_forecast_selection(array $categories): array
{
    $category = trim((string)($_GET['df_category'] ?? ''));
    if ($category !== '' && !in_array($category, $categories, true)) {
        $category = '';
    }
    return [
        'presets' => ias_report_date_presets(),
        'df' => ['range' => ias_resolve_section_range($_GET, 'df', 'this_year'), 'category' => $category, 'months' => ias_forecast_months('df_months')],
        'sf' => ['range' => ias_resolve_section_range($_GET, 'sf', 'this_year'), 'months' => ias_forecast_months('sf_months')],
    ];
}

function ias_forecast_preserve_hidden(array $ownKeys): void
{
    foreach ($_GET as $k => $v) {
        if (in_array($k, $ownKeys, true) || is_array($v)) {
            continue;
        }
        echo '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
}

function ias_forecast_range_fields(string $prefix, array $range, array $presets, string $customId): void
{
    ?>
            <div class="form-group">
                <label class="form-label">Date Range</label>
                <select name="<?php echo $prefix; ?>_preset" class="staff-select" onchange="toggleSectionCustom(this,'<?php echo $customId; ?>')">
                    <?php foreach ($presets as $key => $p): ?>
                    <option value="<?php echo h($key); ?>" <?php echo $range['preset'] === $key ? 'selected' : ''; ?>><?php echo h($p['label']); ?></option>
                    <?php endforeach; ?>
                    <option value="custom" <?php echo $range['preset'] === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>
            <div id="<?php echo $customId; ?>" class="form-row" style="<?php echo $range['preset'] === 'custom' ? '' : 'display:none;'; ?>">
                <div class="form-group"><label class="form-label">From</label><input type="date" name="<?php echo $prefix; ?>_from" class="staff-input" value="<?php echo h($range['from']->format('Y-m-d')); ?>"></div>
                <div class="form-group"><label class="form-label">To</label><input type="date" name="<?php echo $prefix; ?>_to" class="staff-input" value="<?php echo h($range['to']->format('Y-m-d')); ?>"></div>
            </div>
    <?php
}

function ias_forecast_months_field(string $name, int $selected): void
{
    ?>
            <div class="form-group">
                <label class="form-label">Forecast window</label>
                <select name="<?php echo $name; ?>" class="staff-select">
                    <?php foreach ([1 => 'Next 1 month', 2 => 'Next 2 months', 3 => 'Next 3 months'] as $n => $label): ?>
                    <option value="<?php echo $n; ?>" <?php echo $selected === $n ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
    <?php
}

function ias_forecast_cards_render(array $fc, array $categories): void
{
    $df = $fc['df'];
    $sf = $fc['sf'];
    ?>
<div class="card">
    <div class="card-header">
        <div>
            <h3><span class="card-icon"><i class="fas fa-box-open"></i></span> Product Demand Forecasting</h3>
            <div class="card-subtitle" id="fcDemandSub">Loading&hellip;</div>
        </div>
        <div class="card-actions no-print">
            <button type="button" class="btn btn-outline btn-xs" onclick="openFilterModal('dfModal')" title="Filter"><i class="fas fa-filter"></i></button>
            <button type="button" class="btn btn-outline btn-xs" onclick="toggleDetail('fcDemandDetail')" title="Detailed view"><i class="fas fa-table"></i></button>
        </div>
    </div>
    <div class="card-body" style="padding-top:0;">
        <div class="table-wrap">
            <table class="ias-table">
                <thead><tr><th>Product</th><th>Category</th><th>Units sold (date range)</th><th>Forecast units</th><th>Likely range</th><th>Forecast revenue</th><th>Trend</th><th>Confidence</th></tr></thead>
                <tbody id="fcDemandTop"><tr><td colspan="8" class="empty-state">Loading&hellip;</td></tr></tbody>
            </table>
        </div>
        <div id="fcDemandDetail" class="detail-panel">
            <div class="table-wrap">
                <table class="ias-table">
                    <thead><tr><th>Product</th><th>Category</th><th>Units sold (date range)</th><th>Forecast units</th><th>Likely range</th><th>Forecast revenue</th><th>Trend</th><th>Confidence</th></tr></thead>
                    <tbody id="fcDemandAll"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3><span class="card-icon"><i class="fas fa-chart-line"></i></span> Sales Forecasting</h3>
            <div class="card-subtitle" id="fcRevSub">Loading&hellip;</div>
        </div>
        <div class="card-actions no-print">
            <button type="button" class="btn btn-outline btn-xs" onclick="openFilterModal('sfModal')" title="Filter"><i class="fas fa-filter"></i></button>
            <button type="button" class="btn btn-outline btn-xs" onclick="toggleDetail('fcRevDetail')" title="Detailed view"><i class="fas fa-table"></i></button>
        </div>
    </div>
    <div class="card-body">
        <div class="chart-wrap"><canvas id="fcRevChart"></canvas></div>
        <div id="fcRevDetail" class="detail-panel">
            <div class="table-wrap">
                <table class="ias-table">
                    <thead><tr><th>Month</th><th>Type</th><th>Units</th><th>Sales (PHP)</th><th>Likely range (PHP)</th></tr></thead>
                    <tbody id="fcRevBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="dfModal" class="filter-modal no-print" onclick="if(event.target===this)closeFilterModal('dfModal')">
    <div class="filter-modal-card">
        <h4><i class="fas fa-filter"></i> Demand Forecast Filters</h4>
        <form method="get">
            <?php ias_forecast_preserve_hidden(['df_preset', 'df_from', 'df_to', 'df_category', 'df_months']); ?>
            <?php ias_forecast_range_fields('df', $df['range'], $fc['presets'], 'dfCustom'); ?>
            <div class="form-group">
                <label class="form-label">Category (optional)</label>
                <select name="df_category" class="staff-select">
                    <option value="">All Products</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo h($c); ?>" <?php echo $df['category'] === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php ias_forecast_months_field('df_months', $df['months']); ?>
            <button type="submit" class="btn btn-primary">Apply</button>
            <button type="button" class="btn btn-outline" onclick="closeFilterModal('dfModal')">Cancel</button>
        </form>
    </div>
</div>

<div id="sfModal" class="filter-modal no-print" onclick="if(event.target===this)closeFilterModal('sfModal')">
    <div class="filter-modal-card">
        <h4><i class="fas fa-filter"></i> Sales Forecast Filters</h4>
        <form method="get">
            <?php ias_forecast_preserve_hidden(['sf_preset', 'sf_from', 'sf_to', 'sf_months']); ?>
            <?php ias_forecast_range_fields('sf', $sf['range'], $fc['presets'], 'sfCustom'); ?>
            <?php ias_forecast_months_field('sf_months', $sf['months']); ?>
            <button type="submit" class="btn btn-primary">Apply</button>
            <button type="button" class="btn btn-outline" onclick="closeFilterModal('sfModal')">Cancel</button>
        </form>
    </div>
</div>
    <?php
}

/** <script> that loads both cards from forecast_api.php (path is relative to the page's role folder, e.g. ADMIN/). */
function ias_forecast_cards_script(array $fc): string
{
    $cfg = [
        'demand' => [
            'months' => $fc['df']['months'], 'category' => $fc['df']['category'],
            'from' => $fc['df']['range']['from']->format('Y-m-d'), 'to' => $fc['df']['range']['to']->format('Y-m-d'),
            'label' => $fc['df']['range']['label'],
        ],
        'sales' => [
            'months' => $fc['sf']['months'],
            'from' => $fc['sf']['range']['from']->format('Y-m-d'), 'to' => $fc['sf']['range']['to']->format('Y-m-d'),
            'label' => $fc['sf']['range']['label'],
        ],
    ];
    $json = json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script>window.IAS_FORECAST_CFG = ' . $json . ';</script>' . <<<'HTML'
<script>
(function () {
    var API = '../forecast_api.php';
    var cfg = window.IAS_FORECAST_CFG, chart = null;

    function peso(n, d) { return '₱' + Number(n || 0).toLocaleString('en-PH', { maximumFractionDigits: d || 0 }); }
    function num(n) { return Number(n || 0).toLocaleString('en-PH', { maximumFractionDigits: 1 }); }
    function cell(tr, text, strong) {
        var td = document.createElement('td');
        if (strong) { var s = document.createElement('strong'); s.textContent = text; td.appendChild(s); }
        else { td.textContent = text; }
        tr.appendChild(td);
    }
    function emptyRow(tbody, cols, msg) {
        tbody.textContent = '';
        var tr = document.createElement('tr'), td = document.createElement('td');
        td.colSpan = cols; td.className = 'empty-state'; td.textContent = msg;
        tr.appendChild(td); tbody.appendChild(tr);
    }
    function get(type, c) {
        var q = 'type=' + type + '&horizon=' + c.months + '&from=' + c.from + '&to=' + c.to +
                (type === 'demand' ? '&top=50' : '') +
                (c.category ? '&category=' + encodeURIComponent(c.category) : '');
        return fetch(API + '?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    if (!r.ok || j.ok === false) { throw new Error(j.error || ('HTTP ' + r.status)); }
                    return j;
                });
            });
    }
    function windowLabel(m) { return 'next ' + m + (m === 1 ? ' month' : ' months'); }

    function renderDemand(data, c) {
        var rows = data.products || [];
        document.getElementById('fcDemandSub').textContent =
            c.label + ' · ' + (c.category || 'All products') + ' · forecast ' + windowLabel(c.months);
        var top = document.getElementById('fcDemandTop'), all = document.getElementById('fcDemandAll');
        if (!rows.length) { emptyRow(top, 8, data.message || 'No forecast available for this selection.'); all.textContent = ''; return; }
        function fill(tbody, list) {
            tbody.textContent = '';
            list.forEach(function (p) {
                var tr = document.createElement('tr');
                cell(tr, p.product_name, true); cell(tr, p.category);
                cell(tr, p.range_units === undefined ? '—' : num(p.range_units));
                cell(tr, num(p.forecast_units), true);
                cell(tr, num(p.forecast_units_low) + ' – ' + num(p.forecast_units_high));
                cell(tr, peso(p.forecast_revenue));
                cell(tr, p.trend_pct === null || p.trend_pct === undefined ? '—' : (p.trend_pct > 0 ? '+' : '') + p.trend_pct + '%');
                cell(tr, p.confidence + ' · ' + (p.model_reliability || 'unknown'));
                tbody.appendChild(tr);
            });
        }
        fill(top, rows.slice(0, 10)); fill(all, rows);
    }

    function renderSales(data, c) {
        var total = data.total || [], hist = data.history || [];
        var sum = total.reduce(function (a, r) { return a + (r.predicted_revenue || 0); }, 0);
        document.getElementById('fcRevSub').textContent = total.length
            ? c.label + ' · forecast ' + windowLabel(c.months) + ' · projected ' + peso(sum) +
              (hist.length ? '' : ' · no sales recorded in this date range')
            : (data.message || 'No forecast available for this selection.');
        var labels = hist.map(function (r) { return r.month; }).concat(total.map(function (r) { return r.month; }));
        var actual = hist.map(function (r) { return r.actual_revenue; }).concat(total.map(function () { return null; }));
        var fcast = hist.map(function () { return null; }).concat(total.map(function (r) { return r.predicted_revenue; }));
        if (hist.length && total.length) { fcast[hist.length - 1] = hist[hist.length - 1].actual_revenue; }  // join the lines
        if (chart) { chart.destroy(); }
        if (window.Chart) {
            chart = new Chart(document.getElementById('fcRevChart').getContext('2d'), {
                type: 'line',
                data: { labels: labels, datasets: [
                    { label: 'Actual sales', data: actual, borderColor: '#61b337', backgroundColor: 'rgba(97,179,55,.12)', fill: true, tension: .3, borderWidth: 3, pointRadius: 3 },
                    { label: 'Forecast', data: fcast, borderColor: '#fed700', backgroundColor: 'rgba(254,215,0,.15)', borderDash: [6, 4], fill: true, tension: .3, borderWidth: 3, pointRadius: 3 }
                ] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } },
                    scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return peso(v); } } }, x: { grid: { display: false } } } }
            });
        }
        var body = document.getElementById('fcRevBody');
        body.textContent = '';
        hist.forEach(function (r) {
            var tr = document.createElement('tr');
            cell(tr, r.month); cell(tr, 'Actual'); cell(tr, num(r.actual_units)); cell(tr, peso(r.actual_revenue, 2)); cell(tr, '—');
            body.appendChild(tr);
        });
        total.forEach(function (r) {
            var tr = document.createElement('tr');
            cell(tr, r.month); cell(tr, 'Forecast'); cell(tr, num(r.predicted_units)); cell(tr, peso(r.predicted_revenue, 2));
            cell(tr, peso(r.revenue_confidence_low) + ' – ' + peso(r.revenue_confidence_high));
            body.appendChild(tr);
        });
    }

    get('demand', cfg.demand).then(function (d) { renderDemand(d, cfg.demand); }).catch(function (e) {
        emptyRow(document.getElementById('fcDemandTop'), 8, 'Forecast unavailable: ' + e.message);
        document.getElementById('fcDemandSub').textContent = cfg.demand.label;
    });
    get('revenue', cfg.sales).then(function (d) { renderSales(d, cfg.sales); }).catch(function (e) {
        document.getElementById('fcRevSub').textContent = 'Forecast unavailable: ' + e.message;
    });
})();
</script>
HTML;
}
