/*
 * Print a receipt from the current tab. Links marked data-print-receipt
 * (POS "Print receipt", dashboard "Reprint") load cashier_receipt.php?frame=1
 * into a hidden iframe and open the print dialog for it, instead of opening a
 * new tab. Ctrl/Shift/middle-click still opens the receipt page normally.
 * If the frame does not come back with a receipt (session expired, server
 * error), the link is followed in this tab so the normal page handles it.
 */
(function (global) {
    'use strict';

    var frame = null;
    var busy = false;
    var LOAD_TIMEOUT_MS = 15000;

    function frameUrl(href) {
        var u = new URL(href, global.location.href);
        u.searchParams.delete('print');
        u.searchParams.set('frame', '1');
        u.searchParams.set('_', String(Date.now())); // always reload, even for the same receipt
        return u.toString();
    }

    function setBusy(btn, on) {
        if (!btn) return;
        btn.classList.toggle('is-busy', on);
        if (on) btn.setAttribute('aria-busy', 'true'); else btn.removeAttribute('aria-busy');
        var icon = btn.querySelector('i');
        if (icon) icon.className = on ? 'ph ph-spinner-gap cx-spin' : 'ph ph-printer';
    }

    /* IAS_UI is a top-level const in ui_alerts.js, so it is not a window property */
    function notify(msg) {
        if (typeof IAS_UI !== 'undefined') IAS_UI.alert(msg, 'error');
    }

    function print(href, btn) {
        if (busy) return;
        busy = true;
        setBusy(btn, true);
        if (!frame) {
            frame = document.createElement('iframe');
            frame.className = 'cx-print-frame';
            frame.title = 'Receipt for printing';
            frame.setAttribute('aria-hidden', 'true');
            frame.tabIndex = -1;
            document.body.appendChild(frame);
        }
        var settled = false;
        var timer = setTimeout(function () {
            finish();
            notify('The receipt took too long to load. Check the connection and try again.');
        }, LOAD_TIMEOUT_MS);

        function finish() {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            busy = false;
            setBusy(btn, false);
        }

        frame.onload = function () {
            if (settled) return;
            var doc = null;
            try { doc = frame.contentDocument; } catch (e) { doc = null; }
            if (!doc || !doc.querySelector('.receipt')) {
                finish();
                if (doc && doc.querySelector('[data-receipt-missing]')) {
                    notify('This receipt does not exist or belongs to another cashier.');
                } else {
                    global.location.href = href; // e.g. session expired: let the page redirect to login
                }
                return;
            }
            var win = frame.contentWindow;
            var go = function () {
                clearTimeout(timer);
                try {
                    win.focus();
                    win.print(); // blocks until the dialog closes in most browsers
                } catch (e) {
                    global.location.href = href;
                }
                setTimeout(finish, 300);
            };
            (doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve()).then(go, go);
        };
        frame.src = frameUrl(href);
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-print-receipt]');
        if (!link || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        print(link.href, link);
    });

    global.CashierReceiptPrint = { print: print };
})(window);
