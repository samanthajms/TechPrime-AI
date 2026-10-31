<?php
/**
 * Product Trends + Sales Trends sections (shared Trend Analysis page: includes/staff_trends_page.php). On screen the
 * service's predictions are called "Projected"; code and query keys keep the old "forecast" names.
 *
 * Data comes from the XGBoost forecast service through trends_api.php (browser -> PHP proxy -> Render), so the API
 * key never reaches the browser. Each section (wrapper #fc-demand / #fc-sales) has:
 *   - a filter bar            -> Sales history (which ACTUAL sales months are shown next to the projection), Category
 *                                (product view only) and Projection window (1, 2 or 3 months ahead); a change applies at once
 *   - a Summary card          -> the headline figures (.kpis)
 *   - a detail card           -> Chart | Table switch over the same data (choice is remembered per section)
 * The wrapper's data-state (loading | ready | empty | error) picks between the skeleton/message and the figures;
 * data-view and data-sub pick the visible view. Styles: includes/staff_app.css §10 "Trend Analysis pages".
 * Query keys: df_preset/df_from/df_to/df_category/df_months (demand), sf_preset/sf_from/sf_to/sf_months (sales).
 *
 * Figures shown: MoM growth and, for Admin, Retail Officer + Inventory Custodian, inventory actions (stock, days left,
 * safety stock, reorder point, stockout risk, recommended order quantity). Inventory actions need a stock-on-hand
 * snapshot on the forecast service; without one the columns stay hidden and a note explains why.
 *
 * Usage (inside a staff page, Chart.js already loaded in extra_head):
 *     $fc = ias_forecast_selection($categories);
 *     ias_forecast_cards_render($fc, $categories, 'demand');   // or 'sales'
 *     staff_page_end(ias_forecast_cards_script($fc, 'demand') . $pageScripts);
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

function ias_forecast_window_text(int $months): string
{
    return 'next ' . $months . ($months === 1 ? ' month' : ' months');
}

/** Filter bar of one section ($p = 'df' or 'sf'); same look and behaviour as the Stock Movements report bar. */
function ias_forecast_filters(string $p, array $sel, array $presets, ?array $categories, string $view): void
{
    $range = $sel['range'];
    $isCustom = $range['preset'] === 'custom';
    $isDefault = $range['preset'] === 'this_year' && ($sel['category'] ?? '') === '' && $sel['months'] === IAS_FORECAST_MAX_MONTHS;
    $today = date('Y-m-d');
    ?>
        <form class="eodr-filters cx-card fc-filters no-print" method="get" aria-label="<?php echo $view === 'sales' ? 'Sales trend filters' : 'Product trend filters'; ?>">
            <?php ias_forecast_preserve_hidden([$p . '_preset', $p . '_from', $p . '_to', $p . '_category', $p . '_months', 'export_failed']); ?>
            <label class="sort-wrap" title="Which months of actual sales are shown next to the projection">
                <span>Sales history</span>
                <select name="<?php echo $p; ?>_preset" data-fc-preset>
                    <?php foreach ($presets as $key => $preset): ?>
                        <option value="<?php echo h($key); ?>" <?php echo $range['preset'] === $key ? 'selected' : ''; ?>><?php echo h($preset['label']); ?></option>
                    <?php endforeach; ?>
                    <option value="custom" <?php echo $isCustom ? 'selected' : ''; ?>>Custom range</option>
                </select>
            </label>
            <span class="eodr-dates" data-fc-dates <?php echo $isCustom ? '' : 'hidden'; ?>>
                <label class="cx-sr" for="<?php echo $p; ?>From">From</label>
                <input type="date" class="form-control" id="<?php echo $p; ?>From" name="<?php echo $p; ?>_from" value="<?php echo h($range['from']->format('Y-m-d')); ?>" max="<?php echo h($today); ?>">
                <span aria-hidden="true">–</span>
                <label class="cx-sr" for="<?php echo $p; ?>To">To</label>
                <input type="date" class="form-control" id="<?php echo $p; ?>To" name="<?php echo $p; ?>_to" value="<?php echo h($range['to']->format('Y-m-d')); ?>" max="<?php echo h($today); ?>">
            </span>
            <?php if ($categories !== null): ?>
            <label class="sort-wrap">
                <span>Category</span>
                <select name="<?php echo $p; ?>_category">
                    <option value="">All products</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo h($c); ?>" <?php echo $sel['category'] === $c ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <label class="sort-wrap">
                <span>Projection</span>
                <select name="<?php echo $p; ?>_months">
                    <?php for ($n = 1; $n <= IAS_FORECAST_MAX_MONTHS; $n++): ?>
                        <option value="<?php echo $n; ?>" <?php echo $sel['months'] === $n ? 'selected' : ''; ?>><?php echo h(ucfirst(ias_forecast_window_text($n))); ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <button type="submit" class="cx-btn cx-btn-outline">Apply</button>
            <?php if (!$isDefault): ?>
                <a class="cx-btn cx-btn-ghost" href="?view=<?php echo h($view); ?>"><i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Reset</a>
            <?php endif; ?>
        </form>
    <?php
}

