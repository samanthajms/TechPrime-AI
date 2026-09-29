/*
 * Animated hero photo for the auth pages' left panel.
 *   <canvas id="heroCanvas" data-src="assets/X.jpg" data-scene="pc|store">
 * Draws the photo on the canvas with a slow push-in, then adds scene effects:
 *   pc    (EasyPC.jpg, login)       pump glow + spinning ring, RAM light wave, fan sweep,
 *                                   falling code streaks, film grain, glitch slices
 *   store (login-hero.jpg, register) ceiling LED runner, hologram scan line, scanner
 *                                   laser flicker + "beep" flash, drifting smoke, dust
 * No dependencies. The CSS background shows the same photo, so it is the fallback
 * when JS is off, while the image loads, or when the visitor prefers reduced motion.
 * The panel's CSS must use `background-size: cover; background-position: center 45%`.
 */
(function () {
    'use strict';

    var canvas = document.getElementById('heroCanvas');
    if (!canvas || !canvas.getContext) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var ctx = canvas.getContext('2d');
    var host = canvas.parentElement;
    var POS_X = 0.5, POS_Y = 0.45;
    var IMG_W = 1792, IMG_H = 2400;   // both hero photos are 1792x2400

    function rand(a, b) { return a + Math.random() * (b - a); }

    function glow(x, y, r, color, alpha) {
        var g = ctx.createRadialGradient(x, y, 0, x, y, r);
        g.addColorStop(0, 'rgba(' + color + ',' + alpha.toFixed(3) + ')');
        g.addColorStop(1, 'rgba(' + color + ',0)');
        ctx.fillStyle = g;
        ctx.fillRect(x - r, y - r, r * 2, r * 2);
    }

    /* ───────────── Scene: PC interior (EasyPC.jpg) ───────────── */
    function pcScene() {
        var PUMP = { x: 924, y: 1214, r: 292 };
        var FAN = { x: 318, y: 540, r: 396 };
        var RAM = [
            { a: [864, 300], b: [1680, 768], w: 66 },
            { a: [768, 444], b: [1584, 924], w: 66 }
        ];
        var grain = [], streaks = [], glitch = null, nextGlitch = 0;
        var W, H;

        function makeGrain() {
            var tiles = [];
            for (var n = 0; n < 4; n++) {
                var c = document.createElement('canvas');
                c.width = c.height = 128;
                var g = c.getContext('2d');
                var data = g.createImageData(128, 128);
                for (var i = 0; i < data.data.length; i += 4) {
                    var v = Math.random() * 255;
                    data.data[i] = v * 0.6;
                    data.data[i + 1] = v;
                    data.data[i + 2] = v * 0.5;
                    data.data[i + 3] = Math.random() < 0.5 ? 40 : 0;
                }
                g.putImageData(data, 0, 0);
                tiles.push(ctx.createPattern(c, 'repeat'));
            }
            return tiles;
        }

        function newStreak(anywhere) {
            return {
                x: Math.round(rand(0, W)),
                y: anywhere ? rand(-H, H) : rand(-H * 0.6, -40),
                len: rand(40, 160),
                speed: rand(140, 420),
                a: rand(0.1, 0.3)
            };
        }

        return {
            anchor: [PUMP.x, PUMP.y], zoom: 0.05, period: 28,
            layout: function (w, h) {
                W = w; H = h;
                if (!grain.length) grain = makeGrain();
                streaks = [];
                var count = Math.max(10, Math.round(W / 32));
                for (var i = 0; i < count; i++) streaks.push(newStreak(true));
                nextGlitch = performance.now() / 1000 + rand(1.5, 3);
            },
            // Image-pixel space, additive blending.
            image: function (t) {
                // Pump cap: breathing glow + a light spinning around the ring.
                var breathe = 0.5 + 0.5 * Math.sin(t * 1.6);
                glow(PUMP.x, PUMP.y, PUMP.r * 1.25, '98,220,60', 0.10 + breathe * 0.16);
                ctx.save();
                ctx.lineCap = 'round';
                ctx.shadowColor = 'rgba(120,255,80,1)';
                ctx.shadowBlur = 30;
                ctx.lineWidth = 10;
                var ang = t * 1.8;
                for (var k = 0; k < 2; k++) {
                    var a0 = ang + k * Math.PI;
                    for (var s = 0; s < 8; s++) {
                        ctx.strokeStyle = 'rgba(170,255,120,' + ((s + 1) / 8 * 0.5).toFixed(3) + ')';
                        ctx.beginPath();
                        ctx.arc(PUMP.x, PUMP.y, PUMP.r * 0.97, a0 + s * 0.09, a0 + (s + 1) * 0.09);
                        ctx.stroke();
                    }
                }
                ctx.restore();

                // RAM: a light wave running along each stick, offset from each other.
                for (var r = 0; r < RAM.length; r++) {
                    var bar = RAM[r];
                    var k2 = ((t * 0.45 + r * 0.35) % 1.4) - 0.2;
                    if (k2 < 0 || k2 > 1) continue;
                    var x = bar.a[0] + (bar.b[0] - bar.a[0]) * k2, y = bar.a[1] + (bar.b[1] - bar.a[1]) * k2;
                    ctx.save();
                    ctx.translate(x, y);
                    ctx.rotate(Math.atan2(bar.b[1] - bar.a[1], bar.b[0] - bar.a[0]));
                    ctx.scale(2.6, 1);
                    glow(0, 0, bar.w * 1.4, '190,255,140', 0.45);
                    ctx.restore();
                }

                // Fan: slow rotating light sweep, clipped to the fan.
                ctx.save();
                ctx.beginPath();
                ctx.arc(FAN.x, FAN.y, FAN.r, 0, Math.PI * 2);
                ctx.clip();
                ctx.translate(FAN.x, FAN.y);
                ctx.rotate(t * 1.2);
                var fg = ctx.createLinearGradient(0, -FAN.r, 0, FAN.r);
                fg.addColorStop(0, 'rgba(160,255,120,0)');
                fg.addColorStop(0.45, 'rgba(160,255,120,0)');
                fg.addColorStop(0.5, 'rgba(160,255,120,0.14)');
                fg.addColorStop(0.55, 'rgba(160,255,120,0)');
                fg.addColorStop(1, 'rgba(160,255,120,0)');
                ctx.fillStyle = fg;
                ctx.fillRect(-FAN.r, -FAN.r, FAN.r * 2, FAN.r * 2);
                ctx.restore();
            },
            // CSS-pixel space (not zoomed).
            screen: function (t, dt) {
                // Falling code streaks, echoing the photo's vertical glitch lines.
                ctx.globalCompositeOperation = 'lighter';
                ctx.lineWidth = 1;
                for (var i = 0; i < streaks.length; i++) {
                    var st = streaks[i];
                    st.y += st.speed * dt;
                    if (st.y - st.len > H) { streaks[i] = newStreak(false); continue; }
                    var sg = ctx.createLinearGradient(0, st.y - st.len, 0, st.y);
                    sg.addColorStop(0, 'rgba(120,255,90,0)');
                    sg.addColorStop(1, 'rgba(160,255,120,' + st.a.toFixed(3) + ')');
                    ctx.strokeStyle = sg;
                    ctx.beginPath();
                    ctx.moveTo(st.x + 0.5, st.y - st.len);
                    ctx.lineTo(st.x + 0.5, st.y);
                    ctx.stroke();
                }

                // Film grain, re-randomised every frame.
                ctx.globalCompositeOperation = 'screen';
                ctx.globalAlpha = 0.5;
                ctx.save();
                ctx.translate(-Math.random() * 128, -Math.random() * 128);
                ctx.fillStyle = grain[Math.floor(Math.random() * grain.length)];
                ctx.fillRect(0, 0, W + 128, H + 128);
                ctx.restore();
                ctx.globalAlpha = 1;
                ctx.globalCompositeOperation = 'source-over';
            },
            // Device-pixel space, after everything else: occasional glitch slices.
            post: function (t, dpr) {
                if (!glitch && t > nextGlitch) {
                    var slices = [];
                    for (var n = 0, c = Math.floor(rand(2, 5)); n < c; n++) {
                        slices.push({ y: rand(0, H), h: rand(4, 26), dx: rand(-24, 24) });
                    }
                    glitch = { until: t + rand(0.08, 0.18), slices: slices };
                }
                if (!glitch) return;
                for (var g = 0; g < glitch.slices.length; g++) {
                    var sl = glitch.slices[g];
                    var sy = Math.round(sl.y * dpr), sh = Math.round(sl.h * dpr);
                    ctx.drawImage(canvas, 0, sy, canvas.width, sh, Math.round(sl.dx * dpr), sy, canvas.width, sh);
                    ctx.fillStyle = 'rgba(120,255,90,0.08)';
                    ctx.fillRect(0, sy, canvas.width, sh);
                }
                if (t > glitch.until) {
                    glitch = null;
                    nextGlitch = t + rand(2.5, 6);
                }
            }
        };
    }

    /* ───────────── Scene: EasyPC store counter (login-hero.jpg) ───────────── */
    function storeScene() {
        var LASER = [[912, 1426], [1236, 1416]];
        var LABEL = [989, 1438];
        var HOLO = { x: 630, y: 1026, w: 528, h: 288 };
        var CEILING = [[0, 528], [570, 614], [1792, 132]];
        var ceilCum = [0], ceilLen;
        for (var c = 1; c < CEILING.length; c++) {
            ceilCum.push(ceilCum[c - 1] + Math.hypot(CEILING[c][0] - CEILING[c - 1][0], CEILING[c][1] - CEILING[c - 1][1]));
        }
        ceilLen = ceilCum[ceilCum.length - 1];

        var puffSprite = null, puffs = [], motes = [];
        var W, H;

        // Soft cloud sprite built from overlapping radial gradients.
        function makePuff(size) {
            var cv = document.createElement('canvas');
            cv.width = cv.height = size;
            var g = cv.getContext('2d');
            for (var i = 0; i < 26; i++) {
                var a = rand(0, Math.PI * 2), d = rand(0, size * 0.22);
                var x = size / 2 + Math.cos(a) * d, y = size / 2 + Math.sin(a) * d * 0.6;
                var r = rand(size * 0.12, size * 0.3);
                var grad = g.createRadialGradient(x, y, 0, x, y, r);
                grad.addColorStop(0, 'rgba(185,225,170,0.10)');
                grad.addColorStop(1, 'rgba(185,225,170,0)');
                g.fillStyle = grad;
                g.fillRect(0, 0, size, size);
            }
            return cv;
        }

        function newMote(anywhere) {
            return {
                x: rand(0, W), y: anywhere ? rand(0, H) : H + 5,
                r: rand(0.6, 1.8), vy: rand(4, 14), drift: rand(4, 12),
                phase: rand(0, Math.PI * 2), tw: rand(1, 3)
            };
        }

        function ceilingPoint(d) {
            for (var i = 1; i < ceilCum.length; i++) {
                if (ceilCum[i] >= d) {
                    var a = CEILING[i - 1], b = CEILING[i];
                    var k = (d - ceilCum[i - 1]) / (ceilCum[i] - ceilCum[i - 1]);
                    return [a[0] + (b[0] - a[0]) * k, a[1] + (b[1] - a[1]) * k];
                }
            }
            return CEILING[CEILING.length - 1];
        }

        return {
            anchor: [1000, 1250], zoom: 0.06, period: 30,
            layout: function (w, h) {
                W = w; H = h;
                puffSprite = puffSprite || makePuff(256);
                puffs = [];
                for (var i = 0; i < 6; i++) {
                    puffs.push({
                        x: rand(-0.2, 1.1) * W, y: rand(0.3, 0.6) * H,
                        size: rand(0.6, 1.0) * Math.max(W, H * 0.8),
                        vx: rand(6, 16), vy: rand(-2, 0.5), a: rand(0.14, 0.28),
                        rot: rand(0, Math.PI * 2), vr: rand(-0.03, 0.03), phase: rand(0, Math.PI * 2)
                    });
                }
                motes = [];
                var count = Math.round(W * H / 9000);
                for (var m = 0; m < count; m++) motes.push(newMote(true));
            },
            image: function (t) {
                // Light running along the ceiling LED strip.
                var cd = (t * 260) % (ceilLen + 600) - 300;
                if (cd > 0 && cd < ceilLen) {
                    var cp = ceilingPoint(cd);
                    glow(cp[0], cp[1], 140, '170,255,120', 0.55);
                }

                // Hologram: flicker plus a scan line sweeping down.
                var hy = HOLO.y + ((t * 110) % (HOLO.h + 60)) - 30;
                var hg = ctx.createLinearGradient(HOLO.x, 0, HOLO.x + HOLO.w, 0);
                var flick = 0.22 + 0.1 * Math.sin(t * 23) * Math.sin(t * 7.3);
                hg.addColorStop(0, 'rgba(170,255,120,0)');
                hg.addColorStop(0.5, 'rgba(170,255,120,' + flick.toFixed(3) + ')');
                hg.addColorStop(1, 'rgba(170,255,120,0)');
                ctx.fillStyle = hg;
                ctx.fillRect(HOLO.x, hy, HOLO.w, 5);
                ctx.fillStyle = 'rgba(170,255,120,' + (0.035 + 0.03 * Math.max(0, Math.sin(t * 17))).toFixed(3) + ')';
                ctx.fillRect(HOLO.x, HOLO.y, HOLO.w, HOLO.h);

                // Scanner laser: constant flicker, bright "beep" flash every 3.5s.
                var cycle = t % 3.5;
                var scan = cycle < 0.35 ? 1 - cycle / 0.35 : 0;
                ctx.save();
                ctx.lineCap = 'round';
                ctx.shadowColor = 'rgba(98,178,54,1)';
                ctx.shadowBlur = 14 + scan * 30;
                ctx.strokeStyle = 'rgba(160,255,110,' + (0.35 + 0.2 * Math.random() + scan * 0.5).toFixed(3) + ')';
                ctx.lineWidth = 3 + scan * 2;
                ctx.beginPath();
                ctx.moveTo(LASER[0][0], LASER[0][1]);
                ctx.lineTo(LASER[1][0], LASER[1][1]);
                ctx.stroke();
                ctx.restore();
                if (scan > 0) glow(LABEL[0], LABEL[1], 150, '170,255,120', 0.45 * scan);
            },
            screen: function (t, dt) {
                // Drifting smoke
                ctx.globalCompositeOperation = 'screen';
                for (var i = 0; i < puffs.length; i++) {
                    var p = puffs[i];
                    p.x += p.vx * dt; p.y += p.vy * dt; p.rot += p.vr * dt;
                    if (p.x - p.size / 2 > W) { p.x = -p.size / 2; p.y = rand(0.3, 0.6) * H; }
                    ctx.globalAlpha = p.a * (0.75 + 0.25 * Math.sin(t * 0.4 + p.phase));
                    ctx.save();
                    ctx.translate(p.x, p.y);
                    ctx.rotate(p.rot);
                    ctx.drawImage(puffSprite, -p.size / 2, -p.size * 0.35, p.size, p.size * 0.7);
                    ctx.restore();
                }

                // Floating dust in the LED light
                ctx.globalCompositeOperation = 'lighter';
                ctx.fillStyle = 'rgb(190,255,150)';
                for (var m = 0; m < motes.length; m++) {
                    var d = motes[m];
                    d.y -= d.vy * dt;
                    if (d.y < -5) { motes[m] = newMote(false); continue; }
                    ctx.globalAlpha = 0.25 + 0.45 * (0.5 + 0.5 * Math.sin(t * d.tw + d.phase));
                    ctx.beginPath();
                    ctx.arc(d.x + Math.sin(t * 0.6 + d.phase) * d.drift, d.y, d.r, 0, Math.PI * 2);
                    ctx.fill();
                }
                ctx.globalAlpha = 1;
                ctx.globalCompositeOperation = 'source-over';
            },
            post: null
        };
    }

    /* ───────────── Core: photo, push-in, frame loop ───────────── */
    var scene = canvas.getAttribute('data-scene') === 'store' ? storeScene() : pcScene();
    var img = new Image();
    var W, H, DPR, base, photo, lastT = 0;

    function layout() {
        var rect = host.getBoundingClientRect();
        W = Math.max(1, Math.round(rect.width));
        H = Math.max(1, Math.round(rect.height));
        DPR = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = W * DPR;
        canvas.height = H * DPR;
        canvas.style.width = W + 'px';
        canvas.style.height = H + 'px';

        // background-size: cover
        var s = Math.max(W / IMG_W, H / IMG_H);
        base = { s: s, x: (W - IMG_W * s) * POS_X, y: (H - IMG_H * s) * POS_Y };

        // Pre-scale the photo once (with headroom for the zoom) so each frame is cheap.
        var k = 1 + scene.zoom + 0.02;
        photo = document.createElement('canvas');
        photo.width = Math.round(IMG_W * s * DPR * k);
        photo.height = Math.round(IMG_H * s * DPR * k);
        var p = photo.getContext('2d');
        p.imageSmoothingQuality = 'high';
        p.drawImage(img, 0, 0, photo.width, photo.height);

        scene.layout(W, H);
    }

    function frame(now) {
        var t = now / 1000;
        var dt = Math.min(0.05, (now - (lastT || now)) / 1000);
        lastT = now;

        // Slow push-in and back out, anchored on the scene's focal point.
        var zoom = 1 + scene.zoom * (1 - Math.cos(t * 2 * Math.PI / scene.period)) / 2;
        var ax = base.x + scene.anchor[0] * base.s, ay = base.y + scene.anchor[1] * base.s;

        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1;
        ctx.fillStyle = '#050a04';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // DPR -> zoom about anchor -> photo -> image pixel space
        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
        ctx.translate(ax, ay);
        ctx.scale(zoom, zoom);
        ctx.translate(-ax, -ay);
        ctx.drawImage(photo, base.x, base.y, IMG_W * base.s, IMG_H * base.s);
        ctx.translate(base.x, base.y);
        ctx.scale(base.s, base.s);
        ctx.globalCompositeOperation = 'lighter';
        scene.image(t, dt);

        ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
        ctx.globalCompositeOperation = 'source-over';
        scene.screen(t, dt);

        if (scene.post) {
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            scene.post(t, DPR);
        }

        requestAnimationFrame(frame);
    }

    img.onload = function () {
        layout();
        canvas.style.opacity = '1';
        requestAnimationFrame(frame);
    };
    img.src = canvas.getAttribute('data-src');

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () { if (img.complete) layout(); }, 200);
    });
})();
