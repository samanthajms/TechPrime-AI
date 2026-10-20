<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/pos_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('cashier');

$uid = (int)$_SESSION['user_id'];

staff_page_start([
    'role' => 'cashier',
    'title' => 'POS Checkout',
    'active' => 'pos',
    'heading' => 'POS Checkout',
    'hide_head' => true,
]);
?>
        <div class="pos">
            <section class="pos-work" aria-labelledby="posSaleTitle">
                <div class="pos-scan">
                    <label class="pos-scan-label" for="scanInput">
                        <i class="ph ph-scan" aria-hidden="true"></i> Scan or enter a barcode
                    </label>
                    <div class="pos-scan-bar" id="scanBar">
                        <span class="pos-scan-icon" aria-hidden="true"><i class="ph ph-barcode"></i></span>
                        <input type="text" id="scanInput" maxlength="20"
                               placeholder="Waiting for scanner, or type digits and press Enter">
                        <span class="scan-state" id="scanState">Ready to scan</span>
                    </div>
                    <p class="pos-scan-hint">
                        UPC-A, UPC-E, EAN-13 and EAN-8. Scanning the same item again adds one more.
                        <span class="pos-keys"><kbd>Enter</kbd> add item</span>
                    </p>
                    <div id="scanStatus" role="status" aria-live="polite" hidden></div>
                </div>

                <div class="pos-cart">
                    <div class="pos-cart-head">
                        <h2 id="posSaleTitle">Current sale <span class="pos-count"><span id="lineCount">0</span> lines</span></h2>
                        <button type="button" id="clearBtn" class="cx-btn cx-btn-ghost-danger" disabled>
                            <i class="ph ph-trash" aria-hidden="true"></i> Clear sale
                        </button>
                    </div>
                    <div id="cartWrap"></div>
                </div>
            </section>

            <aside class="pos-register" aria-label="Order summary">
                <div class="pos-display">
                    <div class="pos-display-top">
                        <span>Total due</span>
                        <span><span id="sumUnits">0</span> items</span>
                    </div>
                    <strong class="pos-display-total" id="sumTotal">₱0.00</strong>
                </div>
                <dl class="pos-breakdown">
                    <div><dt>Subtotal</dt><dd id="sumSubtotal">₱0.00</dd></div>
                    <div><dt>VATable sales</dt><dd id="sumVatable">₱0.00</dd></div>
                    <div><dt>VAT (12%)</dt><dd id="sumVat">₱0.00</dd></div>
                </dl>
                <button type="button" id="checkoutBtn" class="cx-btn cx-btn-primary cx-btn-xl pos-checkout" disabled>
                    <i class="ph ph-wallet" aria-hidden="true"></i>
                    <span>Checkout</span>
                    <span class="pos-checkout-amt" id="checkoutAmt" aria-hidden="true"></span>
                </button>
                <p class="pos-register-note"><i class="ph ph-info" aria-hidden="true"></i> Prices are VAT-inclusive (12%). Stock is re-checked when the sale is completed.</p>
            </aside>
        </div>

        <!-- ============================== PAYMENT MODAL ============================== -->
        <div id="payModal" class="modal pay-modal" role="dialog" aria-modal="true" aria-labelledby="payTitle">
            <div class="modal-content">
                <button type="button" class="close" id="payClose" aria-label="Close payment"><i class="ph ph-x" aria-hidden="true"></i></button>
                <div id="payForm">
                    <h3 id="payTitle"><i class="ph ph-wallet" aria-hidden="true"></i> Take payment</h3>
                    <div class="pay-total-box"><span>Total due</span><strong id="payTotal">₱0.00</strong></div>
                    <div class="pay-label" id="payMethodLabel">Payment method</div>
                    <div class="pay-methods" id="payMethods" role="group" aria-labelledby="payMethodLabel">
                        <button type="button" class="pay-method" data-method="cash"><i class="ph ph-money" aria-hidden="true"></i> Cash</button>
                        <button type="button" class="pay-method" data-method="gcash"><i class="ph ph-device-mobile" aria-hidden="true"></i> GCash</button>
                        <button type="button" class="pay-method" data-method="maya"><i class="ph ph-device-mobile" aria-hidden="true"></i> Maya</button>
                        <button type="button" class="pay-method" data-method="card"><i class="ph ph-credit-card" aria-hidden="true"></i> Card</button>
                    </div>
                    <div id="cashFields">
                        <div class="form-group pay-group">
                            <label class="form-label" for="tendered">Amount tendered</label>
                            <div class="pay-amount-wrap">
                                <span class="pay-amount-prefix" aria-hidden="true">₱</span>
                                <input type="text" id="tendered" class="form-control pay-amount" inputmode="decimal" autocomplete="off" placeholder="0.00">
                            </div>
                            <div class="pay-quick" id="payQuick"></div>
                        </div>
                        <div class="pay-change" id="payChange" aria-live="polite"><span>Change</span><span id="changeVal">₱0.00</span></div>
                    </div>
                    <div id="refFields" hidden>
                        <div class="form-group pay-group">
                            <label class="form-label" for="payRef">Reference number</label>
                            <input type="text" id="payRef" class="form-control" maxlength="64" autocomplete="off" placeholder="e.g. 1009 234 567890" aria-describedby="payRefHint">
                            <div class="form-hint" id="payRefHint">Required for GCash, Maya and card payments.</div>
                        </div>
                    </div>
                    <div id="payError" class="pay-error" role="alert" hidden></div>
                    <button type="button" id="payConfirm" class="cx-btn cx-btn-primary cx-btn-xl pay-confirm">
                        <i class="ph ph-check" aria-hidden="true"></i> Complete sale
                    </button>
                    <p class="pay-keys"><kbd>Enter</kbd> complete · <kbd>Esc</kbd> cancel</p>
                </div>
                <div id="payDone" class="sale-done" hidden>
                    <div class="done-icon" aria-hidden="true"><i class="ph ph-check"></i></div>
                    <h3>Sale completed</h3>
                    <div class="inv-no" id="doneInvoice"></div>
                    <div class="change-due" id="doneChange"></div>
                    <div class="sale-done-actions">
                        <a id="donePrint" class="cx-btn cx-btn-primary cx-btn-lg" href="#" data-print-receipt><i class="ph ph-printer" aria-hidden="true"></i> Print receipt</a>
                        <button type="button" id="doneNew" class="cx-btn cx-btn-outline cx-btn-lg"><i class="ph ph-plus" aria-hidden="true"></i> New sale</button>
                    </div>
                    <p class="form-hint">Scanning the next item also starts a new sale.</p>
                </div>
            </div>
        </div>