/** Summary card: skeleton + status line while loading (replaced by a message on error/empty), then the figures. */
function ias_forecast_summary_card(string $prefix, string $subtitle): void
{
    ?>
        <section class="cx-card fc-sum" aria-labelledby="<?php echo $prefix; ?>SumTitle">
            <div class="cx-card-head">
                <div>
                    <h2 id="<?php echo $prefix; ?>SumTitle"><i class="ph ph-chart-line-up" aria-hidden="true"></i> Summary</h2>
                    <p id="<?php echo $prefix; ?>Sub"><?php echo h($subtitle); ?></p>
                </div>
            </div>
            <div class="fc-status" id="<?php echo $prefix; ?>Status" aria-live="polite">
                <div class="fc-skel" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                <div class="fc-skel-block" aria-hidden="true"></div>
                <p class="fc-wait"><i class="ph ph-circle-notch cx-spin" aria-hidden="true"></i> <span class="fc-wait-text">Loading the trend analysis&hellip;</span></p>
            </div>
            <div class="kpis fc-kpis" id="<?php echo $prefix; ?>Kpis" data-n="0"></div>
        </section>
    <?php
}

/** Chart | Table switch shown in a detail card header. */
function ias_forecast_view_toggle(): void
{
    ?>
                    <div class="cx-seg fc-seg" role="group" aria-label="Show as">
                        <button type="button" data-view="chart" aria-pressed="false"><i class="ph ph-chart-bar" aria-hidden="true"></i> Chart</button>
                        <button type="button" data-view="table" aria-pressed="false"><i class="ph ph-table" aria-hidden="true"></i> Table</button>
                    </div>
    <?php
}

function ias_forecast_range_text(array $range): string
{
    return $range['label'] . ' (' . $range['from']->format('M j, Y') . ' – ' . $range['to']->format('M j, Y') . ')';
}

/** $which: 'demand' or 'sales' - the section to render ('both' renders both, for a page that shows them together). */
function ias_forecast_cards_render(array $fc, array $categories, string $which = 'both'): void
{
    $df = $fc['df'];
    $sf = $fc['sf'];
    if ($which !== 'sales'):
    ?>
<div class="rep fc-page" id="fc-demand" data-view="table" data-state="loading" aria-busy="true">
    <?php ias_forecast_filters('df', $df, $fc['presets'], $categories, 'demand'); ?>
    <?php ias_forecast_summary_card('fcDemand', ias_forecast_range_text($df['range']) . ' · ' . ($df['category'] !== '' ? $df['category'] : 'All products')
        . ' · projected for the ' . ias_forecast_window_text($df['months'])); ?>
    <section class="cx-card fc-detail" aria-labelledby="fcDemandTitle">
        <div class="cx-card-head">
            <div>
                <h2 id="fcDemandTitle"><i class="ph ph-package" aria-hidden="true"></i> By product</h2>
                <p>Projected units per product and, where stock levels are connected, what to reorder</p>
            </div>
            <div class="cx-card-tools fc-tools no-print">
                <span class="cx-count" id="fcDemandCount" hidden></span>
                <?php ias_forecast_view_toggle(); ?>
            </div>
        </div>
        <div class="fc-view-chart">
            <div class="cx-chart fc-chart-bars"><canvas id="fcDemandChart" role="img" aria-label="Bar chart of projected units per product"></canvas></div>
            <p class="rep-note">Top 10 products by projected units. Both bars are average units per month, so the last 3 months and the projection compare directly.</p>
        </div>
        <div class="fc-view-table">
            <div class="cx-table-wrap fc-scroll">
                <table class="cx-table rep-table fc-table fc-noinv" id="fcDemandTable">
                    <thead><tr>
                        <th scope="col">Product</th>
                        <th scope="col" class="num">Projected units</th>
                        <th scope="col" class="num">Projected revenue</th>
                        <th scope="col" class="num">Trend</th>
                        <th scope="col" class="num fc-inv">Stock</th>
                        <th scope="col" class="num fc-inv" title="Stock on hand / projected daily sales">Days left</th>
                        <th scope="col" class="fc-inv">Stockout risk</th>
                        <th scope="col" class="num fc-inv">Order qty</th>
                        <th scope="col" class="num fc-minor" title="Units sold in the sales history period">Sold in period</th>
                        <th scope="col" class="fc-minor">Confidence</th>
                        <th scope="col" class="num fc-inv fc-minor">Safety stock</th>
                        <th scope="col" class="num fc-inv fc-minor">Reorder point</th>
                    </tr></thead>
                    <tbody id="fcDemandBody"><tr><td colspan="12" class="empty-state">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
            <p class="rep-note" id="fcDemandNote"></p>
        </div>
    </section>
</div>
    <?php
    endif;
    if ($which !== 'demand'):
    ?>
<div class="rep fc-page" id="fc-sales" data-view="chart" data-sub="monthly" data-state="loading" aria-busy="true">
    <?php ias_forecast_filters('sf', $sf, $fc['presets'], null, 'sales'); ?>
    <?php ias_forecast_summary_card('fcRev', ias_forecast_range_text($sf['range']) . ' · projected for the ' . ias_forecast_window_text($sf['months'])); ?>
    <section class="cx-card fc-detail" aria-labelledby="fcRevTitle">
        <div class="cx-card-head">
            <div>
                <h2 id="fcRevTitle"><i class="ph ph-chart-line" aria-hidden="true"></i> Monthly sales</h2>
                <p>Actual sales per month, then the projection with its likely range</p>
            </div>
            <div class="cx-card-tools fc-tools no-print">
                <?php ias_forecast_view_toggle(); ?>
            </div>
        </div>
        <div class="fc-view-chart">
            <div class="cx-chart"><canvas id="fcRevChart" role="img" aria-label="Line chart of monthly sales"></canvas></div>
        </div>
        <div class="fc-view-table">
            <div class="cx-seg fc-sub no-print" id="fcSalesSub" role="group" aria-label="Table">
                <button type="button" data-sub="monthly" aria-pressed="true"><i class="ph ph-calendar-blank" aria-hidden="true"></i> By month</button>
                <button type="button" data-sub="category" aria-pressed="false"><i class="ph ph-squares-four" aria-hidden="true"></i> By category</button>
            </div>
            <h3 class="fc-subhead cx-print-only">By month</h3>
            <div class="cx-table-wrap fc-scroll fc-sub-monthly">
                <table class="cx-table rep-table fc-table">
                    <thead><tr>
                        <th scope="col">Month</th><th scope="col" class="num">Units</th><th scope="col" class="num">Sales</th>
                        <th scope="col" class="num" title="Change vs the month before">Sales MoM</th><th scope="col" class="num" title="Change vs the month before">Units MoM</th>
                        <th scope="col" class="num">Likely range</th>
                    </tr></thead>
                    <tbody id="fcRevBody"><tr><td colspan="6" class="empty-state">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
            <h3 class="fc-subhead fc-sub-cat">By category (whole projection window)</h3>
            <div class="cx-table-wrap fc-scroll fc-sub-cat">
                <table class="cx-table rep-table fc-table">
                    <thead><tr>
                        <th scope="col">Category</th><th scope="col" class="num">Projected units</th><th scope="col" class="num">Projected sales</th>
                        <th scope="col" class="num" title="First projected month vs the latest actual month">Sales MoM (next month)</th>
                        <th scope="col" class="num" title="First projected month vs the latest actual month">Units MoM (next month)</th>
                    </tr></thead>
                    <tbody id="fcRevCatBody"><tr><td colspan="5" class="empty-state">Loading&hellip;</td></tr></tbody>
                </table>
            </div>
            <p class="rep-note">MoM = change versus the month before. For the first projected month that is the latest actual month. A jump from a very small base can look extreme.</p>
        </div>
    </section>
</div>
    <?php
    endif;
}

