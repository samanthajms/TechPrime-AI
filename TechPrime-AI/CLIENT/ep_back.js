/**
 * ep_back.js — "Back" links that return to the storefront page the shopper came from.
 *
 * Markup: <a href="fallback.php" data-ep-back>…</a>
 *   - Came from another CLIENT page → the link points there and a plain click uses
 *     history.back(), so the previous page keeps its filters and scroll position.
 *   - Reloaded / paged / redirected onto the same page → reuse the origin remembered
 *     for this page in this tab (sessionStorage).
 *   - Anything else (typed URL, login redirect, other site) → the fallback href.
 */
(function () {
    'use strict';

    var SKIP_PAGES = /\/(checkout|order_success)\.php$/i;   // never step "back" into a finished flow
    var ONE_SHOT_PARAMS = ['added', 'updated', 'removed', 'alert', 'error'];
    var here = location.pathname;
    var dir = here.slice(0, here.lastIndexOf('/') + 1);
    var storeKey = 'epBack:' + here;

    function clientUrl(raw) {
        var u;
        try { u = new URL(raw, location.href); } catch (e) { return null; }
        if (u.origin !== location.origin || u.pathname.indexOf(dir) !== 0 || SKIP_PAGES.test(u.pathname)) {
            return null;
        }
        ONE_SHOT_PARAMS.forEach(function (p) { u.searchParams.delete(p); });
        u.hash = '';
        return u;
    }

    function store(fn) {
        try { return fn(window.sessionStorage); } catch (e) { return null; }
    }

    var ref = document.referrer ? clientUrl(document.referrer) : null;
    var fromOtherPage = !!ref && ref.pathname !== here;
    var target = null;

    if (fromOtherPage) {
        target = ref.href;
        store(function (s) { s.setItem(storeKey, target); });
    } else if (ref) {
        var saved = store(function (s) { return s.getItem(storeKey); });
        var savedUrl = saved ? clientUrl(saved) : null;
        target = savedUrl && savedUrl.pathname !== here ? savedUrl.href : null;
    } else {
        store(function (s) { s.removeItem(storeKey); });
    }

    function apply() {
        if (!target) return;
        document.querySelectorAll('a[data-ep-back]').forEach(function (a) { a.href = target; });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }

    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[data-ep-back]') : null;
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        if (fromOtherPage && history.length > 1) {
            e.preventDefault();
            history.back();
        }
    });
})();
