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
    'active' => 'stocks',
    'active_child' => 'add_product',
    'heading' => 'Stock In',
    'subtitle' => 'Receive deliveries by scanning each item',
    'actions' => '<a class="cx-btn cx-btn-outline" href="inventory_stocks.php"><i class="ph ph-package" aria-hidden="true"></i> View inventory</a>',
]);
?>
        <div class="inv-stack">
            <div class="si-layout">
                <section class="cx-card si-step" aria-labelledby="siScanTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="siScanTitle"><span class="si-num" aria-hidden="true">1</span> Scan item</h2>
                            <p>Scan the product barcode to receive it</p>
                        </div>
                    </div>
                    <div class="si-body">
                        <label class="cx-sr" for="scanInput">Scan or enter a barcode</label>
                        <div class="pos-scan-bar" id="scanBar">
                            <span class="pos-scan-icon" aria-hidden="true"><i class="ph ph-barcode"></i></span>
                            <input type="text" id="scanInput" maxlength="20" autocomplete="off"
                                   placeholder="Waiting for scanner, or type digits and press Enter">
                            <span class="scan-state" id="scanState">Ready to scan</span>
                        </div>
                        <p class="si-hint">UPC-A, UPC-E, EAN-13 and EAN-8. Scanning the same item again adds one more. <span class="si-keys"><kbd>Enter</kbd> look up</span></p>
                        <div id="scanStatus" role="status" aria-live="polite" hidden></div>
                        <div class="si-product" id="siProduct">
                            <div class="si-product-empty">
                                <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-package"></i></span>
                                <span>No item scanned yet.</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="cx-card si-step" aria-labelledby="siConfirmTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="siConfirmTitle"><span class="si-num" aria-hidden="true">2</span> Confirm receiving</h2>
                            <p>Stock updates immediately and the movement is logged</p>
                        </div>
                    </div>
                    <div class="si-body">
                        <form id="siForm" autocomplete="off" class="inv-form">
                            <div class="form-group">
                                <label class="form-label" for="siQty">Quantity received</label>
                                <input type="number" id="siQty" class="form-control cx-num si-qty" min="1" max="<?php echo (int)POS_MAX_STOCK_IN_QTY; ?>" step="1" value="1" required disabled>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="siSupplier">Supplier <span class="si-required" aria-hidden="true">*</span><span class="cx-sr">(required)</span></label>
                                <select id="siSupplier" class="form-control" required disabled aria-describedby="siSupplierHint">
                                    <option value="">Select a supplier…</option>
                                </select>
                                <div class="form-hint" id="siSupplierHint">Scan an item to see its supplier.</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="siRef">Delivery / reference no. (optional)</label>
                                <input type="text" id="siRef" class="form-control cx-mono" maxlength="64" placeholder="e.g. DR-10234" disabled>
                            </div>
                            <div class="si-actions">
                                <button type="submit" id="siSubmit" class="cx-btn cx-btn-primary cx-btn-xl" disabled>
                                    <i class="ph ph-check-circle" aria-hidden="true"></i> Confirm Stock In
                                </button>
                                <button type="button" id="siCancel" class="cx-btn cx-btn-ghost" disabled>Cancel</button>
                            </div>
                        </form>
                        <div id="siResult" role="status" aria-live="polite" hidden></div>
                    </div>
                </section>
            </div>

            <section class="cx-card" aria-labelledby="siRecentTitle">
                <div class="cx-card-head">
                    <div>
                        <h2 id="siRecentTitle"><i class="ph ph-clock-counter-clockwise" aria-hidden="true"></i> My recent stock-ins</h2>
                        <p>The last 10 receiving entries you recorded</p>
                    </div>
                </div>
                <?php if (!$recent): ?>
                    <div class="cx-empty" id="siEmpty">
                        <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-truck"></i></span>
                        <strong>No stock-ins recorded yet</strong>
                        <span>Items you receive will be listed here.</span>
                    </div>
                <?php endif; ?>
                <div class="cx-table-wrap" id="siTableWrap"<?php echo $recent ? '' : ' hidden'; ?>>
                    <table class="cx-table si-table">
                        <thead><tr>
                            <th scope="col">Date / Time</th>
                            <th scope="col">Product</th>
                            <th scope="col" class="num">Qty</th>
                            <th scope="col" class="num">Stock</th>
                            <th scope="col">Supplier</th>
                            <th scope="col">Reference</th>
                        </tr></thead>
                        <tbody id="siRows">
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td data-label="When" class="si-when cx-num"><?php echo h(pos_format_datetime($r['created_at'])); ?></td>
                                <td data-label="Product"><div class="stocks-pname"><?php echo h((string)$r['product_name']); ?></div></td>
                                <td data-label="Qty" class="num"><span class="si-plus cx-num">+<?php echo (int)$r['quantity']; ?></span></td>
                                <td data-label="Stock" class="num cx-num"><?php echo (int)$r['stock_before']; ?> → <strong><?php echo (int)$r['stock_after']; ?></strong></td>
                                <td data-label="Supplier"><?php echo h((string)($r['supplier'] ?? '')) ?: '—'; ?></td>
                                <td data-label="Reference" class="cx-mono"><?php echo h((string)($r['reference_no'] ?? '')) ?: '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
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

    var STATUS = { ok: ['ok', 'In Stock', 'ph-check-circle'], low: ['low', 'Low Stock', 'ph-warning'], critical: ['critical', 'Critical Stock', 'ph-warning-circle'], out: ['out', 'Out of Stock', 'ph-x-circle'] };

    /* Inline feedback (same contract as EP_Scanner.status, with the app shell's icons + .pos-status styles) */
    var STATUS_ICONS = { success: 'ph-check-circle', error: 'ph-x-circle', info: 'ph-info', warn: 'ph-warning' };
    function status(box, type, title, detail) {
        box.className = 'pos-status pos-status-' + type;
        box.innerHTML = '<i class="ph-fill ' + (STATUS_ICONS[type] || STATUS_ICONS.info) + '" aria-hidden="true"></i>' +
            '<div><strong>' + esc(title) + '</strong>' + (detail ? '<span>' + esc(detail) + '</span>' : '') + '</div>';
        box.hidden = false;
        box.classList.remove('pos-flash');
        void box.offsetWidth; // restart the flash animation
        box.classList.add('pos-flash');
    }

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
        scanBar.classList.toggle('is-busy', !!text);
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
            siProduct.innerHTML = '<div class="si-product-empty"><span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-package"></i></span><span>No item scanned yet.</span></div>';
            setFormEnabled(false);
            return;
        }
        var st = STATUS[p.status] || STATUS.ok;
        siProduct.className = 'si-product has-product';
        siProduct.innerHTML =
            '<div class="si-product-info">' +
                '<div class="si-name">' + esc(p.name) + '</div>' +
                '<div class="si-meta"><span>' + esc(p.category || '') + '</span>' +
                '<span class="barcode-code">' + esc(p.barcode) + '</span></div>' +
                '<span class="stock-status-pill ' + st[0] + '"><i class="ph-fill ' + st[2] + '" aria-hidden="true"></i> ' + st[1] + '</span>' +
            '</div>' +
            '<div class="si-stock-box"><span>Current stock</span><strong class="cx-num">' + p.stock + '</strong></div>';
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
                status(scanStatus, 'error', d && d.error === 'not_found' ? 'Product not found' : 'Scan rejected', (d && d.message) || 'Unknown error.');
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
            status(scanStatus, 'success', 'Scanned: ' + d.product.name,
                'Current stock ' + d.product.stock + ' · receiving ' + qty.value + ' (scan again to add one more, or edit the quantity)');
        }).catch(function () {
            EP_Scanner.beep(false);
            status(scanStatus, 'error', 'Connection problem', 'Could not reach the server. Scan again.');
        }).then(function () { setScanState(null); });
    }

    var scanner = EP_Scanner.attach(scanInput, {
        onScan: onScan,
        onInvalid: function (res) { status(scanStatus, 'error', 'Invalid barcode', res.message); },
        canRefocus: function () {
            return !submitting && !document.querySelector('.cx-user.is-open, .cx-notif.is-open, .cx-sheet:not([hidden]), #cwFab[aria-expanded="true"]');
        }
    });
    scanBar.addEventListener('click', function () { scanInput.focus(); });
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
        tr.innerHTML = '<td data-label="When" class="si-when cx-num">' + esc(now) + '</td>' +
            '<td data-label="Product"><div class="stocks-pname">' + esc(d.product.name) + '</div></td>' +
            '<td data-label="Qty" class="num"><span class="si-plus cx-num">+' + d.quantity + '</span></td>' +
            '<td data-label="Stock" class="num cx-num">' + d.stock_before + ' → <strong>' + d.stock_after + '</strong></td>' +
            '<td data-label="Supplier">' + (esc(supplier.value) || '—') + '</td>' +
            '<td data-label="Reference" class="cx-mono">' + (esc(ref.value.trim()) || '—') + '</td>';
        tbody.insertBefore(tr, tbody.firstChild);
        while (tbody.children.length > 10) tbody.removeChild(tbody.lastChild);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!product || submitting) return;
        var q = qty.value.trim();
        if (!/^\d+$/.test(q) || +q < 1 || +q > SI_CONFIG.maxQty) {
            EP_Scanner.beep(false);
            status(result, 'error', 'Invalid quantity', 'Enter a whole number from 1 to ' + SI_CONFIG.maxQty + '.');
            return;
        }
        if (!supplier.value) {
            EP_Scanner.beep(false);
            status(result, 'error', 'Supplier required', 'Choose the supplier of this delivery.');
            supplier.focus();
            return;
        }
        submitting = true;
        submit.disabled = true;
        submit.innerHTML = '<i class="ph ph-spinner-gap cx-spin" aria-hidden="true"></i> Saving…';
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
                status(result, 'success', 'Stock updated: ' + d.product.name,
                    'Stock ' + d.stock_before + ' → ' + d.stock_after + ' (+' + d.quantity + '). The movement was logged.');
                addRecentRow(d);
                showProduct(null);
                qty.value = 1;
                ref.value = '';
                scanStatus.hidden = true;
                return;
            }
            EP_Scanner.beep(false);
            status(result, 'error', 'Stock-in failed', (d && d.message) || 'No changes were saved.');
        }).catch(function () {
            EP_Scanner.beep(false);
            status(result, 'error', 'Connection problem', 'Could not confirm the stock-in. Check the Recent Stock-Ins list before retrying.');
        }).then(function () {
            submitting = false;
            submit.innerHTML = '<i class="ph ph-check-circle" aria-hidden="true"></i> Confirm Stock In';
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