/** <script> that loads the sections from trends_api.php (path is relative to the page's role folder, e.g. ADMIN/). */
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
        // Same roles as trends_api.php type=actions; everyone else never asks for it.
        'actions' => in_array($role, ['admin', 'inventory_custodian', 'retail_officer'], true),
    ];
    $cfg['which'] = $which;
    $json = json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script>window.IAS_FORECAST_CFG = ' . $json . ';</script>' . <<<'HTML'
<script>
(function () {
    'use strict';
    var API = '../trends_api.php';
    var cfg = window.IAS_FORECAST_CFG, charts = {};
    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    // Staff design tokens (staff_app.css §1); chart marks keep >= 3:1 against white
    var C = { green: '#3d7422', green6: '#4b8b2a', greenFill: 'rgba(98,178,54,.12)', yellow: '#f3c400', yellowEdge: '#8a5a00',
              band: 'rgba(243,196,0,.26)', projArea: 'rgba(243,196,0,.07)', grid: '#e0e6dd', text: '#45514a', muted: '#5c6862' };
    if (window.Chart) {
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 12.5;
        Chart.defaults.color = C.text;
        Chart.defaults.borderColor = C.grid;
    }
    // Room between the legend and the plot, so the top axis label never touches the legend
    var legendGap = { id: 'fcLegendGap', beforeInit: function (chart) {
        if (!chart.legend) { return; }
        var fit = chart.legend.fit;
        chart.legend.fit = function () { fit.call(this); this.height += 14; };
    } };
    var compact = null;
    try { compact = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', notation: 'compact', maximumFractionDigits: 1 }); } catch (e) {}

    function peso(n, d) { return '₱' + Number(n || 0).toLocaleString('en-PH', { maximumFractionDigits: d || 0 }); }
    function pesoShort(n) { return compact ? compact.format(n) : peso(n); }
    function num(n) { return Number(n || 0).toLocaleString('en-PH', { maximumFractionDigits: 1 }); }
    function has(v) { return v !== null && v !== undefined; }
    function pctText(v) { return has(v) ? (v > 0 ? '+' : (v < 0 ? '−' : '')) + Math.abs(v) + '%' : '—'; }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }
    function icon(name, fill) { var i = el('i', (fill ? 'ph-fill ' : 'ph ') + name); i.setAttribute('aria-hidden', 'true'); return i; }
    function td(tr, label, content, cls) {
        var c = document.createElement('td');
        if (label) { c.setAttribute('data-label', label); }
        if (cls) { c.className = cls; }
        if (content && content.nodeType) { c.appendChild(content); } else { c.textContent = content; }
        tr.appendChild(c);
        return c;
    }
    // A value with a secondary line under it (product + category, units + likely range)
    function mainSub(main, sub) {
        var f = document.createDocumentFragment();
        f.appendChild(el('strong', null, main));
        if (sub) { f.appendChild(el('span', 'rep-sub', sub)); }
        return f;
    }
    // +/-% with an arrow: colour is never the only cue
    function change(v) {
        var s = el('span', 'fc-chg' + (v > 0 ? ' fc-up' : (v < 0 ? ' fc-down' : '')));
        if (has(v)) { s.appendChild(icon(v > 0 ? 'ph-trend-up' : (v < 0 ? 'ph-trend-down' : 'ph-minus'))); }
        s.appendChild(document.createTextNode(pctText(v)));
        return s;
    }
    function emptyRow(tbody, cols, msg) {
        tbody.textContent = '';
        var tr = document.createElement('tr'), c = document.createElement('td');
        c.colSpan = cols; c.className = 'empty-state'; c.textContent = msg;
        tr.appendChild(c); tbody.appendChild(tr);
    }
    function kpis(boxId, items) {
        var box = document.getElementById(boxId);
        box.textContent = '';
        box.setAttribute('data-n', String(items.length));
        items.forEach(function (k, i) {
            var d = el('div', 'kpi' + (i === 0 ? ' kpi-hero' : ''));
            d.appendChild(el('span', 'kpi-label', k.label));
            var v = el('strong', 'kpi-value cx-num' + (k.tone === 'up' ? ' fc-up' : (k.tone === 'down' ? ' fc-down' : '')));
            if (k.icon) { v.appendChild(icon(k.icon)); }
            v.appendChild(document.createTextNode(k.value));
            d.appendChild(v);
            if (k.sub) { d.appendChild(el('span', 'kpi-sub', k.sub)); }
            box.appendChild(d);
        });
    }
    function tone(v) { return v > 0 ? 'up' : (v < 0 ? 'down' : ''); }
    function trendIcon(v) { return v > 0 ? 'ph-trend-up' : (v < 0 ? 'ph-trend-down' : ''); }
    function sum(list, key) { return list.reduce(function (a, r) { return a + (r[key] || 0); }, 0); }
    function sentence(s) { s = String(s || '').trim(); s = s.charAt(0).toUpperCase() + s.slice(1); return /[.!?]$/.test(s) ? s : s + '.'; }
    function get(type, c, extra) {
        var q = 'type=' + type + '&horizon=' + c.months + '&from=' + c.from + '&to=' + c.to + (extra || '') +
                (c.category ? '&category=' + encodeURIComponent(c.category) : '');
        // Give up after 60 s so a stuck request shows an error instead of loading forever.
        var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = ctl ? setTimeout(function () { ctl.abort(); }, 60000) : null;
        return fetch(API + '?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctl ? ctl.signal : undefined })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    if (!r.ok || j.ok === false) { throw new Error(j.error || ('HTTP ' + r.status)); }
                    return j;
                });
            }, function (e) {
                throw new Error(e && e.name === 'AbortError' ? 'The request timed out.' : 'Could not reach the server. Check your connection.');
            })
            .finally(function () { if (timer) { clearTimeout(timer); } });
    }
    function windowLabel(m) { return 'next ' + m + (m === 1 ? ' month' : ' months'); }
    function shorten(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }

    // ---- Section state: loading (skeleton) | ready | empty | error (message + Try again) ----
    var skeletons = {}, waitTimers = {};
    function setState(page, state) {
        page.setAttribute('data-state', state);
        page.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    }
    function showLoading(page, statusId) {
        var box = document.getElementById(statusId);
        if (skeletons[statusId] === undefined) { skeletons[statusId] = box.innerHTML; } else { box.innerHTML = skeletons[statusId]; }
        setState(page, 'loading');
        clearTimeout(waitTimers[statusId]);
        // The Render service sleeps when idle; after a few seconds say why it is slow
        waitTimers[statusId] = setTimeout(function () {
            var t = box.querySelector('.fc-wait-text');
            if (t && page.getAttribute('data-state') === 'loading') {
                t.textContent = 'The trend analysis service is starting up. This can take up to a minute.';
            }
        }, 5000);
    }
    function showReady(page, statusId) {
        clearTimeout(waitTimers[statusId]);
        document.getElementById(statusId).textContent = '';
        setState(page, 'ready');
    }
    function showMessage(page, statusId, kind, title, text, retry) {
        clearTimeout(waitTimers[statusId]);
        var box = document.getElementById(statusId);
        box.textContent = '';
        var e = el('div', 'cx-empty');
        var ic = el('span', 'cx-empty-icon' + (kind === 'error' ? ' is-bad' : ''));
        ic.setAttribute('aria-hidden', 'true');
        ic.appendChild(icon(kind === 'error' ? 'ph-cloud-slash' : 'ph-chart-line'));
        e.appendChild(ic);
        e.appendChild(el('strong', null, title));
        e.appendChild(el('span', null, text));
        if (retry) {
            var b = el('button', 'cx-btn cx-btn-outline');
            b.type = 'button';
            b.appendChild(icon('ph-arrow-clockwise'));
            b.appendChild(document.createTextNode(' Try again'));
            b.addEventListener('click', retry);
            e.appendChild(b);
        }
        box.appendChild(e);
        setState(page, kind);
    }

    // ---- Chart | Table toggle (remembered per section; storage may be blocked, so every access is guarded) ----
    function initToggle(pageId, key, fallback, onChange) {
        var page = document.getElementById(pageId);
        if (!page) { return; }
        var buttons = page.querySelectorAll('.fc-seg button');
        function saved() {
            try { var v = window.localStorage.getItem('ias_fc_view_' + key); if (v === 'chart' || v === 'table') { return v; } } catch (e) {}
            return fallback;
        }
        function set(v, remember) {
            page.setAttribute('data-view', v);
            Array.prototype.forEach.call(buttons, function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-view') === v ? 'true' : 'false'); });
            if (remember) { try { window.localStorage.setItem('ias_fc_view_' + key, v); } catch (e) {} }
            if (onChange) { onChange(v); }
        }
        Array.prototype.forEach.call(buttons, function (b) { b.addEventListener('click', function () { set(b.getAttribute('data-view'), true); }); });
        set(saved(), false);
    }
    // Sales table view shows one table at a time: By month | By category (remembered like the main toggle).
    function initSubToggle(pageId, key) {
        var page = document.getElementById(pageId);
        if (!page) { return; }
        var buttons = page.querySelectorAll('.fc-sub button');
        function set(v, remember) {
            page.setAttribute('data-sub', v);
            Array.prototype.forEach.call(buttons, function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-sub') === v ? 'true' : 'false'); });
            if (remember) { try { window.localStorage.setItem('ias_fc_sub_' + key, v); } catch (e) {} }
        }
        Array.prototype.forEach.call(buttons, function (b) { b.addEventListener('click', function () { set(b.getAttribute('data-sub'), true); }); });
        var v = 'monthly';
        try { var saved = window.localStorage.getItem('ias_fc_sub_' + key); if (saved === 'monthly' || saved === 'category') { v = saved; } } catch (e) {}
        set(v, false);
    }
    function resizeChart(name) { if (charts[name]) { charts[name].resize(); } }
    function dropChart(name) { if (charts[name]) { charts[name].destroy(); charts[name] = null; } }
    // Printing from the Table view shows a chart that never drew: it must draw at once, not grow in after the page is captured
    window.addEventListener('beforeprint', function () {
        Object.keys(charts).forEach(function (k) { if (charts[k]) { charts[k].options.animation = false; charts[k].update('none'); } });
    });

    // ---- Filter bars: a new choice applies at once; a custom range waits for Apply ----
    Array.prototype.forEach.call(document.querySelectorAll('form.fc-filters'), function (form) {
        var preset = form.querySelector('[data-fc-preset]'), dates = form.querySelector('[data-fc-dates]');
        form.addEventListener('change', function (e) {
            if (e.target.type === 'date') { return; }
            if (e.target === preset && preset.value === 'custom') {
                dates.hidden = false;
                dates.querySelector('input').focus();
                return;
            }
            if (preset.value === 'custom') {
                var ins = dates.querySelectorAll('input');
                if (!ins[0].value || !ins[1].value) { return; }
            } else {
                Array.prototype.forEach.call(dates.querySelectorAll('input'), function (i) { i.disabled = true; });
            }
            form.submit();
        });
    });

    // ---------------------------------------------------------------- Product demand
    function riskPill(a) {
        var wrap = el('span', 'fc-badges');
        var map = { high: ['High', 'out', 'ph-warning-octagon'], medium: ['Medium', 'low', 'ph-warning'], low: ['Low', 'ok', 'ph-check-circle'] };
        var m = map[a.stockout_risk];
        if (m) {
            var s = el('span', 'stock-status-pill ' + m[1]);
            s.appendChild(icon(m[2], true));
            s.appendChild(document.createTextNode(m[0]));
            if (a.message) { s.title = a.message; }
            wrap.appendChild(s);
        }
        if (a.overstock) {
            var o = el('span', 'cx-badge fc-badge-info', 'Overstock');
            if (a.message) { o.title = a.message; }
            wrap.appendChild(o);
        }
        if (!wrap.firstChild) { wrap.textContent = '—'; }
        return wrap;
    }
    function confidence(p) {
        var c = String(p.confidence || '');
        return c ? c.charAt(0).toUpperCase() + c.slice(1) : '—';
    }
    function renderDemand(data, acts, actsError, c) {
        var page = document.getElementById('fc-demand');
        var rows = data.products || [], total = data.total || [];
        var body = document.getElementById('fcDemandBody'), table = document.getElementById('fcDemandTable');
        var note = document.getElementById('fcDemandNote'), count = document.getElementById('fcDemandCount');
        var byMsku = {};
        if (acts) { (acts.products || []).forEach(function (a) { byMsku[a.msku] = a; }); }
        table.className = 'cx-table rep-table fc-table' + (acts ? '' : ' fc-noinv');

        if (!rows.length) {
            dropChart('demand');
            kpis('fcDemandKpis', []);
            showMessage(page, 'fcDemandStatus', 'empty', data.message ? sentence(data.message) : 'No projection for this selection.',
                'Try a longer sales history or another category.');
            return;
        }
        showReady(page, 'fcDemandStatus');
        count.hidden = false;
        count.textContent = '';
        count.appendChild(icon('ph-rows'));
        count.appendChild(document.createTextNode(' ' + rows.length + (rows.length === 1 ? ' product' : ' products')));

        // Summary
        var first = total[0] || {};
        var tiles = [
            { label: 'Projected units', value: num(sum(total, 'predicted_units')), sub: windowLabel(c.months) },
            { label: 'Projected revenue', value: peso(sum(total, 'predicted_revenue')), sub: windowLabel(c.months) },
            { label: 'Units MoM', value: pctText(first.units_mom_pct), tone: tone(first.units_mom_pct), icon: trendIcon(first.units_mom_pct),
              sub: (first.month || 'Next month') + ' vs last actual month' }
        ];
        if (acts) {
            var need = rows.filter(function (r) { var a = byMsku[r.msku]; return a && (a.action === 'order_now' || a.action === 'reorder'); }).length;
            var urgent = rows.filter(function (r) { var a = byMsku[r.msku]; return a && a.action === 'order_now'; }).length;
            tiles.push(need
                ? { label: 'Need ordering', value: String(need), tone: 'down', icon: 'ph-warning', sub: urgent + ' urgent (stockout risk high)' }
                : { label: 'Need ordering', value: '0', sub: 'Stock covers the projection' });
        }
        kpis('fcDemandKpis', tiles);

        // Table view
        body.textContent = '';
        rows.forEach(function (p) {
            var a = byMsku[p.msku];
            var tr = document.createElement('tr');
            if (a && (a.stockout_risk === 'high' || a.stockout_risk === 'medium')) { tr.className = 'is-risk-' + a.stockout_risk; }
            // Decision columns first; .fc-minor columns are left out of the phone cards (still on wider screens, exports and print)
            td(tr, 'Product', mainSub(p.product_name, p.category), 'rep-name');
            td(tr, 'Projected units', mainSub(num(p.forecast_units), num(p.forecast_units_low) + '–' + num(p.forecast_units_high) + ' likely'), 'num cx-num');
            td(tr, 'Projected revenue', peso(p.forecast_revenue), 'num cx-num');
            td(tr, 'Trend', change(has(p.trend_pct) ? p.trend_pct : null), 'num cx-num');
            td(tr, 'Stock', a ? num(a.stock_on_hand) : '—', 'num cx-num fc-inv');
            td(tr, 'Days left', a && has(a.days_of_inventory) ? num(a.days_of_inventory) + (a.days_of_inventory === 1 ? ' day' : ' days') : '—', 'num cx-num fc-inv');
            td(tr, 'Stockout risk', a ? riskPill(a) : '—', 'fc-inv');
            var order = '—';
            if (a && a.recommended_order_qty > 0) { order = el('strong', null, num(a.recommended_order_qty)); }
            else if (a && a.on_order > 0) { order = el('span', 'cx-badge fc-badge-info', 'On order: ' + num(a.on_order)); if (a.message) { order.title = a.message; } }
            td(tr, 'Order qty', order, 'num cx-num fc-inv');
            td(tr, 'Sold in period', has(p.range_units) ? num(p.range_units) : '—', 'num cx-num fc-minor');
            var conf = td(tr, 'Confidence', confidence(p), 'fc-minor');
            if (p.model_reliability) { conf.title = 'Model reliability: ' + p.model_reliability; }
            td(tr, 'Safety stock', a ? num(a.safety_stock) : '—', 'num cx-num fc-inv fc-minor');
            td(tr, 'Reorder point', a ? num(a.reorder_point) : '—', 'num cx-num fc-inv fc-minor');
            body.appendChild(tr);
        });

        // Note under the table
        note.textContent = '';
        if (acts) {
            var asOf = acts.stock_as_of ? new Date(acts.stock_as_of) : null;
            var parts = ['Stock as of ' + (asOf && !isNaN(asOf) ? asOf.toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }) : 'unknown'),
                         'lead time assumed ' + ((acts.assumptions || {}).lead_time_days_default || '?') + ' days (no supplier data yet)',
                         'order quantity covers ' + ((acts.assumptions || {}).review_days || '?') + ' days of demand'];
            note.textContent = parts.join(' · ') + '.';
            if (acts.stock_stale) {
                note.appendChild(el('strong', 'fc-down', ' The stock snapshot is out of date, so stockout and order alerts may be wrong.'));
            }
        } else if (c.actionsWanted) {
            note.textContent = (actsError ? sentence(actsError).replace(/\.$/, '') : 'Stock levels are not connected to the trend analysis yet') +
                '. Reorder point, safety stock, days left and order quantity appear here once they are.';
        }

        // Chart view: average units per month, last 3 months vs projection
        var top = rows.slice(0, 10), narrow = window.innerWidth < 600;
        var labels = top.map(function (r) { return shorten(r.product_name, narrow ? 22 : 38); });
        var past = top.map(function (r) { return Math.round(r.last_3m_units / 3 * 10) / 10; });
        var fut = top.map(function (r) { return Math.round(r.forecast_units / c.months * 10) / 10; });
        var canvas = document.getElementById('fcDemandChart');
        canvas.setAttribute('aria-label', 'Bar chart of the top ' + top.length + ' products: average units per month over the last 3 months next to the projected average. The Table view lists the same figures.');
        dropChart('demand');
        if (window.Chart) {
            charts.demand = new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: { labels: labels, datasets: [
                    { label: 'Last 3 months', data: past, backgroundColor: C.green6, borderRadius: 4, categoryPercentage: 0.72, barPercentage: 0.9 },
                    { label: 'Projected', data: fut, backgroundColor: C.yellow, borderColor: C.yellowEdge, borderWidth: 1.5, borderRadius: 4, categoryPercentage: 0.72, barPercentage: 0.9 }
                ] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: reduceMotion ? false : { duration: 400 },
                    interaction: { mode: 'index', axis: 'y', intersect: false },
                    plugins: {
                        legend: { position: 'top', align: 'start', labels: { boxWidth: 14, boxHeight: 10, padding: 16 } },
                        tooltip: { callbacks: {
                            title: function (items) { return top[items[0].dataIndex].product_name; },
                            label: function (it) { return ' ' + it.dataset.label + ': ' + num(it.parsed.x) + ' units / month'; }
                        } }
                    },
                    // every product name stays on the axis, also at the shorter printed height
                    scales: { x: { beginAtZero: true, title: { display: true, text: 'Units per month (average)' }, grid: { color: C.grid } },
                              y: { grid: { display: false }, ticks: { autoSkip: false } } } },
                plugins: [legendGap]
            });
        }
    }
    function loadDemand() {
        var page = document.getElementById('fc-demand'), c = cfg.demand;
        c.actionsWanted = !!cfg.actions;
        showLoading(page, 'fcDemandStatus');
        var demandReq = get('demand', c, '&top=50');
        var actsReq = cfg.actions
            ? get('actions', c, '&top=300').then(function (a) { return { acts: a }; }, function (e) { return { acts: null, err: e.message }; })
            : Promise.resolve({ acts: null });
        Promise.all([demandReq, actsReq]).then(function (r) { renderDemand(r[0], r[1].acts, r[1].err, c); }).catch(function (e) {
            showMessage(page, 'fcDemandStatus', 'error', 'Couldn’t load the product trends', sentence(e.message), loadDemand);
        });
    }

    // ---------------------------------------------------------------- Sales
    function monthCell(month, projected) {
        var f = document.createDocumentFragment();
        f.appendChild(el('strong', null, month));
        f.appendChild(el('span', 'fc-type ' + (projected ? 'is-proj' : 'is-actual'), projected ? 'Projected' : 'Actual'));
        return f;
    }
    function renderSales(data, c) {
        var page = document.getElementById('fc-sales');
        var total = data.total || [], hist = data.history || [], cats = data.by_category || [], g = data.growth || {};
        if (!hist.length && !total.length) {
            dropChart('sales');
            kpis('fcRevKpis', []);
            showMessage(page, 'fcRevStatus', 'empty', data.message ? sentence(data.message) : 'No sales data for this selection.',
                'Try a longer sales history.');
            return;
        }
        showReady(page, 'fcRevStatus');
        var projected = sum(total, 'predicted_revenue');
        if (!hist.length) {
            document.getElementById('fcRevSub').textContent += ' · no sales recorded in this period';
        }

        var first = total[0] || {};
        var vs = (first.month || 'Next month') + ' vs ' + (g.last_actual_month || 'last actual month');
        var tiles = total.length ? [
            { label: 'Projected sales', value: peso(projected), sub: windowLabel(c.months) },
            { label: 'Projected units', value: num(sum(total, 'predicted_units')), sub: windowLabel(c.months) },
            { label: 'Sales MoM', value: pctText(first.revenue_mom_pct), tone: tone(first.revenue_mom_pct), icon: trendIcon(first.revenue_mom_pct), sub: vs },
            { label: 'Units MoM', value: pctText(first.units_mom_pct), tone: tone(first.units_mom_pct), icon: trendIcon(first.units_mom_pct), sub: vs }
        ] : [];
        if (total.length && g.last_actual_month) {
            tiles.push({ label: 'Sales in ' + g.last_actual_month, value: has(g.last_actual_revenue) ? peso(g.last_actual_revenue) : '—',
                         sub: has(g.revenue_mom_pct) ? pctText(g.revenue_mom_pct) + ' vs the month before' : 'last actual month' });
        }
        kpis('fcRevKpis', tiles);

        // Chart view: actual line, then the projection (dashed) inside its likely-range band
        var labels = hist.map(function (r) { return r.month; }).concat(total.map(function (r) { return r.month; }));
        var blanks = function (list) { return list.map(function () { return null; }); };
        var actual = hist.map(function (r) { return r.actual_revenue; }).concat(blanks(total));
        var fcast = blanks(hist).concat(total.map(function (r) { return r.predicted_revenue; }));
        var low = blanks(hist).concat(total.map(function (r) { return r.revenue_confidence_low; }));
        var high = blanks(hist).concat(total.map(function (r) { return r.revenue_confidence_high; }));
        var join = -1;
        if (hist.length && total.length) {                          // join the lines (and start the range band) at the last actual month
            join = hist.length - 1;
            var lastActual = hist[join].actual_revenue;
            fcast[join] = lastActual; low[join] = lastActual; high[join] = lastActual;
        }
        var startX = hist.length && total.length ? join : (total.length ? 0 : -1);
        // Light tint behind the projected months, labelled when there is room
        var projectedArea = { id: 'fcProjected', beforeDatasetsDraw: function (chart) {
            if (startX < 0) { return; }
            var a = chart.chartArea, ctx = chart.ctx, x = chart.scales.x.getPixelForValue(startX);
            ctx.save();
            ctx.fillStyle = C.projArea;
            ctx.fillRect(x, a.top, a.right - x, a.bottom - a.top);
            ctx.strokeStyle = '#c7d1c3'; ctx.setLineDash([4, 4]); ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(x, a.top); ctx.lineTo(x, a.bottom); ctx.stroke();
            ctx.setLineDash([]);
            ctx.font = '600 12px ' + Chart.defaults.font.family;
            var text = 'Projected', w = ctx.measureText(text).width;
            if (a.right - x > w + 16) {
                ctx.textAlign = 'left'; ctx.textBaseline = 'top';
                ctx.strokeStyle = 'rgba(255,255,255,.92)'; ctx.lineWidth = 4; ctx.lineJoin = 'round';   // halo: readable over the line
                ctx.strokeText(text, x + 8, a.top + 6);
                ctx.fillStyle = C.yellowEdge;
                ctx.fillText(text, x + 8, a.top + 6);
            }
            ctx.restore();
        } };
        var canvas = document.getElementById('fcRevChart');
        canvas.setAttribute('aria-label', 'Line chart of monthly sales' +
            (hist.length ? ': actual ' + hist[0].month + ' to ' + hist[hist.length - 1].month : '') +
            (total.length ? ', projected to ' + total[total.length - 1].month + ' (' + peso(projected) + ' over the ' + windowLabel(c.months) + ')' : '') +
            '. The Table view lists the same figures.');
        dropChart('sales');
        if (window.Chart) {
            charts.sales = new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: { labels: labels, datasets: [
                    { label: 'Actual sales', data: actual, borderColor: C.green, backgroundColor: C.greenFill, fill: true, tension: 0.3, borderWidth: 2.5,
                      pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: '#fff', pointBorderWidth: 2 },
                    { label: 'Projected', data: fcast, borderColor: C.yellowEdge, borderDash: [6, 4], fill: false, tension: 0.3, borderWidth: 2.5,
                      pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: '#fff', pointBorderWidth: 2 },
                    { label: '_low', data: low, borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: false, tension: 0.3 },
                    { label: 'Likely range', data: high, borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, backgroundColor: C.band, fill: '-1', tension: 0.3 }
                ] },
                options: { responsive: true, maintainAspectRatio: false, animation: reduceMotion ? false : { duration: 400 },
                    interaction: { mode: 'index', intersect: false },
                    layout: { padding: { top: 4 } },
                    plugins: {
                        legend: { position: 'top', align: 'start', labels: { boxWidth: 14, boxHeight: 10, padding: 16, filter: function (item) { return item.text.charAt(0) !== '_'; } } },
                        tooltip: {
                            filter: function (it) { return it.datasetIndex !== 2 && it.parsed.y !== null && !(it.dataIndex === join && it.datasetIndex > 0); },
                            callbacks: { label: function (it) {
                                if (it.datasetIndex === 3) { return ' Likely range: ' + peso(it.chart.data.datasets[2].data[it.dataIndex]) + ' – ' + peso(it.parsed.y); }
                                return ' ' + it.dataset.label + ': ' + peso(it.parsed.y);
                            } }
                        }
                    },
                    scales: { y: { beginAtZero: true, grid: { color: C.grid }, ticks: { maxTicksLimit: 6, callback: function (v) { return pesoShort(v); } } },
                              x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } } } },
                plugins: [legendGap, projectedArea]
            });
        }

        // Table view: months (actual, then projected) with MoM
        var body = document.getElementById('fcRevBody');
        body.textContent = '';
        function pctChange(now, before) { return before > 0 ? Math.round((now / before - 1) * 1000) / 10 : null; }
        hist.forEach(function (r, i) {
            var tr = document.createElement('tr'), prev = i ? hist[i - 1] : null;
            td(tr, 'Month', monthCell(r.month, false), 'rep-name');
            td(tr, 'Units', num(r.actual_units), 'num cx-num');
            td(tr, 'Sales', peso(r.actual_revenue, 2), 'num cx-num');
            td(tr, 'Sales MoM', change(prev ? pctChange(r.actual_revenue, prev.actual_revenue) : null), 'num cx-num');
            td(tr, 'Units MoM', change(prev ? pctChange(r.actual_units, prev.actual_units) : null), 'num cx-num');
            td(tr, 'Likely range', '—', 'num cx-num');
            body.appendChild(tr);
        });
        total.forEach(function (r) {
            var tr = document.createElement('tr');
            tr.className = 'is-proj';
            td(tr, 'Month', monthCell(r.month, true), 'rep-name');
            td(tr, 'Units', num(r.predicted_units), 'num cx-num');
            td(tr, 'Sales', peso(r.predicted_revenue, 2), 'num cx-num');
            td(tr, 'Sales MoM', change(r.revenue_mom_pct), 'num cx-num');
            td(tr, 'Units MoM', change(r.units_mom_pct), 'num cx-num');
            td(tr, 'Likely range', peso(r.revenue_confidence_low) + ' – ' + peso(r.revenue_confidence_high), 'num cx-num');
            body.appendChild(tr);
        });

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
        if (!list.length) { emptyRow(cb, 5, 'No category breakdown for this selection.'); }
        list.forEach(function (k) {
            var tr = document.createElement('tr');
            td(tr, 'Category', mainSub(k.category, k.group || ''), 'rep-name');
            td(tr, 'Projected units', num(k.units), 'num cx-num');
            td(tr, 'Projected sales', peso(k.revenue, 2), 'num cx-num');
            td(tr, 'Sales MoM (next month)', change(k.rmom), 'num cx-num');
            td(tr, 'Units MoM (next month)', change(k.umom), 'num cx-num');
            cb.appendChild(tr);
        });
    }
    function loadSales() {
        var page = document.getElementById('fc-sales');
        showLoading(page, 'fcRevStatus');
        get('revenue', cfg.sales).then(function (d) { renderSales(d, cfg.sales); }).catch(function (e) {
            showMessage(page, 'fcRevStatus', 'error', 'Couldn’t load the sales trends', sentence(e.message), loadSales);
        });
    }

    // ---------------------------------------------------------------- wiring
    if (cfg.which !== 'sales' && document.getElementById('fc-demand')) {
        initToggle('fc-demand', 'demand', 'table', function (v) { if (v === 'chart') { resizeChart('demand'); } });
        loadDemand();
    }
    if (cfg.which !== 'demand' && document.getElementById('fc-sales')) {
        initToggle('fc-sales', 'sales', 'chart', function (v) { if (v === 'chart') { resizeChart('sales'); } });
        initSubToggle('fc-sales', 'sales');
        loadSales();
    }
})();
</script>
HTML;
}
