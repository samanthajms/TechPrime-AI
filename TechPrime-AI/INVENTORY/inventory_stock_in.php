<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];
$recent = pos_recent_stock_ins($db, $uid, 10);
$supplierCatalog = pos_supplier_catalog($db);

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Stock In',
    'active' => 'stockin',
    'heading' => 'Stock In',
    'subtitle' => 'Receive deliveries by scanning each item',
    'extra_head' => <<<'EXTRA'
<style>
/* Panel / table / pill values match inventory_stocks.php; the scan bar matches the Cashier POS. */
.cash-page { display: flex; flex-direction: column; gap: 22px; }
/* staff_shared.css gives .alert display:flex, which would override the hidden attribute */
[hidden] { display: none !important; }
.cash-panel {
    background: linear-gradient(180deg, #ffffff 0%, #f7faf5 100%);
    border: 1px solid var(--ep-border); border-radius: 16px; box-shadow: var(--card-shadow);
}
.cash-panel-header {
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    background: linear-gradient(to bottom, #fff 0%, #f4f9f0 100%);
    border-bottom: 1px solid var(--ep-border); padding: 16px 20px; border-radius: 16px 16px 0 0;
}
.cash-panel-header h3 {
    margin: 0; font-size: 17px; font-weight: 800; color: var(--ep-green-dark);
    display: flex; align-items: center; gap: 10px;
}
.cash-panel-header .card-icon { width: 32px; height: 32px; border-radius: 9px; }
.cash-panel-header .card-subtitle { margin: 2px 0 0; font-size: 12px; color: var(--ep-muted); font-weight: 500; }
.cash-panel-body { padding: 18px 22px 22px; }

.stocks-table-wrap { width: 100%; overflow-x: auto; background: #fff; border: 1px solid var(--ep-border); border-radius: 14px; }
.stocks-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.stocks-table thead tr { background: var(--ep-green-light); border-bottom: 2px solid var(--teal-light, #c6e6b3); }
.stocks-table th {
    padding: 13px 16px; text-align: left; font-size: 10.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.75px; color: var(--teal-deeper, var(--ep-green-dark)); white-space: nowrap;
}
.stocks-table td { padding: 12px 16px; border-bottom: 1px solid var(--ep-border); vertical-align: middle; }
.stocks-table tbody tr:last-child td { border-bottom: none; }
.stocks-table tbody tr:hover { background: rgba(238, 248, 230, 0.75); }
.stocks-table .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.stocks-pname { font-weight: 700; color: var(--ep-text); line-height: 1.4; overflow-wrap: anywhere; }
.stocks-pcat { display: block; margin-top: 3px; font-size: 11.5px; color: var(--ep-muted); font-weight: 600; }
.stocks-qty { font-weight: 800; font-size: 15px; color: var(--ep-text); font-variant-numeric: tabular-nums; }
.mono { font-family: var(--font-mono); font-size: 12.5px; }
.empty-state-row {
    text-align: center; padding: 40px 16px; color: var(--ep-muted); font-weight: 500;
    background: #fff; border: 1px dashed var(--ep-border); border-radius: 14px;
}
.stock-status-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 100px;
    font-size: 11.5px; font-weight: 700; white-space: nowrap; border: 1px solid transparent;
}
.stock-status-pill.ok { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
.stock-status-pill.low { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.stock-status-pill.critical { background: #fff1f2; color: #be123c; border-color: #fecdd3; }
.stock-status-pill.out { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

.pos-scan-bar {
    display: flex; align-items: center; gap: 10px;
    background: var(--ep-gray-bg); border: 2px solid var(--ep-green); border-radius: 999px;
    padding: 6px 8px 6px 18px; box-shadow: 0 0 0 4px rgba(97, 179, 55, 0.12);
}
.pos-scan-bar > i { color: var(--ep-green-dark); font-size: 18px; }
.pos-scan-bar input {
    flex: 1; min-width: 0; border: none; background: transparent; outline: none; box-shadow: none;
    font-family: var(--font-base); font-size: 18px; font-weight: 700; letter-spacing: 1px; padding: 8px 4px;
    font-variant-numeric: tabular-nums; color: var(--ep-text);
}
.pos-scan-bar input::placeholder { font-size: 14px; font-weight: 500; letter-spacing: 0; color: var(--ep-muted); }
.pos-scan-bar .scan-state {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px;
    font-size: 11.5px; font-weight: 700; background: var(--ep-green-light); color: var(--ep-green-dark); white-space: nowrap;
}
.pos-scan-bar .scan-state::before {
    content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--ep-green);
    animation: pos-pulse 1.6s ease-in-out infinite;
}
.pos-scan-bar.is-idle .scan-state { background: #fff8db; color: #854d0e; }
.pos-scan-bar.is-idle .scan-state::before { background: var(--ep-yellow-dark); animation: none; }
.pos-scan-bar.is-idle { border-color: var(--ep-border); box-shadow: none; }
@keyframes pos-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
.pos-scan-status { display: flex; align-items: flex-start; gap: 12px; margin: 14px 0 0; font-size: 13.5px; }
.pos-scan-status > i { font-size: 20px; margin-top: 1px; }
.pos-scan-status strong { display: block; font-size: 14px; }
.pos-scan-status span { display: block; font-weight: 500; margin-top: 2px; }
.pos-flash { animation: pos-flash 0.45s ease; }
@keyframes pos-flash { from { transform: scale(0.98); opacity: 0.55; } to { transform: none; opacity: 1; } }
.pos-line-flash { animation: pos-line-flash 0.9s ease; }
@keyframes pos-line-flash { from { background: #d4efc4; } to { background: transparent; } }
.btn:disabled, .btn[disabled] { opacity: 0.5; cursor: not-allowed; transform: none; }

.si-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 22px; align-items: start; }
.si-product {
    display: flex; flex-direction: column; gap: 10px; padding: 18px; border-radius: 14px;
    border: 1.5px dashed var(--ep-border); background: #fff; min-height: 120px; justify-content: center;
}
.si-product.has-product { border-style: solid; border-color: var(--teal-light); background: var(--ep-green-light); }
.si-product .si-name { font-size: 16px; font-weight: 800; color: var(--ep-text); }
.si-product .si-meta { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; font-size: 12.5px; color: var(--ep-muted); }
.si-product .si-stock { font-size: 28px; font-weight: 800; color: var(--ep-green-dark); }
.si-required { color: #dc2626; }

@media (max-width: 1100px) {
    /* minmax(0, …): a wide table or the scan bar must not stretch the column past the screen */
    .si-layout { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 640px) {
    .cash-page { gap: 16px; }
    .cash-panel-header { padding: 14px 16px; }
    .cash-panel-body { padding: 14px 16px 18px; }
    .pos-scan-bar { padding: 4px 6px 4px 14px; }
    .pos-scan-bar input { font-size: 16px; }   /* 16px+ keeps iOS from zooming on focus */
    .pos-scan-bar .scan-state { display: none; }
}
</style>
EXTRA
]);
?>
        <div class="cash-page">
            <div class="si-layout">
                <section class="cash-panel" aria-label="Scan item">
                    <div class="cash-panel-header">
                        <div>
                            <h3><span class="card-icon"><i class="fas fa-barcode"></i></span> 1. Scan Item</h3>
                            <div class="card-subtitle">Scan the product barcode to receive it</div>
                        </div>
                    </div>
                    <div class="cash-panel-body">
                        <div class="pos-scan-bar" id="scanBar">
                            <i class="fas fa-barcode" aria-hidden="true"></i>
                            <input type="text" id="scanInput" maxlength="20" aria-label="Scan or type a barcode"
                                   placeholder="Scan a barcode, or type it and press Enter">
                            <span class="scan-state" id="scanState">Ready to scan</span>
                        </div>
                        <div id="scanStatus" hidden></div>
                        <div class="si-product" id="siProduct" style="margin-top:16px;">
                            <div class="text-muted" style="text-align:center;color:var(--ep-muted);font-weight:500;">
                                <i class="fas fa-box-open"></i> No item scanned yet.
                            </div>
                        </div>
                    </div>
                </section>

                <section class="cash-panel" aria-label="Receive quantity">
                    <div class="cash-panel-header">
                        <div>
                            <h3><span class="card-icon"><i class="fas fa-dolly"></i></span> 2. Confirm Receiving</h3>
                            <div class="card-subtitle">Stock updates immediately and is logged</div>
                        </div>
                    </div>
                    <div class="cash-panel-body">
                        <form id="siForm" autocomplete="off">
                            <div class="form-group">
                                <label class="form-label" for="siQty">Quantity received</label>
                                <input type="number" id="siQty" class="form-control" min="1" max="<?php echo (int)POS_MAX_STOCK_IN_QTY; ?>" step="1" value="1" required disabled>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="siSupplier">Supplier <span class="si-required" aria-hidden="true">*</span></label>
                                <select id="siSupplier" class="form-control" required disabled>
                                    <option value="">Select a supplier…</option>
                                </select>
                                <div class="form-hint" id="siSupplierHint">Scan an item to see its supplier.</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="siRef">Delivery / reference no. (optional)</label>
                                <input type="text" id="siRef" class="form-control" maxlength="64" placeholder="e.g. DR-10234" disabled>
                            </div>
                            <button type="submit" id="siSubmit" class="btn btn-primary" style="width:100%;justify-content:center;padding:13px;" disabled>
                                <i class="fas fa-check"></i> Confirm Stock In
                            </button>
                            <button type="button" id="siCancel" class="btn btn-outline" style="width:100%;justify-content:center;margin-top:8px;" disabled>
                                Cancel
                            </button>
                        </form>
                        <div id="siResult" hidden style="margin-top:14px;"></div>
                    </div>
                </section>
            </div>

            <section class="cash-panel" aria-label="Recent stock-ins">
                <div class="cash-panel-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-history"></i></span> My Recent Stock-Ins</h3>
                        <div class="card-subtitle">Last 10 receiving entries you recorded</div>
                    </div>
                </div>
                <div class="cash-panel-body">
                    <?php if (!$recent): ?>
                        <div class="empty-state-row" id="siEmpty"><i class="fas fa-dolly"></i> No stock-ins recorded yet.</div>
                    <?php endif; ?>
                    <div class="stocks-table-wrap" id="siTableWrap"<?php echo $recent ? '' : ' hidden'; ?>>
                        <table class="stocks-table">
                            <thead><tr>
                                <th>Date / Time</th><th>Product</th><th class="num">Qty</th><th class="num">Stock</th><th>Supplier</th><th>Reference</th>
                            </tr></thead>
                            <tbody id="siRows">
                            <?php foreach ($recent as $r): ?>
                                <tr>
                                    <td><?php echo h(pos_format_datetime($r['created_at'])); ?></td>
                                    <td><div class="stocks-pname"><?php echo h((string)$r['product_name']); ?></div></td>
                                    <td class="num"><span class="stocks-qty">+<?php echo (int)$r['quantity']; ?></span></td>
                                    <td class="num"><?php echo (int)$r['stock_before']; ?> → <strong><?php echo (int)$r['stock_after']; ?></strong></td>
                                    <td><?php echo h((string)($r['supplier'] ?? '')) ?: '—'; ?></td>
                                    <td><?php echo h((string)($r['reference_no'] ?? '')) ?: '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
<?php
$config = json_encode([
    'csrf' => generateCsrfToken(),
    'maxQty' => POS_MAX_STOCK_IN_QTY,
    'suppliers' => $supplierCatalog['suppliers'],
    'supplierByProduct' => (object)$supplierCatalog['by_product'],
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$script = '<script src="../includes/barcode_scanner.js?v=1"></script>'
    . '<script>var SI_CONFIG = ' . $config . ';</script>'
    . <<<'SCRIPT'
<script>
(function () {
    'use strict';
    var API = '../backend/api/';
    var esc = EP_Scanner.escapeHtml;
    var scanBar = document.getElementById('scanBar');
    var scanInput = document.getElementById('scanInput');
    var scanState = document.getElementById('scanState');
    var scanStatus = document.getElementById('scanStatus');
    var siProduct = document.getElementById('siProduct');
    var form = document.getElementById('siForm');
    var qty = document.getElementById('siQty');
    var supplier = document.getElementById('siSupplier');
    var supplierHint = document.getElementById('siSupplierHint');
    var ref = document.getElementById('siRef');
    var submit = document.getElementById('siSubmit');
    var cancel = document.getElementById('siCancel');
    var result = document.getElementById('siResult');
    var product = null;
    var submitting = false;

    var STATUS = { ok: ['ok', 'In Stock'], low: ['low', 'Low Stock'], critical: ['critical', 'Critical Stock'], out: ['out', 'Out of Stock'] };

    function readJson(r) {
        return r.json().catch(function () {
            return { ok: false, message: 'Unexpected server response (HTTP ' + r.status + ').' };
        }).then(function (d) {
            if (r.status === 401) {
                if (window.IAS_Session) IAS_Session.check();
                else setTimeout(function () { window.location.href = '../login.php?expired=1'; }, 1500);
            }
            return d;
        });
    }
    function setScanState(text) {
        var focused = document.activeElement === scanInput;
        scanBar.classList.toggle('is-idle', !focused && !text);
        scanState.textContent = text || (focused ? 'Ready to scan' : 'Click here to scan');
    }
    function setFormEnabled(on) {
        [qty, supplier, ref, submit, cancel].forEach(function (x) { x.disabled = !on; });
    }
    /* Suppliers are the catalog brands; the scanned product's own brand is listed first and preselected. */
    function fillSuppliers(p) {
        var keep = product && p && product.id === p.id ? supplier.value : '';
        var own = p ? SI_CONFIG.supplierByProduct[p.id] || '' : '';
        var opt = function (name) { return '<option value="' + esc(name) + '">' + esc(name) + '</option>'; };
        var others = SI_CONFIG.suppliers.filter(function (name) { return name !== own; });
        supplier.innerHTML = '<option value="">Select a supplier…</option>' + (own
            ? '<optgroup label="Supplier of this product">' + opt(own) + '</optgroup>' +
              '<optgroup label="Other suppliers">' + others.map(opt).join('') + '</optgroup>'
            : others.map(opt).join(''));
        supplier.value = keep || own;
        supplierHint.textContent = !p ? 'Scan an item to see its supplier.'
            : (own ? 'Preselected from the product brand. Change it if the delivery came from another supplier.'
                   : 'No supplier matches this product yet. Choose the supplier of this delivery.');
    }
    function showProduct(p) {
        fillSuppliers(p);
        product = p;
        if (!p) {
            siProduct.className = 'si-product';
            siProduct.innerHTML = '<div style="text-align:center;color:var(--ep-muted);font-weight:500;"><i class="fas fa-box-open"></i> No item scanned yet.</div>';
            setFormEnabled(false);
            return;
        }
        var st = STATUS[p.status] || STATUS.ok;
        siProduct.className = 'si-product has-product';
        siProduct.innerHTML =
            '<div class="si-name">' + esc(p.name) + '</div>' +
            '<div class="si-meta"><span>' + esc(p.category || '') + '</span>' +
            '<span class="mono">' + esc(p.barcode) + '</span>' +
            '<span class="stock-status-pill ' + st[0] + '">' + st[1] + '</span></div>' +
            '<div><span class="stocks-pcat">Current stock</span><span class="si-stock">' + p.stock + '</span></div>';
        setFormEnabled(true);
    }

    function onScan(code) {
        if (submitting) return;
        setScanState('Looking up ' + code + '…');
        fetch(API + 'barcode_lookup.php?code=' + encodeURIComponent(code), {
            credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        }).then(readJson).then(function (d) {
            if (!d || !d.ok) {
                EP_Scanner.beep(false);
                EP_Scanner.status(scanStatus, 'error', d && d.error === 'not_found' ? 'Product not found' : 'Scan rejected', (d && d.message) || 'Unknown error.');
                return;
            }
            if (product && product.id === d.product.id) {
                // Scanning the same item again counts one more unit.
                qty.value = Math.min(SI_CONFIG.maxQty, (parseInt(qty.value, 10) || 0) + 1);
            } else {
                qty.value = 1;
            }
            showProduct(d.product);
            result.hidden = true;
            EP_Scanner.beep(true);
            EP_Scanner.status(scanStatus, 'success', 'Scanned: ' + d.product.name,
                'Current stock ' + d.product.stock + ' · receiving ' + qty.value + ' (scan again to add one more, or edit the quantity)');
        }).catch(function () {
            EP_Scanner.beep(false);
            EP_Scanner.status(scanStatus, 'error', 'Connection problem', 'Could not reach the server. Scan again.');
        }).then(function () { setScanState(null); });
    }

    var scanner = EP_Scanner.attach(scanInput, {
        onScan: onScan,
        onInvalid: function (res) { EP_Scanner.status(scanStatus, 'error', 'Invalid barcode', res.message); }
    });
    scanInput.addEventListener('focus', function () { setScanState(null); });
    scanInput.addEventListener('blur', function () { setTimeout(function () { setScanState(null); }, 10); });

    function addRecentRow(d) {
        var tbody = document.getElementById('siRows');
        var empty = document.getElementById('siEmpty');
        if (empty) empty.remove();
        document.getElementById('siTableWrap').hidden = false;
        var now = new Date().toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
        var tr = document.createElement('tr');
        tr.className = 'pos-line-flash';
        tr.innerHTML = '<td>' + esc(now) + '</td>' +
            '<td><div class="stocks-pname">' + esc(d.product.name) + '</div></td>' +
            '<td class="num"><span class="stocks-qty">+' + d.quantity + '</span></td>' +
            '<td class="num">' + d.stock_before + ' → <strong>' + d.stock_after + '</strong></td>' +
            '<td>' + (esc(supplier.value) || '—') + '</td>' +
            '<td>' + (esc(ref.value.trim()) || '—') + '</td>';
        tbody.insertBefore(tr, tbody.firstChild);
        while (tbody.children.length > 10) tbody.removeChild(tbody.lastChild);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!product || submitting) return;
        var q = qty.value.trim();
        if (!/^\d+$/.test(q) || +q < 1 || +q > SI_CONFIG.maxQty) {
            EP_Scanner.beep(false);
            EP_Scanner.status(result, 'error', 'Invalid quantity', 'Enter a whole number from 1 to ' + SI_CONFIG.maxQty + '.');
            return;
        }
        if (!supplier.value) {
            EP_Scanner.beep(false);
            EP_Scanner.status(result, 'error', 'Supplier required', 'Choose the supplier of this delivery.');
            supplier.focus();
            return;
        }
        submitting = true;
        submit.disabled = true;
        submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
        fetch(API + 'stock_in.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                csrf_token: SI_CONFIG.csrf,
                product_id: product.id,
                qty: q,
                supplier: supplier.value,
                reference_no: ref.value.trim()
            })
        }).then(readJson).then(function (d) {
            if (d && d.ok) {
                EP_Scanner.beep(true);
                EP_Scanner.status(result, 'success', 'Stock updated: ' + d.product.name,
                    'Stock ' + d.stock_before + ' → ' + d.stock_after + ' (+' + d.quantity + '). The movement was logged.');
                addRecentRow(d);
                showProduct(null);
                qty.value = 1;
                ref.value = '';
                scanStatus.hidden = true;
                return;
            }
            EP_Scanner.beep(false);
            EP_Scanner.status(result, 'error', 'Stock-in failed', (d && d.message) || 'No changes were saved.');
        }).catch(function () {
            EP_Scanner.beep(false);
            EP_Scanner.status(result, 'error', 'Connection problem', 'Could not confirm the stock-in. Check the Recent Stock-Ins list before retrying.');
        }).then(function () {
            submitting = false;
            submit.innerHTML = '<i class="fas fa-check"></i> Confirm Stock In';
            setFormEnabled(!!product);
            scanner.refocus();
        });
    });

    cancel.addEventListener('click', function () {
        showProduct(null);
        qty.value = 1;
        scanStatus.hidden = true;
        result.hidden = true;
        scanner.refocus();
    });
    [qty, supplier, ref].forEach(function (inp) {
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : submit.click(); }
        });
    });

    setScanState(null);
})();
</script>
SCRIPT;

staff_page_end($script);
