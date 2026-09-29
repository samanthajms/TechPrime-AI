/*
 * Animated circuit-board background for the login page's left panel.
 * Draws PCB-style traces fanning out from a central chip onto a <canvas>,
 * then sends glowing green "data pulses" along them. No dependencies.
 * Respects prefers-reduced-motion (renders one still frame).
 */
(function () {
    'use strict';

    var canvas = document.getElementById('circuitCanvas');
    if (!canvas || !canvas.getContext) return;
    var ctx = canvas.getContext('2d');
    var host = canvas.parentElement;

    var GREEN = '98,178,54';      // --ep-green
    var BRIGHT = '170,255,120';
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // 8 grid directions, clockwise from east.
    var DIRS = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];

    var W, H, DPR, G, cols, rows;
    var board;          // offscreen canvas with the static circuit
    var traces = [];    // {pts:[[x,y]...], cum:[...], len, fromChip}
    var vias = [];      // {x,y,r,flash}
    var chip;           // {x,y,w,h}
    var pulses = [];
    var used, mids;
    var lastT = 0, rafId = 0;

    function rand(a, b) { return a + Math.random() * (b - a); }
    function key(x, y) { return x + ',' + y; }

    function free(x, y) {
        return x >= 1 && y >= 1 && x < cols - 1 && y < rows - 1 && !used[key(x, y)];
    }

    // Grid router: walk outward from (sx,sy), allowing at most one 45° deviation
    // from the base direction so traces fan out cleanly without looping back.
    function route(sx, sy, base, maxLen) {
        if (!free(sx, sy)) return null;
        var dir = base, x = sx, y = sy, cells = 1, run = 0;
        var pts = [[sx, sy]];
        used[key(sx, sy)] = 1;

        for (var i = 0; i < maxLen; i++) {
            if (run > 2 && Math.random() < 0.14) {
                var turn = dir === base ? (Math.random() < 0.5 ? 1 : -1) : 0;
                var nd = turn ? (base + turn + 8) % 8 : base;
                if (nd !== dir) { pts.push([x, y]); dir = nd; run = 0; }
            }
            var options = [dir, base, (base + 1) % 8, (base + 7) % 8];
            var moved = false;
            for (var o = 0; o < options.length; o++) {
                var d = DIRS[options[o]], nx = x + d[0], ny = y + d[1];
                var diag = d[0] !== 0 && d[1] !== 0;
                var mk = key(x * 2 + d[0], y * 2 + d[1]);
                if (!free(nx, ny) || (diag && mids[mk])) continue;
                if (options[o] !== dir) { pts.push([x, y]); dir = options[o]; run = 0; }
                if (diag) mids[mk] = 1;
                used[key(nx, ny)] = 1;
                x = nx; y = ny; cells++; run++; moved = true;
                break;
            }
            if (!moved) break;
        }
        pts.push([x, y]);
        if (cells < 4) return null;

        // Grid -> pixels, drop duplicate consecutive points.
        var px = [];
        for (var p = 0; p < pts.length; p++) {
            var q = [pts[p][0] * G + G / 2, pts[p][1] * G + G / 2];
            var prev = px[px.length - 1];
            if (!prev || prev[0] !== q[0] || prev[1] !== q[1]) px.push(q);
        }
        var cum = [0];
        for (var c = 1; c < px.length; c++) {
            cum.push(cum[c - 1] + Math.hypot(px[c][0] - px[c - 1][0], px[c][1] - px[c - 1][1]));
        }
        return { pts: px, cum: cum, len: cum[cum.length - 1] };
    }

    function pointAt(t, d) {
        if (d <= 0) return t.pts[0];
        if (d >= t.len) return t.pts[t.pts.length - 1];
        for (var i = 1; i < t.cum.length; i++) {
            if (t.cum[i] >= d) {
                var a = t.pts[i - 1], b = t.pts[i];
                var k = (d - t.cum[i - 1]) / (t.cum[i] - t.cum[i - 1] || 1);
                return [a[0] + (b[0] - a[0]) * k, a[1] + (b[1] - a[1]) * k];
            }
        }
        return t.pts[t.pts.length - 1];
    }

    function build() {
        var rect = host.getBoundingClientRect();
        W = Math.max(1, Math.round(rect.width));
        H = Math.max(1, Math.round(rect.height));
        DPR = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = W * DPR;
        canvas.height = H * DPR;
        canvas.style.width = W + 'px';
        canvas.style.height = H + 'px';

        G = Math.max(9, Math.round(Math.min(W, H) / 52));
        cols = Math.ceil(W / G);
        rows = Math.ceil(H / G);
        used = {}; mids = {};
        traces = []; vias = []; pulses = [];

        // Central chip, nudged right/up so it clears the logo and tagline.
        var half = Math.max(3, Math.round(Math.min(cols, rows) * 0.09));
        var ccx = Math.round(cols * 0.56), ccy = Math.round(rows * 0.44);
        var gx0 = ccx - half, gy0 = ccy - half, gx1 = ccx + half, gy1 = ccy + half;
        for (var x = gx0 - 1; x <= gx1 + 1; x++) {
            for (var y = gy0 - 1; y <= gy1 + 1; y++) used[key(x, y)] = 1;
        }
        chip = { x: gx0 * G, y: gy0 * G, w: (gx1 - gx0 + 1) * G, h: (gy1 - gy0 + 1) * G };

        // Pins on each side of the chip.
        var starts = [];
        for (var i = gx0; i <= gx1; i++) {
            starts.push([i, gy0 - 2, 6]);   // up
            starts.push([i, gy1 + 2, 2]);   // down
        }
        for (var j = gy0; j <= gy1; j++) {
            starts.push([gx0 - 2, j, 4]);   // left
            starts.push([gx1 + 2, j, 0]);   // right
        }
        // Chip pins stay in place but the order they are routed in is shuffled.
        starts.sort(function () { return Math.random() - 0.5; });
        var maxLen = Math.max(cols, rows);
        starts.forEach(function (s) {
            // Pin cells are reserved by the chip block; release the start cell for routing.
            delete used[key(s[0], s[1])];
            var t = route(s[0], s[1], s[2], Math.round(rand(maxLen * 0.35, maxLen)));
            if (t) { t.fromChip = true; traces.push(t); }
        });

        // Secondary traces scattered across the board.
        var extra = Math.round(cols * rows / 28);
        for (var e = 0; e < extra; e++) {
            var t2 = route(Math.floor(rand(1, cols - 1)), Math.floor(rand(1, rows - 1)),
                Math.floor(rand(0, 8)), Math.round(rand(5, 22)));
            if (t2) { t2.fromChip = false; traces.push(t2); }
        }

        traces.forEach(function (t) {
            var end = t.pts[t.pts.length - 1];
            t.via = { x: end[0], y: end[1], r: G * 0.32, flash: 0 };
            vias.push(t.via);
            if (!t.fromChip) vias.push({ x: t.pts[0][0], y: t.pts[0][1], r: G * 0.26, flash: 0 });
        });

        drawBoard();
    }

    function drawBoard() {
        board = document.createElement('canvas');
        board.width = canvas.width;
        board.height = canvas.height;
        var b = board.getContext('2d');
        b.scale(DPR, DPR);

        var cx = chip.x + chip.w / 2, cy = chip.y + chip.h / 2;
        var bg = b.createRadialGradient(cx, cy, 0, cx, cy, Math.max(W, H) * 0.8);
        bg.addColorStop(0, '#0f2109');
        bg.addColorStop(0.5, '#07110a');
        bg.addColorStop(1, '#030604');
        b.fillStyle = bg;
        b.fillRect(0, 0, W, H);

        // Traces
        b.lineCap = 'round';
        b.lineJoin = 'round';
        b.lineWidth = Math.max(1, G * 0.16);
        traces.forEach(function (t) {
            var dx = t.pts[0][0] - cx, dy = t.pts[0][1] - cy;
            var near = 1 - Math.min(1, Math.hypot(dx, dy) / (Math.max(W, H) * 0.7));
            b.strokeStyle = 'rgba(' + GREEN + ',' + (0.18 + near * 0.25).toFixed(3) + ')';
            b.beginPath();
            b.moveTo(t.pts[0][0], t.pts[0][1]);
            for (var i = 1; i < t.pts.length; i++) b.lineTo(t.pts[i][0], t.pts[i][1]);
            b.stroke();
        });

        // Vias (pads with a drilled hole)
        vias.forEach(function (v) {
            b.beginPath();
            b.arc(v.x, v.y, v.r, 0, Math.PI * 2);
            b.fillStyle = 'rgba(' + GREEN + ',0.55)';
            b.fill();
            b.beginPath();
            b.arc(v.x, v.y, v.r * 0.45, 0, Math.PI * 2);
            b.fillStyle = '#040804';
            b.fill();
        });

        // Chip body
        b.save();
        b.shadowColor = 'rgba(' + GREEN + ',0.9)';
        b.shadowBlur = G * 3;
        b.fillStyle = '#0a1608';
        b.fillRect(chip.x, chip.y, chip.w, chip.h);
        b.restore();
        b.strokeStyle = 'rgba(' + GREEN + ',0.95)';
        b.lineWidth = Math.max(1.5, G * 0.2);
        b.strokeRect(chip.x, chip.y, chip.w, chip.h);

        // Chip dies: 2x2 grid of squares
        var pad = chip.w * 0.14, gap = chip.w * 0.06;
        var cell = (chip.w - pad * 2 - gap) / 2;
        b.lineWidth = Math.max(1, G * 0.1);
        for (var r = 0; r < 2; r++) {
            for (var c = 0; c < 2; c++) {
                var x = chip.x + pad + c * (cell + gap), y = chip.y + pad + r * (cell + gap);
                b.fillStyle = 'rgba(' + GREEN + ',0.12)';
                b.fillRect(x, y, cell, cell);
                b.strokeStyle = 'rgba(' + GREEN + ',0.7)';
                b.strokeRect(x, y, cell, cell);
            }
        }
    }

    function spawn(t0) {
        var t = traces[Math.floor(Math.random() * traces.length)];
        if (!t) return;
        pulses.push({
            t: t,
            d: t0 ? rand(0, t.len) : 0,
            speed: rand(G * 6, G * 14),
            tail: rand(G * 3, G * 7)
        });
    }

    function frame(now) {
        var dt = Math.min(0.05, (now - (lastT || now)) / 1000);
        lastT = now;

        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.drawImage(board, 0, 0);
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);

        // Chip "breathing" glow
        var breathe = 0.5 + 0.5 * Math.sin(now / 900);
        ctx.save();
        ctx.globalCompositeOperation = 'lighter';
        ctx.shadowColor = 'rgba(' + GREEN + ',1)';
        ctx.shadowBlur = G * (2 + breathe * 3);
        ctx.strokeStyle = 'rgba(' + BRIGHT + ',' + (0.25 + breathe * 0.35).toFixed(3) + ')';
        ctx.lineWidth = Math.max(1.5, G * 0.2);
        ctx.strokeRect(chip.x, chip.y, chip.w, chip.h);
        ctx.restore();

        ctx.save();
        ctx.globalCompositeOperation = 'lighter';
        ctx.lineCap = 'round';
        ctx.lineWidth = Math.max(1.2, G * 0.2);

        var target = Math.min(45, Math.max(12, Math.round(traces.length / 3)));
        while (pulses.length < target) spawn(false);

        for (var i = pulses.length - 1; i >= 0; i--) {
            var p = pulses[i];
            p.d += p.speed * dt;
            var head = Math.min(p.d, p.t.len);
            var steps = 10, seg = p.tail / steps;
            for (var s = 0; s < steps; s++) {
                var a = head - p.tail + s * seg, b = a + seg;
                if (b <= 0) continue;
                var pa = pointAt(p.t, Math.max(0, a)), pb = pointAt(p.t, b);
                ctx.strokeStyle = 'rgba(' + BRIGHT + ',' + ((s + 1) / steps * 0.9).toFixed(3) + ')';
                ctx.beginPath();
                ctx.moveTo(pa[0], pa[1]);
                ctx.lineTo(pb[0], pb[1]);
                ctx.stroke();
            }
            if (p.d < p.t.len) {
                var h = pointAt(p.t, head);
                ctx.shadowColor = 'rgba(' + GREEN + ',1)';
                ctx.shadowBlur = G;
                ctx.fillStyle = 'rgba(220,255,200,0.95)';
                ctx.beginPath();
                ctx.arc(h[0], h[1], Math.max(1.4, G * 0.18), 0, Math.PI * 2);
                ctx.fill();
                ctx.shadowBlur = 0;
            } else if (!p.hit) {
                p.hit = true;
                p.t.via.flash = 1;
            }
            if (p.d - p.tail > p.t.len) pulses.splice(i, 1);
        }

        // Via flashes when a pulse arrives
        for (var v = 0; v < traces.length; v++) {
            var via = traces[v].via;
            if (via.flash <= 0) continue;
            ctx.fillStyle = 'rgba(' + BRIGHT + ',' + (via.flash * 0.8).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(via.x, via.y, via.r * (1 + (1 - via.flash) * 1.4), 0, Math.PI * 2);
            ctx.fill();
            via.flash = Math.max(0, via.flash - dt * 1.8);
        }
        ctx.restore();

        if (!reduceMotion) rafId = requestAnimationFrame(frame);
    }

    function start() {
        cancelAnimationFrame(rafId);
        build();
        lastT = 0;
        if (reduceMotion) {
            for (var i = 0; i < 30; i++) spawn(true);
            frame(performance.now());
        } else {
            rafId = requestAnimationFrame(frame);
        }
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(start, 200);
    });

    start();
})();
