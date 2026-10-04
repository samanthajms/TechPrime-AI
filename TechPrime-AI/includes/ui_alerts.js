/**
 * EasyPC E-commerce — single shared alert modal (centered, close button).
 * IAS_UI.alert(message, type, durationMs, opts) — durationMs 0 = until closed.
 * opts (optional): {
 *   title:   heading text (defaults per type),
 *   detail:  short highlighted line shown under the message (e.g. a name),
 *   actions: [{ label, href, primary }] — buttons; without href they just close.
 *            Defaults to a single "OK" button.
 * }
 */
const IAS_UI = {
    _styled: false,

    _injectStyles: function () {
        if (this._styled) return;
        this._styled = true;
        const css =
            '#ias-alert-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);display:flex;justify-content:center;' +
            'align-items:center;z-index:9999;padding:20px;animation:iasFade .18s ease-out}' +
            '.ias-alert-box{background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(15,23,42,.25);text-align:center;' +
            'max-width:400px;width:100%;position:relative;overflow:hidden;font-family:inherit;animation:iasPop .22s cubic-bezier(.2,.9,.3,1.2)}' +
            '.ias-alert-box::before{content:"";display:block;height:4px;background:var(--ias-accent)}' +
            '.ias-alert-body{padding:28px 28px 22px}' +
            '.ias-alert-close{position:absolute;top:12px;right:12px;width:32px;height:32px;border:none;border-radius:8px;' +
            'background:none;font-size:22px;line-height:1;cursor:pointer;color:#94a3b8;display:flex;align-items:center;justify-content:center}' +
            '.ias-alert-close:hover{background:#f1f5f9;color:#334155}' +
            '.ias-alert-icon{width:60px;height:60px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;' +
            'justify-content:center;background:var(--ias-soft);color:var(--ias-accent)}' +
            '.ias-alert-icon svg{width:30px;height:30px}' +
            '.ias-alert-title{margin:0 0 8px;color:#1e293b;font-size:19px;font-weight:800}' +
            '.ias-alert-msg{margin:0;color:#475569;line-height:1.55;font-size:14.5px;white-space:pre-line}' +
            '.ias-alert-detail{display:inline-block;max-width:100%;margin:2px 0 12px;padding:7px 14px;border-radius:999px;' +
            'background:var(--ias-soft);color:var(--ias-accent-dark);font-weight:700;font-size:14px;overflow:hidden;' +
            'text-overflow:ellipsis;white-space:nowrap;vertical-align:top}' +
            '.ias-alert-actions{display:flex;gap:10px;justify-content:center;margin-top:22px;flex-wrap:wrap}' +
            '.ias-alert-btn{flex:1 1 0;min-width:120px;padding:11px 18px;border-radius:10px;font-weight:700;font-size:14px;' +
            'cursor:pointer;text-decoration:none;border:1.5px solid #e2e8f0;background:#fff;color:#334155;font-family:inherit;' +
            'transition:background .15s,border-color .15s,color .15s}' +
            '.ias-alert-btn:hover{border-color:var(--ias-accent);color:var(--ias-accent-dark)}' +
            '.ias-alert-btn.is-primary{background:var(--ias-accent);border-color:var(--ias-accent);color:#fff}' +
            '.ias-alert-btn.is-primary:hover{background:var(--ias-accent-dark);border-color:var(--ias-accent-dark);color:#fff}' +
            '.ias-alert-btn:focus-visible,.ias-alert-close:focus-visible{outline:3px solid var(--ias-soft);outline-offset:2px}' +
            '@keyframes iasFade{from{opacity:0}to{opacity:1}}' +
            '@keyframes iasPop{from{opacity:0;transform:translateY(8px) scale(.96)}to{opacity:1;transform:none}}' +
            '@media (prefers-reduced-motion:reduce){#ias-alert-overlay,.ias-alert-box{animation:none}}';
        const style = document.createElement('style');
        style.id = 'ias-alert-styles';
        style.textContent = css;
        document.head.appendChild(style);
    },

    alert: function (message, type = 'success', duration = 0, opts = {}) {
        const existing = document.getElementById('ias-alert-overlay');
        if (existing && typeof existing._iasClose === 'function') existing._iasClose(true);
        else if (existing) existing.remove();
        this._injectStyles();
        opts = opts || {};

        const themes = {
            success: {
                title: 'Success!', accent: 'var(--ep-green, #62b236)', dark: 'var(--ep-green-dark, #4b8b2a)', soft: '#eef8e6',
                icon: '<path d="M5 12.5l4.5 4.5L19 7.5"/>'
            },
            error: {
                title: 'Something went wrong', accent: '#dc2626', dark: '#b91c1c', soft: '#fdecec',
                icon: '<path d="M7 7l10 10M17 7L7 17"/>'
            },
            info: {
                title: 'Notice', accent: '#0998a8', dark: '#077784', soft: '#e6f6f8',
                icon: '<path d="M12 11v6"/><circle cx="12" cy="7.5" r=".6" fill="currentColor"/>'
            }
        };
        const t = themes[type] || themes.success;

        const overlay = document.createElement('div');
        overlay.id = 'ias-alert-overlay';

        const box = document.createElement('div');
        box.className = 'ias-alert-box';
        box.setAttribute('role', type === 'error' ? 'alertdialog' : 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-labelledby', 'ias-alert-title');
        box.setAttribute('aria-describedby', 'ias-alert-msg');
        box.style.setProperty('--ias-accent', t.accent);
        box.style.setProperty('--ias-accent-dark', t.dark);
        box.style.setProperty('--ias-soft', t.soft);

        box.innerHTML =
            '<button type="button" class="ias-alert-close" aria-label="Close">&times;</button>' +
            '<div class="ias-alert-body">' +
            '<div class="ias-alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">' + t.icon + '</svg></div>' +
            '<h3 class="ias-alert-title" id="ias-alert-title"></h3>' +
            '<p class="ias-alert-msg" id="ias-alert-msg"></p>' +
            '<div class="ias-alert-actions"></div>' +
            '</div>';

        box.querySelector('.ias-alert-title').textContent = opts.title || t.title;
        const msgEl = box.querySelector('.ias-alert-msg');
        msgEl.textContent = message;
        if (opts.detail) {
            const detail = document.createElement('div');
            detail.className = 'ias-alert-detail';
            detail.textContent = opts.detail;
            detail.title = opts.detail;
            msgEl.insertAdjacentElement('beforebegin', detail);
        }

        const prevFocus = document.activeElement;
        let timer = null;

        function onKey(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                removeAlert();
            }
        }

        function removeAlert(silent) {
            if (timer) clearTimeout(timer);
            window.removeEventListener('keydown', onKey, true);
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            if (silent !== true && prevFocus && typeof prevFocus.focus === 'function' && document.contains(prevFocus)) {
                prevFocus.focus();
            }
            if (window.history.replaceState) {
                const u = new URL(window.location.href);
                ['alert', 'success', 'logged_out', 'registered', 'added'].forEach(function (k) {
                    u.searchParams.delete(k);
                });
                window.history.replaceState({}, '', u);
            }
        }
        overlay._iasClose = removeAlert;

        const actions = Array.isArray(opts.actions) && opts.actions.length ? opts.actions : [{ label: 'OK', primary: true }];
        const actionsEl = box.querySelector('.ias-alert-actions');
        let primaryEl = null;
        actions.forEach(function (a) {
            const el = document.createElement(a.href ? 'a' : 'button');
            el.className = 'ias-alert-btn' + (a.primary ? ' is-primary ias-alert-ok' : '');
            el.textContent = a.label;
            if (a.href) el.href = a.href;
            else {
                el.type = 'button';
                el.onclick = removeAlert;
            }
            if (a.primary && !primaryEl) primaryEl = el;
            actionsEl.appendChild(el);
        });

        box.querySelector('.ias-alert-close').onclick = removeAlert;
        overlay.onclick = function (e) {
            if (e.target === overlay) removeAlert();
        };
        window.addEventListener('keydown', onKey, true);

        overlay.appendChild(box);
        document.body.appendChild(overlay);
        (primaryEl || actionsEl.firstChild).focus();

        if (duration > 0) {
            timer = setTimeout(removeAlert, duration);
        }
    }
};
