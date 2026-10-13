<?php
/**
 * Product Demand + Sales Forecast cards (Admin Forecast page, Inventory Custodian Forecast page, Retail dashboard).
 *
 * Data comes from the XGBoost forecast service through forecast_api.php (browser -> PHP proxy -> Render), so the API
 * key never reaches the browser. Each card has:
 *   - a Chart | Table toggle  -> same data as a chart or as a table (choice is remembered per card)
 *   - a filter popup          -> Date Range (which ACTUAL sales months are shown next to the forecast), Category (demand card
 *                                only) and Forecast window (1, 2 or 3 months ahead)
 * Query keys: df_preset/df_from/df_to/df_category/df_months (demand), sf_preset/sf_from/sf_to/sf_months (sales).
 *
 * Forecast figures shown: MoM growth and, for Admin + Inventory Custodian,
 * inventory actions (stock, days left, safety stock, reorder point, stockout risk, recommended order quantity). Inventory
 * actions need a stock-on-hand snapshot on the forecast service; without one the columns stay hidden and a note explains why.
 *
 * Usage (inside a staff page, Chart.js already loaded in extra_head, openFilterModal/closeFilterModal
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

/** Chart | Table switch shown in a card header. */
function ias_forecast_view_toggle(): void
{
    ?>
            <div class="fc-seg" role="group" aria-label="View">
                <button type="button" data-view="chart" aria-pressed="false" title="Chart view"><i class="fas fa-chart-bar"></i> Chart</button>
                <button type="button" data-view="table" aria-pressed="false" title="Table view"><i class="fas fa-table"></i> Table</button>
            </div>
    <?php
}

