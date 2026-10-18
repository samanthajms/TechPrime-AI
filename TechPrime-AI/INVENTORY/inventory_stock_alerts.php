<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$alerts = pos_stock_alerts($db);
// Preselected level, e.g. from the dashboard tiles: all | out | critical | low | running (critical + low).
$initialStatus = (string)($_GET['status'] ?? 'all');
if (!in_array($initialStatus, ['all', 'out', 'critical', 'low', 'running'], true)) {
    $initialStatus = 'all';
}

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Stock Alerts',
    'active' => 'stocks',
    'active_child' => 'alerts',
    'heading' => 'Stock Alerts',
    'subtitle' => 'Products at 15 units or fewer · restock them through Stock In',
    'actions' => '<span class="alerts-live" id="alertsLive" title="Refreshes every 30 seconds">Live stock</span>'
        . '<a class="cx-btn cx-btn-primary" href="inventory_stock_in.php"><i class="ph ph-barcode" aria-hidden="true"></i> Stock In</a>',
]);
?>
        <div class="alerts-page">
            <div class="level-tiles" id="statusToggle" role="group" aria-label="Filter by alert level">
                <button type="button" class="alert-tile level-tile is-all active" data-status="all" aria-pressed="true">
                    <span class="level-tile-icon" aria-hidden="true"><i class="ph ph-bell"></i></span>
                    <span class="level-tile-body">
                        <strong class="cx-num" id="statAll"><?php echo count($alerts['items']); ?></strong>
                        <span class="level-tile-label">All alerts</span>
                        <span class="level-tile-hint">15 or fewer units</span>
                    </span>
                </button>
                <button type="button" class="alert-tile level-tile is-out" data-status="out" aria-pressed="false">
                    <span class="level-tile-icon" aria-hidden="true"><i class="ph ph-x-circle"></i></span>
                    <span class="level-tile-body">
                        <strong class="cx-num" id="statOut"><?php echo (int)$alerts['out']; ?></strong>
                        <span class="level-tile-label">Out of stock</span>
                        <span class="level-tile-hint">None left · cannot be sold</span>
                    </span>
                </button>
                <button type="button" class="alert-tile level-tile is-critical" data-status="critical" aria-pressed="false">
                    <span class="level-tile-icon" aria-hidden="true"><i class="ph ph-warning-circle"></i></span>
                    <span class="level-tile-body">
                        <strong class="cx-num" id="statCritical"><?php echo (int)$alerts['critical']; ?></strong>
                        <span class="level-tile-label">Critical</span>
                        <span class="level-tile-hint">5 or fewer units left</span>
                    </span>
                </button>
                <button type="button" class="alert-tile level-tile is-low" data-status="low" aria-pressed="false">
                    <span class="level-tile-icon" aria-hidden="true"><i class="ph ph-warning"></i></span>
                    <span class="level-tile-body">
                        <strong class="cx-num" id="statLow"><?php echo (int)$alerts['low']; ?></strong>
                        <span class="level-tile-label">Low stock</span>
                        <span class="level-tile-hint">6–15 units left</span>
                    </span>
                </button>
            </div>

            <section class="cx-card" aria-labelledby="alertListTitle">
                <h2 class="cx-sr" id="alertListTitle">Stock alert list</h2>
                <div class="alerts-toolbar">
                    <div class="search-wrap">
                        <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                        <label class="cx-sr" for="searchInput">Search alerts</label>
                        <input type="text" id="searchInput" placeholder="Search name, category or barcode" autocomplete="off">
                        <button type="button" id="scanBtn" class="btn-scan" title="Focus this field, then use the barcode scanner">
                            <i class="ph ph-barcode" aria-hidden="true"></i> Scan
                        </button>
                    </div>
                    <div class="alerts-toolbar-side">
                        <label class="sort-wrap">
                            <span>Sort</span>
                            <select id="sortBy">
                                <option value="stock_asc" selected>Stock: low to high</option>
                                <option value="stock_desc">Stock: high to low</option>
                                <option value="name_asc">Name: A–Z</option>
                                <option value="name_desc">Name: Z–A</option>
                                <option value="price_asc">Price: low to high</option>
                                <option value="price_desc">Price: high to low</option>
                            </select>
                        </label>
                        <button type="button" id="resetFilters" class="cx-btn cx-btn-ghost" disabled>
                            <i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i> Reset
                        </button>
                    </div>
                </div>

                <div class="cx-table-wrap" id="alertTableWrap">
                    <table class="cx-table alerts-table">
                        <thead><tr>
                            <th scope="col">Product</th>
                            <th scope="col" class="col-barcode">Barcode</th>
                            <th scope="col" class="col-stock">Stock</th>
                            <th scope="col" class="col-status">Status</th>
                            <th scope="col" class="col-price num">Price</th>
                        </tr></thead>
                        <tbody id="alertRows"></tbody>
                    </table>
                </div>
                <div id="emptyState" class="cx-empty" hidden>
                    <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-magnifying-glass"></i></span>
                    <strong id="emptyTitle">No products match your filters</strong>
                    <span id="emptyText">Try another search or alert level.</span>
                </div>

                <div class="pagination-bar">
                    <div class="pg-info" id="pgInfo" aria-live="polite"></div>
                    <div class="pg-controls" id="pgControls"></div>
                </div>
            </section>
        </div>
