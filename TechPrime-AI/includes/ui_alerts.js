/**
 * EasyPC E-commerce — single shared alert modal (close button, phone-width bottom sheet).
 *
 * IAS_UI.alert(message, type, durationMs, opts)
 *   type:       'success' | 'error' | 'info' | 'warning'
 *   durationMs: 0 = until closed; otherwise auto-closes (a countdown bar shows the time left,
 *               paused while the pointer is over the box).
 *   opts (optional): {
 *     title:   heading text (defaults per type),
 *     detail:  short highlighted line shown under the message (e.g. a name),
 *     actions: [{ label, href, primary, value }] — buttons; without href they just close.
 *              Defaults to a single "OK" button.
 *     onClose: function (value) — called once when the box closes; value is the clicked
 *              action's value, or undefined when dismissed (Esc, ×, backdrop, replaced).
 *     focus:   'primary' (default) | 'secondary' — which button gets keyboard focus.
 *   }
 *
 * IAS_UI.confirm(message, opts) → Promise<boolean>
 *   opts: { title, detail, confirmLabel, cancelLabel, type: 'warning' (default) | 'danger' | 'info' }
 *
 * Forms: <form data-confirm="Delete this user?" data-confirm-ok="Delete" data-confirm-type="danger">
 *   asks with IAS_UI.confirm() before submitting (data-confirm-title / data-confirm-cancel are optional).
 */