function ias_forecast_card_css(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<style>
.fc-seg { display:inline-flex; border:1px solid var(--ep-border, #e4e8ea); border-radius:8px; overflow:hidden; background:#fff; }
.fc-seg button { border:0; background:transparent; padding:5px 12px; font-size:12px; font-weight:600; color:var(--ep-muted, #64748b); cursor:pointer; display:inline-flex; gap:6px; align-items:center; }
.fc-seg button + button { border-left:1px solid var(--ep-border, #e4e8ea); }
.fc-seg button[aria-pressed="true"] { background:var(--ep-green, #62b236); color:#fff; }
.fc-seg button:focus-visible { outline:2px solid var(--ep-green-dark, #4b8b2a); outline-offset:-2px; }
.fc-sub { margin:0 0 12px; }
.fc-card[data-sub="monthly"] .fc-sub-cat { display:none; }
.fc-card[data-sub="category"] .fc-sub-monthly { display:none; }
.fc-card[data-view="chart"] .fc-view-table { display:none; }
.fc-card[data-view="table"] .fc-view-chart { display:none; }
.fc-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin:0 0 14px; }
.fc-kpi { border:1px solid var(--ep-border, #e4e8ea); border-top:3px solid var(--ep-green, #62b236); border-radius:10px; padding:10px 12px; background:#fff; }
.fc-kpi-label { font-size:11px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:var(--ep-muted, #64748b); }
.fc-kpi-value { font-size:20px; font-weight:800; color:var(--ep-text, #1f2328); margin-top:2px; }
.fc-kpi-sub { font-size:11.5px; color:var(--ep-muted, #64748b); margin-top:2px; }
.fc-up { color:#16a34a; font-weight:600; }
.fc-down { color:#dc2626; font-weight:600; }
.fc-scroll { max-height:520px; overflow:auto; }
.fc-scroll thead th { position:sticky; top:0; background:#fff; z-index:1; }
.fc-note { margin-top:10px; font-size:12px; color:var(--ep-muted, #64748b); }
.fc-subhead { margin:18px 0 8px; font-size:13px; font-weight:700; color:var(--ep-green-dark, #4b8b2a); }
.fc-noinv .fc-inv { display:none; }
.fc-badges { display:inline-flex; gap:4px; flex-wrap:wrap; }
@media print { .fc-seg { display:none !important; } .fc-card .fc-view-table, .fc-card .fc-view-chart, .fc-card .fc-sub-monthly, .fc-card .fc-sub-cat { display:block !important; } .fc-scroll { max-height:none; } }
</style>
    <?php
}

/** $which: 'both' (default), 'demand' or 'sales' - render only that card (and its filter modal). */
function ias_forecast_cards_render(array $fc, array $categories, string $which = 'both'): void
{
    $df = $fc['df'];
    $sf = $fc['sf'];
    $showDemand = $which !== 'sales';
    $showSales = $which !== 'demand';
    ias_forecast_card_css();
    if ($showDemand):
    ?>
<div class="card fc-card" id="fc-demand" data-view="table">
    <div class="card-header">
        <div>
            <h3><span class="card-icon"><i class="fas fa-box-open"></i></span> Product Demand Forecasting</h3>
            <div class="card-subtitle" id="fcDemandSub">Loading&hellip;</div>
        </div>
        <div class="card-actions no-print">
            <?php ias_forecast_view_toggle(); ?>
            <button type="button" class="btn btn-outline btn-xs" onclick="openFilterModal('dfModal')" title="Date range, category and forecast window"><i class="fas fa-filter"></i></button>
        </div>
    </div>
    <div class="card-body">
        <div class="fc-kpis" id="fcDemandKpis"></div>
        <div class="fc-view-chart">
            <div class="chart-wrap" style="height:340px;"><canvas id="fcDemandChart"></canvas></div>
            <div class="fc-note">Top 10 products by forecast. Average units per month, so the last 3 months and the forecast window are comparable.</div>
        </div>
        <div class="fc-view-table">
            <div class="table-wrap fc-scroll">
                <table class="ias-table fc-noinv" id="fcDemandTable">
                    <thead><tr>
                        <th>Product</th><th>Category</th>
                        <th>Units sold (date range)</th><th>Forecast units</th><th>Likely range</th><th>Forecast revenue</th><th>Trend</th><th>Confidence</th>
                        <th class="fc-inv">Stock</th><th class="fc-inv" title="Stock on hand / forecast daily demand">Days left</th>
                        <th class="fc-inv">Safety stock</th><th class="fc-inv">Reorder point</th><th class="fc-inv">Stockout risk</th><th class="fc-inv">Order qty</th>
                    </tr></thead>
                    <tbody id="fcDemandBody"><tr><td colspan="14" class="empty-state">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
            <div class="fc-note" id="fcDemandNote"></div>
        </div>
    </div>
</div>
    <?php
    endif;
    if ($showSales):
    ?>
<div class="card fc-card" id="fc-sales" data-view="chart" data-sub="monthly">
    <div class="card-header">
        <div>
            <h3><span class="card-icon"><i class="fas fa-chart-line"></i></span> Sales Forecasting</h3>
            <div class="card-subtitle" id="fcRevSub">Loading&hellip;</div>
        </div>
        <div class="card-actions no-print">
            <?php ias_forecast_view_toggle(); ?>
            <button type="button" class="btn btn-outline btn-xs" onclick="openFilterModal('sfModal')" title="Date range and forecast window"><i class="fas fa-filter"></i></button>
        </div>
    </div>
    <div class="card-body">
        <div class="fc-kpis" id="fcRevKpis"></div>
        <div class="fc-view-chart">
            <div class="chart-wrap"><canvas id="fcRevChart"></canvas></div>
        </div>
        <div class="fc-view-table">
            <div class="fc-seg fc-sub no-print" id="fcSalesSub" role="group" aria-label="Table">
                <button type="button" data-sub="monthly" aria-pressed="true"><i class="fas fa-calendar-alt"></i> By month</button>
                <button type="button" data-sub="category" aria-pressed="false"><i class="fas fa-layer-group"></i> By category</button>
            </div>
            <div class="table-wrap fc-scroll fc-sub-monthly">
                <table class="ias-table">
                    <thead><tr><th>Month</th><th>Type</th><th>Units</th><th>Sales (PHP)</th><th title="Change vs the month before">Sales MoM</th><th title="Change vs the month before">Units MoM</th><th>Likely range (PHP)</th></tr></thead>
                    <tbody id="fcRevBody"></tbody>
                </table>
            </div>
            <div class="fc-subhead fc-sub-cat">By category (whole forecast window)</div>
            <div class="table-wrap fc-scroll fc-sub-cat">
                <table class="ias-table">
                    <thead><tr><th>Category</th><th>Group</th><th>Forecast units</th><th>Forecast sales (PHP)</th><th title="First forecast month vs the latest actual month">Sales MoM (next month)</th><th title="First forecast month vs the latest actual month">Units MoM (next month)</th></tr></thead>
                    <tbody id="fcRevCatBody"></tbody>
                </table>
            </div>
            <div class="fc-note">MoM = change versus the month before. For the first forecast month that is the latest actual month. A jump from a very small base can look extreme.</div>
        </div>
    </div>
</div>
    <?php
    endif;
    if ($showDemand):
    ?>
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
    <?php
    endif;
    if ($showSales):
    ?>
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
    endif;
}

/** <script> that loads both cards from forecast_api.php (path is relative to the page's role folder, e.g. ADMIN/). */
function ias_forecast_cards_script(array $fc, string $which = 'both'): string
{
    $role = (string)($_SESSION['role'] ?? '');
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
        // Same roles as forecast_api.php type=actions; everyone else never asks for it.
        'actions' => in_array($role, ['admin', 'inventory_custodian'], true),
    ];
    $cfg['which'] = $which;
    $json = json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script>window.IAS_FORECAST_CFG = ' . $json . ';</script>' . <<<'HTML'
<script>
(function () {
    var API = '../forecast_api.php';
    var cfg = window.IAS_FORECAST_CFG, charts = {};

    function peso(n, d) { return '₱' + Number(n || 0).toLocaleString('en-PH', { maximumFractionDigits: d || 0 }); }
    function num(n) { return Number(n || 0).toLocaleString('en-PH', { maximumFractionDigits: 1 }); }
    function pctText(v) { return (v === null || v === undefined) ? '—' : (v > 0 ? '+' : '') + v + '%'; }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }
    function cell(tr, content, strong, cls) {
        var td = document.createElement('td');
        if (cls) { td.className = cls; }
        if (content && content.nodeType) { td.appendChild(content); }
        else if (strong) { var s = document.createElement('strong'); s.textContent = content; td.appendChild(s); }
        else { td.textContent = content; }
        tr.appendChild(td);
        return td;
    }
    function momCell(tr, v) {
        var td = cell(tr, pctText(v));
        if (v > 0) { td.className = 'fc-up'; } else if (v < 0) { td.className = 'fc-down'; }
    }
    function badge(text, cls, title) {
        var b = el('span', 'badge ' + cls, text);
        if (title) { b.title = title; }
        return b;
    }
    function emptyRow(tbody, cols, msg) {
        tbody.textContent = '';
        var tr = document.createElement('tr'), td = document.createElement('td');
        td.colSpan = cols; td.className = 'empty-state'; td.textContent = msg;
        tr.appendChild(td); tbody.appendChild(tr);
    }
    function kpis(boxId, items) {
        var box = document.getElementById(boxId);
        box.textContent = '';
        items.forEach(function (k) {
            var d = el('div', 'fc-kpi');
            d.appendChild(el('div', 'fc-kpi-label', k.label));
            var v = el('div', 'fc-kpi-value', k.value);
            if (k.tone === 'up') { v.className += ' fc-up'; } else if (k.tone === 'down') { v.className += ' fc-down'; }
            d.appendChild(v);
            if (k.sub) { d.appendChild(el('div', 'fc-kpi-sub', k.sub)); }
            box.appendChild(d);
        });
    }
    function tone(v) { return v > 0 ? 'up' : (v < 0 ? 'down' : ''); }
    function sum(list, key) { return list.reduce(function (a, r) { return a + (r[key] || 0); }, 0); }
    function get(type, c, extra) {
        var q = 'type=' + type + '&horizon=' + c.months + '&from=' + c.from + '&to=' + c.to + (extra || '') +
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
    function shorten(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }

    // ---- Chart | Table toggle (remembered per card; storage may be blocked, so every access is guarded) ----
    function initToggle(cardId, key, fallback, onChange) {
        var card = document.getElementById(cardId);
        if (!card) { return; }
        var buttons = card.querySelectorAll('.fc-seg:not(.fc-sub) button');
        function saved() {
            try { var v = window.localStorage.getItem('ias_fc_view_' + key); if (v === 'chart' || v === 'table') { return v; } } catch (e) {}
            return fallback;
        }
        function set(v, remember) {
            card.setAttribute('data-view', v);
            Array.prototype.forEach.call(buttons, function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-view') === v ? 'true' : 'false'); });
            if (remember) { try { window.localStorage.setItem('ias_fc_view_' + key, v); } catch (e) {} }
            if (onChange) { onChange(v); }
        }
        Array.prototype.forEach.call(buttons, function (b) { b.addEventListener('click', function () { set(b.getAttribute('data-view'), true); }); });
        set(saved(), false);
    }
    // Sales table view shows one table at a time: By month | By category (remembered like the main toggle).
    function initSubToggle(cardId, key) {
        var card = document.getElementById(cardId);
        if (!card) { return; }
        var buttons = card.querySelectorAll('.fc-sub button');
        function set(v, remember) {
            card.setAttribute('data-sub', v);
            Array.prototype.forEach.call(buttons, function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-sub') === v ? 'true' : 'false'); });
            if (remember) { try { window.localStorage.setItem('ias_fc_sub_' + key, v); } catch (e) {} }
        }
        Array.prototype.forEach.call(buttons, function (b) { b.addEventListener('click', function () { set(b.getAttribute('data-sub'), true); }); });
        var v = 'monthly';
        try { var saved = window.localStorage.getItem('ias_fc_sub_' + key); if (saved === 'monthly' || saved === 'category') { v = saved; } } catch (e) {}
        set(v, false);
    }
    function resizeChart(name) { if (charts[name]) { charts[name].resize(); } }

    // ---------------------------------------------------------------- Product demand
    function riskBadge(a) {
        var wrap = el('span', 'fc-badges');
        var map = { high: ['High', 'badge-locked'], medium: ['Medium', 'badge-pending'], low: ['Low', 'badge-active'] };
        if (map[a.stockout_risk]) { wrap.appendChild(badge(map[a.stockout_risk][0], map[a.stockout_risk][1], a.message)); }
        if (a.overstock) { wrap.appendChild(badge('Overstock', 'badge-info', a.message)); }
        if (!wrap.firstChild) { wrap.textContent = '—'; }
        return wrap;
    }
    function renderDemand(data, acts, actsError, c) {
        var rows = data.products || [], total = data.total || [];
        document.getElementById('fcDemandSub').textContent =
            c.label + ' · ' + (c.category || 'All products') + ' · forecast ' + windowLabel(c.months);
        var body = document.getElementById('fcDemandBody'), table = document.getElementById('fcDemandTable');
        var note = document.getElementById('fcDemandNote');
        var byMsku = {};
        if (acts) { (acts.products || []).forEach(function (a) { byMsku[a.msku] = a; }); }
        table.className = 'ias-table' + (acts ? '' : ' fc-noinv');

        if (!rows.length) {
            emptyRow(body, 14, data.message || 'No forecast available for this selection.');
            kpis('fcDemandKpis', []);
            if (charts.demand) { charts.demand.destroy(); charts.demand = null; }
            return;
        }

        // KPI tiles
        var first = total[0] || {};
        var tiles = [
            { label: 'Forecast units', value: num(sum(total, 'predicted_units')), sub: windowLabel(c.months) },
            { label: 'Forecast revenue', value: peso(sum(total, 'predicted_revenue')), sub: windowLabel(c.months) },
            { label: 'Units MoM', value: pctText(first.units_mom_pct), tone: tone(first.units_mom_pct), sub: (first.month || '') + ' vs last actual month' }
        ];
        if (acts) {
            var need = rows.filter(function (r) { var a = byMsku[r.msku]; return a && (a.action === 'order_now' || a.action === 'reorder'); }).length;
            var urgent = rows.filter(function (r) { var a = byMsku[r.msku]; return a && a.action === 'order_now'; }).length;
            tiles.push({ label: 'Need ordering', value: String(need), tone: need ? 'down' : '', sub: urgent + ' urgent (stockout risk high)' });
        }
        kpis('fcDemandKpis', tiles);

        // Table view
        body.textContent = '';
        rows.forEach(function (p) {
            var a = byMsku[p.msku];
            var tr = document.createElement('tr');
            cell(tr, p.product_name, true); cell(tr, p.category);
            cell(tr, p.range_units === undefined ? '—' : num(p.range_units));
            cell(tr, num(p.forecast_units), true);
            cell(tr, num(p.forecast_units_low) + ' – ' + num(p.forecast_units_high));
            cell(tr, peso(p.forecast_revenue));
            cell(tr, p.trend_pct === null || p.trend_pct === undefined ? '—' : (p.trend_pct > 0 ? '+' : '') + p.trend_pct + '%');
            cell(tr, p.confidence + ' · ' + (p.model_reliability || 'unknown'));
            cell(tr, a ? num(a.stock_on_hand) : '—', false, 'fc-inv');
            cell(tr, a && a.days_of_inventory !== null ? num(a.days_of_inventory) + ' d' : '—', false, 'fc-inv');
            cell(tr, a ? num(a.safety_stock) : '—', false, 'fc-inv');
            cell(tr, a ? num(a.reorder_point) : '—', false, 'fc-inv');
            cell(tr, a ? riskBadge(a) : '—', false, 'fc-inv');
            var orderCell = '—';
            if (a && a.recommended_order_qty > 0) { orderCell = num(a.recommended_order_qty); }
            else if (a && a.on_order > 0) { orderCell = badge('On order: ' + num(a.on_order), 'badge-info', a.message); }
            cell(tr, orderCell, a && a.recommended_order_qty > 0, 'fc-inv');
            body.appendChild(tr);
        });

        // Note under the table
        note.textContent = '';
        if (acts) {
            var asOf = acts.stock_as_of ? new Date(acts.stock_as_of) : null;
            var parts = ['Stock as of ' + (asOf && !isNaN(asOf) ? asOf.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : 'unknown'),
                         'lead time assumed ' + ((acts.assumptions || {}).lead_time_days_default || '?') + ' days (no supplier data yet)',
                         'order quantity covers ' + ((acts.assumptions || {}).review_days || '?') + ' days of demand'];
            note.textContent = parts.join(' · ') + '.';
            if (acts.stock_stale) {
                var w = el('strong', 'fc-down', ' The stock snapshot is out of date, so stockout and order alerts may be wrong.');
                note.appendChild(w);
            }
        } else if (c.actionsWanted) {
            note.textContent = (actsError || 'Stock levels are not connected to the forecast yet') +
                '. Reorder point, safety stock, days left and order quantity appear here once they are.';
        }

        // Chart view: average units per month, last 3 months vs forecast
        var top = rows.slice(0, 10);
        var labels = top.map(function (r) { return shorten(r.product_name, 38); });
        var past = top.map(function (r) { return Math.round(r.last_3m_units / 3 * 10) / 10; });
        var fut = top.map(function (r) { return Math.round(r.forecast_units / c.months * 10) / 10; });
        if (charts.demand) { charts.demand.destroy(); charts.demand = null; }
        if (window.Chart) {
            charts.demand = new Chart(document.getElementById('fcDemandChart').getContext('2d'), {
                type: 'bar',
                data: { labels: labels, datasets: [
                    { label: 'Last 3 months (avg / month)', data: past, backgroundColor: 'rgba(97,179,55,.75)' },
                    { label: 'Forecast (avg / month)', data: fut, backgroundColor: 'rgba(254,215,0,.9)' }
                ] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } },
                    scales: { x: { beginAtZero: true, title: { display: true, text: 'Units per month' } }, y: { grid: { display: false } } } }
            });
        }
    }

    // ---------------------------------------------------------------- Sales
    function renderSales(data, c) {
        var total = data.total || [], hist = data.history || [], cats = data.by_category || [], g = data.growth || {};
        var projected = sum(total, 'predicted_revenue');
        document.getElementById('fcRevSub').textContent = total.length
            ? c.label + ' · forecast ' + windowLabel(c.months) + ' · projected ' + peso(projected) +
              (hist.length ? '' : ' · no sales recorded in this date range')
            : (data.message || 'No forecast available for this selection.');

        var first = total[0] || {};
        kpis('fcRevKpis', total.length ? [
            { label: 'Projected sales', value: peso(projected), sub: windowLabel(c.months) },
            { label: 'Projected units', value: num(sum(total, 'predicted_units')), sub: windowLabel(c.months) },
            { label: 'Sales MoM', value: pctText(first.revenue_mom_pct), tone: tone(first.revenue_mom_pct), sub: first.month + ' vs ' + (g.last_actual_month || 'last actual month') },
            { label: 'Units MoM', value: pctText(first.units_mom_pct), tone: tone(first.units_mom_pct), sub: first.month + ' vs ' + (g.last_actual_month || 'last actual month') },
            { label: 'Last actual month', value: g.last_actual_month || '—', sub: g.last_actual_revenue !== undefined ? peso(g.last_actual_revenue) + ' (' + pctText(g.revenue_mom_pct) + ' MoM)' : '' }
        ] : []);

        // Chart view
        var labels = hist.map(function (r) { return r.month; }).concat(total.map(function (r) { return r.month; }));
        var blanks = function (list) { return list.map(function () { return null; }); };
        var actual = hist.map(function (r) { return r.actual_revenue; }).concat(blanks(total));
        var fcast = blanks(hist).concat(total.map(function (r) { return r.predicted_revenue; }));
        var low = blanks(hist).concat(total.map(function (r) { return r.revenue_confidence_low; }));
        var high = blanks(hist).concat(total.map(function (r) { return r.revenue_confidence_high; }));
        if (hist.length && total.length) {                          // join the lines (and start the range band) at the last actual month
            var j = hist.length - 1, lastActual = hist[j].actual_revenue;
            fcast[j] = lastActual; low[j] = lastActual; high[j] = lastActual;
        }
        if (charts.sales) { charts.sales.destroy(); charts.sales = null; }
        if (window.Chart) {
            charts.sales = new Chart(document.getElementById('fcRevChart').getContext('2d'), {
                type: 'line',
                data: { labels: labels, datasets: [
                    { label: 'Actual sales', data: actual, borderColor: '#61b337', backgroundColor: 'rgba(97,179,55,.12)', fill: true, tension: .3, borderWidth: 3, pointRadius: 3 },
                    { label: 'Forecast', data: fcast, borderColor: '#c9a200', borderDash: [6, 4], fill: false, tension: .3, borderWidth: 3, pointRadius: 3 },
                    { label: '_low', data: low, borderWidth: 0, pointRadius: 0, fill: false, tension: .3 },
                    { label: 'Likely range', data: high, borderWidth: 0, pointRadius: 0, backgroundColor: 'rgba(254,215,0,.22)', fill: '-1', tension: .3 }
                ] },
                options: { responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { filter: function (item) { return item.text.charAt(0) !== '_'; } } } },
                    scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return peso(v); } } }, x: { grid: { display: false } } } }
            });
        }

        // Table view: months (actual, then forecast) with MoM
        var body = document.getElementById('fcRevBody');
        body.textContent = '';
        function pctChange(now, before) { return before > 0 ? Math.round((now / before - 1) * 1000) / 10 : null; }
        hist.forEach(function (r, i) {
            var tr = document.createElement('tr'), prev = i ? hist[i - 1] : null;
            cell(tr, r.month); cell(tr, 'Actual'); cell(tr, num(r.actual_units)); cell(tr, peso(r.actual_revenue, 2));
            momCell(tr, prev ? pctChange(r.actual_revenue, prev.actual_revenue) : null);
            momCell(tr, prev ? pctChange(r.actual_units, prev.actual_units) : null);
            cell(tr, '—');
            body.appendChild(tr);
        });
        total.forEach(function (r) {
            var tr = document.createElement('tr');
            cell(tr, r.month); cell(tr, 'Forecast'); cell(tr, num(r.predicted_units)); cell(tr, peso(r.predicted_revenue, 2));
            momCell(tr, r.revenue_mom_pct); momCell(tr, r.units_mom_pct);
            cell(tr, peso(r.revenue_confidence_low) + ' – ' + peso(r.revenue_confidence_high));
            body.appendChild(tr);
        });
        if (!hist.length && !total.length) { emptyRow(body, 7, data.message || 'No data for this selection.'); }

        // Table view: by category over the whole window
        var byCat = {};
        cats.forEach(function (r) {
            var k = byCat[r.category] || (byCat[r.category] = { category: r.category, group: r.group, units: 0, revenue: 0, month: null });
            k.units += r.predicted_units || 0; k.revenue += r.predicted_revenue || 0;
            if (k.month === null || r.month < k.month) { k.month = r.month; k.rmom = r.revenue_mom_pct; k.umom = r.units_mom_pct; }
        });
        var list = Object.keys(byCat).map(function (k) { return byCat[k]; }).sort(function (a, b) { return b.revenue - a.revenue; });
        var cb = document.getElementById('fcRevCatBody');
        cb.textContent = '';
        if (!list.length) { emptyRow(cb, 6, 'No category breakdown for this selection.'); }
        list.forEach(function (k) {
            var tr = document.createElement('tr');
            cell(tr, k.category, true); cell(tr, k.group || '—'); cell(tr, num(k.units)); cell(tr, peso(k.revenue, 2));
            momCell(tr, k.rmom); momCell(tr, k.umom);
            cb.appendChild(tr);
        });
    }

    // ---------------------------------------------------------------- wiring
    if (cfg.which !== 'sales') {
        initToggle('fc-demand', 'demand', 'table', function (v) { if (v === 'chart') { resizeChart('demand'); } });
        var c = cfg.demand;
        c.actionsWanted = !!cfg.actions;
        var demandReq = get('demand', c, '&top=50');
        var actsReq = cfg.actions
            ? get('actions', c, '&top=300').then(function (a) { return { acts: a }; }, function (e) { return { acts: null, err: e.message }; })
            : Promise.resolve({ acts: null });
        Promise.all([demandReq, actsReq]).then(function (r) { renderDemand(r[0], r[1].acts, r[1].err, c); }).catch(function (e) {
            emptyRow(document.getElementById('fcDemandBody'), 14, 'Forecast unavailable: ' + e.message);
            document.getElementById('fcDemandSub').textContent = c.label;
        });
    }
    if (cfg.which !== 'demand') {
        initToggle('fc-sales', 'sales', 'chart', function (v) { if (v === 'chart') { resizeChart('sales'); } });
        initSubToggle('fc-sales', 'sales');
        get('revenue', cfg.sales).then(function (d) { renderSales(d, cfg.sales); }).catch(function (e) {
            document.getElementById('fcRevSub').textContent = 'Forecast unavailable: ' + e.message;
        });
    }
})();
</script>
HTML;
}
