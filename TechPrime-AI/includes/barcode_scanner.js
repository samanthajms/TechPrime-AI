/**
 * EasyPC POS — USB keyboard-wedge barcode scanner input.
 * The scanner "types" the digits very fast and then sends Enter; no driver needed.
 *
 *   EP_Scanner.analyze(code)          UPC-A / UPC-E / EAN-13 / EAN-8 length + check digit
 *                                     (mirrors pos_barcode_analyze() in includes/pos_helpers.php)
 *   EP_Scanner.attach(input, options) keeps the scan field focused and emits scans
 *   EP_Scanner.status(el, type, title, detail)  inline scan feedback
 *   EP_Scanner.beep(ok)
 */
(function (global) {
    'use strict';

    function gtinCheckDigit(body) {
        var sum = 0;
        for (var i = 0; i < body.length; i++) {
            sum += Number(body.charAt(body.length - 1 - i)) * (i % 2 === 0 ? 3 : 1);
        }
        return (10 - (sum % 10)) % 10;
    }

    function gtinValid(code) {
        return /^\d{8,14}$/.test(code) && gtinCheckDigit(code.slice(0, -1)) === Number(code.slice(-1));
    }

    function upceToUpca(code) {
        if (!/^[01]\d{7}$/.test(code)) return null;
        var m = code.substr(1, 6);
        var last = m.charAt(5);
        var body;
        if (last <= '2') body = m.charAt(0) + m.charAt(1) + last + '0000' + m.substr(2, 3);
        else if (last === '3') body = m.substr(0, 3) + '00000' + m.substr(3, 2);
        else if (last === '4') body = m.substr(0, 4) + '00000' + m.charAt(4);
        else body = m.substr(0, 5) + '0000' + last;
        return code.charAt(0) + body + code.charAt(7);
    }

    /** @returns {{ok:boolean, code:string, type?:string, message?:string}} */
    function analyze(raw) {
        var code = String(raw == null ? '' : raw).replace(/\s+/g, '');
        if (code === '' || !/^\d+$/.test(code)) {
            return { ok: false, code: code, message: 'Barcode must contain digits only.' };
        }
        if ([8, 12, 13].indexOf(code.length) === -1) {
            return { ok: false, code: code, message: 'Unsupported barcode length (' + code.length + ' digits). Use UPC-A, UPC-E, EAN-13 or EAN-8.' };
        }
        var type = '';
        if (code.length === 13 && gtinValid(code)) type = 'EAN-13';
        else if (code.length === 12 && gtinValid(code)) type = 'UPC-A';
        else if (code.length === 8) {
            if (gtinValid(code)) type = 'EAN-8';
            var upca = upceToUpca(code);
            if (upca && gtinValid(upca)) type = type ? type + '/UPC-E' : 'UPC-E';
        }
        if (!type) {
            return { ok: false, code: code, message: 'Invalid barcode check digit (' + code + '). Please scan again.' };
        }
        return { ok: true, code: code, type: type };
    }

    var audioCtx = null;
    function beep(ok) {
        try {
            var Ctx = global.AudioContext || global.webkitAudioContext;
            if (!Ctx) return;
            audioCtx = audioCtx || new Ctx();
            var osc = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.type = ok ? 'sine' : 'square';
            osc.frequency.value = ok ? 1250 : 280;
            gain.gain.value = 0.06;
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + (ok ? 0.08 : 0.28));
        } catch (e) { /* audio is optional */ }
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** Inline feedback using staff_shared.css .alert styles. type: success | error | info | warn */
    function status(el, type, title, detail) {
        if (!el) return;
        var icons = { success: 'fa-check-circle', error: 'fa-times-circle', info: 'fa-barcode', warn: 'fa-exclamation-triangle' };
        el.className = 'alert alert-' + type + ' pos-scan-status';
        el.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '" aria-hidden="true"></i>' +
            '<div><strong>' + escapeHtml(title) + '</strong>' +
            (detail ? '<span>' + escapeHtml(detail) + '</span>' : '') + '</div>';
        el.hidden = false;
        el.classList.remove('pos-flash');
        void el.offsetWidth; // restart the flash animation
        el.classList.add('pos-flash');
    }

    function isEditable(el) {
        if (!el || el === document.body) return false;
        var tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    }

    /**
     * @param {HTMLInputElement} input  the always-focused scan field
     * @param {{onScan:function(string,{source:string,type:string}), onInvalid?:function(object,string),
     *          canRefocus?:function():boolean, keyGapMs?:number, dedupeMs?:number}} opts
     */
    function attach(input, opts) {
        opts = opts || {};
        var keyGapMs = opts.keyGapMs || 50;
        var dedupeMs = opts.dedupeMs || 300;
        var minScanLength = 8;
        var canRefocus = opts.canRefocus || function () { return true; };
        var lastCode = '';
        var lastAt = 0;
        var keyTimes = [];

        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        input.setAttribute('inputmode', 'numeric');

        function emit(raw, source) {
            var code = String(raw).replace(/\s+/g, '');
            if (!code) return;
            var now = Date.now();
            if (code === lastCode && now - lastAt < dedupeMs) return; // same code re-sent by the scanner
            lastCode = code;
            lastAt = now;
            var result = analyze(code);
            if (!result.ok) {
                beep(false);
                if (opts.onInvalid) opts.onInvalid(result, source);
                return;
            }
            if (opts.onScan) opts.onScan(result.code, { source: source, type: result.type });
        }

        function averageGap(times) {
            if (times.length < 2) return Infinity;
            return (times[times.length - 1] - times[0]) / (times.length - 1);
        }

        // Scan field: scanner bursts and manual typing both end with Enter.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var value = input.value;
                var fast = keyTimes.length >= minScanLength && averageGap(keyTimes) < keyGapMs;
                keyTimes = [];
                input.value = '';
                emit(value, fast ? 'scanner' : 'manual');
                return;
            }
            if (e.key && e.key.length === 1) {
                var now = performance.now();
                if (keyTimes.length && now - keyTimes[keyTimes.length - 1] > 1000) keyTimes = [];
                keyTimes.push(now);
            }
        });

        // A scan that lands in another field (e.g. a quantity box) or on a button is
        // detected by its speed, the field's value is restored, and the code is routed here.
        var burst = { target: null, chars: '', last: 0, snapshot: null };
        function resetBurst() { burst.target = null; burst.chars = ''; burst.snapshot = null; }
        document.addEventListener('keydown', function (e) {
            if (e.target === input || e.ctrlKey || e.altKey || e.metaKey) return;
            var now = performance.now();
            if (e.key === 'Enter') {
                if (burst.chars.length >= minScanLength && burst.target === e.target && now - burst.last < keyGapMs * 2) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (burst.snapshot !== null && 'value' in e.target) e.target.value = burst.snapshot;
                    var code = burst.chars;
                    resetBurst();
                    emit(code, 'scanner');
                    return;
                }
                resetBurst();
                return;
            }
            if (/^[0-9]$/.test(e.key)) {
                if (burst.target !== e.target || now - burst.last > keyGapMs) {
                    burst.target = e.target;
                    burst.chars = '';
                    burst.snapshot = ('value' in e.target) ? e.target.value : null;
                }
                burst.chars += e.key;
                burst.last = now;
            } else {
                resetBurst();
            }
        }, true);

        // Keep the scan field focused, except while the cashier edits another field
        // (quantity, amount tendered) or the page says not to (modal open).
        function refocus() {
            if (!canRefocus()) return;
            var active = document.activeElement;
            if (active === input || isEditable(active)) return;
            input.focus({ preventScroll: true });
        }
        input.addEventListener('blur', function () { setTimeout(refocus, 0); });
        document.addEventListener('click', function () { setTimeout(refocus, 0); });
        document.addEventListener('focusout', function (e) {
            if (e.target !== input) setTimeout(refocus, 0);
        });
        global.addEventListener('focus', function () { setTimeout(refocus, 0); });
        refocus();

        return { refocus: refocus, emit: emit, input: input };
    }

    global.EP_Scanner = { analyze: analyze, attach: attach, status: status, beep: beep, escapeHtml: escapeHtml };
})(window);