const IAS_UI = {
    _styled: false,

    _injectStyles: function () {
        if (this._styled) return;
        this._styled = true;
        const css =
            '#ias-alert-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;justify-content:center;' +
            'align-items:center;z-index:9999;padding:20px;animation:iasFade .16s ease-out}' +
            '.ias-alert-box{--ias-text:#0f172a;--ias-muted:#475569;--ias-line:#e8edf2;--ias-foot:#f6f8fa;--ias-btn:#fff;' +
            '--ias-btn-line:#d5dce4;--ias-btn-text:#1e293b;--ias-halo:var(--ias-soft);' +
            'background:#fff;border-radius:14px;box-shadow:0 1px 2px rgba(15,23,42,.08),0 22px 48px -12px rgba(15,23,42,.35);' +
            'text-align:left;max-width:440px;width:100%;position:relative;overflow:hidden;font-family:inherit;' +
            'animation:iasPop .2s cubic-bezier(.2,.9,.3,1.15)}' +
            '.ias-alert-body{display:flex;gap:16px;align-items:flex-start;padding:24px 52px 22px 24px}' +
            '.ias-alert-icon{flex:0 0 auto;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;' +
            'justify-content:center;background:var(--ias-accent);color:#fff;box-shadow:0 0 0 6px var(--ias-halo)}' +
            '.ias-alert-icon svg{width:26px;height:26px}' +
            '.ias-alert-text{flex:1 1 auto;min-width:0;padding-top:1px}' +
            '.ias-alert-title{margin:0 0 5px;color:var(--ias-text);font-size:17px;font-weight:700;line-height:1.35}' +
            '.ias-alert-msg{margin:0;color:var(--ias-muted);line-height:1.55;font-size:14.5px;white-space:pre-line;' +
            'overflow-wrap:anywhere}' +
            '.ias-alert-detail{display:inline-block;max-width:100%;margin:0 0 8px;padding:5px 12px;border-radius:8px;' +
            'background:var(--ias-soft);color:var(--ias-accent-dark);font-weight:600;font-size:13.5px;overflow:hidden;' +
            'text-overflow:ellipsis;white-space:nowrap;vertical-align:top}' +
            '.ias-alert-close{position:absolute;top:14px;right:14px;width:32px;height:32px;border:none;border-radius:8px;' +
            'background:none;font-size:22px;line-height:1;cursor:pointer;color:#94a3b8;display:flex;align-items:center;' +
            'justify-content:center;font-family:inherit}' +
            '.ias-alert-close:hover{background:#f1f5f9;color:#334155}' +
            '.ias-alert-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;padding:14px 20px;' +
            'background:var(--ias-foot);border-top:1px solid var(--ias-line)}' +
            '.ias-alert-btn{min-width:104px;padding:10px 18px;border-radius:9px;font-weight:600;font-size:14px;line-height:1.3;' +
            'cursor:pointer;text-decoration:none;text-align:center;border:1px solid var(--ias-btn-line);background:var(--ias-btn);' +
            'color:var(--ias-btn-text);font-family:inherit;transition:background .15s,border-color .15s,color .15s,box-shadow .15s}' +
            '.ias-alert-btn:hover{border-color:var(--ias-accent);color:var(--ias-accent-dark)}' +
            '.ias-alert-btn.is-primary{background:var(--ias-accent);border-color:var(--ias-accent);color:#fff;' +
            'box-shadow:0 1px 2px rgba(15,23,42,.15)}' +
            '.ias-alert-btn.is-primary:hover{background:var(--ias-accent-dark);border-color:var(--ias-accent-dark);color:#fff}' +
            '.ias-alert-btn:focus-visible,.ias-alert-close:focus-visible{outline:2px solid var(--ias-accent);outline-offset:2px}' +
            '.ias-alert-timer{position:absolute;left:0;right:0;bottom:0;height:3px;background:var(--ias-accent);' +
            'transform-origin:left center;animation:iasTimer linear forwards}' +
            '.ias-alert-box.is-paused .ias-alert-timer{animation-play-state:paused}' +
            /* Customer-site dark theme (html[data-theme="dark"], toggled in CLIENT/ep_header.php) */
            'html[data-theme="dark"] .ias-alert-box{--ias-text:#eef0f3;--ias-muted:#b4bac4;--ias-line:#2c3138;' +
            '--ias-foot:#16181c;--ias-btn:#22262c;--ias-btn-line:#363b44;--ias-btn-text:#e4e6ea;' +
            '--ias-halo:color-mix(in srgb,var(--ias-accent) 22%,transparent);' +
            'background:#1b1e23;box-shadow:0 0 0 1px #2c3138,0 24px 60px rgba(0,0,0,.6)}' +
            'html[data-theme="dark"] .ias-alert-detail{background:color-mix(in srgb,var(--ias-accent) 18%,transparent);' +
            'color:#eef0f3}' +
            'html[data-theme="dark"] .ias-alert-close{color:#8b939f}' +
            'html[data-theme="dark"] .ias-alert-close:hover{background:#262a31;color:#e4e6ea}' +
            'html[data-theme="dark"] .ias-alert-btn:not(.is-primary):hover{color:#fff}' +
            '@media (max-width:520px){' +
            '#ias-alert-overlay{align-items:flex-end;padding:0}' +
            '.ias-alert-box{max-width:none;border-radius:16px 16px 0 0;animation-name:iasSheet}' +
            '.ias-alert-body{padding:22px 48px 20px 18px;gap:14px}' +
            '.ias-alert-actions{flex-direction:column-reverse;padding:14px 16px calc(14px + env(safe-area-inset-bottom))}' +
            '.ias-alert-btn{width:100%;padding:12px 18px}}' +
            '@keyframes iasFade{from{opacity:0}to{opacity:1}}' +
            '@keyframes iasPop{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:none}}' +
            '@keyframes iasSheet{from{transform:translateY(100%)}to{transform:none}}' +
            '@keyframes iasTimer{from{transform:scaleX(1)}to{transform:scaleX(0)}}' +
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
                title: 'Done', accent: 'var(--ep-green, #62b236)', dark: 'var(--ep-green-dark, #4b8b2a)', soft: '#e8f5df',
                icon: '<path d="M5 12.5l4.5 4.5L19 7.5"/>'
            },
            error: {
                title: 'Something went wrong', accent: '#dc2626', dark: '#b91c1c', soft: '#fde3e3',
                icon: '<path d="M12 7v6"/><circle cx="12" cy="17" r="1.1" fill="currentColor" stroke="none"/>'
            },
            warning: {
                title: 'Please confirm', accent: '#d97706', dark: '#b45309', soft: '#fdf0d5',
                icon: '<path d="M12 4l9 15.5H3z"/><path d="M12 10v4"/>' +
                    '<circle cx="12" cy="16.9" r=".9" fill="currentColor" stroke="none"/>'
            },
            info: {
                title: 'Notice', accent: '#0998a8', dark: '#077784', soft: '#dcf1f4',
                icon: '<path d="M12 11v6"/><circle cx="12" cy="7" r="1.1" fill="currentColor" stroke="none"/>'
            }
        };
        const t = themes[type] || themes.success;

        const overlay = document.createElement('div');
        overlay.id = 'ias-alert-overlay';

        const box = document.createElement('div');
        box.className = 'ias-alert-box';
        box.setAttribute('role', type === 'error' || type === 'warning' ? 'alertdialog' : 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-labelledby', 'ias-alert-title');
        box.setAttribute('aria-describedby', 'ias-alert-msg');
        box.style.setProperty('--ias-accent', t.accent);
        box.style.setProperty('--ias-accent-dark', t.dark);
        box.style.setProperty('--ias-soft', t.soft);

        box.innerHTML =
            '<div class="ias-alert-body">' +
            '<div class="ias-alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">' + t.icon + '</svg></div>' +
            '<div class="ias-alert-text">' +
            '<h3 class="ias-alert-title" id="ias-alert-title"></h3>' +
            '<p class="ias-alert-msg" id="ias-alert-msg"></p>' +
            '</div>' +
            '</div>' +
            '<div class="ias-alert-actions"></div>' +
            '<button type="button" class="ias-alert-close" aria-label="Close">&times;</button>';

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
        let closed = false;

        function onKey(e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                removeAlert();
            } else if (e.key === 'Tab') {
                // Keep keyboard focus inside the box while it is open.
                const items = Array.prototype.slice.call(box.querySelectorAll('button, a[href]'));
                if (!items.length) return;
                const first = items[0];
                const last = items[items.length - 1];
                if (!box.contains(document.activeElement)) {
                    e.preventDefault();
                    first.focus();
                } else if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }

        function removeAlert(silent, value) {
            if (closed) return;
            closed = true;
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
            if (typeof opts.onClose === 'function') opts.onClose(value);
        }
        overlay._iasClose = removeAlert;

        const actions = Array.isArray(opts.actions) && opts.actions.length ? opts.actions : [{ label: 'OK', primary: true }];
        const actionsEl = box.querySelector('.ias-alert-actions');
        let primaryEl = null;
        let secondaryEl = null;
        actions.forEach(function (a) {
            const el = document.createElement(a.href ? 'a' : 'button');
            el.className = 'ias-alert-btn' + (a.primary ? ' is-primary ias-alert-ok' : '');
            el.textContent = a.label;
            if (a.href) el.href = a.href;
            else {
                el.type = 'button';
                el.onclick = function () { removeAlert(false, a.value); };
            }
            if (a.primary && !primaryEl) primaryEl = el;
            if (!a.primary && !secondaryEl) secondaryEl = el;
            actionsEl.appendChild(el);
        });

        box.querySelector('.ias-alert-close').onclick = function () { removeAlert(); };
        overlay.onclick = function (e) {
            if (e.target === overlay) removeAlert();
        };
        window.addEventListener('keydown', onKey, true);

        if (duration > 0) {
            const bar = document.createElement('div');
            bar.className = 'ias-alert-timer';
            bar.setAttribute('aria-hidden', 'true');
            bar.style.animationDuration = duration + 'ms';
            box.appendChild(bar);

            let remaining = duration;
            let startedAt = Date.now();
            timer = setTimeout(removeAlert, remaining);
            box.addEventListener('mouseenter', function () {
                if (!timer) return;
                clearTimeout(timer);
                timer = null;
                remaining -= Date.now() - startedAt;
                box.classList.add('is-paused');
            });
            box.addEventListener('mouseleave', function () {
                if (closed || timer) return;
                startedAt = Date.now();
                timer = setTimeout(removeAlert, Math.max(remaining, 600));
                box.classList.remove('is-paused');
            });
        }

        overlay.appendChild(box);
        document.body.appendChild(overlay);
        const focusEl = opts.focus === 'secondary' ? (secondaryEl || primaryEl) : (primaryEl || secondaryEl);
        (focusEl || actionsEl.firstChild).focus();
    },

    confirm: function (message, opts) {
        opts = opts || {};
        const self = this;
        const danger = opts.type === 'danger';
        return new Promise(function (resolve) {
            self.alert(message, danger ? 'error' : (opts.type || 'warning'), 0, {
                title: opts.title || (danger ? 'Are you sure?' : 'Please confirm'),
                detail: opts.detail,
                focus: danger ? 'secondary' : 'primary',
                actions: [
                    { label: opts.cancelLabel || 'Cancel', value: false },
                    { label: opts.confirmLabel || 'Confirm', primary: true, value: true }
                ],
                onClose: function (value) { resolve(value === true); }
            });
        });
    }
};

// <form data-confirm="..."> — ask with the styled dialog instead of the browser's confirm().
document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form || !form.matches || !form.matches('form[data-confirm]')) return;
    if (form._iasConfirmed) {
        form._iasConfirmed = false;
        return;
    }
    e.preventDefault();
    e.stopPropagation();
    const submitter = e.submitter || null;
    IAS_UI.confirm(form.getAttribute('data-confirm'), {
        title: form.getAttribute('data-confirm-title') || undefined,
        confirmLabel: form.getAttribute('data-confirm-ok') || undefined,
        cancelLabel: form.getAttribute('data-confirm-cancel') || undefined,
        type: form.getAttribute('data-confirm-type') || undefined
    }).then(function (ok) {
        if (!ok) return;
        form._iasConfirmed = true;
        if (typeof form.requestSubmit === 'function') {
            try {
                form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                return;
            } catch (err) { /* fall through */ }
        }
        form._iasConfirmed = false;
        form.submit();
    });
}, true);
