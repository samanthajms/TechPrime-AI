/**
 * Idle-session warning and "session expired" dialog.
 * Config: window.IAS_SESSION_CFG, printed by ias_session_timeout_assets() on signed-in pages
 * (staff layout, client header) and by ias_session_expired_notice() on login.php.
 *
 * - User activity pings backend/api/session.php (at most once a minute), so someone working
 *   on a page is not signed out; background polling does not count as activity.
 * - cfg.warn seconds before the idle timeout a countdown warning appears.
 * - At the timeout the server ends the session and the dialog shows the session details with
 *   a "Log in again" button, instead of the next click landing on an error page.
 * The server is asked for the real time left before warning or ending, so activity in another
 * tab (or any request that went through checkSessionTimeout()) is respected.
 */
(function () {
    'use strict';
    var cfg = window.IAS_SESSION_CFG;
    if (!cfg || window.IAS_Session) return;

    var TZ = 'Asia/Manila';
    var PING_EVERY = 60 * 1000;
    var SYNC_KEY = 'ias_session_deadline';
    var CSS = [
        '.ias-sess-overlay{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;',
        'padding:16px;background:rgba(15,23,32,.6);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);',
        'font-family:Poppins,"Segoe UI",Arial,sans-serif;animation:ias-sess-fade .18s ease-out}',
        '.ias-sess-card{width:min(420px,100%);max-height:calc(100vh - 32px);overflow:auto;box-sizing:border-box;background:#fff;',
        'border-radius:18px;box-shadow:0 24px 60px rgba(0,0,0,.28);padding:28px 24px 22px;text-align:center;color:#1f2328;',
        'animation:ias-sess-pop .2s ease-out}',
        '.ias-sess-icon{width:58px;height:58px;margin:0 auto 14px;border-radius:16px;display:flex;align-items:center;',
        'justify-content:center;font-size:24px;background:#eef8e6;color:#4b8b2a}',
        '.ias-sess-icon.is-warn{background:#fff6d1;color:#a07d00}',
        '.ias-sess-icon.is-ended{background:#fdecec;color:#c0392b}',
        '.ias-sess-title{margin:0 0 6px;font-size:20px;font-weight:700;line-height:1.3;color:#1f2328}',
        '.ias-sess-text{margin:0 0 16px;font-size:14px;line-height:1.55;color:#64748b}',
        '.ias-sess-count{margin:-6px 0 18px;font-size:36px;font-weight:800;line-height:1.1;color:#1f2328;font-variant-numeric:tabular-nums}',
        '.ias-sess-info{margin:0 0 14px;padding:2px 14px;text-align:left;background:#f7f9fa;border:1px solid #e4e8ea;border-radius:12px}',
        '.ias-sess-info div{display:flex;justify-content:space-between;gap:14px;padding:9px 0;border-bottom:1px solid #e4e8ea;font-size:13px}',
        '.ias-sess-info div:last-child{border-bottom:0}',
        '.ias-sess-info dt{color:#64748b;flex-shrink:0}',
        '.ias-sess-info dd{margin:0;font-weight:600;text-align:right;overflow-wrap:anywhere;color:#1f2328}',
        '.ias-sess-info dd small{display:block;font-weight:500;color:#64748b}',
        '.ias-sess-note{margin:0 0 18px;font-size:12.5px;line-height:1.5;color:#64748b}',
        '.ias-sess-actions{display:flex;flex-wrap:wrap;gap:10px}',
        '.ias-sess-btn{flex:1 1 140px;padding:12px 16px;border-radius:10px;border:1px solid transparent;cursor:pointer;',
        'font:600 14px/1.2 Poppins,"Segoe UI",Arial,sans-serif;transition:background .15s,border-color .15s}',
        '.ias-sess-btn.is-primary{background:#62b236;color:#fff}',
        '.ias-sess-btn.is-primary:hover{background:#4b8b2a}',
        '.ias-sess-btn.is-ghost{background:#fff;color:#1f2328;border-color:#d7dde0}',
        '.ias-sess-btn.is-ghost:hover{background:#f3f4f5}',
        '.ias-sess-btn:focus-visible{outline:3px solid rgba(98,178,54,.45);outline-offset:2px}',
        'body.ias-sess-open{overflow:hidden}',
        '@keyframes ias-sess-fade{from{opacity:0}to{opacity:1}}',
        '@keyframes ias-sess-pop{from{transform:translateY(8px) scale(.98);opacity:0}to{transform:none;opacity:1}}',
        '@media (prefers-reduced-motion:reduce){.ias-sess-overlay,.ias-sess-card{animation:none}}'
    ].join('');

    /* ---------- dialog ---------- */

    var overlay = null;

    function make(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function closeDialog() {
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        document.body.classList.remove('ias-sess-open');
    }

    /**
     * @param {{tone:string, icon:string, title:string, text:string, count?:boolean,
     *          info?:Array, note?:string, actions:Array<{label:string, primary?:boolean, onClick:function}>}} o
     */
    function openDialog(o) {
        if (!document.getElementById('ias-sess-style')) {
            var style = make('style', null, CSS);
            style.id = 'ias-sess-style';
            document.head.appendChild(style);
        }
        var reuse = !!overlay; // warning -> expired: swap the card, keep the backdrop
        if (reuse) overlay.textContent = '';
        else overlay = make('div', 'ias-sess-overlay');
        var card = make('div', 'ias-sess-card');
        card.setAttribute('role', 'alertdialog');
        card.setAttribute('aria-modal', 'true');
        card.setAttribute('aria-labelledby', 'iasSessTitle');
        card.setAttribute('aria-describedby', 'iasSessText');

        var icon = make('div', 'ias-sess-icon is-' + o.tone);
        icon.setAttribute('aria-hidden', 'true');
        icon.appendChild(make('i', 'fas ' + o.icon));
        var title = make('h2', 'ias-sess-title', o.title);
        title.id = 'iasSessTitle';
        var text = make('p', 'ias-sess-text', o.text);
        text.id = 'iasSessText';
        card.appendChild(icon);
        card.appendChild(title);
        card.appendChild(text);

        var count = null;
        if (o.count) {
            count = make('div', 'ias-sess-count');
            card.appendChild(count);
        }
        if (o.info && o.info.length) {
            var dl = make('dl', 'ias-sess-info');
            o.info.forEach(function (row) {
                var wrap = make('div');
                var dd = make('dd');
                wrap.appendChild(make('dt', null, row[0]));
                if (Array.isArray(row[1])) {
                    dd.appendChild(document.createTextNode(row[1][0] || row[1][1]));
                    if (row[1][0] && row[1][1]) dd.appendChild(make('small', null, row[1][1]));
                } else {
                    dd.textContent = row[1];
                }
                wrap.appendChild(dd);
                dl.appendChild(wrap);
            });
            card.appendChild(dl);
        }
        if (o.note) card.appendChild(make('p', 'ias-sess-note', o.note));

        var actions = make('div', 'ias-sess-actions');
        var buttons = o.actions.map(function (a) {
            var b = make('button', 'ias-sess-btn ' + (a.primary ? 'is-primary' : 'is-ghost'), a.label);
            b.type = 'button';
            b.addEventListener('click', function () { a.onClick(b); });
            actions.appendChild(b);
            return b;
        });
        card.appendChild(actions);

        // Keep keyboard focus inside the dialog.
        card.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab' || buttons.length < 2) return;
            var first = buttons[0], last = buttons[buttons.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });

        overlay.appendChild(card);
        if (!reuse) document.body.appendChild(overlay);
        document.body.classList.add('ias-sess-open'); // barcode_scanner.js stops refocusing its input
        if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
        buttons[0].focus({ preventScroll: true });
        return { count: count };
    }

    /* ---------- session details ---------- */

    function fmt(ts, withDate) {
        var o = { timeZone: TZ, hour: 'numeric', minute: '2-digit' };
        if (withDate) { o.month = 'short'; o.day = 'numeric'; o.year = 'numeric'; }
        try { return new Date(ts * 1000).toLocaleString('en-US', o); }
        catch (e) { return new Date(ts * 1000).toLocaleString(); }
    }
    function day(ts) {
        if (!ts) return '';
        try { return new Date(ts * 1000).toLocaleDateString('en-US', { timeZone: TZ }); }
        catch (e) { return new Date(ts * 1000).toDateString(); }
    }

    function infoRows(s) {
        var rows = [];
        if (s.name || s.email) rows.push(['Account', [s.name, s.email]]);
        if (s.role_label) rows.push(['Role', s.role_label]);
        if (s.login_at) rows.push(['Signed in', fmt(s.login_at, true)]);
        if (s.last_activity) rows.push(['Last activity', fmt(s.last_activity, day(s.last_activity) !== day(s.login_at))]);
        if (s.expired_at) rows.push(['Signed out', fmt(s.expired_at, day(s.expired_at) !== day(s.last_activity || s.login_at))]);
        return rows;
    }

    function merge(a, b) {
        var out = {}, k;
        for (k in a) if (Object.prototype.hasOwnProperty.call(a, k)) out[k] = a[k];
        for (k in b) if (Object.prototype.hasOwnProperty.call(b, k) && b[k] !== null && b[k] !== '') out[k] = b[k];
        return out;
    }

    function showEnded(serverInfo, reason) {
        var s = merge(cfg.session || {}, serverInfo || {});
        var mins = s.timeout_minutes || Math.round(cfg.timeout / 60);
        var expired = reason !== 'signed_out';
        var onLoginPage = !!cfg.notice;
        var isClient = !s.role || s.role === 'client' || s.role === 'customer';
        var actions = [{
            label: 'Log in again',
            primary: true,
            onClick: function () {
                if (!onLoginPage) { window.location.href = cfg.login; return; }
                closeDialog();
                var email = document.getElementById('loginEmail');
                var pass = document.getElementById('loginPassword');
                if (email && !email.value && s.email) email.value = s.email;
                var target = email && !email.value ? email : pass;
                if (target) target.focus();
            }
        }];
        if (isClient && !onLoginPage) {
            actions.push({ label: 'Continue as guest', onClick: function () { window.location.reload(); } });
        }
        openDialog({
            tone: 'ended',
            icon: expired ? 'fa-user-clock' : 'fa-sign-out-alt',
            title: expired ? 'Session expired' : 'You have been signed out',
            text: expired
                ? 'For your security, you were signed out after ' + mins + ' minutes of inactivity. Please log in again to continue.'
                : 'This session is no longer active (you may have logged out in another tab). Please log in again to continue.',
            info: infoRows(s),
            note: onLoginPage ? '' : 'Changes on this page that were not saved before the session ended were not submitted.',
            actions: actions
        });
    }

    /* ---------- login page: server already ended the session ---------- */

    if (cfg.notice) {
        showEnded(cfg.notice, 'session_expired');
        if (window.history.replaceState) {
            var u = new URL(window.location.href);
            u.searchParams.delete('expired');
            window.history.replaceState({}, '', u);
        }
        return;
    }

    /* ---------- signed-in pages: idle tracking ---------- */

    var deadline = Date.now() + Math.max(0, cfg.remaining) * 1000;
    var lastTouch = deadline - cfg.timeout * 1000; // last server-side activity, client clock
    var timer = null, countTimer = null, countEl = null;
    var warning = false, ended = false, pinging = false;

    function request(method, action) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (method === 'POST') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({ csrf_token: cfg.csrf });
        }
        return fetch(cfg.api + '?action=' + action, opts).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (d) {
                return { status: r.status, data: d };
            });
        }, function () {
            return { status: 0, data: null };
        });
    }

    function broadcast(value) {
        try { localStorage.setItem(SYNC_KEY, value + '|' + Date.now()); } catch (e) {}
    }

    function schedule(delay) {
        clearTimeout(timer);
        if (ended) return;
        if (delay === undefined) delay = (warning ? deadline : deadline - cfg.warn * 1000) - Date.now();
        timer = setTimeout(check, Math.max(1000, delay));
    }

    function check() {
        if (!ended) request('GET', 'status').then(apply);
    }

    function ping() {
        if (pinging || ended) return;
        pinging = true;
        lastTouch = Date.now();
        request('POST', 'ping').then(function (res) {
            pinging = false;
            apply(res);
        });
    }

    function apply(res) {
        if (ended) return;
        var d = res.data;
        if (d && d.ok) {
            setDeadline(Date.now() + d.remaining * 1000);
            broadcast(deadline);
            return;
        }
        if (d && (d.error === 'session_expired' || d.error === 'signed_out')) {
            end(d.session, d.error);
            return;
        }
        schedule(15000); // offline / server error: keep the session, try again shortly
    }

    function setDeadline(ms) {
        deadline = ms;
        lastTouch = ms - cfg.timeout * 1000;
        if (deadline - Date.now() <= cfg.warn * 1000) showWarning(); else hideWarning();
        schedule();
    }

    function tick() {
        if (!countEl) return;
        var s = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        countEl.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }

    function showWarning() {
        if (warning) return;
        warning = true;
        countEl = openDialog({
            tone: 'warn',
            icon: 'fa-hourglass-half',
            title: 'Are you still there?',
            text: 'You have been inactive for a while. For your security, you will be signed out in',
            count: true,
            actions: [
                { label: 'Stay signed in', primary: true, onClick: ping },
                { label: 'Log out', onClick: function () { window.location.href = cfg.logout; } }
            ]
        }).count;
        tick();
        countTimer = setInterval(tick, 1000);
    }

    function hideWarning() {
        if (!warning) return;
        warning = false;
        clearInterval(countTimer);
        countEl = null;
        closeDialog();
    }

    function end(info, reason) {
        warning = false;
        clearInterval(countTimer);
        countEl = null;
        ended = true;
        clearTimeout(timer);
        showEnded(info, reason);
        broadcast('ended');
    }

    function onActivity() {
        if (warning || ended || Date.now() - lastTouch < PING_EVERY) return;
        ping();
    }
    ['mousedown', 'mousemove', 'keydown', 'touchstart', 'wheel', 'scroll'].forEach(function (ev) {
        document.addEventListener(ev, onActivity, { capture: true, passive: true });
    });

    // Timers are throttled or frozen in background tabs and during sleep: re-check on return.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') check();
    });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) check();
    });

    // Other tabs of the same session: a newer deadline means someone is active there.
    window.addEventListener('storage', function (e) {
        if (e.key !== SYNC_KEY || !e.newValue || ended) return;
        var v = e.newValue.split('|')[0];
        if (v === 'ended') { check(); return; }
        var ms = +v;
        if (ms > deadline) setDeadline(ms);
    });

    schedule();

    /** check(): ask the server now, e.g. after an API call answered 401. */
    window.IAS_Session = { check: check };
})();
