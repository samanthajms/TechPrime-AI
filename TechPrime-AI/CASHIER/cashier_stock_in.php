<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('cashier');

$uid = (int)$_SESSION['user_id'];
$recent = pos_recent_stock_ins($db, $uid, 10);

staff_page_start([
    'role' => 'cashier',
    'title' => 'Stock In',
    'active' => 'stockin',
    'heading' => 'Stock In',
    'subtitle' => 'Receive deliveries by scanning each item',
    'extra_head' => '<link rel="stylesheet" href="cashier.css?v=3">',
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
                                <label class="form-label" for="siSupplier">Supplier (optional)</label>
                                <input type="text" id="siSupplier" class="form-control" maxlength="150" placeholder="e.g. RAKK Philippines" disabled>
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
            if (r.status === 401) setTimeout(function () { window.location.href = '../login.php'; }, 1500);
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
    function showProduct(p) {
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
            '<td>' + (esc(supplier.value.trim()) || '—') + '</td>' +
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
                supplier: supplier.value.trim(),
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