<?php
$config = json_encode([
    'csrf' => generateCsrfToken(),
    'storeKey' => 'ep_pos_cart_' . $uid,
    'vatRate' => POS_VAT_RATE,
    'maxQty' => POS_MAX_LINE_QTY,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$script = '<script src="../includes/barcode_scanner.js?v=1"></script>'
    . '<script src="receipt_print.js?v=2"></script>'
    . '<script>var POS_CONFIG = ' . $config . ';</script>'
    . <<<'SCRIPT'
<script>
(function () {
    'use strict';
    var API = '../backend/api/';
    var esc = EP_Scanner.escapeHtml;

    var el = {
        scanBar: document.getElementById('scanBar'),
        scanInput: document.getElementById('scanInput'),
        scanState: document.getElementById('scanState'),
        scanStatus: document.getElementById('scanStatus'),
        cartWrap: document.getElementById('cartWrap'),
        lineCount: document.getElementById('lineCount'),
        sumUnits: document.getElementById('sumUnits'),
        sumSubtotal: document.getElementById('sumSubtotal'),
        sumVatable: document.getElementById('sumVatable'),
        sumVat: document.getElementById('sumVat'),
        sumTotal: document.getElementById('sumTotal'),
        checkoutBtn: document.getElementById('checkoutBtn'),
        checkoutAmt: document.getElementById('checkoutAmt'),
        clearBtn: document.getElementById('clearBtn'),
        payModal: document.getElementById('payModal'),
        payClose: document.getElementById('payClose'),
        payForm: document.getElementById('payForm'),
        payDone: document.getElementById('payDone'),
        payTotal: document.getElementById('payTotal'),
        payMethods: document.getElementById('payMethods'),
        cashFields: document.getElementById('cashFields'),
        refFields: document.getElementById('refFields'),
        tendered: document.getElementById('tendered'),
        payQuick: document.getElementById('payQuick'),
        payChange: document.getElementById('payChange'),
        changeVal: document.getElementById('changeVal'),
        payRef: document.getElementById('payRef'),
        payError: document.getElementById('payError'),
        payConfirm: document.getElementById('payConfirm'),
        doneInvoice: document.getElementById('doneInvoice'),
        doneChange: document.getElementById('doneChange'),
        donePrint: document.getElementById('donePrint')
    };

    var cart = loadCart();      // [{id, name, category, barcode, priceC, stock, qty}]
    var clientRef = null;       // idempotency key for the current checkout attempt
    var paying = false;
    var saleDone = false;
    var submitting = false;
    var method = 'cash';

    var CONFIRM_HTML = '<i class="ph ph-check" aria-hidden="true"></i> Complete sale';

    /* ---------- helpers ---------- */
    function toCents(v) { return Math.round(parseFloat(v) * 100); }
    function peso(c) {
        return '₱' + (c / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function parseMoney(v) {
        var s = String(v || '').replace(/,/g, '').trim();
        return /^\d{1,9}(\.\d{1,2})?$/.test(s) ? Math.round(parseFloat(s) * 100) : null;
    }
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        var b = new Uint8Array(16);
        crypto.getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        return h.substr(0, 8) + '-' + h.substr(8, 4) + '-' + h.substr(12, 4) + '-' + h.substr(16, 4) + '-' + h.substr(20);
    }
    function loadCart() {
        try {
            var v = JSON.parse(sessionStorage.getItem(POS_CONFIG.storeKey) || '[]');
            return Array.isArray(v) ? v : [];
        } catch (e) { return []; }
    }
    function saveCart() {
        try { sessionStorage.setItem(POS_CONFIG.storeKey, JSON.stringify(cart)); } catch (e) { /* private mode */ }
        clientRef = null; // cart changed → a new checkout attempt
    }
    function findLine(id) {
        for (var i = 0; i < cart.length; i++) if (cart[i].id === id) return cart[i];
        return null;
    }
    function totals() {
        var total = 0, units = 0;
        cart.forEach(function (l) { total += l.priceC * l.qty; units += l.qty; });
        var vatable = Math.round(total / (1 + POS_CONFIG.vatRate));
        return { total: total, units: units, vatable: vatable, vat: total - vatable };
    }
    /* Inline scan feedback (same contract as EP_Scanner.status, with this page's icons) */
    var STATUS_ICONS = { success: 'ph-check-circle', error: 'ph-x-circle', info: 'ph-info', warn: 'ph-warning' };
    function status(type, title, detail) {
        var box = el.scanStatus;
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
            return { ok: false, error: 'bad_response', message: 'Unexpected server response (HTTP ' + r.status + ').' };
        }).then(function (d) {
            if (r.status === 401) {
                if (window.IAS_Session) IAS_Session.check();
                else setTimeout(function () { window.location.href = '../login.php?expired=1'; }, 1500);
            }
            return d;
        });
    }
    function lookup(code) {
        return fetch(API + 'barcode_lookup.php?code=' + encodeURIComponent(code), {
            credentials: 'same-origin', headers: { 'Accept': 'application/json' }
        }).then(readJson);
    }

    /* ---------- rendering ---------- */
    function lineHtml(l, flashId) {
        var over = l.qty > l.stock;
        var max = Math.min(l.stock, POS_CONFIG.maxQty);
        var cls = 'pos-line' + (l.id === flashId ? ' pos-line-flash' : '') + (over ? ' is-over' : '');
        var name = esc(l.name);
        return '<li class="' + cls + '" data-id="' + l.id + '">' +
            '<div class="pos-line-info">' +
                '<div class="pos-line-name">' + name + '</div>' +
                '<div class="pos-line-meta">' + esc(l.category || 'Product') +
                    (l.barcode ? '<span class="mono">' + esc(l.barcode) + '</span>' : '') + '</div>' +
                (over ? '<div class="pos-over"><i class="ph-fill ph-warning" aria-hidden="true"></i> Only ' + l.stock + ' in stock — lower the quantity</div>' : '') +
            '</div>' +
            '<div class="pos-line-price"><span class="cx-num">' + peso(l.priceC) + '</span><span>each</span></div>' +
            '<div class="pos-stepper">' +
                '<button type="button" class="pos-step" data-step="-1" data-id="' + l.id + '"' + (l.qty <= 1 ? ' disabled' : '') +
                    ' aria-label="One less ' + name + '"><i class="ph ph-minus" aria-hidden="true"></i></button>' +
                '<input type="number" class="pos-qty" data-id="' + l.id + '" min="1" max="' + max + '" value="' + l.qty + '" aria-label="Quantity for ' + name + '">' +
                '<button type="button" class="pos-step" data-step="1" data-id="' + l.id + '"' + (l.qty >= max ? ' disabled' : '') +
                    ' aria-label="One more ' + name + '"><i class="ph ph-plus" aria-hidden="true"></i></button>' +
            '</div>' +
            '<div class="pos-line-total cx-num">' + peso(l.priceC * l.qty) + '</div>' +
            '<button type="button" class="pos-remove" data-remove="' + l.id + '" title="Remove line" aria-label="Remove ' + name + '"><i class="ph ph-trash" aria-hidden="true"></i></button>' +
        '</li>';
    }
    function render(flashId) {
        var t = totals();
        if (!cart.length) {
            el.cartWrap.innerHTML =
                '<div class="pos-empty">' +
                    '<span class="pos-empty-icon" aria-hidden="true"><i class="ph ph-barcode"></i></span>' +
                    '<strong>No items yet</strong>' +
                    '<span>Scan a product barcode to start the sale.</span>' +
                '</div>';
        } else {
            el.cartWrap.innerHTML = '<ul class="pos-lines">' +
                cart.map(function (l) { return lineHtml(l, flashId); }).join('') + '</ul>';
        }
        el.lineCount.textContent = cart.length;
        el.sumUnits.textContent = t.units;
        el.sumSubtotal.textContent = peso(t.total);
        el.sumVatable.textContent = peso(t.vatable);
        el.sumVat.textContent = peso(t.vat);
        el.sumTotal.textContent = peso(t.total);
        el.checkoutAmt.textContent = cart.length ? peso(t.total) : '';
        el.checkoutBtn.disabled = cart.length === 0;
        el.clearBtn.disabled = cart.length === 0;
    }

    function setScanState(text) {
        var focused = document.activeElement === el.scanInput;
        el.scanBar.classList.toggle('is-idle', !focused && !text);
        el.scanBar.classList.toggle('is-busy', !!text);
        el.scanState.textContent = text || (focused ? 'Ready to scan' : 'Click here to scan');
    }

    /* ---------- scanning ---------- */
    function onScan(code) {
        if (paying) {
            if (saleDone) {
                closePay();
            } else {
                EP_Scanner.beep(false);
                status('error', 'Payment in progress', 'Finish or cancel the payment before scanning more items.');
                return;
            }
        }
        setScanState('Looking up ' + code + '…');
        lookup(code).then(function (d) {
            if (!d || !d.ok) {
                EP_Scanner.beep(false);
                status('error', d && d.error === 'not_found' ? 'Product not found' : 'Scan rejected', (d && d.message) || 'Unknown error.');
                return;
            }
            var p = d.product;
            var line = findLine(p.id);
            var inCart = line ? line.qty : 0;
            if (line) { line.stock = p.stock; line.priceC = toCents(p.price); line.name = p.name; }
            if (p.stock <= 0) {
                EP_Scanner.beep(false);
                status('error', 'Out of stock', p.name + ' has no stock left.');
                render();
                return;
            }
            if (inCart + 1 > p.stock) {
                EP_Scanner.beep(false);
                status('error', 'Not enough stock', 'Only ' + p.stock + ' of ' + p.name + ' available (' + inCart + ' already in this sale).');
                render();
                return;
            }
            if (line) {
                line.qty = inCart + 1;
            } else {
                cart.push({ id: p.id, name: p.name, category: p.category, barcode: p.barcode,
                            priceC: toCents(p.price), stock: p.stock, qty: 1 });
            }
            saveCart();
            render(p.id);
            EP_Scanner.beep(true);
            status('success', 'Added: ' + p.name,
                peso(toCents(p.price)) + (line ? ' · quantity now ' + (inCart + 1) : '') + ' · ' + (p.stock - inCart - 1) + ' left in stock');
        }).catch(function () {
            EP_Scanner.beep(false);
            status('error', 'Connection problem', 'Could not reach the server. Check the connection and scan again.');
        }).then(function () { setScanState(null); });
    }

    var scanner = EP_Scanner.attach(el.scanInput, {
        onScan: onScan,
        onInvalid: function (res) { status('error', 'Invalid barcode', res.message); },
        /* not while paying, or while the account menu in the top bar is open */
        canRefocus: function () { return !paying && !document.querySelector('.cx-user.is-open'); }
    });
    el.scanInput.addEventListener('focus', function () { setScanState(null); });
    el.scanInput.addEventListener('blur', function () { setTimeout(function () { setScanState(null); }, 10); });
    el.scanBar.addEventListener('click', function () { el.scanInput.focus(); });

    /* ---------- cart editing ---------- */
    el.cartWrap.addEventListener('change', function (e) {
        var input = e.target.closest('.pos-qty');
        if (!input) return;
        var line = findLine(Number(input.getAttribute('data-id')));
        if (!line) return;
        var q = parseInt(input.value, 10);
        if (!(q >= 1)) q = 1;
        var max = Math.min(line.stock, POS_CONFIG.maxQty);
        if (q > max) {
            EP_Scanner.beep(false);
            status('error', 'Not enough stock', 'Only ' + line.stock + ' of ' + line.name + ' available.');
            q = Math.max(1, max);
        }
        line.qty = q;
        saveCart();
        render();
    });
    el.cartWrap.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList.contains('pos-qty')) {
            e.preventDefault();
            e.target.blur();
        }
    });
    el.cartWrap.addEventListener('click', function (e) {
        /* − / + steppers go through the quantity field's own change handling */
        var step = e.target.closest('[data-step]');
        if (step) {
            var qty = el.cartWrap.querySelector('.pos-qty[data-id="' + step.getAttribute('data-id') + '"]');
            if (!qty) return;
            qty.value = Math.max(1, (parseInt(qty.value, 10) || 1) + Number(step.getAttribute('data-step')));
            qty.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }
        var btn = e.target.closest('[data-remove]');
        if (!btn) return;
        var id = Number(btn.getAttribute('data-remove'));
        var line = findLine(id);
        cart = cart.filter(function (l) { return l.id !== id; });
        saveCart();
        render();
        if (line) status('info', 'Removed: ' + line.name, 'The line was removed from this sale.');
    });
    el.clearBtn.addEventListener('click', function () {
        if (!cart.length) return;
        IAS_UI.confirm('Every item in this sale will be removed.', {
            title: 'Clear this sale?', confirmLabel: 'Clear sale', type: 'danger'
        }).then(function (ok) {
            if (!ok) return;
            cart = [];
            saveCart();
            render();
            status('info', 'Sale cleared', 'Scan a product to start again.');
        });
    });

    /** Re-read price and stock for every line (after reload or a price change). */
    function refreshCart() {
        var jobs = cart.filter(function (l) { return l.barcode; }).map(function (l) {
            return lookup(l.barcode).then(function (d) {
                if (d && d.ok) { l.priceC = toCents(d.product.price); l.stock = d.product.stock; l.name = d.product.name; }
            }).catch(function () {});
        });
        return Promise.all(jobs).then(function () { saveCart(); render(); });
    }

    /* ---------- payment ---------- */
    function selectMethod(m) {
        method = m;
        Array.prototype.forEach.call(el.payMethods.querySelectorAll('.pay-method'), function (b) {
            var on = b.getAttribute('data-method') === m;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        el.cashFields.hidden = m !== 'cash';
        el.refFields.hidden = m === 'cash';
        el.payError.hidden = true;
        updateChange();
        setTimeout(function () { (m === 'cash' ? el.tendered : el.payRef).focus(); }, 20);
    }
    function updateChange() {
        var total = totals().total;
        var tendered = parseMoney(el.tendered.value);
        var ok = method !== 'cash' || (tendered !== null && tendered >= total);
        if (method === 'cash') {
            if (tendered === null) {
                el.changeVal.textContent = '—';
                el.payChange.className = 'pay-change';
            } else if (tendered < total) {
                el.changeVal.textContent = 'Short ' + peso(total - tendered);
                el.payChange.className = 'pay-change short';
            } else {
                el.changeVal.textContent = peso(tendered - total);
                el.payChange.className = 'pay-change ok';
            }
        }
        if (method !== 'cash') ok = el.payRef.value.trim() !== '';
        el.payConfirm.disabled = !ok || submitting;
    }
    function quickAmounts(total) {
        var list = [total];
        [100, 500, 1000].forEach(function (step) {
            var v = Math.ceil(total / (step * 100)) * step * 100;
            if (list.indexOf(v) === -1) list.push(v);
        });
        el.payQuick.innerHTML = list.map(function (v, i) {
            return '<button type="button" class="pay-chip-amt' + (i === 0 ? ' is-exact' : '') + '" data-amount="' + (v / 100).toFixed(2) + '">' +
                (i === 0 ? '<span>Exact</span>' : '') + peso(v) + '</button>';
        }).join('');
    }
    function openPay() {
        if (!cart.length) return;
        var bad = cart.filter(function (l) { return l.qty > l.stock; })[0];
        if (bad) {
            EP_Scanner.beep(false);
            status('error', 'Not enough stock', 'Reduce ' + bad.name + ' to ' + bad.stock + ' or remove it before checkout.');
            return;
        }
        if (!clientRef) clientRef = uuid();
        paying = true;
        saleDone = false;
        el.payForm.hidden = false;
        el.payDone.hidden = true;
        el.payTotal.textContent = peso(totals().total);
        el.tendered.value = '';
        el.payRef.value = '';
        quickAmounts(totals().total);
        el.payModal.classList.add('open');
        selectMethod('cash');
    }
    function closePay() {
        if (submitting) return;
        paying = false;
        saleDone = false;
        el.payModal.classList.remove('open');
        scanner.refocus();
    }
    function payFail(msg) {
        el.payError.innerHTML = '<i class="ph-fill ph-x-circle" aria-hidden="true"></i><span>' + esc(msg) + '</span>';
        el.payError.hidden = false;
        EP_Scanner.beep(false);
    }
    function confirmPay() {
        if (submitting) return;
        var t = totals();
        var tendered = parseMoney(el.tendered.value);
        if (method === 'cash' && (tendered === null || tendered < t.total)) {
            payFail('Cash tendered must be at least ' + peso(t.total) + '.');
            return;
        }
        if (method !== 'cash' && el.payRef.value.trim() === '') {
            payFail('Enter the reference number for this payment.');
            return;
        }
        if (!clientRef) clientRef = uuid();
        submitting = true;
        el.payConfirm.disabled = true;
        el.payConfirm.innerHTML = '<i class="ph ph-spinner-gap cx-spin" aria-hidden="true"></i> Processing…';
        el.payError.hidden = true;

        fetch(API + 'stock_out.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                csrf_token: POS_CONFIG.csrf,
                client_ref: clientRef,
                items: cart.map(function (l) { return { product_id: l.id, qty: l.qty }; }),
                payment: {
                    method: method,
                    tendered: method === 'cash' ? (tendered / 100).toFixed(2) : null,
                    ref: method === 'cash' ? '' : el.payRef.value.trim()
                },
                expected_total: (t.total / 100).toFixed(2)
            })
        }).then(readJson).then(function (d) {
            if (d && d.ok) {
                cart = [];
                saveCart();
                render();
                saleDone = true;
                el.payForm.hidden = true;
                el.payDone.hidden = false;
                el.doneInvoice.textContent = d.invoice_no;
                el.doneChange.textContent = method === 'cash' ? 'Change: ' + peso(toCents(d.change)) : 'Paid via ' + method.toUpperCase();
                el.donePrint.href = 'cashier_receipt.php?id=' + encodeURIComponent(d.sale_id) + '&print=1';
                EP_Scanner.beep(true);
                status('success', 'Sale completed: ' + d.invoice_no, 'Total ' + peso(toCents(d.total)) + ' · stock updated.');
                el.donePrint.focus();
                return;
            }
            payFail((d && d.message) || 'The sale could not be completed.');
            if (d && d.error === 'insufficient_stock' && d.product_id) {
                var line = findLine(d.product_id);
                if (line) { line.stock = d.available; render(); }
            }
            if (d && d.error === 'total_mismatch') {
                refreshCart().then(function () {
                    el.payTotal.textContent = peso(totals().total);
                    quickAmounts(totals().total);
                    updateChange();
                });
            }
        }).catch(function () {
            payFail('Connection problem. The sale may not have been saved — press Complete sale again; it will not be recorded twice.');
        }).then(function () {
            submitting = false;
            el.payConfirm.innerHTML = CONFIRM_HTML;
            updateChange();
        });
    }

    el.checkoutBtn.addEventListener('click', openPay);
    el.payClose.addEventListener('click', closePay);
    document.getElementById('doneNew').addEventListener('click', closePay);
    el.payModal.addEventListener('click', function (e) { if (e.target === el.payModal) closePay(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && paying) closePay(); });
    el.payMethods.addEventListener('click', function (e) {
        var b = e.target.closest('.pay-method');
        if (b) selectMethod(b.getAttribute('data-method'));
    });
    el.payQuick.addEventListener('click', function (e) {
        var b = e.target.closest('[data-amount]');
        if (!b) return;
        el.tendered.value = b.getAttribute('data-amount');
        updateChange();
        el.tendered.focus();
    });
    el.tendered.addEventListener('input', updateChange);
    el.payRef.addEventListener('input', updateChange);
    [el.tendered, el.payRef].forEach(function (inp) {
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); confirmPay(); }
        });
    });
    el.payConfirm.addEventListener('click', confirmPay);

    render();
    setScanState(null);
    if (cart.length) {
        refreshCart();
        status('info', 'Sale restored', 'Items from the unfinished sale were restored with current prices and stock.');
    }
})();
</script>
SCRIPT;

staff_page_end($script);
