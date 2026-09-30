<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('cashier');

$alerts = pos_stock_alerts($db);

staff_page_start([
    'role' => 'cashier',
    'title' => 'Stock Alerts',
    'active' => 'alerts',
    'heading' => 'Stock Alerts',
    'subtitle' => 'Low, critical and out-of-stock products · read-only · restocking is handled by the Inventory Custodian',
    'extra_head' => '<link rel="stylesheet" href="cashier.css?v=5">',
]);
?>
        <div class="cash-page">
            <div class="inv-stats-grid">
                <div class="inv-stat-card stat-dark">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">All Alerts</div>
                            <div class="inv-stat-num" id="statAll"><?php echo count($alerts['items']); ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-bell"></i></div>
                    </div>
                    <div class="inv-stat-foot">Products with 15 or fewer units</div>
                </div>
                <div class="inv-stat-card stat-yellow">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Low Stock</div>
                            <div class="inv-stat-num" id="statLow"><?php echo (int)$alerts['low']; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                    <div class="inv-stat-foot">6–15 units left</div>
                </div>
                <div class="inv-stat-card stat-red">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Critical Stock</div>
                            <div class="inv-stat-num" id="statCritical"><?php echo (int)$alerts['critical']; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-exclamation-circle"></i></div>
                    </div>
                    <div class="inv-stat-foot">5 or fewer units left</div>
                </div>
                <div class="inv-stat-card stat-red">
                    <div class="inv-stat-top">
                        <div>
                            <div class="inv-stat-label">Out of Stock</div>
                            <div class="inv-stat-num" id="statOut"><?php echo (int)$alerts['out']; ?></div>
                        </div>
                        <div class="inv-stat-icon"><i class="fas fa-times-circle"></i></div>
                    </div>
                    <div class="inv-stat-foot">None left · cannot be sold</div>
                </div>
            </div>

            <section class="cash-panel" aria-label="Stock alert list">
                <div class="cash-panel-body">
                    <div class="stocks-toolbar">
                        <div class="search-wrap">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by name, category or barcode..." autocomplete="off">
                            <button type="button" id="scanBtn" class="btn btn-outline btn-scan" title="Focus this field, then use the barcode scanner">
                                <i class="fas fa-barcode"></i> Scan
                            </button>
                        </div>
                        <div class="toolbar-actions">
                            <span class="alerts-live" id="alertsLive" title="Refreshes every 30 seconds">Live stock</span>
                            <div class="stocks-filter-wrap">
                                <button type="button" id="filterToggleBtn" class="stocks-filter-btn" aria-expanded="false" aria-controls="stocksFilterPanel">
                                    <i class="fas fa-filter"></i> Filter <i class="fas fa-chevron-down"></i> <span class="filter-dot" aria-hidden="true"></span>
                                </button>
                                <div id="stocksFilterPanel" class="stocks-filter-panel" role="dialog" aria-label="Stock alert filters">
                                    <h4 class="stocks-filter-panel-title">Filter Stock Alerts</h4>
                                    <div>
                                        <span class="filter-title">Alert Level</span>
                                        <div class="status-toggle" id="statusToggle">
                                            <button type="button" class="status-btn active" data-status="all">All Alerts <span class="cnt" data-cnt="all"></span></button>
                                            <button type="button" class="status-btn" data-status="out">Out of Stock <span class="cnt" data-cnt="out"></span></button>
                                            <button type="button" class="status-btn" data-status="critical">Critical Stock (&le; 5) <span class="cnt" data-cnt="critical"></span></button>
                                            <button type="button" class="status-btn" data-status="low">Low Stock (6–15) <span class="cnt" data-cnt="low"></span></button>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="filter-title">Sort By</span>
                                        <select id="sortBy" class="form-control">
                                            <option value="stock_asc" selected>Stock Quantity (Low to High)</option>
                                            <option value="stock_desc">Stock Quantity (High to Low)</option>
                                            <option value="name_asc">Alphabetical (A-Z)</option>
                                            <option value="name_desc">Alphabetical (Z-A)</option>
                                            <option value="price_asc">Price (Low to High)</option>
                                            <option value="price_desc">Price (High to Low)</option>
                                        </select>
                                    </div>
                                    <button type="button" id="resetFilters" class="btn btn-outline btn-reset">Reset Filters</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="stocks-table-wrap">
                        <table class="stocks-table wide">
                            <thead><tr>
                                <th>Product Name</th>
                                <th class="col-barcode">Barcode</th>
                                <th class="col-stock">Stock</th>
                                <th class="col-status">Status</th>
                                <th class="col-price">Price</th>
                            </tr></thead>
                            <tbody id="alertRows"></tbody>
                        </table>
                    </div>
                    <div id="emptyState" class="empty-state-row" style="margin-top:14px;" hidden>No products match your filters.</div>

                    <div class="pagination-bar">
                        <div class="pg-info" id="pgInfo"></div>
                        <div class="pg-controls" id="pgControls"></div>
                    </div>
                </div>
            </section>
        </div>
