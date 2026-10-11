/*
 * Cashier stock alerts: shared rendering helpers and live sync with
 * backend/api/stock_alerts.php (dashboard panel + Stock Alerts page).
 * Polls every 30 s while the tab is visible and right away when it becomes
 * visible again. Polling does not count as session activity.
 */
(function (global) {
    'use strict';

    var POLL_MS = 30000;
    var LABELS = { out: 'Out of Stock', critical: 'Critical Stock', low: 'Low Stock', ok: 'In Stock' };
    var ICONS = { out: 'fa-times-circle', critical: 'fa-exclamation-circle', low: 'fa-exclamation-triangle', ok: 'fa-check-circle' };

    function esc(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function pill(status) {
        var s = LABELS[status] ? status : 'ok';
        return '<span class="stock-status-pill ' + s + '"><i class="fas ' + ICONS[s] + '"></i> ' + LABELS[s] + '</span>';
    }
    function qtyClass(status) {
        return status === 'out' ? ' out' : (status === 'ok' ? '' : ' warn');
    }
    function peso(n) {
        return '₱' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    /* UPC-A is stored as a 13-digit GTIN with a leading 0; show the 12 digits printed on the box. */
    function barcodeLabel(code) {
        code = String(code || '');
        return (code.length === 13 && code.charAt(0) === '0') ? code.slice(1) : code;
    }
    function timeLabel(d) {
        return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    }

    /** Calls onData(data, fetchedAt) with every fresh result. Returns {refresh}. */
    function sync(url, onData) {
        var stopped = false;
        var busy = false;
        function load() {
            if (stopped || busy || document.hidden) return;
            busy = true;
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.ok) {
                        onData(d, new Date());
                    } else if (d && (d.error === 'session_expired' || d.error === 'forbidden')) {
                        stopped = true;
                        if (global.IAS_Session) global.IAS_Session.check();
                    }
                })
                .catch(function () { /* keep showing the last data; retry on the next tick */ })
                .then(function () { busy = false; });
        }
        setInterval(load, POLL_MS);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
        return { refresh: load };
    }

    global.CashierStockAlerts = {
        esc: esc, pill: pill, qtyClass: qtyClass, peso: peso,
        barcodeLabel: barcodeLabel, timeLabel: timeLabel, sync: sync
    };
})(window);
