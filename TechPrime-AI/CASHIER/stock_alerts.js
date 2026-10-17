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
    var ICONS = { out: 'ph-x-circle', critical: 'ph-warning-circle', low: 'ph-warning', ok: 'ph-check-circle' };

    function esc(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function pill(status) {
        var s = LABELS[status] ? status : 'ok';
        return '<span class="stock-status-pill ' + s + '"><i class="ph-fill ' + ICONS[s] + '" aria-hidden="true"></i> ' + LABELS[s] + '</span>';
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

    /**
     * Calls onData(data, fetchedAt) with every fresh result, and onError() when a poll
     * fails (network, server error, bad JSON) so the page can say its data is stale.
     * Returns {refresh}.
     */
    function sync(url, onData, onError) {
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
                    } else if (onError) {
                        onError();
                    }
                })
                .catch(function () {
                    // Keep showing the last data and retry on the next tick, but say so.
                    if (onError) onError();
                })
                .then(function () { busy = false; });
        }
        setInterval(load, POLL_MS);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
        return { refresh: load };
    }

    /** Live label helpers shared by the dashboard panel and the Stock Alerts page. */
    var lastUpdate = null;
    function markLive(el, at) {
        lastUpdate = at;
        el.classList.remove('is-stale');
        el.textContent = 'Live · updated ' + timeLabel(at);
    }
    function markStale(el) {
        el.classList.add('is-stale');
        el.textContent = 'Connection problem · showing data from ' + (lastUpdate ? timeLabel(lastUpdate) : 'page load') + ', retrying';
    }

    global.CashierStockAlerts = {
        esc: esc, pill: pill, qtyClass: qtyClass, peso: peso,
        barcodeLabel: barcodeLabel, timeLabel: timeLabel, sync: sync,
        markLive: markLive, markStale: markStale
    };
})(window);