<?php
$alertsJson = json_encode($alerts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$script = '<script src="stock_alerts.js?v=1"></script>'
    . '<script>var INITIAL_ALERTS = ' . $alertsJson . ';</script>'
    . <<<'SCRIPT'
<script>
(function () {
    'use strict';
    var SA = CashierStockAlerts;
    var PAGE_SIZE = 10;
    var state = { status: 'all', sort: 'stock_asc', search: '', page: 1 };
    var items = [];

    var searchInput = document.getElementById('searchInput');
    var sortBy = document.getElementById('sortBy');
    var filterBtn = document.getElementById('filterToggleBtn');
    var filterPanel = document.getElementById('stocksFilterPanel');

    function getFiltered() {
        var q = state.search.trim().toLowerCase();
        var list = items.filter(function (p) {
            if (state.status !== 'all' && p.status !== state.status) return false;
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
            : '<span class="barcode-missing"><i class="fas fa-minus-circle"></i> None</span>';
        return '<tr>' +
            '<td><div class="stocks-pname">' + SA.esc(p.name || ('Product #' + p.id)) + '</div>' +
            '<span class="stocks-pcat">' + SA.esc(p.category) + '</span></td>' +
            '<td>' + barcode + '</td>' +
            '<td><span class="stocks-qty' + SA.qtyClass(p.status) + '">' + p.stock + '</span></td>' +
            '<td>' + SA.pill(p.status) + '</td>' +
            '<td class="price-tag">' + SA.peso(p.price) + '</td>' +
        '</tr>';
    }

    function renderPagination(totalPages) {
        var WINDOW = 20;
        var start = Math.floor((state.page - 1) / WINDOW) * WINDOW + 1;
        var end = Math.min(start + WINDOW - 1, totalPages);
        var html = '';
        if (start > 1) html += '<button type="button" data-page="' + (start - 1) + '" title="Previous page set">&lt;</button>';
        html += '<button type="button" data-page="' + (state.page - 1) + '"' + (state.page <= 1 ? ' disabled' : '') + '>Prev</button>';
        for (var i = start; i <= end; i++) {
            html += '<button type="button" data-page="' + i + '"' + (i === state.page ? ' class="active"' : '') + '>' + i + '</button>';
        }
        html += '<button type="button" data-page="' + (state.page + 1) + '"' + (state.page >= totalPages ? ' disabled' : '') + '>Next</button>';
        if (end < totalPages) html += '<button type="button" data-page="' + (end + 1) + '" title="Next page set">&gt;</button>';
        document.getElementById('pgControls').innerHTML = html;
    }

    function render() {
        var filtered = getFiltered();
        var totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
        if (state.page > totalPages) state.page = totalPages;
        var startIdx = (state.page - 1) * PAGE_SIZE;
        var pageItems = filtered.slice(startIdx, startIdx + PAGE_SIZE);

        document.getElementById('alertRows').innerHTML = pageItems.map(rowHtml).join('');
        document.querySelector('.stocks-table-wrap').hidden = pageItems.length === 0;
        document.getElementById('emptyState').hidden = pageItems.length !== 0;
        document.getElementById('emptyState').textContent = items.length === 0
            ? 'All products are well stocked.' : 'No products match your filters.';
        document.getElementById('pgInfo').textContent = filtered.length === 0
            ? 'No products'
            : 'Showing ' + (startIdx + 1) + '–' + (startIdx + pageItems.length) + ' of ' + filtered.length;
        renderPagination(totalPages);
        filterBtn.classList.toggle('has-active', state.status !== 'all' || state.sort !== 'stock_asc');
        document.querySelectorAll('#statusToggle .status-btn').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-status') === state.status);
        });
    }

    function setData(d, at) {
        items = d.items.map(function (p) { p.price = Number(p.price); return p; });
        var counts = { all: items.length, out: d.out, critical: d.critical, low: d.low };
        document.querySelectorAll('[data-cnt]').forEach(function (el) { el.textContent = counts[el.getAttribute('data-cnt')]; });
        document.getElementById('statAll').textContent = counts.all;
        document.getElementById('statLow').textContent = d.low;
        document.getElementById('statCritical').textContent = d.critical;
        document.getElementById('statOut').textContent = d.out;
        document.getElementById('alertsLive').textContent = 'Live · updated ' + SA.timeLabel(at);
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
        var btn = e.target.closest('.status-btn');
        if (!btn) return;
        state.status = btn.getAttribute('data-status');
        state.page = 1;
        render();
    });
    document.getElementById('resetFilters').addEventListener('click', function () {
        state.status = 'all';
        state.sort = sortBy.value = 'stock_asc';
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

    function setFilterOpen(open) {
        filterPanel.classList.toggle('open', open);
        filterBtn.classList.toggle('open', open);
        filterBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    filterBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        setFilterOpen(!filterPanel.classList.contains('open'));
    });
    filterPanel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { setFilterOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setFilterOpen(false); });

    setData(INITIAL_ALERTS, new Date());
    SA.sync('../backend/api/stock_alerts.php', setData);
})();
</script>
SCRIPT;

staff_page_end($script);
