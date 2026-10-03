/**
 * EasyPC — printable EAN-13 / UPC-A / EAN-8 barcodes as SVG (no dependencies).
 *
 *   EP_BarcodeSVG.svg(code, opts)  SVG markup sized in millimetres for true-scale printing
 *   EP_BarcodeSVG.printed(code)    the digits as printed (UPC-A shows its 12 digits)
 *
 * Codes are the normalized values stored in products.barcode: 13 digits (EAN-13, or
 * UPC-A with a leading 0) or 8 digits (EAN-8). Default module (bar unit) is 0.33 mm,
 * the GS1 nominal size that handheld CCD/laser scanners read reliably.
 */
(function (global) {
    'use strict';

    // Left-hand odd parity (L); right-hand (R) is its complement, even parity (G) is R reversed.
    var L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    var R = L.map(function (p) { return p.replace(/[01]/g, function (b) { return b === '1' ? '0' : '1'; }); });
    var G = R.map(function (p) { return p.split('').reverse().join(''); });
    // EAN-13 first digit selects the parity of the six left-hand digits.
    var PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    function checkDigit(body) {
        var sum = 0;
        for (var i = 0; i < body.length; i++) {
            sum += Number(body.charAt(body.length - 1 - i)) * (i % 2 === 0 ? 3 : 1);
        }
        return (10 - (sum % 10)) % 10;
    }

    function valid(code) {
        return /^(\d{8}|\d{13})$/.test(code) && checkDigit(code.slice(0, -1)) === Number(code.slice(-1));
    }

    /** Bar pattern as a string of 1 (bar) / 0 (space) modules. */
    function modules(code) {
        var s = '101', i;
        if (code.length === 13) {
            var parity = PARITY[Number(code.charAt(0))];
            for (i = 1; i <= 6; i++) s += (parity.charAt(i - 1) === 'L' ? L : G)[Number(code.charAt(i))];
            s += '01010';
            for (i = 7; i <= 12; i++) s += R[Number(code.charAt(i))];
        } else {
            for (i = 0; i < 4; i++) s += L[Number(code.charAt(i))];
            s += '01010';
            for (i = 4; i < 8; i++) s += R[Number(code.charAt(i))];
        }
        return s + '101';
    }

    function printed(code) {
        code = String(code || '');
        return (code.length === 13 && code.charAt(0) === '0') ? code.slice(1) : code;
    }

    /**
     * @param {string} code
     * @param {{moduleMm?:number, barHeightMm?:number}} [opts]
     * @returns {string} SVG markup, or '' when the code is not a valid EAN-13/UPC-A/EAN-8
     */
    function svg(code, opts) {
        code = String(code || '');
        if (!valid(code)) return '';
        opts = opts || {};
        var mm = opts.moduleMm || 0.33;
        var barH = (opts.barHeightMm || 16) / mm;       // in modules
        var isUpcA = code.length === 13 && code.charAt(0) === '0';
        var quietL = code.length === 13 ? 11 : 7;
        var quietR = code.length === 13 ? 9 : 7;
        var bits = modules(code);
        var n = bits.length;                            // 95 or 67
        var width = quietL + n + quietR;
        var fontSize = 8.5;
        var height = barH + 5 + fontSize;

        // Guard bars (and UPC-A's outer digits) extend down into the text line.
        var longBars = {};
        var mark = function (from, to) { for (var k = from; k <= to; k++) longBars[k] = true; };
        mark(0, 2); mark(n - 3, n - 1);
        mark(Math.floor(n / 2) - 2, Math.floor(n / 2) + 2);
        if (isUpcA) { mark(3, 9); mark(n - 10, n - 4); }

        var rects = '';
        for (var i = 0; i < n; i++) {
            if (bits.charAt(i) !== '1') continue;
            var start = i;
            var tall = !!longBars[i];
            while (i + 1 < n && bits.charAt(i + 1) === '1' && !!longBars[i + 1] === tall) i++;
            rects += '<rect x="' + (quietL + start) + '" y="0" width="' + (i - start + 1) + '" height="' +
                (tall ? barH + 5 : barH) + '"/>';
        }

        var text = '';
        var t = function (x, s, anchor) {
            text += '<text x="' + x + '" y="' + (barH + 4 + fontSize * 0.8) + '" text-anchor="' + (anchor || 'middle') + '">' + s + '</text>';
        };
        var digitX = function (group, idx) { return quietL + (group === 'L' ? 3 : Math.floor(n / 2) + 3) + idx * 7 + 3.5; };
        if (code.length === 8) {
            for (var a = 0; a < 4; a++) t(digitX('L', a), code.charAt(a));
            for (var b = 0; b < 4; b++) t(digitX('R', b), code.charAt(4 + b));
        } else if (isUpcA) {
            var u = code.slice(1);
            t(quietL - 2, u.charAt(0), 'end');
            for (var c = 1; c <= 5; c++) t(digitX('L', c), u.charAt(c));
            for (var d = 0; d < 5; d++) t(digitX('R', d), u.charAt(6 + d));
            t(quietL + n + 2, u.charAt(11), 'start');
        } else {
            t(quietL - 2, code.charAt(0), 'end');
            for (var e = 0; e < 6; e++) t(digitX('L', e), code.charAt(1 + e));
            for (var f = 0; f < 6; f++) t(digitX('R', f), code.charAt(7 + f));
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" class="ep-barcode" role="img"' +
            ' aria-label="Barcode ' + printed(code) + '"' +
            ' width="' + (width * mm).toFixed(2) + 'mm" height="' + (height * mm).toFixed(2) + 'mm"' +
            ' viewBox="0 0 ' + width + ' ' + height.toFixed(2) + '" shape-rendering="crispEdges">' +
            '<rect width="100%" height="100%" fill="#fff"/>' +
            '<g fill="#000">' + rects + '</g>' +
            '<g fill="#000" font-family="Consolas, \'Courier New\', monospace" font-size="' + fontSize + '">' + text + '</g>' +
            '</svg>';
    }

    global.EP_BarcodeSVG = { svg: svg, printed: printed, modules: modules, valid: valid };
})(window);