<?php
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$script = '<script src="../CASHIER/stock_alerts.js?v=3"></script>'
    . '<script>var INITIAL_ALERTS = ' . $alertsJson . '; var INITIAL_STATUS = ' . json_encode($initialStatus) . ';</script>'
    . <<<'SCRIPT'
<script>
(function () {
    'use strict';
    var SA = CashierStockAlerts;
    var PAGE_SIZE = 10;
    var DEFAULT_SORT = 'stock_asc';
    var state = { status: INITIAL_STATUS, sort: DEFAULT_SORT, search: '', page: 1 };
    var items = [];

    var searchInput = document.getElementById('searchInput');
    var sortBy = document.getElementById('sortBy');
    var resetBtn = document.getElementById('resetFilters');
    var tableWrap = document.getElementById('alertTableWrap');

    function getFiltered() {
        var q = state.search.trim().toLowerCase();
        var list = items.filter(function (p) {
            if (state.status === 'running') {
                if (p.status !== 'critical' && p.status !== 'low') return false;
            } else if (state.status !== 'all' && p.status !== state.status) {
                return false;
            }
            if (q) {
                var hay = (p.name + ' ' + p.category + ' ' + p.barcode + ' ' + SA.barcodeLabel(p.barcode)).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
        list.sort(function (a, b) {
            switch (state.sort) {
                case 'stock_desc': return b.stock - a.stock || a.name.localeCompare(b.name);
                case 'name_asc': return a.name.localeCompare(b.name);
                case 'name_desc': return b.name.localeCompare(a.name);
                case 'price_asc': return a.price - b.price;
                case 'price_desc': return b.price - a.price;
                default: return a.stock - b.stock || a.name.localeCompare(b.name);
            }
        });
        return list;
    }

    function rowHtml(p) {
        var barcode = p.barcode
            ? '<span class="barcode-code">' + SA.esc(SA.barcodeLabel(p.barcode)) + '</span>'
            : '<span class="barcode-missing"><i class="ph ph-prohibit" aria-hidden="true"></i> None</span>';
        var pct = Math.max(0, Math.min(100, Math.round(p.stock / 15 * 100)));
        return '<tr class="is-' + SA.esc(p.status) + '">' +
            '<td data-label="Product"><div class="stocks-pname">' + SA.esc(p.name || ('Product #' + p.id)) + '</div>' +
            '<span class="stocks-pcat">' + SA.esc(p.category) + '</span></td>' +
            '<td data-label="Barcode">' + barcode + '</td>' +
            '<td data-label="Stock"><div class="qty-cell"><span class="stocks-qty' + SA.qtyClass(p.status) + '">' + p.stock + '</span>' +
                '<span class="qty-meter ' + SA.esc(p.status) + '" aria-hidden="true"><i style="width:' + pct + '%"></i></span></div></td>' +
            '<td data-label="Status">' + SA.pill(p.status) + '</td>' +
            '<td data-label="Price" class="num price-tag cx-num">' + SA.peso(p.price) + '</td>' +
        '</tr>';
    }

    function renderPagination(totalPages) {
        var WINDOW = 20;
        var start = Math.floor((state.page - 1) / WINDOW) * WINDOW + 1;
        var end = Math.min(start + WINDOW - 1, totalPages);
        var html = '';
        if (start > 1) html += '<button type="button" data-page="' + (start - 1) + '" aria-label="Previous page set">&hellip;</button>';
        html += '<button type="button" data-page="' + (state.page - 1) + '"' + (state.page <= 1 ? ' disabled' : '') + ' aria-label="Previous page"><i class="ph ph-caret-left" aria-hidden="true"></i></button>';
        for (var i = start; i <= end; i++) {
            html += '<button type="button" data-page="' + i + '"' + (i === state.page ? ' class="active" aria-current="page"' : '') + '>' + i + '</button>';
        }
        html += '<button type="button" data-page="' + (state.page + 1) + '"' + (state.page >= totalPages ? ' disabled' : '') + ' aria-label="Next page"><i class="ph ph-caret-right" aria-hidden="true"></i></button>';
        if (end < totalPages) html += '<button type="button" data-page="' + (end + 1) + '" aria-label="Next page set">&hellip;</button>';
        document.getElementById('pgControls').innerHTML = html;
    }

    function render() {
        var filtered = getFiltered();
        var totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
        if (state.page > totalPages) state.page = totalPages;
        var startIdx = (state.page - 1) * PAGE_SIZE;
        var pageItems = filtered.slice(startIdx, startIdx + PAGE_SIZE);

        document.getElementById('alertRows').innerHTML = pageItems.map(rowHtml).join('');
        tableWrap.hidden = pageItems.length === 0;
        document.getElementById('emptyState').hidden = pageItems.length !== 0;
        var allClear = items.length === 0;
        document.getElementById('emptyTitle').textContent = allClear ? 'All products are well stocked' : 'No products match your filters';
        document.getElementById('emptyText').textContent = allClear ? 'Nothing is at 15 units or fewer right now.' : 'Try another search or alert level.';
        document.getElementById('pgInfo').textContent = filtered.length === 0
            ? 'No products'
            : 'Showing ' + (startIdx + 1) + '–' + (startIdx + pageItems.length) + ' of ' + filtered.length;
        renderPagination(totalPages);
        resetBtn.disabled = state.status === 'all' && state.sort === DEFAULT_SORT && state.search === '';
        document.querySelectorAll('#statusToggle .alert-tile').forEach(function (b) {
            var st = b.getAttribute('data-status');
            var on = st === state.status || (state.status === 'running' && (st === 'critical' || st === 'low'));
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function setData(d, at) {
        items = d.items.map(function (p) { p.price = Number(p.price); return p; });
        document.getElementById('statAll').textContent = items.length;
        document.getElementById('statLow').textContent = d.low;
        document.getElementById('statCritical').textContent = d.critical;
        document.getElementById('statOut').textContent = d.out;
        SA.markLive(document.getElementById('alertsLive'), at);
        render();
    }

    searchInput.addEventListener('input', function () { state.search = searchInput.value; state.page = 1; render(); });
    searchInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    document.getElementById('scanBtn').addEventListener('click', function () {
        searchInput.focus();
        searchInput.select();
        if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Ready to scan — point your barcode scanner at the field.', 'success', 0);
    });
    sortBy.addEventListener('change', function () { state.sort = sortBy.value; state.page = 1; render(); });
    document.getElementById('statusToggle').addEventListener('click', function (e) {
        var btn = e.target.closest('.alert-tile');
        if (!btn) return;
        state.status = btn.getAttribute('data-status');
        state.page = 1;
        render();
    });
    resetBtn.addEventListener('click', function () {
        state.status = 'all';
        state.sort = sortBy.value = DEFAULT_SORT;
        state.search = searchInput.value = '';
        state.page = 1;
        render();
    });
    document.getElementById('pgControls').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-page]');
        if (!btn || btn.disabled) return;
        state.page = parseInt(btn.getAttribute('data-page'), 10);
        render();
    });

    setData(INITIAL_ALERTS, new Date());
    SA.sync('../backend/api/stock_alerts.php', setData, function () { SA.markStale(document.getElementById('alertsLive')); });
})();
</script>
SCRIPT;

staff_page_end($script);
