/**
 * Fire-and-forget Render warm-up (server side: backend/api/warmup.php).
 *
 * <script src="../includes/service_warmup.js" data-svc="forecast" defer></script>   staff shell (forecast roles)
 * <script src="../includes/service_warmup.js" data-svc="primo" defer></script>      Primo widget (CLIENT/primo.php)
 *
 * Sends one background GET after the page has loaded, at most once per tab every 5 minutes per service, and never
 * waits for or shows the answer. Its only job is to make a sleeping Render instance start booting early, so the
 * Forecast page or the first Primo reply does not pay the full cold-start wait.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script || !window.fetch) return;
    var svc = script.getAttribute('data-svc');
    if (svc !== 'forecast' && svc !== 'primo') return;

    var url;
    try {
        url = new URL('../backend/api/warmup.php?svc=' + svc, script.src).href;
    } catch (e) {
        return;
    }

    var KEY = 'tp_warmup_' + svc;
    var EVERY_MS = 5 * 60 * 1000;

    function recentlyWarmed() {
        try {
            var last = parseInt(window.sessionStorage.getItem(KEY) || '0', 10);
            return last > 0 && Date.now() - last < EVERY_MS;
        } catch (e) {
            return false;   // storage blocked: the server-side throttle still applies
        }
    }

    function warm() {
        if (recentlyWarmed()) return;
        try {
            window.sessionStorage.setItem(KEY, String(Date.now()));
        } catch (e) { /* ignore */ }
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: true,
            headers: { 'Accept': 'application/json' }
        }).catch(function () { /* background only: nothing to report */ });
    }

    // Let the page's own requests go first.
    function later() {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(warm, { timeout: 4000 });
        } else {
            window.setTimeout(warm, 1500);
        }
    }

    if (document.readyState === 'complete') {
        later();
    } else {
        window.addEventListener('load', later, { once: true });
    }
})();
