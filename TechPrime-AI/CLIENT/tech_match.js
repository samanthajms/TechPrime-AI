/**
 * Build a PC (former Tech & Match) — PC builder matching the EasyPC website layout:
 * slot list, case preview, score/summary, product picker drawer, budget, share/load/report/compare.
 * Products come from tech_match_api.php; compatibility rules mirror includes/pc_compatibility.php.
 */
(function () {
    'use strict';

    var SLOTS = [
        { id: 'processor', group: 'CORE COMPONENTS', label: 'PROCESSOR', name: 'Processor', select: 'Select Processor', icon: 'fa-microchip', step: 1, watts: 65 },
        { id: 'motherboard', group: 'CORE COMPONENTS', label: 'MOTHERBOARD', name: 'Motherboard', select: 'Select Motherboard', icon: 'fa-server', step: 1, watts: 40 },
        { id: 'memory', group: 'MEMORY & STORAGE', label: 'MEMORY', name: 'Memory', select: 'Select Memory', icon: 'fa-memory', step: 2, watts: 10 },
        { id: 'ssd', group: 'MEMORY & STORAGE', label: 'SSD', name: 'SSD', select: 'Select SSD', icon: 'fa-hdd', step: 4, watts: 5 },
        { id: 'ssd_sata', group: 'MEMORY & STORAGE', label: 'SSD (SATA)', name: 'SSD (SATA)', select: 'Select SSD (SATA)', icon: 'fa-hdd', step: 4, watts: 5, optional: true },
        { id: 'hdd', group: 'MEMORY & STORAGE', label: 'HARD DISK', name: 'Hard Disk', select: 'Select Hard Disk', icon: 'fa-database', step: 4, watts: 8, optional: true },
        { id: 'gpu', group: 'GRAPHICS & POWER', label: 'GRAPHICS CARD', name: 'Graphics Card', select: 'Select Graphics Card', icon: 'fa-tv', step: 3, watts: 180 },
        { id: 'psu', group: 'GRAPHICS & POWER', label: 'POWER SUPPLY', name: 'Power Supply', select: 'Select Power Supply', icon: 'fa-plug', step: 5, watts: 0 },
        { id: 'case', group: 'CHASSIS & COOLING', label: 'CASE', name: 'Case', select: 'Select Case', icon: 'fa-cube', step: 6, watts: 0 },
        { id: 'cooler', group: 'CHASSIS & COOLING', label: 'CPU COOLER', name: 'CPU Cooler', select: 'Select CPU Cooler', icon: 'fa-fan', step: 7, watts: 15, optional: true },
        { id: 'case_fan', group: 'CHASSIS & COOLING', label: 'CASE FAN', name: 'Case Fan', select: 'Select Case Fan', icon: 'fa-wind', step: 7, watts: 5, optional: true }
    ];
    var TOTAL_SLOTS = SLOTS.length;

    var STEPS = [
        { id: 1, label: 'Core' },
        { id: 2, label: 'Memory' },
        { id: 3, label: 'Graphics' },
        { id: 4, label: 'Storage' },
        { id: 5, label: 'Power' },
        { id: 6, label: 'Case' },
        { id: 7, label: 'Cooling' }
    ];

    var FORM_LABELS = { ITX: 'Mini-ITX', MATX: 'Micro-ATX', ATX: 'ATX', EATX: 'E-ATX' };
    var FORM_RANK = { ITX: 1, MATX: 2, ATX: 3, EATX: 4 };
    var CPU_DDR = { AM4: 'DDR4', AM5: 'DDR5', LGA1851: 'DDR5' };
    var LEGACY_DRAFT_KEY = 'easypc_tech_match_build';
    var DEFAULT_BUDGET = 200000;

    var build = {};
    var activeStep = 1;
    var activeSlot = null;
    var editingBuildId = 0;
    var pendingBuildName = '';
    var budget = DEFAULT_BUDGET;
    var pickerProducts = [];
    var pickerToken = 0;
    var brandFilter = {};
    var compare = [null, null];

    function $(id) { return document.getElementById(id); }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function slotMeta(id) {
        return SLOTS.find(function (s) { return s.id === id; }) || null;
    }

    function alertUi(msg, type, opts) {
        if (typeof IAS_UI !== 'undefined') IAS_UI.alert(msg, type, 0, opts);
    }

    /* ---------- Draft (survives a page refresh, per user) ---------- */

    function draftKey() {
        return LEGACY_DRAFT_KEY + '_' + (Number(window.EP_TM_USER) || 0);
    }

    function saveDraft() {
        try {
            localStorage.setItem(draftKey(), JSON.stringify({
                v: 2,
                components: build,
                editId: editingBuildId,
                name: pendingBuildName,
                budget: budget
            }));
        } catch (e) {}
    }

    function loadDraft() {
        try {
            localStorage.removeItem(LEGACY_DRAFT_KEY);
            var raw = localStorage.getItem(draftKey());
            if (!raw) return;
            var d = JSON.parse(raw);
            if (!d || typeof d !== 'object') return;
            editingBuildId = Number(d.editId) > 0 ? Number(d.editId) : 0;
            pendingBuildName = String(d.name || '');
            if (Number(d.budget) > 0) budget = Number(d.budget);
            applyComponents(d.components);
        } catch (e) {
            build = {};
        }
    }

    /* ---------- Derived values ---------- */

    function filledCount(comps) {
        comps = comps || build;
        return SLOTS.reduce(function (n, s) { return n + (comps[s.id] ? 1 : 0); }, 0);
    }

    function totalPrice(comps) {
        comps = comps || build;
        return SLOTS.reduce(function (sum, s) {
            return sum + (comps[s.id] ? Number(comps[s.id].price || 0) : 0);
        }, 0);
    }

    function powerDraw() {
        return SLOTS.reduce(function (sum, s) {
            return sum + (build[s.id] ? Number(s.watts || 0) : 0);
        }, 0);
    }

    function hasStorage(comps) {
        comps = comps || build;
        return !!(comps.ssd || comps.ssd_sata || comps.hdd);
    }

    /* Required parts still missing; any one drive covers storage. */
    function missingEssentials() {
        var out = [];
        ['processor', 'motherboard', 'memory'].forEach(function (id) { if (!build[id]) out.push(slotMeta(id)); });
        if (!hasStorage()) out.push(slotMeta('ssd'));
        ['psu', 'case'].forEach(function (id) { if (!build[id]) out.push(slotMeta(id)); });
        return out;
    }

    function missingNames() {
        return missingEssentials().map(function (m) { return m.id === 'ssd' ? 'Storage' : m.name; });
    }

    function buildScore(comps) {
        comps = comps || build;
        var base = Math.round((filledCount(comps) / TOTAL_SLOTS) * 70);
        var bonus = 0;
        if (comps.processor && comps.motherboard) bonus += 8;
        if (comps.memory) bonus += 6;
        if (comps.gpu) bonus += 8;
        if (comps.psu) bonus += 4;
        if (comps['case']) bonus += 4;
        return Math.min(100, base + bonus);
    }

    function axisScore(keys) {
        var hit = 0;
        keys.forEach(function (k) { if (build[k]) hit++; });
        return keys.length ? hit / keys.length : 0;
    }

    /* ---------- Compatibility (mirror of includes/pc_compatibility.php) ---------- */

    function inferCompatFromText(name, description, category) {
        var raw = String((name || '') + ' ' + (description || '') + ' ' + (category || '')).toLowerCase();
        var lname = String(name || '').toLowerCase();
        var tags = { socket: null, ddr: null, form: null, storage: null, watts: null, raw: raw };
        if (/\bam5\b/.test(raw)) tags.socket = 'AM5';
        else if (/\bam4\b/.test(raw)) tags.socket = 'AM4';
        else if (/\blga\s*1851\b/.test(raw)) tags.socket = 'LGA1851';
        else if (/\blga\s*1700\b/.test(raw)) tags.socket = 'LGA1700';
        else if (/\blga\s*1200\b/.test(raw)) tags.socket = 'LGA1200';
        else if (/\blga\s*1151\b/.test(raw)) tags.socket = 'LGA1151';
        else if (/\blga\s*1150\b/.test(raw)) tags.socket = 'LGA1150';
        if (/\bddr5\b/.test(raw)) tags.ddr = 'DDR5';
        else if (/\bddr4\b/.test(raw)) tags.ddr = 'DDR4';
        else if (/\bddr3\b/.test(raw)) tags.ddr = 'DDR3';
        if (/\bmini[\s\-]?itx\b|\bitx\b/.test(raw)) tags.form = 'ITX';
        else if (/\bmicro[\s\-]?atx\b|\bmatx\b|\bm\-atx\b/.test(raw)) tags.form = 'MATX';
        else if (/\beatx\b/.test(raw)) tags.form = 'EATX';
        else if (/\batx\b/.test(raw)) tags.form = 'ATX';
        var cm = /\bmotherboard\b/.test(lname) ? lname.match(/\b[abhxz]\d{3}(m?)/) : null;
        if (cm) {
            if (!tags.form) tags.form = cm[1] === 'm' ? 'MATX' : 'ATX';
            if (!tags.socket) {
                if (/\b(a320|b350|x370|b450|x470|a520|b550|x570)/.test(lname)) tags.socket = 'AM4';
                else if (/\b(a620|b650|x670|b840|b850|x870)/.test(lname)) tags.socket = 'AM5';
                else if (/\b(h610|b660|h670|z690|b760|z790)/.test(lname)) tags.socket = 'LGA1700';
                else if (/\b(h810|b860|z890)/.test(lname)) tags.socket = 'LGA1851';
            }
        }
        if (/\bnvme\b|\bm\.?2\b/.test(raw)) tags.storage = 'NVME';
        else if (/\bsata\b/.test(raw)) tags.storage = 'SATA';
        var wm = raw.match(/\b(\d{3,4})\s*w\b/);
        if (wm) {
            var w = parseInt(wm[1], 10);
            if (w >= 200 && w <= 2000) tags.watts = w;
        }
        return tags;
    }

    function ensureCompat(item) {
        if (item && !item.compat) {
            item.compat = inferCompatFromText(item.name, item.description, item.category);
        }
        return item;
    }

    function tagsFor(slot, except) {
        if (slot === except || !build[slot]) return null;
        return ensureCompat(build[slot]).compat || null;
    }

    /* Reason why `product` can't go in `slotId` next to the other selected parts, or null. */
    function candidateIncompatible(slotId, product) {
        if (!product) return null;
        ensureCompat(product);
        var tags = product.compat;
        if (!tags) return null;
        var cpu = tagsFor('processor', slotId);
        var mobo = tagsFor('motherboard', slotId);
        var ram = tagsFor('memory', slotId);
        var caseT = tagsFor('case', slotId);
        var psu = tagsFor('psu', slotId);

        if (slotId === 'processor' && mobo && mobo.socket && tags.socket && mobo.socket !== tags.socket) {
            return 'Not compatible with the selected motherboard — CPU socket mismatch.';
        }
        if (slotId === 'motherboard' && cpu && cpu.socket && tags.socket && cpu.socket !== tags.socket) {
            return 'Not compatible with the selected processor — CPU socket mismatch.';
        }
        if (slotId === 'motherboard' && ram && ram.ddr && tags.ddr && ram.ddr !== tags.ddr) {
            return 'Not compatible with the selected memory — RAM type mismatch.';
        }
        if (slotId === 'memory' && mobo && mobo.ddr && tags.ddr && mobo.ddr !== tags.ddr) {
            return 'Not compatible with the selected motherboard — RAM type mismatch.';
        }
        if (slotId === 'memory' && cpu && tags.ddr && CPU_DDR[cpu.socket] && CPU_DDR[cpu.socket] !== tags.ddr) {
            return 'Not compatible with the selected processor — it needs ' + CPU_DDR[cpu.socket] + ' memory.';
        }
        if (slotId === 'processor' && ram && ram.ddr && CPU_DDR[tags.socket] && CPU_DDR[tags.socket] !== ram.ddr) {
            return 'Not compatible with the selected memory — this processor needs ' + CPU_DDR[tags.socket] + '.';
        }
        if (slotId === 'cooler') {
            var ref = (cpu && cpu.socket) || (mobo && mobo.socket) || null;
            if (ref && tags.socket && tags.socket !== ref) {
                return 'Not compatible with the selected CPU/motherboard — cooler socket mismatch.';
            }
        }
        if (slotId === 'case' && mobo && mobo.form && tags.form &&
            FORM_RANK[tags.form] && FORM_RANK[mobo.form] && FORM_RANK[tags.form] < FORM_RANK[mobo.form]) {
            return 'Not compatible with the selected motherboard — case form factor too small.';
        }
        if (slotId === 'motherboard' && caseT && caseT.form && tags.form &&
            FORM_RANK[caseT.form] && FORM_RANK[tags.form] && FORM_RANK[caseT.form] < FORM_RANK[tags.form]) {
            return 'Not compatible with the selected case — motherboard form factor too large.';
        }
        if (slotId === 'ssd' && tags.storage === 'SATA' && tags.raw.indexOf('nvme') === -1 && tags.raw.indexOf('m.2') === -1) {
            return 'Not compatible with this SSD slot — SATA drive should use SSD (SATA).';
        }
        if (slotId === 'psu') {
            var estimate = 0;
            if (build.processor) estimate += 65;
            if (build.motherboard) estimate += 40;
            if (build.gpu) estimate += 180;
            if (build.memory) estimate += 10;
            if (build.ssd || build.ssd_sata || build.hdd) estimate += 15;
            if (tags.watts && tags.watts < estimate) {
                return 'Not compatible with the current build — PSU wattage too low for estimated power draw.';
            }
        }
        if (slotId === 'gpu' && psu && psu.watts) {
            var est2 = 180 + (cpu ? 65 : 0) + (mobo ? 40 : 0) + 25;
            if (psu.watts < est2) {
                return 'Not compatible with the selected power supply — estimated GPU build draw exceeds PSU wattage.';
            }
        }
        return null;
    }

    function validateCurrentBuild() {
        var issues = [];
        Object.keys(build).forEach(function (slot) {
            var item = build[slot];
            if (!item) return;
            var reason = candidateIncompatible(slot, item);
            if (reason) issues.push((item.name || slot) + ': ' + reason);
        });
        return issues;
    }

    function specChips(item, withType) {
        var t = item && ensureCompat(item).compat;
        var chips = [];
        if (withType && item.type) chips.push('<span class="tm-chip is-type">' + escapeHtml(item.type) + '</span>');
        if (t) {
            if (t.socket) chips.push(t.socket);
            if (t.ddr) chips.push(t.ddr);
            if (t.form) chips.push(FORM_LABELS[t.form] || t.form);
            if (t.storage) chips.push(t.storage === 'NVME' ? 'NVMe' : 'SATA');
            if (t.watts) chips.push(t.watts + 'W');
        }
        if (!chips.length) return '';
        return '<span class="tm-chips">' + chips.map(function (c) {
            return c.charAt(0) === '<' ? c : '<span class="tm-chip">' + escapeHtml(c) + '</span>';
        }).join('') + '</span>';
    }

    /* ---------- Builder rendering ---------- */

    function nextSlot() {
        var essentials = missingEssentials();
        if (essentials.length) return essentials[0];
        return SLOTS.find(function (s) { return !build[s.id]; }) || null;
    }

    function stepDone(stepId) {
        var related = SLOTS.filter(function (s) { return s.step === stepId; });
        if (stepId === 4) return hasStorage();
        return related.every(function (s) { return !!build[s.id] || s.optional; }) &&
            related.some(function (s) { return !!build[s.id]; });
    }

    function renderSteps() {
        var el = $('tmSteps');
        if (!el) return;
        el.innerHTML = STEPS.map(function (st, i) {
            var cls = 'tm-step';
            if (st.id === activeStep) cls += ' is-active';
            else if (stepDone(st.id)) cls += ' is-done';
            return (i ? '<span class="tm-step-line" aria-hidden="true"></span>' : '') +
                '<button type="button" class="' + cls + '" data-step="' + st.id + '">' +
                '<span class="tm-step-num">' + st.id + '</span>' +
                '<span class="tm-step-label">' + escapeHtml(st.label) + '</span></button>';
        }).join('');
    }

    function renderSlots() {
        var el = $('tmSlotList');
        if (!el) return;
        var next = nextSlot();
        var html = '';
        var lastGroup = '';
        SLOTS.forEach(function (s) {
            if (s.group !== lastGroup) {
                html += '<div class="tm-section-label">' + escapeHtml(s.group) + '</div>';
                lastGroup = s.group;
            }
            var item = build[s.id];
            var cls = 'tm-slot' + (item ? ' is-filled' : '') + (!item && next && next.id === s.id ? ' is-next' : '');
            var icon = item && item.image
                ? '<img src="' + escapeHtml(item.image) + '" alt="">'
                : '<i class="fas ' + s.icon + '" aria-hidden="true"></i>';
            html += '<div class="' + cls + '" id="tm-slot-' + s.id + '" data-pick="' + s.id + '" role="button" tabindex="0">' +
                '<span class="tm-slot-icon">' + icon + '</span>' +
                '<span class="tm-slot-text">' +
                '<span class="tm-slot-name">' + escapeHtml(s.label) + (s.optional && !item ? '<em class="tm-tag">Optional</em>' : '') + '</span>' +
                (item
                    ? '<span class="tm-slot-hint" title="' + escapeHtml(item.name) + '">' + escapeHtml(item.name) + '</span>' +
                      '<span class="tm-slot-price">' + peso(item.price) + '</span>' +
                      '<span class="tm-slot-actions">' +
                      '<button type="button" class="tm-change" data-pick="' + s.id + '">Change</button>' +
                      '<button type="button" class="tm-remove" data-remove="' + s.id + '">Remove</button>' +
                      '</span>'
                    : '<span class="tm-slot-hint">+ ' + escapeHtml(s.select) + '</span>') +
                '</span></div>';
        });
        el.innerHTML = html;
    }

    function renderCenter() {
        var count = filledCount();
        pcxSync();
        if ($('tmComponentCount')) $('tmComponentCount').innerHTML = '<b>' + count + '</b> / ' + TOTAL_SLOTS + ' components';
        if ($('tmComponentBar')) $('tmComponentBar').style.width = Math.round((count / TOTAL_SLOTS) * 100) + '%';

        var watts = powerDraw();
        var psu = tagsFor('psu');
        var recommended = watts > 0 ? Math.max(450, Math.ceil((watts * 1.5) / 50) * 50) : 0;
        var hint = $('tmPowerHint');
        var bar = $('tmPowerBar');
        if ($('tmPowerDraw')) $('tmPowerDraw').textContent = '~' + watts + 'W';
        var pct;
        if (psu && psu.watts) {
            pct = Math.round((watts / psu.watts) * 100);
            if (hint) hint.textContent = 'About ' + pct + '% of your ' + psu.watts + 'W power supply.';
        } else {
            pct = Math.round((watts / 650) * 100);
            if (hint) hint.textContent = recommended ? ('Recommended power supply: ' + recommended + 'W or higher.') : '';
        }
        if (bar) {
            bar.style.width = Math.min(100, pct) + '%';
            bar.parentNode.classList.toggle('is-high', !!(psu && psu.watts && pct > 80));
        }
        return { pct: pct, psu: psu };
    }

    function renderRight(power) {
        var count = filledCount();
        var total = totalPrice();
        var score = buildScore();
        var issues = validateCurrentBuild();
        if ($('tmBuildScore')) $('tmBuildScore').textContent = String(score);
        if ($('tmSummaryCount')) $('tmSummaryCount').textContent = count + ' / ' + TOTAL_SLOTS;
        if ($('tmSummaryBar')) $('tmSummaryBar').style.width = Math.round((count / TOTAL_SLOTS) * 100) + '%';
        if ($('tmSummaryTotal')) $('tmSummaryTotal').textContent = peso(total);
        if ($('tmMobileTotal')) $('tmMobileTotal').textContent = peso(total);
        if ($('tmMobileCount')) $('tmMobileCount').textContent = count + ' / ' + TOTAL_SLOTS + ' components';

        /* Score badges: score / compatibility / power */
        setBadge('tmScoreBadgeScore', score >= 70 ? 'good' : '', 'Build score: ' + score + '/100');
        setBadge('tmScoreBadgeCompat', issues.length ? 'bad' : (count ? 'good' : ''),
            issues.length ? (issues.length + ' compatibility issue(s)') : (count ? 'All selected parts are compatible' : 'Compatibility: no parts yet'));
        var p = power || {};
        setBadge('tmScoreBadgePower', p.psu && p.psu.watts ? (p.pct > 80 ? 'bad' : 'good') : '',
            p.psu && p.psu.watts ? ('Power supply load: ' + p.pct + '%') : 'Power: no power supply selected');

        var compat = $('tmCompatStatus');
        if (compat) {
            var html = '';
            if (issues.length) {
                html += issues.slice(0, 4).map(function (msg) {
                    return '<div class="tm-compat-line is-error"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><span>' + escapeHtml(msg) + '</span></div>';
                }).join('');
            } else if (count > 0) {
                html += '<div class="tm-compat-line is-ok"><i class="fas fa-check-circle" aria-hidden="true"></i><span>All selected parts are compatible.</span></div>';
            }
            var missing = missingNames();
            if (count > 0 && missing.length) {
                html += '<div class="tm-compat-line is-todo"><i class="fas fa-list-ul" aria-hidden="true"></i><span>Still needed: ' + escapeHtml(missing.join(', ')) + '</span></div>';
            }
            compat.innerHTML = html;
        }

        var plat = $('tmPlatform');
        if (plat) {
            var cpu = tagsFor('processor');
            var mobo = tagsFor('motherboard');
            var ram = tagsFor('memory');
            var chips = [];
            var socket = (cpu && cpu.socket) || (mobo && mobo.socket);
            var ddr = (mobo && mobo.ddr) || (ram && ram.ddr) || (cpu && CPU_DDR[cpu.socket]);
            if (socket) chips.push('Socket ' + socket);
            if (ddr) chips.push(ddr);
            if (mobo && mobo.form) chips.push(FORM_LABELS[mobo.form] || mobo.form);
            plat.hidden = !chips.length;
            plat.innerHTML = chips.map(function (c) { return '<span class="tm-chip">' + escapeHtml(c) + '</span>'; }).join('');
        }

        if ($('tmSaveLabel')) $('tmSaveLabel').textContent = editingBuildId > 0 ? 'Update Build' : 'Save Build';
        renderRadar();
        renderBudget(total);
        renderCompareSlots();
    }

    function setBadge(id, state, title) {
        var el = $(id);
        if (!el) return;
        el.classList.toggle('is-good', state === 'good');
        el.classList.toggle('is-bad', state === 'bad');
        el.title = title;
    }

    function renderBudget(total) {
        var wrap = document.querySelector('.bp-budget');
        if ($('tmBudgetUsed')) $('tmBudgetUsed').textContent = peso(total);
        if ($('tmBudgetEdit')) $('tmBudgetEdit').textContent = peso(budget);
        if ($('tmBudgetBar')) $('tmBudgetBar').style.width = Math.min(100, budget > 0 ? (total / budget) * 100 : 0) + '%';
        if (wrap) wrap.classList.toggle('is-over', total > budget);
    }

    function renderRadar() {
        var svg = $('tmRadarSvg');
        if (!svg) return;
        var axes = [
            { label: 'CPU', v: axisScore(['processor', 'motherboard', 'cooler']) },
            { label: 'RAM', v: axisScore(['memory']) },
            { label: 'GPU', v: axisScore(['gpu']) },
            { label: 'SSD', v: hasStorage() ? 1 : 0 },
            { label: 'PSU', v: axisScore(['psu', 'case']) }
        ];
        var cx = 90, cy = 90, r = 58;
        function pt(i, rr) {
            var ang = (-Math.PI / 2) + (i * 2 * Math.PI / axes.length);
            return [cx + Math.cos(ang) * rr, cy + Math.sin(ang) * rr];
        }
        var grid = '';
        for (var ring = 1; ring <= 4; ring++) {
            grid += '<polygon points="' + axes.map(function (_, i) { return pt(i, r * ring / 4).join(','); }).join(' ') +
                '" fill="none" stroke="#d7dbe0" stroke-width="1"/>';
        }
        var spokes = axes.map(function (_, i) {
            var p = pt(i, r);
            return '<line x1="' + cx + '" y1="' + cy + '" x2="' + p[0] + '" y2="' + p[1] + '" stroke="#e5e7eb" stroke-width="1"/>';
        }).join('');
        var data = axes.map(function (a, i) { return pt(i, r * Math.max(0.04, a.v)).join(','); }).join(' ');
        var labels = axes.map(function (a, i) {
            var p = pt(i, r + 15);
            return '<text x="' + p[0] + '" y="' + p[1] + '" text-anchor="middle" dominant-baseline="middle" fill="#1f2328" font-size="9" font-weight="500">' + a.label + '</text>';
        }).join('');
        svg.innerHTML = grid + spokes +
            '<polygon points="' + data + '" fill="rgba(98,178,54,0.22)" stroke="#62b236" stroke-width="1.5"/>' +
            '<circle cx="' + cx + '" cy="' + cy + '" r="2.5" fill="#62b236"/>' +
            labels;
    }

    function renderEditBanner() {
        var banner = $('tmEditBanner');
        if (!banner) return;
        banner.hidden = !(editingBuildId > 0);
        if ($('tmEditName')) $('tmEditName').textContent = pendingBuildName ? ('"' + pendingBuildName + '"') : '';
    }

    function refresh() {
        renderSteps();
        renderSlots();
        var power = renderCenter();
        renderRight(power);
        renderEditBanner();
        saveDraft();
    }

    /* Scroll so a slot row is visible, and highlight it. */
    function revealSlot(slotId) {
        var row = $('tm-slot-' + slotId);
        if (!row) return;
        var rect = row.getBoundingClientRect();
        if (rect.top < 110 || rect.bottom > window.innerHeight) {
            window.scrollTo({ top: rect.top + window.pageYOffset - 140, behavior: 'smooth' });
        }
        row.classList.add('is-flash');
        setTimeout(function () { row.classList.remove('is-flash'); }, 1300);
    }

    /* ---------- 3D / 2D case preview ---------- */

    var PCX_HINTS = {
        processor: 'The brain of the PC. Pick this first: it decides the socket and memory type.',
        motherboard: 'Connects every part. Must match the processor socket.',
        memory: 'Desktop RAM. DDR4 or DDR5, depending on your platform.',
        ssd: 'Fast M.2 NVMe drive for Windows, apps and games.',
        ssd_sata: 'Optional 2.5" SATA SSD for extra storage.',
        hdd: 'Optional large, budget-friendly bulk storage.',
        gpu: 'For gaming and creative work. Skip it if your CPU has built-in graphics.',
        psu: 'Powers everything. Size it to the estimated power draw.',
        'case': 'Houses the build. Must fit the motherboard size.',
        cooler: 'Optional if your processor comes with a stock cooler.',
        case_fan: 'Optional fans for better airflow and RGB style.'
    };
    var PCX_DEFAULT = { rx: -6, ry: -18 };
    var pcx = { view: '3d', explode: false, rx: PCX_DEFAULT.rx, ry: PCX_DEFAULT.ry, focus: null, drag: null, suppressClick: false };

    function pcxStage() { return $('tmPcStage'); }

    function pcxPart(slot) {
        var stage = pcxStage();
        return stage ? stage.querySelector('.pcx-part[data-slot="' + slot + '"]') : null;
    }

    function pcxApply() {
        var stage = pcxStage();
        if (!stage) return;
        stage.setAttribute('data-view', pcx.view);
        stage.style.setProperty('--rx', pcx.rx + 'deg');
        stage.style.setProperty('--ry', pcx.ry + 'deg');
        stage.style.setProperty('--ex', pcx.explode && pcx.view === '3d' ? '3' : '1');
        var scene = $('pcxScene');
        if (scene) {
            var w = scene.clientWidth || 400;
            var h = scene.clientHeight || 400;
            /* Leave room for rotation / exploded depth so the case never clips. */
            var roomW = pcx.view === '3d' ? (pcx.explode ? 470 : 420) : 360;
            stage.style.setProperty('--scale', String(Math.min(1.15, (w - 24) / roomW, (h - 30) / 420).toFixed(3)));
        }
        stage.querySelectorAll('[data-pcx-view]').forEach(function (b) {
            b.classList.toggle('is-on', b.getAttribute('data-pcx-view') === pcx.view);
        });
        if ($('pcxExplode')) {
            $('pcxExplode').setAttribute('aria-pressed', pcx.explode && pcx.view === '3d' ? 'true' : 'false');
            $('pcxExplode').disabled = pcx.view !== '3d';
        }
        pcxRenderInfo();
    }

    /* Mark installed parts and refresh labels / info card. */
    function pcxSync() {
        var stage = pcxStage();
        if (!stage) return;
        stage.querySelectorAll('.pcx-part[data-slot]').forEach(function (el) {
            var slot = el.getAttribute('data-slot');
            var meta = slotMeta(slot);
            var item = build[slot];
            el.classList.toggle('is-filled', !!item);
            el.setAttribute('aria-label', (meta ? meta.name : slot) + ': ' + (item ? 'installed, ' + item.name : 'missing'));
            /* has-case, has-cooler, has-case_fan... drive the decorative bits (tubes, pump, rear fan). */
            stage.classList.toggle('has-' + slot, !!item);
        });
        pcxRenderInfo();
    }

    function pcxFocus(slot) {
        var stage = pcxStage();
        if (!stage) return;
        var el = slot ? pcxPart(slot) : null;
        pcx.focus = el ? slot : null;
        stage.querySelectorAll('.pcx-part.is-focus').forEach(function (p) { p.classList.remove('is-focus'); });
        if (el) el.classList.add('is-focus');
        stage.classList.toggle('has-focus', !!el);
        pcxRenderInfo();
    }

    /* Info panel under the scene: the focused part, or a short guide. */
    function pcxRenderInfo() {
        var box = $('pcxInfo');
        if (!box) return;
        var slot = pcx.focus;
        var meta = slot ? slotMeta(slot) : null;
        if (!meta) {
            var parts = document.querySelectorAll('#tmPcStage .pcx-part[data-slot]');
            var installed = 0;
            parts.forEach(function (el) { if (build[el.getAttribute('data-slot')]) installed++; });
            box.innerHTML = '<div class="pcx-dock-idle"><strong>' + installed + ' of ' + parts.length + ' parts installed.</strong> ' +
                'Click any part in the case to inspect it' + (pcx.view === '3d' ? ', or drag to rotate.' : '.') + '</div>';
            return;
        }
        var item = build[slot];
        var badge = item
            ? '<span class="pcx-badge is-on">Installed</span>'
            : '<span class="pcx-badge ' + (meta.optional ? 'is-optional">Optional' : 'is-missing">Missing') + '</span>';
        var html = '<div class="pcx-dock-main">' +
            '<div class="pcx-dock-title"><strong>' + escapeHtml(meta.name) + '</strong>' + badge + '</div>';
        if (item) {
            html += '<p class="pcx-info-name" title="' + escapeHtml(item.name) + '">' + escapeHtml(item.name) + '</p>' + specChips(item, false) + '</div>' +
                '<div class="pcx-dock-side">' +
                '<span class="pcx-info-price">' + peso(item.price) + '</span>' +
                '<button type="button" class="pcx-btn-go" data-pick="' + slot + '">Change</button>' +
                '<button type="button" class="pcx-btn-remove" data-remove="' + slot + '" aria-label="Remove ' + escapeHtml(meta.name) + '" title="Remove"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>' +
                '<button type="button" class="pcx-info-close" data-pcx-close aria-label="Close" title="Close"><i class="fas fa-times" aria-hidden="true"></i></button>' +
                '</div>';
        } else {
            html += '<p class="pcx-info-text">' + escapeHtml(PCX_HINTS[slot] || '') + '</p></div>' +
                '<div class="pcx-dock-side">' +
                '<button type="button" class="pcx-btn-go" data-pick="' + slot + '">Choose ' + escapeHtml(meta.name) + '</button>' +
                '<button type="button" class="pcx-info-close" data-pcx-close aria-label="Close" title="Close"><i class="fas fa-times" aria-hidden="true"></i></button>' +
                '</div>';
        }
        box.innerHTML = html;
    }

    /* Drop a freshly selected part into the case, then keep it highlighted. */
    function pcxInstall(slot) {
        var el = pcxPart(slot);
        if (!el) return;
        pcxFocus(null);
        el.classList.remove('is-installing');
        void el.offsetWidth;
        el.classList.add('is-installing');
        setTimeout(function () {
            el.classList.remove('is-installing');
            pcxFocus(slot);
        }, 700);
    }

    function pcxHover(slot, on) {
        var el = slot ? pcxPart(slot) : null;
        if (el) el.classList.toggle('is-hover', !!on);
    }

    function pcxBind() {
        var stage = pcxStage();
        var scene = $('pcxScene');
        if (!stage || !scene) return;
        try {
            var saved = JSON.parse(localStorage.getItem('easypc_pcx_view') || 'null');
            if (saved && (saved.view === '3d' || saved.view === '2d')) pcx.view = saved.view;
            if (saved) pcx.explode = !!saved.explode;
        } catch (e) {}
        var remember = function () {
            try { localStorage.setItem('easypc_pcx_view', JSON.stringify({ view: pcx.view, explode: pcx.explode })); } catch (e) {}
        };

        stage.addEventListener('click', function (e) {
            var viewBtn = e.target.closest('[data-pcx-view]');
            if (viewBtn) {
                pcx.view = viewBtn.getAttribute('data-pcx-view');
                remember();
                pcxApply();
                return;
            }
            if (e.target.closest('#pcxExplode')) {
                pcx.explode = !pcx.explode;
                remember();
                pcxApply();
                return;
            }
            if (e.target.closest('#pcxReset')) {
                pcx.rx = PCX_DEFAULT.rx;
                pcx.ry = PCX_DEFAULT.ry;
                pcxFocus(null);
                pcxApply();
                return;
            }
            if (e.target.closest('[data-pcx-close]')) {
                pcxFocus(null);
                return;
            }
            if (e.target.closest('.pcx-dock, .pcx-toolbar')) return;
            if (pcx.suppressClick) {
                pcx.suppressClick = false;
                return;
            }
            var part = e.target.closest('.pcx-part[data-slot]');
            if (!part) {
                pcxFocus(null);
                return;
            }
            var slot = part.getAttribute('data-slot');
            if (pcx.focus === slot) openDrawer(slot);
            else pcxFocus(slot);
        });

        /* Drag to rotate (3D only); a real drag must not count as a click. */
        scene.addEventListener('pointerdown', function (e) {
            if (pcx.view !== '3d' || e.button !== 0) return;
            pcx.drag = { x: e.clientX, y: e.clientY, rx: pcx.rx, ry: pcx.ry, moved: false };
        });
        window.addEventListener('pointermove', function (e) {
            var d = pcx.drag;
            if (!d) return;
            var dx = e.clientX - d.x;
            var dy = e.clientY - d.y;
            if (!d.moved && Math.abs(dx) + Math.abs(dy) < 5) return;
            if (!d.moved) {
                d.moved = true;
                scene.classList.add('is-dragging');
            }
            pcx.ry = Math.max(-65, Math.min(65, d.ry + dx * 0.4));
            pcx.rx = Math.max(-30, Math.min(20, d.rx - dy * 0.3));
            pcxApply();
        });
        window.addEventListener('pointerup', function () {
            if (!pcx.drag) return;
            if (pcx.drag.moved) pcx.suppressClick = true;
            pcx.drag = null;
            scene.classList.remove('is-dragging');
            setTimeout(function () { pcx.suppressClick = false; }, 0);
        });
        window.addEventListener('resize', pcxApply);

        /* Hovering a slot in the list (or "Add Missing") lights the matching part. */
        var list = $('tmSlotList');
        if (list) {
            list.addEventListener('mouseover', function (e) {
                var slot = e.target.closest('.tm-slot');
                if (slot) pcxHover(slot.getAttribute('data-pick'), true);
            });
            list.addEventListener('mouseout', function (e) {
                var slot = e.target.closest('.tm-slot');
                if (slot && !slot.contains(e.relatedTarget)) pcxHover(slot.getAttribute('data-pick'), false);
            });
        }
        var addMissing = $('tmAddMissing');
        if (addMissing) {
            addMissing.addEventListener('mouseenter', function () { stage.classList.add('show-missing'); });
            addMissing.addEventListener('mouseleave', function () { stage.classList.remove('show-missing'); });
        }
        pcxApply();
    }

    /* ---------- Picker drawer ---------- */

    function openDrawer(slotId) {
        var meta = slotMeta(slotId);
        if (!meta) return;
        activeSlot = slotId;
        brandFilter = {};
        if ($('tmPickerTitle')) $('tmPickerTitle').textContent = meta.select;
        if ($('tmPickerSearch')) $('tmPickerSearch').value = '';
        if ($('tmPickerSort')) $('tmPickerSort').value = 'price-asc';
        if ($('tmPickerInStock')) $('tmPickerInStock').checked = true;
        if ($('tmPickerCompatOnly')) $('tmPickerCompatOnly').checked = true;
        setFiltersOpen(false);
        renderPickerCurrent();
        var drawer = $('tmPickerView');
        if ($('tmDrawerBackdrop')) $('tmDrawerBackdrop').hidden = false;
        if (drawer) {
            drawer.classList.add('is-open');
            drawer.setAttribute('aria-hidden', 'false');
        }
        document.body.classList.add('tm-drawer-open');
        pcxFocus(slotId);
        loadProducts(slotId);
        setTimeout(function () { if ($('tmPickerSearch')) $('tmPickerSearch').focus(); }, 260);
    }

    function closeDrawer(focusSlot) {
        var drawer = $('tmPickerView');
        if (drawer) {
            drawer.classList.remove('is-open');
            drawer.setAttribute('aria-hidden', 'true');
        }
        if ($('tmDrawerBackdrop')) $('tmDrawerBackdrop').hidden = true;
        document.body.classList.remove('tm-drawer-open');
        activeSlot = null;
        refresh();
        if (focusSlot) revealSlot(focusSlot);
    }

    function setFiltersOpen(open) {
        if ($('tmFilters')) $('tmFilters').hidden = !open;
        if ($('tmFiltersBtn')) $('tmFiltersBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function renderPickerCurrent() {
        var box = $('tmPickerCurrent');
        if (!box) return;
        var item = activeSlot ? build[activeSlot] : null;
        box.hidden = !item;
        if (!item) { box.innerHTML = ''; return; }
        box.innerHTML = (item.image ? '<img src="' + escapeHtml(item.image) + '" alt="">' : '') +
            '<span title="' + escapeHtml(item.name) + '">Current: <strong>' + escapeHtml(item.name) + '</strong></span>' +
            '<button type="button" data-remove="' + activeSlot + '">Remove</button>';
    }

    function loadProducts(slotId) {
        var list = $('tmProductGrid');
        if (!list) return;
        var token = ++pickerToken;
        pickerProducts = [];
        if ($('tmPickerCount')) $('tmPickerCount').textContent = '';
        list.innerHTML = '<div class="tm-picker-loading">Loading products…</div>';
        fetch('tech_match_api.php?slot=' + encodeURIComponent(slotId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (token !== pickerToken || activeSlot !== slotId) return;
                pickerProducts = (data && data.products) ? data.products : [];
                pickerProducts.forEach(function (p, i) { p._rank = i; ensureCompat(p); });
                renderBrandChips();
                renderProducts();
            })
            .catch(function () {
                if (token !== pickerToken || activeSlot !== slotId) return;
                list.innerHTML = '<div class="tm-picker-empty"><i class="fas fa-wifi" aria-hidden="true"></i>Unable to load products. Please try again.</div>';
            });
    }

    function renderBrandChips() {
        var el = $('tmBrandChips');
        if (!el) return;
        var brands = [];
        pickerProducts.forEach(function (p) {
            var b = String(p.brand || '').trim();
            if (b && brands.indexOf(b) === -1) brands.push(b);
        });
        brands.sort(function (a, b) { return a.localeCompare(b); });
        el.innerHTML = brands.map(function (b) {
            return '<button type="button" data-brand="' + escapeHtml(b) + '" class="' + (brandFilter[b] ? 'is-on' : '') + '">' + escapeHtml(b) + '</button>';
        }).join('');
    }

    function renderProducts() {
        var listEl = $('tmProductGrid');
        if (!listEl || !activeSlot) return;
        var meta = slotMeta(activeSlot);
        var q = String(($('tmPickerSearch') || {}).value || '').trim().toLowerCase();
        var sort = ($('tmPickerSort') || {}).value || 'price-asc';
        var inStock = !!($('tmPickerInStock') || {}).checked;
        var compatOnly = !!($('tmPickerCompatOnly') || {}).checked;
        var brands = Object.keys(brandFilter).filter(function (b) { return brandFilter[b]; });
        var current = build[activeSlot];
        if ($('tmFiltersBtn')) $('tmFiltersBtn').classList.toggle('has-filters', brands.length > 0 || !compatOnly);

        var list = pickerProducts.map(function (p) { return { p: p, reason: candidateIncompatible(activeSlot, p) }; });
        if (q) {
            list = list.filter(function (x) {
                return ((x.p.name || '') + ' ' + (x.p.brand || '') + ' ' + (x.p.type || '')).toLowerCase().indexOf(q) !== -1;
            });
        }
        if (inStock) list = list.filter(function (x) { return Number(x.p.stock || 0) > 0; });
        if (brands.length) list = list.filter(function (x) { return brands.indexOf(String(x.p.brand || '')) !== -1; });
        var hidden = 0;
        if (compatOnly) {
            hidden = list.filter(function (x) { return !!x.reason; }).length;
            list = list.filter(function (x) { return !x.reason; });
        }
        list.sort(function (a, b) {
            if (sort === 'price-desc') return Number(b.p.price) - Number(a.p.price);
            if (sort === 'name') return String(a.p.name).localeCompare(String(b.p.name));
            if (sort === 'stock') return Number(b.p.stock) - Number(a.p.stock) || a.p._rank - b.p._rank;
            return Number(a.p.price) - Number(b.p.price);
        });

        if ($('tmPickerCount')) {
            $('tmPickerCount').textContent = list.length + ' product' + (list.length === 1 ? '' : 's') +
                (hidden ? ' · ' + hidden + ' incompatible hidden' : '');
        }
        if (!pickerProducts.length) {
            listEl.innerHTML = '<div class="tm-picker-empty"><i class="fas fa-box-open" aria-hidden="true"></i>' +
                'No ' + escapeHtml(meta ? meta.name : 'products') + ' in stock right now.<br>Please check back soon.</div>';
            return;
        }
        if (!list.length) {
            listEl.innerHTML = '<div class="tm-picker-empty"><i class="fas fa-search" aria-hidden="true"></i>' +
                (q ? 'No products match "' + escapeHtml(q) + '".' : 'No products match these filters.') +
                (hidden ? '<br><button type="button" data-show-all>Show incompatible products</button>' : '') +
                '</div>';
            return;
        }

        listEl.innerHTML = list.map(function (x) {
            var p = x.p;
            var stock = Number(p.stock || 0);
            var out = stock <= 0;
            var isCurrent = current && Number(current.id) === Number(p.id);
            var pid = parseInt(p.id, 10) || 0;
            var img = p.image
                ? '<img class="tm-product-thumb" src="' + escapeHtml(p.image) + '" alt="">'
                : '<span class="tm-img-fallback"><i class="fas ' + (meta ? meta.icon : 'fa-box') + '" aria-hidden="true"></i></span>';
            var btn;
            if (isCurrent) btn = '<button type="button" class="tm-select-btn is-current" data-close-drawer>Selected</button>';
            else if (x.reason) btn = '<button type="button" class="tm-select-btn" disabled>Incompatible</button>';
            else if (out) btn = '<button type="button" class="tm-select-btn" disabled>Out of stock</button>';
            else btn = '<button type="button" class="tm-select-btn" data-select="' + pid + '">Select</button>';
            return '<article class="tm-product-card' + (x.reason ? ' is-incompatible' : '') + (isCurrent ? ' is-current' : '') + '">' +
                img +
                '<div class="tm-product-body">' +
                (p.brand ? '<span class="tm-product-brand">' + escapeHtml(p.brand) + '</span>' : '') +
                '<h4 title="' + escapeHtml(p.name || '') + '">' +
                (pid > 0 ? '<a href="products.php?id=' + pid + '" target="_blank" rel="noopener">' + escapeHtml(p.name || '') + '</a>' : escapeHtml(p.name || '')) +
                '</h4>' +
                specChips(p, false) +
                (x.reason ? '<p class="tm-compat-warn">' + escapeHtml(x.reason) + '</p>' : '') +
                '</div>' +
                '<div class="tm-product-side">' +
                '<span class="tm-product-price">' + peso(p.price) + '</span>' +
                '<span class="tm-product-stock' + (stock > 0 && stock <= 5 ? ' is-low' : '') + '">' +
                (out ? 'Out of stock' : (stock <= 5 ? 'Only ' + stock + ' left' : 'Stock: ' + stock)) + '</span>' +
                btn +
                '</div></article>';
        }).join('');
    }

    function selectProduct(product) {
        if (!activeSlot || !product) return;
        var slot = activeSlot;
        var reason = candidateIncompatible(slot, product);
        if (reason) {
            alertUi(reason, 'error');
            return;
        }
        build[slot] = {
            id: product.id,
            name: product.name,
            price: product.price,
            image: product.image,
            category: product.category,
            description: product.description || '',
            compat: product.compat || null
        };
        activeStep = slotMeta(slot).step;
        /* Drop other parts that no longer fit after this change. */
        var cleared = [];
        Object.keys(build).forEach(function (other) {
            if (other === slot) return;
            if (candidateIncompatible(other, build[other])) {
                cleared.push(build[other].name || other);
                delete build[other];
            }
        });
        closeDrawer();
        pcxInstall(slot);
        revealSlot(slot);
        if (cleared.length) {
            alertUi('Removed parts that no longer fit after your change:\n• ' + cleared.slice(0, 5).join('\n• '), 'info');
        }
    }

    function removeSlot(slotId) {
        if (!build[slotId]) return;
        delete build[slotId];
        if (activeSlot === slotId) {
            renderPickerCurrent();
            renderProducts();
            saveDraft();
        } else {
            refresh();
        }
    }

    /* ---------- Dialogs ---------- */

    function openDialog(html, onReady, wide) {
        var existing = $('tmSaveNameOverlay');
        if (existing) existing.remove();
        var overlay = document.createElement('div');
        overlay.id = 'tmSaveNameOverlay';
        overlay.className = 'tm-save-overlay';
        overlay.innerHTML = '<div class="tm-save-dialog' + (wide ? ' is-wide' : '') + '" role="dialog" aria-modal="true" aria-labelledby="tmDialogTitle">' +
            '<button type="button" class="tm-save-x" aria-label="Close">&times;</button>' + html + '</div>';
        document.body.appendChild(overlay);
        var onKey = function (e) { if (e.key === 'Escape') close(); };
        var close = function () {
            document.removeEventListener('keydown', onKey);
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        };
        document.addEventListener('keydown', onKey);
        overlay.querySelector('.tm-save-x').onclick = close;
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.closest('[data-dialog-cancel]')) close();
        });
        if (onReady) onReady(overlay, close);
    }

    function confirmDialog(title, message, confirmLabel, onConfirm) {
        openDialog(
            '<h3 id="tmDialogTitle">' + escapeHtml(title) + '</h3>' +
            '<p class="tm-save-lead">' + escapeHtml(message) + '</p>' +
            '<div class="tm-save-actions">' +
            '<button type="button" class="tm-save-cancel" data-dialog-cancel>Cancel</button>' +
            '<button type="button" class="tm-save-confirm is-danger" data-dialog-ok>' + escapeHtml(confirmLabel) + '</button>' +
            '</div>',
            function (overlay, close) {
                var ok = overlay.querySelector('[data-dialog-ok]');
                ok.focus();
                ok.onclick = function () { close(); onConfirm(); };
            }
        );
    }

    function openSaveNameModal() {
        if (filledCount() <= 0) {
            alertUi('Select at least one component before saving.', 'info');
            return;
        }
        var issues = validateCurrentBuild();
        if (issues.length) {
            alertUi('This build has incompatible components:\n• ' + issues.slice(0, 5).join('\n• '), 'error');
            return;
        }
        var isEdit = editingBuildId > 0;
        openDialog(
            '<h3 id="tmDialogTitle">' + (isEdit ? 'Update Your Build' : 'Save Your Build') + '</h3>' +
            '<p class="tm-save-lead">Give your Build a name:</p>' +
            '<div class="tm-save-summary"><span>' + filledCount() + ' / ' + TOTAL_SLOTS + ' components</span><strong>' + peso(totalPrice()) + '</strong></div>' +
            '<input type="text" id="tmSaveNameInput" class="tm-save-input" maxlength="120" placeholder="e.g. My PC Build" autocomplete="off">' +
            '<div class="tm-save-error" id="tmSaveError" hidden>Please enter a name for your build.</div>' +
            '<div class="tm-save-actions">' +
            '<button type="button" class="tm-save-cancel" data-dialog-cancel>Cancel</button>' +
            '<button type="button" class="tm-save-confirm" id="tmSaveConfirm">' + (isEdit ? 'Update Build' : 'Save Build') + '</button>' +
            '</div>',
            function (overlay, close) {
                var input = $('tmSaveNameInput');
                var err = $('tmSaveError');
                var confirmBtn = $('tmSaveConfirm');
                if (pendingBuildName) input.value = pendingBuildName;
                input.focus();
                input.select();
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); confirmBtn.click(); }
                });
                confirmBtn.onclick = function () {
                    var name = String(input.value || '').trim();
                    if (!name) {
                        err.hidden = false;
                        input.focus();
                        return;
                    }
                    err.hidden = true;
                    persistBuild(name, close);
                };
            }
        );
    }

    function clearCurrentBuild() {
        build = {};
        activeStep = 1;
        activeSlot = null;
        editingBuildId = 0;
        pendingBuildName = '';
        try {
            sessionStorage.removeItem('ep_tm_edit_id');
            sessionStorage.removeItem('ep_tm_load_name');
        } catch (e) {}
        refresh();
    }

    function persistBuild(name, onDone) {
        var confirmBtn = $('tmSaveConfirm');
        var idleLabel = editingBuildId > 0 ? 'Update Build' : 'Save Build';
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.textContent = editingBuildId > 0 ? 'Updating…' : 'Saving…';
        }
        var payload = {
            action: 'save',
            build_name: name,
            components: build,
            csrf_token: window.EP_CSRF || ''
        };
        if (editingBuildId > 0) payload.id = editingBuildId;
        fetch('saved_builds_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
            .then(function (res) {
                var data = res.data || {};
                if (!data.ok) {
                    if (res.status === 401) alertUi('Please log in to save your build.', 'info');
                    else alertUi(data.message || 'Could not save your build.', 'error');
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = idleLabel;
                    }
                    return;
                }
                var wasUpdate = !!data.updated;
                savedCache = null;
                clearCurrentBuild();
                if (typeof onDone === 'function') onDone();
                alertUi(
                    'Find it anytime in Saved Builds or with the Load button. The builder is cleared and ready for your next PC.',
                    'success',
                    {
                        title: wasUpdate ? 'Build updated' : 'Build saved',
                        detail: name,
                        actions: [
                            { label: 'View Saved Builds', href: 'saved_builds.php' },
                            { label: 'Start New Build', primary: true }
                        ]
                    }
                );
            })
            .catch(function () {
                alertUi('Could not save your build.', 'error');
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = idleLabel;
                }
            });
    }

    /* ---------- Saved builds (Load / Compare) ---------- */

    var savedCache = null;

    function fetchSavedBuilds() {
        if (savedCache) return Promise.resolve(savedCache);
        return fetch('saved_builds_api.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) throw new Error('list_failed');
                savedCache = data.builds || [];
                return savedCache;
            });
    }

    function savedDate(raw) {
        var d = raw ? new Date(String(raw).replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z') : null;
        if (!d || isNaN(d.getTime())) return '';
        return d.toLocaleDateString('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric' });
    }

    function savedListHtml(builds, extraTop) {
        var items = (extraTop || '') + builds.map(function (b) {
            return '<button type="button" class="tm-list-item" data-build-id="' + Number(b.id) + '">' +
                '<span><strong>' + escapeHtml(b.name) + '</strong>' +
                '<small>' + Number(b.component_count || 0) + ' components' + (savedDate(b.created_at) ? ' · ' + savedDate(b.created_at) : '') + '</small></span>' +
                '<em>' + peso(b.total_price) + '</em></button>';
        }).join('');
        return items ? '<div class="tm-list">' + items + '</div>' : '<div class="tm-list-empty">You have no saved builds yet.</div>';
    }

    function openLoadDialog() {
        fetchSavedBuilds()
            .then(function (builds) {
                openDialog(
                    '<h3 id="tmDialogTitle">Load a Saved Build</h3>' +
                    '<p class="tm-save-lead">Pick a build to continue editing it. Saving will update that build.</p>' +
                    savedListHtml(builds) +
                    '<div class="tm-save-actions">' +
                    '<a class="tm-save-cancel" href="saved_builds.php"><i class="fas fa-folder-open" aria-hidden="true"></i> Manage saved builds</a>' +
                    '</div>',
                    function (overlay, close) {
                        overlay.addEventListener('click', function (e) {
                            var item = e.target.closest('[data-build-id]');
                            if (!item) return;
                            var b = builds.find(function (x) { return Number(x.id) === Number(item.getAttribute('data-build-id')); });
                            if (!b) return;
                            var doLoad = function () {
                                close();
                                editingBuildId = Number(b.id);
                                pendingBuildName = String(b.name || '');
                                var dropped = applyComponents(b.components);
                                activeStep = 1;
                                refresh();
                                noticeDropped(dropped);
                            };
                            if (filledCount() > 0) {
                                close();
                                confirmDialog('Replace current build?', 'Loading "' + b.name + '" replaces the parts currently in the builder.', 'Load build', doLoad);
                            } else {
                                doLoad();
                            }
                        });
                    }
                );
            })
            .catch(function () { alertUi('Could not load your saved builds.', 'error'); });
    }

    function renderCompareSlots() {
        var el = $('tmCompareSlots');
        if (!el) return;
        el.innerHTML = compare.map(function (c, i) {
            if (!c) {
                return '<button type="button" class="tm-compare-slot" data-compare-slot="' + i + '">' +
                    '<i class="fas fa-desktop" aria-hidden="true"></i><span>Add build</span></button>';
            }
            return '<button type="button" class="tm-compare-slot is-set" data-compare-slot="' + i + '" title="Change">' +
                '<strong>' + escapeHtml(c.name) + '</strong><em>' + peso(totalPrice(c.components)) + '</em>' +
                '<span>' + filledCount(c.components) + ' parts</span></button>';
        }).join('');
        if ($('tmCompareGo')) $('tmCompareGo').hidden = !(compare[0] && compare[1]);
    }

    function pickCompareBuild(index) {
        fetchSavedBuilds()
            .catch(function () { return []; })
            .then(function (builds) {
                var current = '<button type="button" class="tm-list-item" data-build-id="current">' +
                    '<span><strong>Current build</strong><small>' + filledCount() + ' components in the builder</small></span>' +
                    '<em>' + peso(totalPrice()) + '</em></button>';
                openDialog(
                    '<h3 id="tmDialogTitle">Choose Build ' + (index === 0 ? 'A' : 'B') + '</h3>' +
                    savedListHtml(builds, current) +
                    (compare[index] ? '<div class="tm-save-actions"><button type="button" class="tm-save-cancel" data-compare-clear>Clear this slot</button></div>' : ''),
                    function (overlay, close) {
                        overlay.addEventListener('click', function (e) {
                            if (e.target.closest('[data-compare-clear]')) {
                                compare[index] = null;
                                close();
                                renderCompareSlots();
                                return;
                            }
                            var item = e.target.closest('[data-build-id]');
                            if (!item) return;
                            var id = item.getAttribute('data-build-id');
                            if (id === 'current') {
                                compare[index] = { name: 'Current build', components: JSON.parse(JSON.stringify(build)) };
                            } else {
                                var b = builds.find(function (x) { return String(x.id) === id; });
                                if (!b) return;
                                compare[index] = { name: b.name, components: b.components || {} };
                            }
                            close();
                            renderCompareSlots();
                        });
                    }
                );
            });
    }

    function openCompareDialog() {
        var a = compare[0];
        var b = compare[1];
        if (!a || !b) return;
        function cell(item) {
            return item
                ? '<td>' + escapeHtml(item.name) + '<small>' + peso(item.price) + '</small></td>'
                : '<td class="is-empty">—</td>';
        }
        var rows = SLOTS.filter(function (s) { return a.components[s.id] || b.components[s.id]; }).map(function (s) {
            return '<tr><th>' + escapeHtml(s.name) + '</th>' + cell(a.components[s.id]) + cell(b.components[s.id]) + '</tr>';
        }).join('');
        var ta = totalPrice(a.components);
        var tb = totalPrice(b.components);
        openDialog(
            '<h3 id="tmDialogTitle">Compare Builds</h3>' +
            '<table class="tm-compare-table"><thead><tr><th></th><th>' + escapeHtml(a.name) + '</th><th>' + escapeHtml(b.name) + '</th></tr></thead>' +
            '<tbody>' + (rows || '<tr><td colspan="3" class="is-empty">Both builds are empty.</td></tr>') + '</tbody>' +
            '<tfoot>' +
            '<tr><th>Score</th><td>' + buildScore(a.components) + '/100</td><td>' + buildScore(b.components) + '/100</td></tr>' +
            '<tr><th>Total</th><td>' + peso(ta) + '</td><td>' + peso(tb) + '</td></tr>' +
            '</tfoot></table>' +
            (ta !== tb ? '<p class="tm-save-lead" style="margin-top:12px">' + escapeHtml(ta < tb ? a.name : b.name) + ' is ' + peso(Math.abs(ta - tb)) + ' cheaper.</p>' : ''),
            null,
            true
        );
    }

    /* ---------- Share / Report / Budget ---------- */

    function buildSummaryText() {
        var lines = ['My EasyPC Build (' + filledCount() + ' / ' + TOTAL_SLOTS + ' components)'];
        SLOTS.forEach(function (s) {
            if (build[s.id]) lines.push('• ' + s.name + ': ' + build[s.id].name + ' — ' + peso(build[s.id].price));
        });
        lines.push('Total: ' + peso(totalPrice()));
        lines.push('Estimated power draw: ~' + powerDraw() + 'W');
        return lines.join('\n');
    }

    function shareBuild() {
        if (filledCount() <= 0) {
            alertUi('Add at least one component before sharing.', 'info');
            return;
        }
        var text = buildSummaryText();
        if (navigator.share) {
            navigator.share({ title: 'My EasyPC Build', text: text }).catch(function () {});
            return;
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text)
                .then(function () { alertUi('Build summary copied to clipboard. Paste it anywhere to share.', 'success'); })
                .catch(function () { showShareText(text); });
            return;
        }
        showShareText(text);
    }

    function showShareText(text) {
        openDialog(
            '<h3 id="tmDialogTitle">Share Your Build</h3>' +
            '<p class="tm-save-lead">Copy this summary and paste it anywhere.</p>' +
            '<textarea class="tm-save-input" rows="9" readonly id="tmShareText">' + escapeHtml(text) + '</textarea>' +
            '<div class="tm-save-actions"><button type="button" class="tm-save-confirm" id="tmShareCopy">Copy</button></div>',
            function (overlay, close) {
                var ta = $('tmShareText');
                ta.select();
                $('tmShareCopy').onclick = function () {
                    ta.select();
                    try { document.execCommand('copy'); } catch (e) {}
                    close();
                    alertUi('Build summary copied.', 'success');
                };
            }
        );
    }

    function reportBuild() {
        if (filledCount() <= 0) {
            alertUi('Add at least one component to generate a build report.', 'info');
            return;
        }
        var issues = validateCurrentBuild();
        var missing = missingNames();
        var rows = SLOTS.filter(function (s) { return build[s.id]; }).map(function (s) {
            return '<tr><td>' + escapeHtml(s.name) + '</td><td>' + escapeHtml(build[s.id].name) + '</td><td class="r">' + peso(build[s.id].price) + '</td></tr>';
        }).join('');
        var now = new Date().toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' });
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>EasyPC Build Report</title>' +
            '<style>body{font-family:Arial,sans-serif;color:#1f2328;margin:32px;}h1{font-size:20px;margin:0 0 4px;}' +
            'p{margin:4px 0;font-size:13px;color:#4b5563;}table{width:100%;border-collapse:collapse;margin:18px 0;font-size:13px;}' +
            'th,td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left;}th{background:#f7f8fa;}.r{text-align:right;white-space:nowrap;}' +
            'tfoot td{font-weight:700;font-size:14px;}.ok{color:#3d7422;}.bad{color:#b91c1c;}small{color:#8a93a0;}</style></head><body>' +
            '<h1>EasyPC Build Report' + (pendingBuildName ? ': ' + escapeHtml(pendingBuildName) : '') + '</h1>' +
            '<p>Generated ' + escapeHtml(now) + ' · EasyPC One Oasis</p>' +
            '<table><thead><tr><th>Component</th><th>Product</th><th class="r">Price</th></tr></thead><tbody>' + rows + '</tbody>' +
            '<tfoot><tr><td colspan="2">Total</td><td class="r">' + peso(totalPrice()) + '</td></tr></tfoot></table>' +
            '<p>Build score: <strong>' + buildScore() + '/100</strong> · Components: ' + filledCount() + ' / ' + TOTAL_SLOTS +
            ' · Estimated power draw: ~' + powerDraw() + 'W</p>' +
            '<p class="' + (issues.length ? 'bad' : 'ok') + '">' + (issues.length ? 'Compatibility issues: ' + escapeHtml(issues.join(' | ')) : 'All selected parts are compatible.') + '</p>' +
            (missing.length ? '<p>Still needed: ' + escapeHtml(missing.join(', ')) + '</p>' : '') +
            '<p><small>Compatibility checks are for guidance only and may not cover all scenarios. Please verify component specifications before purchasing. Prices may change.</small></p>' +
            '<script>window.onload=function(){window.print();};<\/script></body></html>';
        var w = window.open('', '_blank');
        if (!w) {
            alertUi('Please allow pop-ups to open the build report.', 'info');
            return;
        }
        w.document.open();
        w.document.write(html);
        w.document.close();
    }

    function editBudget() {
        openDialog(
            '<h3 id="tmDialogTitle">Set Your Budget</h3>' +
            '<p class="tm-save-lead">The budget bar shows how much of it your build uses.</p>' +
            '<input type="number" id="tmBudgetInput" class="tm-save-input" min="1000" step="1000" value="' + budget + '">' +
            '<div class="tm-save-error" id="tmBudgetError" hidden>Enter an amount of at least ₱1,000.</div>' +
            '<div class="tm-save-actions">' +
            '<button type="button" class="tm-save-cancel" data-dialog-cancel>Cancel</button>' +
            '<button type="button" class="tm-save-confirm" id="tmBudgetSave">Save Budget</button>' +
            '</div>',
            function (overlay, close) {
                var input = $('tmBudgetInput');
                input.focus();
                input.select();
                var save = function () {
                    var v = Math.round(Number(input.value));
                    if (!(v >= 1000)) {
                        $('tmBudgetError').hidden = false;
                        return;
                    }
                    budget = v;
                    close();
                    refresh();
                };
                $('tmBudgetSave').onclick = save;
                input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });
            }
        );
    }

    /* ---------- Loading saved builds (from Saved Builds page) ---------- */

    /* Returns the names of saved items whose slot no longer exists (e.g. the removed Extras slot). */
    function applyComponents(components) {
        build = {};
        var dropped = [];
        if (!components || typeof components !== 'object') return dropped;
        Object.keys(components).forEach(function (k) {
            var item = components[k];
            if (!item || typeof item !== 'object' || !item.id) return;
            if (!slotMeta(k)) {
                dropped.push(String(item.name || k));
                return;
            }
            build[k] = {
                id: item.id,
                name: item.name,
                price: item.price,
                image: item.image || '',
                category: item.category || '',
                description: item.description || '',
                compat: item.compat || null
            };
        });
        return dropped;
    }

    function noticeDropped(dropped) {
        if (dropped && dropped.length) {
            alertUi('Extras are no longer part of Build a PC, so this was left out of the build:\n• ' + dropped.join('\n• ') +
                '\n\nSaving this build will remove it from the saved copy.', 'info');
        }
    }

    function applyLoadedBuild(components) {
        var dropped = applyComponents(components);
        refresh();
        noticeDropped(dropped);
    }

    function loadPendingBuild() {
        try {
            var raw = sessionStorage.getItem('ep_tm_load_build');
            if (!raw) return false;
            var comps = JSON.parse(raw);
            sessionStorage.removeItem('ep_tm_load_build');
            var name = sessionStorage.getItem('ep_tm_load_name') || '';
            sessionStorage.removeItem('ep_tm_load_name');
            var editId = parseInt(sessionStorage.getItem('ep_tm_edit_id') || '0', 10) || 0;
            sessionStorage.removeItem('ep_tm_edit_id');
            editingBuildId = editId > 0 ? editId : 0;
            pendingBuildName = name;
            applyLoadedBuild(comps);
            return true;
        } catch (e) {
            return false;
        }
    }

    /* ---------- Events ---------- */

    function bind() {
        var root = $('ep-tech-match');
        if (root) {
            root.addEventListener('click', function (e) {
                var remove = e.target.closest('[data-remove]');
                if (remove) {
                    removeSlot(remove.getAttribute('data-remove'));
                    return;
                }
                var pick = e.target.closest('[data-pick]');
                if (pick) {
                    openDrawer(pick.getAttribute('data-pick'));
                    return;
                }
                var cmp = e.target.closest('[data-compare-slot]');
                if (cmp) pickCompareBuild(Number(cmp.getAttribute('data-compare-slot')));
            });
            root.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var slot = e.target.closest('.tm-slot');
                if (!slot || e.target.closest('button')) return;
                e.preventDefault();
                openDrawer(slot.getAttribute('data-pick'));
            });
        }

        var steps = $('tmSteps');
        if (steps) {
            steps.addEventListener('click', function (e) {
                var step = e.target.closest('.tm-step');
                if (!step) return;
                activeStep = parseInt(step.getAttribute('data-step'), 10) || 1;
                renderSteps();
                var first = SLOTS.find(function (s) { return s.step === activeStep && !build[s.id]; }) ||
                    SLOTS.find(function (s) { return s.step === activeStep; });
                if (first) revealSlot(first.id);
            });
        }

        var drawer = $('tmPickerView');
        if (drawer) {
            drawer.addEventListener('click', function (e) {
                var remove = e.target.closest('[data-remove]');
                if (remove) {
                    removeSlot(remove.getAttribute('data-remove'));
                    return;
                }
                var sel = e.target.closest('[data-select]');
                if (sel && !sel.disabled) {
                    var id = Number(sel.getAttribute('data-select'));
                    selectProduct(pickerProducts.find(function (p) { return Number(p.id) === id; }));
                    return;
                }
                var brand = e.target.closest('[data-brand]');
                if (brand) {
                    var b = brand.getAttribute('data-brand');
                    brandFilter[b] = !brandFilter[b];
                    brand.classList.toggle('is-on', brandFilter[b]);
                    renderProducts();
                    return;
                }
                if (e.target.closest('[data-show-all]')) {
                    if ($('tmPickerCompatOnly')) $('tmPickerCompatOnly').checked = false;
                    renderProducts();
                    return;
                }
                if (e.target.closest('[data-close-drawer]')) closeDrawer(activeSlot);
            });
        }
        if ($('tmPickerBack')) $('tmPickerBack').addEventListener('click', function () { closeDrawer(activeSlot); });
        if ($('tmDrawerBackdrop')) $('tmDrawerBackdrop').addEventListener('click', function () { closeDrawer(activeSlot); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && activeSlot && !$('tmSaveNameOverlay')) closeDrawer(activeSlot);
        });
        if ($('tmFiltersBtn')) {
            $('tmFiltersBtn').addEventListener('click', function () {
                setFiltersOpen($('tmFilters').hidden);
            });
        }
        ['tmPickerSearch'].forEach(function (id) { if ($(id)) $(id).addEventListener('input', renderProducts); });
        ['tmPickerSort', 'tmPickerInStock', 'tmPickerCompatOnly'].forEach(function (id) {
            if ($(id)) $(id).addEventListener('change', renderProducts);
        });

        if ($('tmAddMissing')) {
            $('tmAddMissing').addEventListener('click', function () {
                var next = nextSlot();
                if (next) openDrawer(next.id);
                else alertUi('Every component slot is filled.', 'info');
            });
        }
        [$('tmSaveBuild'), $('tmMobileSave')].forEach(function (btn) {
            if (btn) btn.addEventListener('click', openSaveNameModal);
        });
        if ($('tmShareBuild')) $('tmShareBuild').addEventListener('click', shareBuild);
        if ($('tmLoadBuild')) $('tmLoadBuild').addEventListener('click', openLoadDialog);
        if ($('tmReportBuild')) $('tmReportBuild').addEventListener('click', reportBuild);
        if ($('tmBudgetEdit')) $('tmBudgetEdit').addEventListener('click', editBudget);
        if ($('tmCompareGo')) $('tmCompareGo').addEventListener('click', openCompareDialog);
        if ($('tmClearBuild')) {
            $('tmClearBuild').addEventListener('click', function () {
                if (filledCount() === 0 && !editingBuildId) return;
                confirmDialog('Clear this build?', 'This removes every part from the builder. Saved builds are not affected.', 'Clear', clearCurrentBuild);
            });
        }
        if ($('tmEditCancel')) {
            $('tmEditCancel').addEventListener('click', function () {
                confirmDialog('Stop editing?', 'Unsaved changes to "' + (pendingBuildName || 'this build') + '" will be discarded. The saved version stays as it is.', 'Start new build', clearCurrentBuild);
            });
        }
    }

    function init() {
        if (!$('ep-tech-match')) return;
        loadDraft();
        bind();
        pcxBind();
        if (!loadPendingBuild()) refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.epTechMatchRefresh = refresh;
    window.epTechMatchLoadPending = loadPendingBuild;
    window.epTechMatchApplyBuild = applyLoadedBuild;
})();
