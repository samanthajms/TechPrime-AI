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
        { id: 'case_fan', group: 'CHASSIS & COOLING', label: 'CASE FAN', name: 'Case Fan', select: 'Select Case Fan', icon: 'fa-wind', step: 7, watts: 5, optional: true },
        /* Add-ons (tech_match_api.php, pc_recommend_addon_map()): not part of the PC itself, so no compatibility
           check, no Build Score and not in the component count, but in the total, the cart and saved builds. */
        { id: 'monitor', group: 'ADD-ONS', label: 'MONITOR', name: 'Monitor', select: 'Select Monitor', icon: 'fa-desktop', step: 8, watts: 0, optional: true, addon: true },
        { id: 'keyboard', group: 'ADD-ONS', label: 'KEYBOARD', name: 'Keyboard', select: 'Select Keyboard (or keyboard + mouse set)', icon: 'fa-keyboard', step: 8, watts: 0, optional: true, addon: true },
        { id: 'mouse', group: 'ADD-ONS', label: 'MOUSE', name: 'Mouse', select: 'Select Mouse', icon: 'fa-mouse', step: 8, watts: 0, optional: true, addon: true },
        { id: 'os', group: 'ADD-ONS', label: 'WINDOWS', name: 'Windows', select: 'Select Windows', icon: 'fa-compact-disc', step: 8, watts: 0, optional: true, addon: true },
        { id: 'ups', group: 'ADD-ONS', label: 'UPS / AVR', name: 'UPS / AVR', select: 'Select UPS / AVR', icon: 'fa-car-battery', step: 8, watts: 0, optional: true, addon: true }
    ];
    /* The PC itself: what the component count, "Add Missing Components" and the 3D case cover. */
    var PARTS = SLOTS.filter(function (s) { return !s.addon; });
    var TOTAL_SLOTS = PARTS.length;
    /* Build Summary line order (matches the EasyPC website summary), add-ons last. */
    var PSU_SAFE_LOAD = 0.8; // keep the build under 80% of the PSU's rated wattage
    var SUMMARY_ORDER =['processor', 'cooler', 'motherboard', 'memory', 'gpu', 'ssd', 'ssd_sata', 'hdd', 'psu', 'case', 'case_fan',
        'monitor', 'keyboard', 'mouse', 'os', 'ups'];
    /* Stock at or below this shows "Only N left" (same as the picker). */
    var LOW_STOCK = 5;
    /* EasyPC One Oasis contact (same as the site footer), for "Ask EasyPC about this build". */
    var STORE = { phone: '+639176255078', phoneLabel: '0917 625 5078', messenger: 'https://m.me/EASYPCOneOasisPasigBranch', hours: 'Open daily, 10:00 AM to 7:00 PM' };

    var STEPS = [
        { id: 1, label: 'Core' },
        { id: 2, label: 'Memory' },
        { id: 3, label: 'Graphics' },
        { id: 4, label: 'Storage' },
        { id: 5, label: 'Power' },
        { id: 6, label: 'Case' },
        { id: 7, label: 'Cooling' },
        { id: 8, label: 'Add-ons' }
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
    /* False until the shopper picks a budget; the button then reads "Change budget". */
    var budgetSet = false;
    /* Which suggested budget is in use: 'starter' / 'recommended' / 'high', or '' for an amount the shopper
       typed. A suggested budget follows the purpose; a typed one is kept and only gets a hint. */
    var budgetTier = '';
    /* Last automatic budget change (purpose switch), for the Undo link: { prev }. Not saved. */
    var budgetNotice = null;
    var BUDGET_TIERS = { starter: 'Starter', recommended: 'Recommended', high: 'High-end' };
    /* Suggested budgets from tech_match_api.php (action=budget_guide), fetched once. */
    var budgetGuide = null;
    /* The same suggestions once loaded, for synchronous use (budget bar, purpose switch). */
    var budgetGuideData = null;
    /* Ready-made starter builds (tech_match_api.php action=starter_builds), loaded once. */
    var starters = null;
    /* Add-ons group in the slot list: null = open only when an add-on is picked; true/false once the shopper toggles it. */
    var addonsOpen = null;
    /* Build purpose chip (keys from includes/pc_purpose.php); steers the recommendations. */
    var purpose = 'balanced';
    /* Last build check from tech_match_api.php (action=review); rating = Build Score + Performance Balance (pc_rating.php).
       parts = today's price / stock per slot, soldOut = picked parts out of stock (with an in-stock alternative),
       checkedAt = when those prices were read. */
    var review = { key: '', gpuNeeded: false, warnings: [], notes: [], rating: null, pending: false, parts: {}, soldOut: [], checkedAt: null };
    /* slot -> { id, old } for parts whose saved price was replaced by today's price. */
    var priceChanges = {};
    /* Shown on the Build Summary, the printed summary and the shared text. */
    var DISCLAIMER = 'Prices are VAT-inclusive and may change without notice; stock is not reserved until you check out. ' +
        'Compatibility checks and the Build Score are advisory, based on the product specifications we list. ' +
        'Please confirm with EasyPC staff before buying.';
    var reviewTimer = null;
    var lastPower = null;
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
                budget: budget,
                budgetSet: budgetSet,
                budgetTier: budgetTier,
                purpose: purpose
            }));
        } catch (e) {}
    }

    function loadDraft() {
        try {
            localStorage.removeItem(LEGACY_DRAFT_KEY);
            var raw = localStorage.getItem(draftKey());
            /* A build started as a guest (user 0) carries over once the shopper logs in. */
            var guestKey = LEGACY_DRAFT_KEY + '_0';
            if (!raw && Number(window.EP_TM_USER) > 0) {
                raw = localStorage.getItem(guestKey);
                if (raw) localStorage.removeItem(guestKey);
            }
            if (!raw) return;
            var d = JSON.parse(raw);
            if (!d || typeof d !== 'object') return;
            editingBuildId = Number(d.editId) > 0 ? Number(d.editId) : 0;
            pendingBuildName = String(d.name || '');
            if (Number(d.budget) > 0) budget = Number(d.budget);
            budgetSet = !!d.budgetSet || (Number(d.budget) > 0 && Number(d.budget) !== DEFAULT_BUDGET);
            budgetTier = budgetSet && BUDGET_TIERS[d.budgetTier] ? d.budgetTier : '';
            applyPurpose(d.purpose);
            applyComponents(d.components);
        } catch (e) {
            build = {};
        }
    }

    /* ---------- Derived values ---------- */

    /* PC parts picked (add-ons are counted separately). */
    function filledCount(comps) {
        comps = comps || build;
        return PARTS.reduce(function (n, s) { return n + (comps[s.id] ? 1 : 0); }, 0);
    }

    function addonCount(comps) {
        comps = comps || build;
        return SLOTS.reduce(function (n, s) { return n + (s.addon && comps[s.id] ? 1 : 0); }, 0);
    }

    /* What the customer pays: every part and add-on except the ones they already have ("I have this"). */
    function totalPrice(comps) {
        comps = comps || build;
        return SLOTS.reduce(function (sum, s) {
            var it = comps[s.id];
            return sum + (it && !it.owned ? Number(it.price || 0) : 0);
        }, 0);
    }

    function addonTotal() {
        return SLOTS.reduce(function (sum, s) {
            return sum + (s.addon && build[s.id] ? Number(build[s.id].price || 0) : 0);
        }, 0);
    }

    /* Parts and add-ons the customer would buy (not the ones they already have). */
    function buyableSlots() {
        return SLOTS.filter(function (s) { return build[s.id] && !build[s.id].owned; });
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

    /* A graphics card is required for Gaming / Content Creation, or when the CPU has no built-in graphics. */
    function gpuNeeded() {
        return purpose === 'gaming' || purpose === 'creation' || review.gpuNeeded;
    }

    /* Required parts still missing; any one drive covers storage. */
    function missingEssentials() {
        var out = [];
        ['processor', 'motherboard', 'memory'].forEach(function (id) { if (!build[id]) out.push(slotMeta(id)); });
        if (!hasStorage()) out.push(slotMeta('ssd'));
        if (!build.gpu && gpuNeeded()) out.push(slotMeta('gpu'));
        ['psu', 'case'].forEach(function (id) { if (!build[id]) out.push(slotMeta(id)); });
        return out;
    }

    function missingNames() {
        return missingEssentials().map(function (m) { return m.id === 'ssd' ? 'Storage' : m.name; });
    }

    /* Build Score of the current build for the chosen purpose, rated on the server (pc_rating.php). */
    function buildScore() {
        return review.rating ? Number(review.rating.score) || 0 : 0;
    }

    /* Rates any build for a purpose with the same server check (used by Compare). */
    function fetchRating(components, forPurpose) {
        var parts = {};
        SLOTS.forEach(function (s) {
            var it = components && components[s.id];
            if (it && typeof it === 'object') {
                parts[s.id] = { id: it.id, name: it.name, price: it.price, category: it.category || '', description: it.description || '' };
            }
        });
        if (!Object.keys(parts).length) return Promise.resolve(null);
        return fetch('tech_match_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'review', csrf_token: window.EP_CSRF || '', build: parts, purpose: forPurpose })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { return data && data.ok ? data.rating || null : null; });
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
        return PARTS.find(function (s) { return !build[s.id]; }) || null;
    }

    function stepDone(stepId) {
        var related = SLOTS.filter(function (s) { return s.step === stepId; });
        if (stepId === 4) return hasStorage();
        if (stepId === 8) return addonCount() > 0;
        /* Graphics: done once a card is picked, or once the processor is in and the purpose does not need one. */
        if (stepId === 3) return !!build.gpu || (!!build.processor && !gpuNeeded());
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
        var addonsShown = addonsOpen === null ? addonCount() > 0 : addonsOpen;
        SLOTS.forEach(function (s) {
            if (s.group !== lastGroup) {
                if (s.addon) {
                    /* Add-ons are optional, so the group stays folded until it is opened or one is picked. */
                    var added = addonCount();
                    html += '<button type="button" class="tm-addons-toggle" data-addons-toggle aria-expanded="' + (addonsShown ? 'true' : 'false') + '" aria-controls="tmAddonList">' +
                        '<span class="tm-section-label">' + escapeHtml(s.group) + ' <em class="tm-tag">Optional</em></span>' +
                        '<span class="tm-addons-sub">' + (added ? added + ' added · ' + peso(addonTotal()) : 'Monitor, keyboard, mouse, Windows, UPS') + '</span>' +
                        '<i class="fas fa-chevron-down" aria-hidden="true"></i></button>' +
                        '<div class="tm-addon-list" id="tmAddonList"' + (addonsShown ? '' : ' hidden') + '>' +
                        '<p class="tm-section-note">Not part of the PC itself, but a first PC also needs a screen, a keyboard and mouse, and Windows.</p>';
                } else {
                    html += '<div class="tm-section-label">' + escapeHtml(s.group) + '</div>';
                }
                lastGroup = s.group;
            }
            var item = build[s.id];
            var out = isSoldOut(s.id);
            var stock = partStock(s.id);
            var low = item && !out && !item.owned && stock !== null && stock > 0 && stock <= LOW_STOCK;
            var cls = 'tm-slot' + (item ? ' is-filled' : '') + (!item && next && next.id === s.id ? ' is-next' : '') +
                (out ? ' is-soldout' : '') + (item && item.owned ? ' is-owned' : '');
            var icon = item && item.image
                ? '<img src="' + escapeHtml(item.image) + '" alt="">'
                : '<i class="fas ' + s.icon + '" aria-hidden="true"></i>';
            html += '<div class="' + cls + '" id="tm-slot-' + s.id + '" data-pick="' + s.id + '" role="button" tabindex="0">' +
                '<span class="tm-slot-icon">' + icon + '</span>' +
                '<span class="tm-slot-text">' +
                '<span class="tm-slot-name">' + escapeHtml(s.label) + (!s.addon && (s.optional || (s.id === 'gpu' && !gpuNeeded())) && !item ? '<em class="tm-tag">Optional</em>' : '') +
                (item && item.auto ? '<em class="tm-tag-auto-icon" title="Picked by Build it for me"><i class="fas fa-magic" aria-hidden="true"></i><span class="sf-sr">Picked by Build it for me</span></em>' : '') +
                (out ? '<em class="tm-tag tm-tag-out"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Out of stock</em>' : '') +
                (low ? '<em class="tm-tag tm-tag-low">Only ' + stock + ' left</em>' : '') +
                (item && item.owned ? '<em class="tm-tag tm-tag-owned"><i class="fas fa-check" aria-hidden="true"></i> You have this</em>' : '') + '</span>' +
                (item
                    ? '<span class="tm-slot-hint" title="' + escapeHtml(item.name) + '">' + escapeHtml(item.name) + '</span>' +
                      (item.owned
                          ? '<span class="tm-slot-price is-owned"><s>' + peso(item.price) + '</s> Not in your total</span>'
                          : '<span class="tm-slot-price">' + peso(item.price) +
                            (item.warranty ? '<span class="tm-slot-wty"><i class="fas fa-shield-alt" aria-hidden="true"></i> ' + escapeHtml(item.warranty) + '</span>' : '') + '</span>') +
                      (out ? '<span class="tm-slot-warn">Can\'t be bought right now. Keep it to plan your build, then swap it before checkout.</span>' : '') +
                      '<span class="tm-slot-actions">' +
                      '<button type="button" class="tm-change" data-pick="' + s.id + '">Change</button>' +
                      '<button type="button" class="tm-remove" data-remove="' + s.id + '">Remove</button>' +
                      /* "I already have this": stays in the checks, leaves the total and the cart. Add-ons can just be removed. */
                      (s.addon ? '' : '<button type="button" class="tm-own" data-own="' + s.id + '" aria-pressed="' + (item.owned ? 'true' : 'false') + '">' +
                          (item.owned ? 'I need to buy this' : 'I already have this') + '</button>') +
                      '</span>'
                    : '<span class="tm-slot-hint">+ ' + escapeHtml(s.select) + '</span>') +
                '</span></div>';
        });
        if (lastGroup === 'ADD-ONS') html += '</div>';
        el.innerHTML = html;
    }

    function renderCenter() {
        var count = filledCount();
        pcxSync();
        if ($('tmComponentCount')) $('tmComponentCount').innerHTML = '<b>' + count + '</b> / ' + TOTAL_SLOTS + ' components';
        if ($('tmComponentBar')) $('tmComponentBar').style.width = Math.round((count / TOTAL_SLOTS) * 100) + '%';

        /* Power meter: the scale is the selected PSU's wattage, with a marker at the
           80% safe-load point (520W on a 650W unit), as on the EasyPC website builder. */
        var watts = powerDraw();
        var psu = tagsFor('psu');
        var capacity = psu && psu.watts ? psu.watts : 0;
        var safeLoad = Math.round(capacity * PSU_SAFE_LOAD);
        var recommended = watts > 0 ? Math.max(450, Math.ceil((watts * 1.5) / 50) * 50) : 0;
        var hint = $('tmPowerHint');
        var bar = $('tmPowerBar');
        var track = $('tmPowerTrack');
        var pct = Math.round((watts / (capacity || 650)) * 100);
        var level = !capacity ? '' : (watts > capacity ? 'over' : (watts > safeLoad ? 'high' : 'ok'));
        if ($('tmPowerDraw')) $('tmPowerDraw').textContent = '~' + watts + 'W' + (capacity ? ' / ' + capacity + 'W' : '');
        if (hint) {
            if (level === 'over') hint.textContent = 'Exceeds your ' + capacity + 'W power supply. Choose at least ' + recommended + 'W.';
            else if (level === 'high') hint.textContent = 'Above the ' + Math.round(PSU_SAFE_LOAD * 100) + '% safe load (' + safeLoad + 'W). A ' + recommended + 'W or higher power supply is recommended.';
            else if (level === 'ok') hint.textContent = 'About ' + pct + '% of your ' + capacity + 'W power supply — within the safe load.';
            else if (psu) hint.textContent = 'The wattage of this power supply is not listed. Recommended: ' + recommended + 'W or higher.';
            else hint.textContent = recommended ? ('Recommended power supply: ' + recommended + 'W or higher.') : '';
        }
        if (bar) {
            bar.style.width = Math.min(100, pct) + '%';
            bar.parentNode.classList.toggle('is-high', level === 'high' || level === 'over');
        }
        if (track) {
            track.setAttribute('aria-valuemax', String(capacity || 650));
            track.setAttribute('aria-valuenow', String(Math.min(watts, capacity || 650)));
            track.setAttribute('aria-valuetext', watts + ' watts' + (capacity ? ' of ' + capacity + ' watts' : ''));
        }
        if ($('tmPowerMark')) $('tmPowerMark').hidden = !capacity;
        if ($('tmPowerScale')) $('tmPowerScale').hidden = !capacity;
        if (capacity) {
            if ($('tmPowerScaleMark')) $('tmPowerScaleMark').textContent = safeLoad + 'W (' + Math.round(PSU_SAFE_LOAD * 100) + '%)';
            if ($('tmPowerScaleMax')) $('tmPowerScaleMax').textContent = capacity + 'W';
        }
        return { pct: pct, psu: psu };
    }

    /* One line per part (component, product, price, stock / price flags); each line reopens the picker for that slot. */
    function renderSummaryList() {
        var el = $('tmSummaryList');
        if (!el) return;
        var html = '';
        var addonHead = false;
        SUMMARY_ORDER.forEach(function (id) {
            var s = slotMeta(id);
            var item = build[id];
            if (!s || !item) return;
            if (s.addon && !addonHead) {
                html += '<p class="tm-summary-sub">Add-ons</p>';
                addonHead = true;
            }
            var change = item.owned ? null : priceChange(id);
            var out = isSoldOut(id);
            var flags = (out ? '<span class="tm-summary-flag is-out"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Out of stock: can\'t be bought now</span>' : '') +
                (item.owned ? '<span class="tm-summary-flag is-owned"><i class="fas fa-check" aria-hidden="true"></i> You already have this</span>' : '') +
                (change ? '<span class="tm-summary-flag is-price"><i class="fas fa-tag" aria-hidden="true"></i> Price updated (was ' + peso(change.old) + ')</span>' : '');
            html += '<button type="button" class="tm-summary-line' + (out ? ' is-out' : '') + (item.owned ? ' is-owned' : '') + '" data-pick="' + s.id + '" title="' + escapeHtml(item.name) + ' (click to change)">' +
                '<span class="tm-summary-label">' + escapeHtml(s.name) + '</span>' +
                '<strong class="tm-summary-price">' + (item.owned ? '₱0' : peso(item.price)) + '</strong>' +
                (flags ? '<span class="tm-summary-flags">' + flags + '</span>' : '') +
                '</button>';
        });
        el.innerHTML = html || '<p class="tm-summary-empty">No components selected yet.</p>';
        var asOf = $('tmSummaryAsOf');
        if (asOf) {
            var when = filledCount() + addonCount() ? asOfLabel() : '';
            asOf.hidden = !when;
            asOf.textContent = when ? 'Prices as of ' + when + ' (Manila time)' : '';
        }
    }

    function formatPoints(n) {
        var v = Math.round(Number(n || 0) * 10) / 10;
        return String(v % 1 === 0 ? v.toFixed(0) : v.toFixed(1));
    }

    /* "How this score is computed": each part's points (weight x fit for the purpose), then deductions and caps (pc_rating.php). */
    function renderScoreWhy(rating) {
        var box = $('tmScoreWhy');
        var body = $('tmScoreWhyBody');
        if (!box || !body) return;
        if (!rating || !rating.axes || !filledCount()) {
            box.hidden = true;
            return;
        }
        box.hidden = false;
        var need = purpose === 'balanced' ? 'everyday use' : purposeLabel();
        var rows = rating.axes.map(function (a) {
            return '<li><span class="tm-why-axis">' + escapeHtml(a.label) + '</span>' +
                '<span class="tm-why-text">' + escapeHtml(a.text) + '</span>' +
                '<strong class="tm-why-pts">' + formatPoints(a.points) + '<small>/' + Number(a.weight) + '</small></strong></li>';
        }).join('');
        var adjust = (rating.adjustments || []).map(function (x) {
            return '<li class="is-adjust"><span class="tm-why-axis">' + (x.cap ? 'Limit' : 'Minus') + '</span>' +
                '<span class="tm-why-text">' + escapeHtml(x.text) + '</span>' +
                '<strong class="tm-why-pts">' + (x.cap ? 'max ' + Number(x.cap) : formatPoints(x.points)) + '</strong></li>';
        }).join('');
        body.innerHTML = '<p class="tm-why-lead">Each part earns points for how well it meets what <strong>' + escapeHtml(need) +
            '</strong> needs. How many points a part can earn depends on the purpose.</p>' +
            '<ul class="tm-why-list">' + rows + '</ul>' +
            (adjust ? '<ul class="tm-why-list">' + adjust + '</ul>' : '') +
            '<p class="tm-why-total"><span>Parts ' + formatPoints(rating.base) + (adjust ? ', after deductions and limits' : '') + '</span>' +
            '<strong>' + Number(rating.score) + '/100</strong></p>';
    }

    /* Icons for the ready-made builds' "can run" line (pc_rating.php pc_rating_can_run, via starter_builds). */
    var CAN_RUN_ICON = { great: 'fa-check-circle', good: 'fa-check', limited: 'fa-exclamation-circle', no: 'fa-times-circle' };

    function renderRight(power) {
        var count = filledCount();
        var total = totalPrice();
        var score = buildScore();
        var issues = validateCurrentBuild();
        var rating = count ? review.rating : null;
        if ($('tmBuildScore')) $('tmBuildScore').textContent = String(count ? score : 0);
        var scoreCard = document.querySelector('.tm-score-card');
        if (scoreCard) scoreCard.classList.toggle('is-pending', !!(count && review.pending));
        if ($('tmScoreVerdict')) {
            $('tmScoreVerdict').textContent = rating ? rating.verdict : 'Rated for ' + (purpose === 'balanced' ? 'everyday use' : purposeLabel()) + ' as you add parts';
        }
        var scoreNotes = $('tmScoreNotes');
        if (scoreNotes) {
            var noteList = rating && rating.notes ? rating.notes.slice(0, 2) : [];
            scoreNotes.hidden = !noteList.length;
            scoreNotes.innerHTML = noteList.map(function (n) { return '<li>' + escapeHtml(n) + '</li>'; }).join('');
        }
        renderScoreWhy(rating);
        if ($('tmSummaryCount')) $('tmSummaryCount').textContent = count + ' / ' + TOTAL_SLOTS;
        if ($('tmSummaryBar')) $('tmSummaryBar').style.width = Math.round((count / TOTAL_SLOTS) * 100) + '%';
        if ($('tmSummaryTotal')) $('tmSummaryTotal').textContent = peso(total);
        renderSummaryList();
        if ($('tmMobileTotal')) $('tmMobileTotal').textContent = peso(total);
        if ($('tmMobileCount')) $('tmMobileCount').textContent = count + ' / ' + TOTAL_SLOTS + ' components';

        /* Score badges: score / compatibility / power */
        setBadge('tmScoreBadgeScore', rating ? (score >= 70 ? 'good' : (score < 50 && rating.complete ? 'bad' : '')) : '',
            rating ? ('Build score: ' + score + '/100 · ' + rating.verdict) : 'Build score: no parts yet');
        setBadge('tmScoreBadgeCompat', issues.length ? 'bad' : (count ? 'good' : ''),
            issues.length ? (issues.length + ' compatibility issue(s)') : (count ? 'All selected parts are compatible' : 'Compatibility: no parts yet'));
        var p = power || {};
        setBadge('tmScoreBadgePower', p.psu && p.psu.watts ? (p.pct > 80 ? 'bad' : 'good') : '',
            p.psu && p.psu.watts ? ('Power supply load: ' + p.pct + '%') : 'Power: no power supply selected');
        if ($('tmAddMissing')) $('tmAddMissing').hidden = !nextSlot();

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
            /* Build check for the chosen purpose: no-display warning, then parts that are not a good fit. */
            review.warnings.forEach(function (msg) {
                html += '<div class="tm-compat-line is-warn"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span>' + escapeHtml(msg) + '</span></div>';
            });
            /* Sold-out parts stay in the build (marked red), with the in-stock part the model suggests instead. */
            review.soldOut.forEach(function (s) {
                if (!isSoldOut(s.slot)) return;
                var meta = slotMeta(s.slot);
                var alt = s.alternative;
                html += '<div class="tm-compat-line is-out"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span><strong>' +
                    escapeHtml(meta ? meta.name : s.slot) + ':</strong> out of stock. ' +
                    (alt ? 'In stock instead: ' + escapeHtml(alt.name) + ' (' + peso(alt.price) + '). ' : 'No in-stock part fits this build right now. ') +
                    '<button type="button" class="tm-inline-link" data-pick="' + escapeHtml(s.slot) + '">See options</button></span></div>';
            });
            review.notes.slice(0, 3).forEach(function (n) {
                var meta = slotMeta(n.slot);
                html += '<div class="tm-compat-line is-tip"><i class="fas fa-lightbulb" aria-hidden="true"></i><span><strong>' +
                    escapeHtml(meta ? meta.name : n.slot) + ':</strong> ' + escapeHtml(n.text) + '</span></div>';
            });
            /* Sold-out add-ons have no model alternative: just say so. */
            SLOTS.forEach(function (s) {
                if (!s.addon || !isSoldOut(s.id)) return;
                html += '<div class="tm-compat-line is-out"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span><strong>' +
                    escapeHtml(s.name) + ':</strong> out of stock. <button type="button" class="tm-inline-link" data-pick="' + s.id + '">See options</button></span></div>';
            });
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
        var owned = PARTS.filter(function (s) { return build[s.id] && build[s.id].owned; }).length;
        if ($('tmSummaryOwned')) {
            $('tmSummaryOwned').hidden = !owned;
            $('tmSummaryOwned').textContent = owned ? 'Not counting ' + owned + ' part' + (owned === 1 ? '' : 's') + ' you already have.' : '';
        }
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
        if ($('tmBudgetAmount')) $('tmBudgetAmount').textContent = budgetSet ? ' / ' + peso(budget) : ' · No budget set';
        if ($('tmBudgetEditLabel')) $('tmBudgetEditLabel').textContent = budgetSet ? 'Change budget' : 'Set budget';
        if ($('tmBudgetEdit')) $('tmBudgetEdit').classList.toggle('is-unset', !budgetSet);
        if ($('tmBudgetBar')) $('tmBudgetBar').style.width = Math.min(100, budgetSet && budget > 0 ? (total / budget) * 100 : 0) + '%';
        if (wrap) wrap.classList.toggle('is-over', budgetSet && total > budget);
        var tierEl = $('tmBudgetTier');
        if (tierEl) {
            var showTier = budgetSet && !!budgetTier;
            tierEl.hidden = !showTier;
            tierEl.textContent = showTier ? BUDGET_TIERS[budgetTier] + ' · ' + purposeFor() : '';
        }
        renderBudgetNote();
    }

    /* Budget sent to the recommender: 0 (no budget) until the shopper sets one, so the ₱200,000
       placeholder never steers it toward high-end parts. */
    function apiBudget() {
        return budgetSet ? budget : 0;
    }

    function purposeFor() {
        return purpose !== 'balanced' ? purposeLabel() : 'most uses';
    }

    function guideTiers(key) {
        var g = budgetGuideData;
        return g && g.purposes ? g.purposes[key || purpose] || null : null;
    }

    /* The suggested level an amount matches for the current purpose, or '' when it is the shopper's own amount. */
    function tierFor(amount) {
        var tiers = guideTiers();
        if (!tiers) return '';
        var found = '';
        Object.keys(BUDGET_TIERS).forEach(function (k) { if (!found && Number(tiers[k]) === Number(amount)) found = k; });
        return found;
    }

    /* Line under the budget bar: what changed after a purpose switch (with Undo), or a hint when the
       budget is unset or a typed amount is outside the usual range for this purpose. */
    function renderBudgetNote() {
        var el = $('tmBudgetNote');
        if (!el) return;
        var tiers = guideTiers();
        var html = '';
        var tone = '';
        if (budgetNotice) {
            html = '<i class="fas fa-sync-alt" aria-hidden="true"></i><span>Budget updated to <strong>' + peso(budget) + '</strong>, the ' +
                BUDGET_TIERS[budgetTier].toLowerCase() + ' budget for ' + escapeHtml(purposeFor()) + '.</span>' +
                '<button type="button" class="bp-budget-note-btn" data-budget-undo>Undo</button>';
        } else if (tiers) {
            var range = peso(tiers.starter) + '–' + peso(tiers.high);
            var minimum = Number(((budgetGuideData || {}).minimum || {})[purpose]) || 0;
            var use = '<button type="button" class="bp-budget-note-btn" data-budget-use>Use ' + peso(tiers.recommended) + '</button>';
            if (!budgetSet) {
                html = '<i class="fas fa-lightbulb" aria-hidden="true"></i><span>Suggested budget for ' + escapeHtml(purposeFor()) + ': <strong>' + range + '</strong>.</span>' + use;
            } else if (!budgetTier && minimum && budget < minimum) {
                tone = 'is-warn';
                html = '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span>Your budget is below the cheapest complete ' +
                    (purpose !== 'balanced' ? escapeHtml(purposeLabel()) + ' ' : '') + 'PC in stock (about <strong>' + peso(minimum) + '</strong>).</span>' + use;
            } else if (!budgetTier && (budget < Number(tiers.starter) || budget > Number(tiers.high))) {
                html = '<i class="fas fa-info-circle" aria-hidden="true"></i><span>' + (purpose !== 'balanced' ? escapeHtml(purposeLabel()) + ' builds' : 'Most builds') +
                    ' usually cost <strong>' + range + '</strong>.</span>' + use;
            }
        }
        el.hidden = !html;
        el.className = 'bp-budget-note' + (tone ? ' ' + tone : '');
        el.innerHTML = html;
    }

    /* Purpose switch: a suggested budget moves to the same level for the new purpose. Returns true when it changed. */
    function retargetBudget() {
        budgetNotice = null;
        if (!budgetSet || !budgetTier) return false;
        var tiers = guideTiers();
        var next = tiers ? Number(tiers[budgetTier]) : 0;
        if (!next || next === budget) return false;
        budgetNotice = { prev: budget };
        budget = next;
        return true;
    }

    function setBudget(amount, tier) {
        budget = amount;
        budgetSet = true;
        budgetTier = tier || '';
        budgetNotice = null;
        refresh();
        if (activeSlot) loadProducts(activeSlot);
    }

    function renderRadar() {
        var svg = $('tmRadarSvg');
        if (!svg) return;
        /*
         * Green fill = absolute strength of each picked part (1 = top of the range, pc_rating.php);
         * amber dots mark parts that fall short of what the chosen purpose needs.
         */
        var rated = filledCount() && review.rating ? review.rating.axes || [] : [];
        function clamp(n) { return Math.max(0, Math.min(1, Number(n) || 0)); }
        var axes = [
            { key: 'cpu', label: 'CPU' },
            { key: 'ram', label: 'RAM' },
            { key: 'gpu', label: 'GPU' },
            { key: 'storage', label: 'Storage' },
            { key: 'psu', label: 'PSU' }
        ].map(function (a) {
            var r = rated.find(function (x) { return x.key === a.key; });
            var power = r ? clamp(r.power) : 0;
            var tip = a.label + ': ' + (r ? r.text : 'not picked yet') + (power > 0 ? '. Strength ' + Math.round(power * 100) + '%' : '');
            return { label: a.label, power: power, short: !!r && power > 0 && Number(r.value) < 0.85, tip: tip };
        });
        svg.setAttribute('aria-label', 'Performance balance of your parts: ' +
            axes.map(function (a) { return a.label + ' ' + Math.round(a.power * 100) + '%'; }).join(', '));

        var cx = 90, cy = 90, r = 58;
        function pt(i, rr) {
            var ang = (-Math.PI / 2) + (i * 2 * Math.PI / axes.length);
            return [cx + Math.cos(ang) * rr, cy + Math.sin(ang) * rr];
        }
        function poly(values) {
            return axes.map(function (a, i) { return pt(i, r * values(a)).map(function (n) { return n.toFixed(1); }).join(','); }).join(' ');
        }
        var grid = '';
        for (var ring = 1; ring <= 4; ring++) {
            grid += '<polygon class="tm-radar-grid" points="' + poly(function () { return ring / 4; }) + '"/>';
        }
        var spokes = axes.map(function (_, i) {
            var p = pt(i, r);
            return '<line class="tm-radar-spoke" x1="' + cx + '" y1="' + cy + '" x2="' + p[0] + '" y2="' + p[1] + '"/>';
        }).join('');
        var fill = rated.length
            ? '<polygon class="tm-radar-fill" points="' + poly(function (a) { return Math.max(0.03, a.power); }) + '"/>'
            : '';
        var dots = axes.map(function (a, i) {
            if (a.power <= 0) return '';
            var p = pt(i, r * a.power);
            return '<circle class="tm-radar-dot' + (a.short ? ' is-short' : '') + '" cx="' + p[0] + '" cy="' + p[1] + '" r="' + (a.short ? 3.2 : 2.6) + '">' +
                '<title>' + escapeHtml(a.tip) + '</title></circle>';
        }).join('');
        var labels = axes.map(function (a, i) {
            var p = pt(i, r + 15);
            return '<text class="tm-radar-label' + (a.short ? ' is-short' : '') + '" x="' + p[0] + '" y="' + p[1] + '" text-anchor="middle" dominant-baseline="middle">' +
                '<title>' + escapeHtml(a.tip) + '</title>' + a.label + '</text>';
        }).join('');
        svg.innerHTML = grid + spokes + fill + dots + labels;
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
        lastPower = power;
        renderRight(power);
        renderEditBanner();
        saveDraft();
        scheduleReview();
    }

    /* ---------- Build purpose + build check ---------- */

    /* Picked parts as sent to tech_match_api.php, leaving out one slot (the one being picked). */
    function pickedParts(except) {
        var picked = {};
        Object.keys(build).forEach(function (k) {
            if (k === except || !build[k]) return;
            picked[k] = {
                id: build[k].id,
                name: build[k].name,
                price: build[k].price,
                category: build[k].category || '',
                description: build[k].description || ''
            };
        });
        return picked;
    }

    /* Selects a purpose chip without re-rendering; unknown keys fall back to "Not sure yet". */
    function applyPurpose(key) {
        var chips = document.querySelectorAll('[data-purpose]');
        if (!chips.length) return;
        var found = null;
        chips.forEach(function (c) { if (c.getAttribute('data-purpose') === key) found = c; });
        if (!found) found = chips[0];
        purpose = found.getAttribute('data-purpose');
        chips.forEach(function (c) {
            var on = c === found;
            c.setAttribute('aria-checked', on ? 'true' : 'false');
            c.tabIndex = on ? 0 : -1;
        });
        if ($('bpPurposeHint')) $('bpPurposeHint').textContent = found.getAttribute('data-hint') || '';
        if ($('bpPurposeName')) $('bpPurposeName').textContent = (found.getAttribute('data-label') || '') + ':';
    }

    function setPurpose(key) {
        if (key === purpose) return;
        applyPurpose(key);
        if (!budgetGuideData && budgetSet && budgetTier) {
            /* Suggestions not loaded yet: move the budget as soon as they arrive. */
            loadBudgetGuide().then(function () {
                if (retargetBudget()) {
                    refresh();
                    if (activeSlot) loadProducts(activeSlot);
                }
            }).catch(function () {});
        } else {
            retargetBudget();
        }
        refresh();
        if (activeSlot) loadProducts(activeSlot);
    }

    function purposeLabel(key) {
        var chip = document.querySelector('[data-purpose="' + (key || purpose) + '"]');
        return chip ? chip.getAttribute('data-label') : '';
    }

    /* Asks the server which picked parts suit the purpose; re-renders the build check when it answers. */
    function scheduleReview() {
        var key = purpose + '|' + Object.keys(build).sort().map(function (k) { return k + ':' + (build[k] ? build[k].id : ''); }).join(',');
        if (key === review.key) return;
        review.key = key;
        review.warnings = [];
        review.notes = [];
        clearTimeout(reviewTimer);
        if (!filledCount()) {
            review.gpuNeeded = false;
            review.rating = null;
            review.pending = false;
            review.parts = {};
            review.soldOut = [];
            review.checkedAt = null;
            return;
        }
        /* The last score stays on screen (dimmed) until the new rating arrives. */
        review.pending = true;
        var card = document.querySelector('.tm-score-card');
        if (card) card.classList.add('is-pending');
        reviewTimer = setTimeout(function () {
            fetch('tech_match_api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'review', csrf_token: window.EP_CSRF || '', build: pickedParts(''), purpose: purpose, budget: apiBudget() })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (review.key !== key) return;
                    if (!data || !data.ok) throw new Error('review failed');
                    review.gpuNeeded = !!data.gpu_needed;
                    review.warnings = data.warnings || [];
                    review.notes = data.notes || [];
                    review.rating = data.rating || null;
                    review.parts = data.parts || {};
                    review.soldOut = data.sold_out || [];
                    review.checkedAt = new Date();
                    review.pending = false;
                    syncPrices();
                    renderSlots();
                    renderRight(lastPower);
                })
                .catch(function () {
                    if (review.key !== key) return;
                    review.pending = false;
                    renderRight(lastPower);
                });
        }, 250);
    }

    /* A draft or a loaded saved build can hold old prices: keep every picked part at today's price. */
    function syncPrices() {
        var changed = false;
        Object.keys(review.parts).forEach(function (slot) {
            var now = review.parts[slot];
            var item = build[slot];
            if (!item || Number(item.id) !== Number(now.id)) return;
            if (now.warranty) item.warranty = now.warranty;
            item.stock = Number(now.stock);
            var old = Number(item.price) || 0;
            if (Math.abs(old - Number(now.price)) < 0.01) return;
            priceChanges[slot] = { id: Number(item.id), old: old };
            item.price = Number(now.price);
            changed = true;
        });
        if (changed) saveDraft();
    }

    function priceChange(slot) {
        var c = priceChanges[slot];
        return c && build[slot] && Number(build[slot].id) === c.id ? c : null;
    }

    /* Today's stock of a picked part (from the last build check, else from when it was picked), or null. */
    function partStock(slot) {
        var item = build[slot];
        if (!item) return null;
        var p = review.parts[slot];
        if (p && Number(p.id) === Number(item.id)) return Number(p.stock);
        return item.stock != null && item.stock !== '' ? Number(item.stock) : null;
    }

    /* Out of stock and still to be bought (a part the customer already has never counts). */
    function isSoldOut(slot) {
        var item = build[slot];
        if (!item || item.owned) return false;
        var stock = partStock(slot);
        return stock !== null && stock <= 0;
    }

    function asOfLabel() {
        return review.checkedAt
            ? review.checkedAt.toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })
            : '';
    }

    /* Scroll so a slot row is visible, and highlight it. */
    function revealSlot(slotId) {
        var meta = slotMeta(slotId);
        var addonList = $('tmAddonList');
        if (meta && meta.addon && addonList && addonList.hidden) {
            addonsOpen = true;
            renderSlots();
        }
        var row = $('tm-slot-' + slotId);
        if (!row) return;
        var rect = row.getBoundingClientRect();
        // Keep the row clear of the fixed header and, when it is sticky, the budget/steps bar.
        var offset = 140;
        var top = document.querySelector('.bp-top');
        if (top && getComputedStyle(top).position === 'sticky') {
            offset = (parseInt(getComputedStyle(top).top, 10) || 0) + top.offsetHeight + 16;
        }
        if (rect.top < offset - 30 || rect.bottom > window.innerHeight) {
            window.scrollTo({ top: rect.top + window.pageYOffset - offset, behavior: 'smooth' });
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

    /* Short label for an installed part: the model for CPUs/GPUs ("Ryzen 5 5600X", "RX 580"), else the product name (CSS truncates it). */
    function pcxShortName(slot, name) {
        var n = String(name || '').replace(/\s+/g, ' ').trim();
        var m = null;
        if (slot === 'gpu') {
            m = n.match(/\b(?:rtx|gtx|rx|arc)\s?[a-z]?\d{3,4}[a-z]*(?:\s(?:ti|super|xtx|xt|gre))?\b/i);
        } else if (slot === 'processor') {
            m = n.match(/\bryzen\s?\d\s?(?:pro\s)?\d{4}[a-z0-9]*\b/i) ||
                n.match(/\bcore\sultra\s?\d(?:\sprocessor)?\s\d{3}[a-z]*\b/i) ||
                n.match(/\bi[3579][-\s]?\d{4,5}[a-z]*\b/i) ||
                n.match(/\b(?:ryzen\s?\d|athlon|pentium|celeron)\b[^,(]*/i);
        }
        if (m) return m[0].replace(/\sprocessor\b/i, '').trim();
        /* The case label only has room for the brand; RAM shows brand + size + type ("Kingston 16GB DDR4"). */
        if (slot === 'case') return n.split(' ')[0];
        if (slot === 'memory') {
            var gb = n.match(/\b\d{1,3}\s?gb\b/i);
            var ddr = n.match(/\b(?:lp)?ddr\d\b/i);
            if (gb || ddr) return [n.split(' ')[0], gb ? gb[0].replace(/\s/, '') : '', ddr ? ddr[0] : ''].join(' ').replace(/\s+/g, ' ').trim();
        }
        /* Elsewhere drop the socket/spec tail, then trailing colour and category words. */
        var s = n.split(/\s(?:socket|with)\b|\s?[(\/|,]/i)[0].trim();
        var tail = /\s(?:black(?:\sgold)?|white|silver|motherboard|processor|videocard|power supply|psu|(?:pc\s)?case|ssd|hdd|hard (?:disk|drive)|(?:single\s)?chassis fan|(?:cpu\s)?(?:air|liquid)\scooler|liquid cooling|heatsink fan)$/i;
        while (tail.test(s)) s = s.replace(tail, '');
        return s || n;
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
            /* Show the chosen product's name on the part; restore the generic label when it is removed. */
            var label = el.querySelector('.pcx-name');
            if (label) {
                if (!label.hasAttribute('data-default')) label.setAttribute('data-default', label.textContent);
                label.textContent = item ? pcxShortName(slot, item.name) : label.getAttribute('data-default');
                label.classList.toggle('is-product', !!item);
            }
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
        var summary = $('tmSummaryList');
        if (summary) {
            summary.addEventListener('mouseover', function (e) {
                var line = e.target.closest('.tm-summary-line');
                if (line) pcxHover(line.getAttribute('data-pick'), true);
            });
            summary.addEventListener('mouseout', function (e) {
                var line = e.target.closest('.tm-summary-line');
                if (line && !line.contains(e.relatedTarget)) pcxHover(line.getAttribute('data-pick'), false);
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
        /* Every component opens on the recommender's order; the shopper can still change the sort. */
        if ($('tmPickerSort')) $('tmPickerSort').value = 'recommended';
        /* Sold-out parts are listed too (marked, with an in-stock alternative); the toggle hides them. */
        if ($('tmPickerInStock')) $('tmPickerInStock').checked = false;
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
        fetch('tech_match_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                slot: slotId,
                csrf_token: window.EP_CSRF || '',
                build: pickedParts(slotId),
                purpose: purpose,
                budget: apiBudget()
            })
        })
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
        var sort = ($('tmPickerSort') || {}).value || 'recommended';
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
            if (sort === 'recommended') {
                return (Number(b.p.recommend_score) || 0) - (Number(a.p.recommend_score) || 0) || a.p._rank - b.p._rank;
            }
            if (sort === 'price-desc') return Number(b.p.price) - Number(a.p.price);
            if (sort === 'name') return String(a.p.name).localeCompare(String(b.p.name));
            if (sort === 'stock') return Number(b.p.stock) - Number(a.p.stock) || a.p._rank - b.p._rank;
            return Number(a.p.price) - Number(b.p.price);
        });

        if ($('tmPickerCount')) {
            var soldOutCount = list.filter(function (x) { return Number(x.p.stock || 0) <= 0; }).length;
            $('tmPickerCount').textContent = list.length + ' product' + (list.length === 1 ? '' : 's') +
                (soldOutCount ? ' · ' + soldOutCount + ' out of stock' : '') +
                (hidden ? ' · ' + hidden + ' incompatible hidden' : '');
        }
        if (!pickerProducts.length) {
            listEl.innerHTML = '<div class="tm-picker-empty"><i class="fas fa-box-open" aria-hidden="true"></i>' +
                'No ' + escapeHtml(meta ? meta.name : 'products') + ' available right now.<br>Please check back soon.</div>';
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
            /* Sold-out parts can still be picked to plan the build (panel feedback); they stay marked red. */
            else if (out) btn = '<button type="button" class="tm-select-btn is-out" data-select="' + pid + '">Select anyway</button>';
            else btn = '<button type="button" class="tm-select-btn" data-select="' + pid + '">Select</button>';
            var recommended = !x.reason && p.recommended && p.recommend_reason;
            /* Good fits outside the top picks: same reason, lighter "Good fit" label. */
            var goodFit = !x.reason && !p.recommended && p.recommend_reason;
            /* Sold out: still recommended when it fits; the in-stock alternative can be picked right here. */
            var alt = out && !x.reason ? p.alternative : null;
            var altHtml = out && !x.reason
                ? '<p class="tm-soldout-alt">' + (alt
                    ? '<span>In stock instead: <strong>' + escapeHtml(alt.name) + '</strong> · ' + peso(alt.price) + '</span>' +
                      '<button type="button" class="tm-alt-btn" data-select="' + (parseInt(alt.id, 10) || 0) + '">Select this</button>'
                    : '<span>No in-stock part fits this build right now.</span>') + '</p>'
                : '';
            return '<article class="tm-product-card' + (x.reason ? ' is-incompatible' : '') + (recommended ? ' is-recommended' : '') + (out ? ' is-soldout' : '') + (isCurrent ? ' is-current' : '') + '">' +
                img +
                '<div class="tm-product-body">' +
                (out ? '<span class="tm-out-label"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Out of stock</span>' : '') +
                (recommended ? '<span class="tm-recommend">Recommended</span>' : '') +
                (goodFit ? '<span class="tm-recommend tm-recommend-fit">Good fit</span>' : '') +
                (p.brand ? '<span class="tm-product-brand">' + escapeHtml(p.brand) + '</span>' : '') +
                '<h4 title="' + escapeHtml(p.name || '') + '">' +
                /* The product page only lists in-stock products, so sold-out names are not links. */
                (pid > 0 && !out ? '<a href="products.php?id=' + pid + '" target="_blank" rel="noopener">' + escapeHtml(p.name || '') + '</a>' : escapeHtml(p.name || '')) +
                '</h4>' +
                specChips(p, false) +
                (recommended || goodFit ? '<p class="tm-recommend-why">' + escapeHtml(p.recommend_reason) + '</p>' : '') +
                /* Add-ons are not rated by the recommender: their note just describes the product (neutral, not the orange "not recommended" note). */
                (!recommended && !goodFit && !x.reason && p.recommend_note ? '<p class="' + (p.addon ? 'tm-addon-desc' : 'tm-recommend-note') + '">' + escapeHtml(p.recommend_note) + '</p>' : '') +
                (x.reason ? '<p class="tm-compat-warn">' + escapeHtml(x.reason) + '</p>' : '') +
                (p.warranty ? '<p class="tm-product-wty"><i class="fas fa-shield-alt" aria-hidden="true"></i> ' + escapeHtml(p.warranty) + '</p>' : '') +
                (out && !x.reason ? '<p class="tm-out-note">Can\'t be bought right now. You can still add it to plan your build, then swap it before checkout.</p>' : '') +
                altHtml +
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
            compat: product.compat || null,
            warranty: product.warranty || '',
            stock: Number(product.stock || 0)
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

    /* Guests (and staff accounts) can build freely; saving and buying need a customer account. */
    function needsAccount(title, lead) {
        if (window.EP_TM_CAN_SAVE !== false) return false;
        var loginUrl = window.EP_TM_LOGIN_URL || '../login.php';
        openDialog(
            '<h3 id="tmDialogTitle">' + escapeHtml(title) + '</h3>' +
            '<p class="tm-save-lead">' + escapeHtml(lead) + ' Your current parts stay in the builder while you log in.</p>' +
            '<div class="tm-save-actions">' +
            '<a class="tm-save-cancel" href="../register.php">Create account</a>' +
            '<a class="tm-save-confirm" href="' + escapeHtml(loginUrl) + '"><i class="fas fa-sign-in-alt" aria-hidden="true"></i> Log in</a>' +
            '</div>'
        );
        return true;
    }

    function openSaveNameModal() {
        if (filledCount() <= 0) {
            alertUi('Select at least one component before saving.', 'info');
            return;
        }
        if (needsAccount('Log in to save your build', 'Saving a build needs a customer account.')) return;
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
        priceChanges = {};
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
            purpose: purpose,
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

    /* ---------- Add to Cart / Buy Now (whole build, no saving needed) ---------- */

    var buying = false;

    function purchaseBuild(action) {
        if (buying) return;
        if (filledCount() + addonCount() <= 0) {
            alertUi('Select at least one component first.', 'info');
            return;
        }
        if (!buyableSlots().length) {
            alertUi('You already have every part in this build, so there is nothing to buy.', 'info');
            return;
        }
        if (action === 'buy_now'
            ? needsAccount('Log in to buy this build', 'Buying a build needs a customer account. After logging in, click Buy Now again.')
            : needsAccount('Log in to add this build to your cart', 'Adding a build to your cart needs a customer account. After logging in, click Add to Cart again.')) {
            return;
        }
        var issues = validateCurrentBuild();
        if (issues.length) {
            alertUi('This build has incompatible components:\n• ' + issues.slice(0, 5).join('\n• '), 'error');
            return;
        }
        var label = action === 'buy_now' ? 'Buy Now' : 'Add to Cart';
        /* Sold-out parts can't be bought: Buy Now needs every part, Add to Cart skips them. */
        var soldOut = buyableSlots().filter(function (s) { return isSoldOut(s.id); });
        if (soldOut.length) {
            var inStock = buyableSlots().length - soldOut.length;
            var names = '<ul class="tm-out-list">' + soldOut.map(function (s) {
                return '<li><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span><strong>' + escapeHtml(s.name) + ':</strong> ' + escapeHtml(build[s.id].name) + '</span></li>';
            }).join('') + '</ul>';
            openDialog(
                '<h3 id="tmDialogTitle">' + (soldOut.length === 1 ? 'A part is out of stock' : soldOut.length + ' parts are out of stock') + '</h3>' +
                '<p class="tm-save-lead">' + (action === 'buy_now'
                    ? 'Buy Now needs every part in stock. Swap ' + (soldOut.length === 1 ? 'it' : 'them') + ' for an in-stock part, or add the rest of the build to your cart.'
                    : (soldOut.length === 1 ? 'It' : 'They') + ' can\'t be added to your cart yet. Swap ' + (soldOut.length === 1 ? 'it' : 'them') + ', or add the other parts now.') + '</p>' +
                names +
                '<div class="tm-save-actions">' +
                '<button type="button" class="tm-save-cancel" data-dialog-cancel>Keep building</button>' +
                (inStock ? '<button type="button" class="tm-save-confirm" data-dialog-ok>Add the other ' + inStock + ' to cart</button>' : '') +
                '</div>',
                function (overlay, close) {
                    var ok = overlay.querySelector('[data-dialog-ok]');
                    if (!ok) return;
                    ok.focus();
                    ok.onclick = function () { close(); sendBuildPurchase('add_to_cart'); };
                }
            );
            return;
        }
        var missing = missingNames();
        if (!missing.length) {
            sendBuildPurchase(action);
            return;
        }
        openDialog(
            '<h3 id="tmDialogTitle">Your build is not complete</h3>' +
            '<p class="tm-save-lead">Still missing: ' + escapeHtml(missing.join(', ')) +
            '. You can still continue with the ' + filledCount() + ' part' + (filledCount() === 1 ? '' : 's') + ' you picked.</p>' +
            '<div class="tm-save-actions">' +
            '<button type="button" class="tm-save-cancel" data-dialog-cancel>Keep building</button>' +
            '<button type="button" class="tm-save-confirm" data-dialog-ok>' + escapeHtml(label) + ' anyway</button>' +
            '</div>',
            function (overlay, close) {
                var ok = overlay.querySelector('[data-dialog-ok]');
                ok.focus();
                ok.onclick = function () { close(); sendBuildPurchase(action); };
            }
        );
    }

    function sendBuildPurchase(action) {
        var btns = [$('tmBuildToCart'), $('tmBuildBuyNow'), $('tmMobileCart')].filter(Boolean);
        var components = {};
        buyableSlots().forEach(function (s) {
            if (build[s.id].id && !isSoldOut(s.id)) components[s.id] = Number(build[s.id].id);
        });
        buying = true;
        btns.forEach(function (b) { b.disabled = true; });
        var done = function () {
            buying = false;
            btns.forEach(function (b) { b.disabled = false; });
        };
        fetch('build_cart_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action: action, components: components, csrf_token: window.EP_CSRF || '' })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    done();
                    alertUi((data && data.message) || 'Something went wrong. Please try again.', 'error');
                    return;
                }
                if (action === 'buy_now') {
                    window.location.href = data.redirect || 'checkout.php';
                    return;
                }
                done();
                if (data.cart && typeof window.epUpdateCartPreview === 'function') window.epUpdateCartPreview(data.cart);
                alertUi(data.message || 'Added to cart.', 'success', {
                    title: 'Build added to cart',
                    actions: [
                        { label: 'Keep Building' },
                        { label: 'View Cart', href: 'cart.php', primary: true }
                    ]
                });
            })
            .catch(function () {
                done();
                alertUi('Something went wrong. Please try again.', 'error');
            });
    }

    /* ---------- Saved builds (Load / Compare) ---------- */

    var savedCache = null;

    function fetchSavedBuilds() {
        if (window.EP_TM_CAN_SAVE === false) return Promise.resolve([]);
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
            /* A saved build expires when a part's price changed since it was saved (pc_saved_builds.php). */
            var expired = !!(b.price_check && b.price_check.expired);
            var when = savedDate(b.priced_at || b.created_at);
            return '<button type="button" class="tm-list-item" data-build-id="' + Number(b.id) + '">' +
                '<span><strong>' + escapeHtml(b.name) + '</strong>' +
                '<small>' + Number(b.component_count || 0) + ' components' + (when ? ' · prices as of ' + when : '') +
                (expired ? ' · <b class="tm-list-flag">Prices changed</b>' : '') + '</small></span>' +
                '<em>' + peso(b.total_price) + '</em></button>';
        }).join('');
        return items ? '<div class="tm-list">' + items + '</div>' : '<div class="tm-list-empty">You have no saved builds yet.</div>';
    }

    function openLoadDialog() {
        if (needsAccount('Log in to load saved builds', 'Saved builds are kept in your customer account.')) return;
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
                                applyPurpose((b.components || {})._purpose);
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
                                compare[index] = { name: 'Current build', components: JSON.parse(JSON.stringify(build)), purpose: purpose };
                            } else {
                                var b = builds.find(function (x) { return String(x.id) === id; });
                                if (!b) return;
                                compare[index] = { name: b.name, components: b.components || {}, purpose: (b.components || {})._purpose || 'balanced' };
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
            '<tr><th>Built for</th><td>' + escapeHtml(purposeLabel(a.purpose)) + '</td><td>' + escapeHtml(purposeLabel(b.purpose)) + '</td></tr>' +
            '<tr><th>Score</th><td id="tmCmpScoreA">…</td><td id="tmCmpScoreB">…</td></tr>' +
            '<tr><th>Total</th><td>' + peso(ta) + '</td><td>' + peso(tb) + '</td></tr>' +
            '</tfoot></table>' +
            (ta !== tb ? '<p class="tm-save-lead" style="margin-top:12px">' + escapeHtml(ta < tb ? a.name : b.name) + ' is ' + peso(Math.abs(ta - tb)) + ' cheaper.</p>' : ''),
            function () {
                /* Each build is scored for its own purpose (pc_rating.php). */
                [[a, 'tmCmpScoreA'], [b, 'tmCmpScoreB']].forEach(function (pair) {
                    fetchRating(pair[0].components, pair[0].purpose || 'balanced')
                        .then(function (r) {
                            var td = $(pair[1]);
                            if (td) td.innerHTML = r ? r.score + '/100<small>' + escapeHtml(r.verdict) + '</small>' : '—';
                        })
                        .catch(function () { var td = $(pair[1]); if (td) td.textContent = '—'; });
                });
            },
            true
        );
    }

    /* ---------- Share / Report / Budget ---------- */

    /* Plain-text summary for Share (pasted into chats, Notepad, email). Pasted text is usually shown in a
       proportional font, so it relies on line breaks, numbering and indents rather than aligned columns. */
    function buildSummaryText() {
        var rule = '────────────────────────────';
        var title = (pendingBuildName || 'My EasyPC Build').toUpperCase();
        var lines = [title];
        lines.push((purpose !== 'balanced' ? 'Built for ' + purposeLabel() + ' · ' : '') + filledCount() + ' of ' + TOTAL_SLOTS + ' components' +
            (addonCount() ? ' + ' + addonCount() + ' add-on' + (addonCount() === 1 ? '' : 's') : ''));
        if (asOfLabel()) lines.push('Prices as of ' + asOfLabel() + ' (Manila time)');
        lines.push(rule, '', 'PARTS');
        var n = 0;
        var addonHead = false;
        SUMMARY_ORDER.forEach(function (id) {
            var s = slotMeta(id);
            var item = build[id];
            if (!s || !item) return;
            if (s.addon && !addonHead) {
                lines.push('', 'ADD-ONS');
                addonHead = true;
            }
            n++;
            lines.push(n + '. ' + s.name + ' — ' + (item.owned ? 'already have it (not in the total)' : peso(item.price)) + (isSoldOut(id) ? '  (OUT OF STOCK)' : ''));
            lines.push('    ' + item.name);
            if (item.warranty && !item.owned) lines.push('    ' + item.warranty);
        });
        lines.push('', rule);
        lines.push('TOTAL: ' + peso(totalPrice()) + ' (VAT-inclusive)');
        if (review.rating) lines.push('Build Score: ' + buildScore() + '/100, ' + review.rating.verdict);
        lines.push('Estimated power draw: ~' + powerDraw() + 'W');
        lines.push(rule, '', 'Note: ' + DISCLAIMER, '', 'EasyPC One Oasis Branch · Rosario, Pasig City');
        return lines.join('\n');
    }

    function shareBuild() {
        if (filledCount() <= 0) {
            alertUi('Add at least one component before sharing.', 'info');
            return;
        }
        var text = buildSummaryText();
        /* The system share sheet on phones and tablets; desktops copy the summary instead
           (desktop share sheets are unreliable). A failed share falls back to the copy dialog. */
        var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        if (navigator.share && touch) {
            navigator.share({ title: 'My EasyPC Build', text: text }).catch(function (err) {
                if (!err || err.name !== 'AbortError') showShareText(text);
            });
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
            '<textarea class="tm-save-input" rows="14" readonly id="tmShareText">' + escapeHtml(text) + '</textarea>' +
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

    /* Printable Build Summary in a business format: header, build details, parts table with VAT,
       Build Score breakdown, compatibility and the disclaimer. Printed in the same tab (hidden iframe). */
    function reportBuild() {
        if (filledCount() <= 0) {
            alertUi('Add at least one component to print a build summary.', 'info');
            return;
        }
        /* A part was just changed: wait (up to 5 s) for its new Build Score and prices before printing. */
        if (review.pending) {
            var tries = 0;
            var wait = setInterval(function () {
                if (!review.pending || ++tries > 50) {
                    clearInterval(wait);
                    printBuildSummary();
                }
            }, 100);
            return;
        }
        printBuildSummary();
    }

    function printBuildSummary() {
        var issues = validateCurrentBuild();
        var missing = missingNames();
        var rating = review.rating;
        var total = totalPrice();
        var vatable = total / 1.12;
        var now = new Date().toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' });
        var n = 0;
        var addonHead = false;
        var rows = SUMMARY_ORDER.filter(function (id) { return build[id]; }).map(function (id) {
            var s = slotMeta(id);
            var item = build[id];
            var change = item.owned ? null : priceChange(id);
            var head = '';
            if (s.addon && !addonHead) {
                head = '<tr class="sub"><td colspan="7">Add-ons</td></tr>';
                addonHead = true;
            }
            n++;
            return head + '<tr><td class="c">' + n + '</td><td>' + escapeHtml(s.name) + '</td><td>' + escapeHtml(item.name) +
                (change ? '<small>Price updated (was ' + peso(change.old) + ')</small>' : '') + '</td>' +
                '<td>' + escapeHtml(item.owned ? '' : item.warranty || '') + '</td>' +
                '<td class="c">' + (item.owned ? 'Customer has it' : (isSoldOut(id) ? '<span class="bad">Out of stock</span>' : 'In stock')) + '</td>' +
                '<td class="c">' + (item.owned ? '0' : '1') + '</td><td class="r">' + (item.owned ? '—' : peso(item.price)) + '</td></tr>';
        }).join('');
        var why = rating && rating.axes ? rating.axes.map(function (a) {
            return '<tr><td>' + escapeHtml(a.label) + '</td><td>' + escapeHtml(a.text) + '</td><td class="r">' + formatPoints(a.points) + ' / ' + Number(a.weight) + '</td></tr>';
        }).join('') + (rating.adjustments || []).map(function (x) {
            return '<tr><td>' + (x.cap ? 'Limit' : 'Deduction') + '</td><td>' + escapeHtml(x.text) + '</td><td class="r">' + (x.cap ? 'max ' + Number(x.cap) : formatPoints(x.points)) + '</td></tr>';
        }).join('') : '';
        var html = '<!doctype html><html><head><meta charset="utf-8"><title>Build Summary</title>' +
            '<style>@page{margin:14mm;}body{font-family:Arial,Helvetica,sans-serif;color:#16201a;margin:0;font-size:12px;}' +
            '.head{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:3px solid #3d7422;padding-bottom:10px;margin-bottom:14px;}' +
            '.head h1{margin:0;font-size:20px;}.head p{margin:2px 0 0;color:#45514a;}.doc{text-align:right;}.doc strong{display:block;font-size:16px;color:#3d7422;letter-spacing:.04em;}' +
            'table{width:100%;border-collapse:collapse;margin:0 0 14px;}th,td{border:1px solid #d5ddd1;padding:6px 8px;text-align:left;vertical-align:top;}' +
            'th{background:#eef4ea;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}.r{text-align:right;white-space:nowrap;}.c{text-align:center;}' +
            'tr.sub td{background:#f6f8f4;font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:.04em;}td small{display:block;color:#5c6862;}.meta td{border:0;padding:2px 8px 2px 0;}.meta td:first-child{color:#5c6862;width:130px;}' +
            '.tot td{border:0;padding:3px 8px;}.tot .grand td{border-top:2px solid #16201a;font-size:15px;font-weight:700;padding-top:6px;}' +
            'h2{font-size:13px;margin:16px 0 6px;text-transform:uppercase;letter-spacing:.05em;color:#3d7422;}' +
            '.ok{color:#3d7422;}.bad{color:#b42318;font-weight:700;}.note{border:1px solid #d5ddd1;background:#f6f8f4;padding:8px 10px;color:#45514a;line-height:1.5;}' +
            '.foot{margin-top:18px;color:#5c6862;font-size:10.5px;text-align:center;}</style></head><body>' +
            '<div class="head"><div><h1>EasyPC One Oasis Branch</h1><p>Rosario, Pasig City</p></div>' +
            '<div class="doc"><strong>PC BUILD SUMMARY</strong><span>Generated ' + escapeHtml(now) + '</span></div></div>' +
            '<table class="meta"><tbody>' +
            '<tr><td>Build name</td><td>' + escapeHtml(pendingBuildName || 'Untitled build') + '</td></tr>' +
            '<tr><td>Built for</td><td>' + escapeHtml(purpose === 'balanced' ? 'Everyday use (not sure yet)' : purposeLabel()) + '</td></tr>' +
            '<tr><td>Prices as of</td><td>' + escapeHtml(asOfLabel() || now) + ' (Manila time)</td></tr>' +
            '<tr><td>Components</td><td>' + filledCount() + ' of ' + TOTAL_SLOTS + (missing.length ? ' (still needed: ' + escapeHtml(missing.join(', ')) + ')' : ', all required parts included') +
                (addonCount() ? ', plus ' + addonCount() + ' add-on' + (addonCount() === 1 ? '' : 's') : '') + '</td></tr>' +
            '</tbody></table>' +
            '<table><thead><tr><th class="c">#</th><th>Component</th><th>Product</th><th>Warranty</th><th class="c">Availability</th><th class="c">Qty</th><th class="r">Price</th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table>' +
            '<table class="tot"><tbody>' +
            '<tr><td class="r">VATable amount</td><td class="r" style="width:140px">' + peso(vatable) + '</td></tr>' +
            '<tr><td class="r">VAT (12%)</td><td class="r">' + peso(total - vatable) + '</td></tr>' +
            '<tr class="grand"><td class="r">Total (VAT-inclusive)</td><td class="r">' + peso(total) + '</td></tr>' +
            '</tbody></table>' +
            '<h2>Build Score: ' + buildScore() + '/100' + (rating && rating.verdict ? ' · ' + escapeHtml(rating.verdict) : '') + '</h2>' +
            (why ? '<table><thead><tr><th>Part</th><th>Assessment</th><th class="r">Points</th></tr></thead><tbody>' + why + '</tbody></table>' : '') +
            '<h2>Compatibility and power</h2>' +
            '<p class="' + (issues.length ? 'bad' : 'ok') + '">' + (issues.length ? 'Compatibility issues: ' + escapeHtml(issues.join(' | ')) : 'All selected parts are compatible.') + '</p>' +
            '<p>Estimated power draw: about ' + powerDraw() + ' W.</p>' +
            '<h2>Disclaimer</h2><p class="note">' + escapeHtml(DISCLAIMER) + '</p>' +
            '<p class="foot">Generated by TechPrime AI Build a PC · This summary is not an official receipt or a reservation.</p>' +
            '</body></html>';
        var old = $('tmPrintFrame');
        if (old) old.remove();
        var frame = document.createElement('iframe');
        frame.id = 'tmPrintFrame';
        frame.setAttribute('aria-hidden', 'true');
        frame.tabIndex = -1;
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
        document.body.appendChild(frame);
        var doc = frame.contentWindow.document;
        doc.open();
        doc.write(html);
        doc.close();
        setTimeout(function () {
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (e) {
                alertUi('Could not open the print dialog.', 'error');
            }
        }, 150);
    }

    /* Suggested budgets for each purpose, from current catalog prices; fetched once and reused. */
    function loadBudgetGuide() {
        if (!budgetGuide) {
            budgetGuide = fetch('tech_match_api.php?action=budget_guide', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) throw new Error('budget_guide');
                    budgetGuideData = data;
                    return data;
                })
                .catch(function (err) {
                    budgetGuide = null;
                    throw err;
                });
        }
        return budgetGuide;
    }

    /* Least it costs to finish the current build: parts so far plus the cheapest of each required part still missing. */
    function finishBuildMinimum(cheapest) {
        var sum = totalPrice();
        missingEssentials().forEach(function (m) {
            if (m.id === 'ssd') {
                var drives = ['ssd', 'ssd_sata', 'hdd'].map(function (k) { return Number(cheapest[k] || 0); }).filter(function (v) { return v > 0; });
                sum += drives.length ? Math.min.apply(null, drives) : 0;
            } else {
                sum += Number(cheapest[m.id] || 0);
            }
        });
        return Math.ceil(sum / 1000) * 1000;
    }

    function editBudget() {
        var label = purposeLabel();
        openDialog(
            '<h3 id="tmDialogTitle">Set Your Budget</h3>' +
            '<p class="tm-save-lead">Pick a suggested amount' + (purpose !== 'balanced' ? ' for <strong>' + escapeHtml(label) + '</strong>' : '') +
            ', or type your own. Recommended parts will fit inside it.</p>' +
            '<div class="tm-budget-presets" id="tmBudgetPresets" role="group" aria-label="Suggested budgets">' +
            '<p class="tm-budget-loading">Loading suggestions…</p></div>' +
            '<div class="tm-budget-finish" id="tmBudgetFinish" hidden></div>' +
            '<label class="tm-budget-own" for="tmBudgetInput">Your budget (₱)</label>' +
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
                var markPicked = function () {
                    var v = Math.round(Number(input.value));
                    overlay.querySelectorAll('[data-amount]').forEach(function (b) {
                        b.setAttribute('aria-pressed', Number(b.getAttribute('data-amount')) === v ? 'true' : 'false');
                    });
                };
                var save = function () {
                    var v = Math.round(Number(input.value));
                    if (!(v >= 1000)) {
                        $('tmBudgetError').hidden = false;
                        return;
                    }
                    close();
                    setBudget(v, tierFor(v));
                };
                overlay.addEventListener('click', function (e) {
                    var preset = e.target.closest('[data-amount]');
                    if (!preset) return;
                    input.value = preset.getAttribute('data-amount');
                    $('tmBudgetError').hidden = true;
                    markPicked();
                });
                input.addEventListener('input', markPicked);
                $('tmBudgetSave').onclick = save;
                input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });

                loadBudgetGuide()
                    .then(function (guide) {
                        var tiers = (guide.purposes || {})[purpose] || (guide.purposes || {}).balanced;
                        var box = $('tmBudgetPresets');
                        if (!box || !tiers) return;
                        var forWhat = purpose !== 'balanced' ? label.toLowerCase() : 'most uses';
                        var options = [
                            { key: 'starter', name: 'Starter', text: 'Basic parts that do the job.' },
                            { key: 'recommended', name: 'Recommended', text: 'Good balance of price and performance for ' + forWhat + '.', tag: true },
                            { key: 'high', name: 'High-end', text: 'Faster parts and more room to upgrade.' }
                        ];
                        box.innerHTML = options.map(function (o) {
                            return '<button type="button" class="tm-budget-preset" data-amount="' + Number(tiers[o.key]) + '" aria-pressed="false">' +
                                '<span class="tm-budget-preset-name">' + o.name + (o.tag ? ' <em>Suggested</em>' : '') + '</span>' +
                                '<strong>' + peso(tiers[o.key]) + '</strong>' +
                                '<small>' + escapeHtml(o.text) + '</small></button>';
                        }).join('') +
                            '<p class="tm-budget-note">Based on current EasyPC prices for a complete PC' +
                            (purpose === 'gaming' || purpose === 'creation' ? ' with a graphics card' : '') + '.</p>';
                        if (filledCount() > 0 && $('tmBudgetFinish')) {
                            var least = finishBuildMinimum(guide.cheapest || {});
                            $('tmBudgetFinish').hidden = false;
                            $('tmBudgetFinish').innerHTML = '<span>Your parts so far: <strong>' + peso(totalPrice()) + '</strong>. ' +
                                (missingEssentials().length ? 'Finishing this build costs at least <strong>' + peso(least) + '</strong>.' : 'Your build has every required part.') +
                                '</span>' +
                                (missingEssentials().length ? '<button type="button" class="tm-budget-use" data-amount="' + least + '">Use ' + peso(least) + '</button>' : '');
                        }
                        markPicked();
                    })
                    .catch(function () {
                        var box = $('tmBudgetPresets');
                        if (box) box.hidden = true;
                    });
            },
            true
        );
    }

    /* ---------- Build it for me (tech_match_api.php action=auto_build) ---------- */

    function buildSnapshot() {
        return { build: JSON.parse(JSON.stringify(build)), budget: budget, budgetSet: budgetSet, budgetTier: budgetTier, purpose: purpose };
    }

    function restoreSnapshot(snap) {
        build = snap.build;
        budget = snap.budget;
        budgetSet = snap.budgetSet;
        budgetTier = snap.budgetTier || '';
        budgetNotice = null;
        applyPurpose(snap.purpose);
        refresh();
        if (activeSlot) loadProducts(activeSlot);
    }

    /* Builds the PC from scratch, except the parts the customer already has (sent as kept, at no cost).
       Add-ons stay as they are, so their cost comes off the budget first. */
    function requestAutoBuild(amount) {
        var owned = {};
        PARTS.forEach(function (s) {
            var it = build[s.id];
            if (it && it.owned) owned[s.id] = { id: it.id, name: it.name, owned: true };
        });
        return fetch('tech_match_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'auto_build', csrf_token: window.EP_CSRF || '', build: owned, purpose: purpose, budget: Math.max(1000, amount - addonTotal()) })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) throw new Error('auto_build');
                return data;
            });
    }

    /* Replaces the PC parts with the server's picks; parts the customer has and add-ons stay. */
    function mergeAutoBuild(data) {
        var kept = {};
        Object.keys(build).forEach(function (slot) {
            var meta = slotMeta(slot);
            if (build[slot] && (build[slot].owned || (meta && meta.addon))) kept[slot] = build[slot];
        });
        build = kept;
        var added = data.added || {};
        Object.keys(added).forEach(function (slot) {
            var p = added[slot];
            if (!slotMeta(slot) || !p) return;
            build[slot] = {
                id: p.id,
                name: p.name,
                price: p.price,
                image: p.image,
                category: p.category,
                description: p.description || '',
                compat: p.compat || null,
                warranty: p.warranty || '',
                stock: Number(p.stock || 0),
                auto: true
            };
        });
    }

    /* Drops each listed part into the case preview one after another. */
    function installParts(slots) {
        SUMMARY_ORDER.filter(function (slot) { return slots.indexOf(slot) !== -1; }).forEach(function (slot, i) {
            setTimeout(function () { pcxInstall(slot); }, 160 * i);
        });
    }

    function openAutoBuildDialog() {
        var label = purposeLabel();
        /* Builds from scratch: every part already in the build is replaced (Undo brings them back),
           except parts the customer already has and add-ons. */
        var parts = PARTS.filter(function (s) { return build[s.id] && !build[s.id].owned; }).length;
        var keeps = PARTS.length - PARTS.filter(function (s) { return !build[s.id] || !build[s.id].owned; }).length;
        openDialog(
            '<h3 id="tmDialogTitle"><i class="fas fa-magic" aria-hidden="true"></i> Build it for me</h3>' +
            '<p class="tm-save-lead">EasyPC\'s recommendation model picks a complete ' +
            (purpose !== 'balanced' ? '<strong>' + escapeHtml(label) + '</strong> ' : '') +
            'PC within your budget, one part at a time, and checks every part for compatibility. You can change any part afterwards.</p>' +
            (purpose === 'balanced' ? '<p class="tm-autobuild-tip"><i class="fas fa-info-circle" aria-hidden="true"></i> Tip: choose what the PC is for (School, Office, Gaming…) first for better picks.</p>' : '') +
            '<label class="tm-budget-own" for="tmAutoBudget">Budget (₱)</label>' +
            '<input type="number" id="tmAutoBudget" class="tm-save-input" min="1000" step="1000" value="' + budget + '">' +
            '<p class="tm-budget-note" id="tmAutoBudgetHint"></p>' +
            (parts > 0 ? '<p class="tm-autobuild-tip"><i class="fas fa-info-circle" aria-hidden="true"></i> This starts a new build and replaces the ' + parts +
                ' part' + (parts === 1 ? '' : 's') + ' you picked. You can Undo afterwards.</p>' : '') +
            (keeps > 0 || addonCount() ? '<p class="tm-autobuild-tip"><i class="fas fa-check-circle" aria-hidden="true"></i> Kept as they are: ' +
                [keeps ? keeps + ' part' + (keeps === 1 ? '' : 's') + ' you already have' : '', addonCount() ? 'your add-ons (' + peso(addonTotal()) + ' of the budget)' : '']
                    .filter(Boolean).join(' and ') + '.</p>' : '') +
            '<div class="tm-save-error" id="tmAutoError" hidden></div>' +
            '<div class="tm-save-actions">' +
            '<button type="button" class="tm-save-cancel" data-dialog-cancel>Cancel</button>' +
            '<button type="button" class="tm-save-confirm" id="tmAutoGo"><i class="fas fa-magic" aria-hidden="true"></i> Build it for me</button>' +
            '</div>',
            function (overlay, close) {
                var input = $('tmAutoBudget');
                var go = $('tmAutoGo');
                /* Least a complete PC for this purpose costs (budget guide); smaller budgets are refused. */
                var minimum = 0;
                input.focus();
                /* No budget picked yet: wait for the suggested amount before building. */
                if (!budgetSet) go.disabled = true;
                loadBudgetGuide()
                    .then(function (guide) {
                        minimum = Number((guide.minimum || {})[purpose]) || 0;
                        var tiers = (guide.purposes || {})[purpose];
                        if (!tiers) return;
                        if (!budgetSet) input.value = tiers.recommended;
                        if ($('tmAutoBudgetHint')) {
                            $('tmAutoBudgetHint').textContent = 'Suggested for ' + (purpose !== 'balanced' ? label : 'most uses') + ': ' +
                                peso(tiers.starter) + ' – ' + peso(tiers.high) + ' (recommended ' + peso(tiers.recommended) + ').';
                        }
                    })
                    .catch(function () {})
                    .then(function () { go.disabled = false; });
                go.onclick = function () {
                    var v = Math.round(Number(input.value));
                    var err = $('tmAutoError');
                    if (!(v >= 1000)) {
                        err.textContent = 'Enter a budget of at least ₱1,000.';
                        err.hidden = false;
                        return;
                    }
                    if (minimum && v < minimum) {
                        err.innerHTML = 'The cheapest complete ' + (purpose !== 'balanced' ? escapeHtml(label) + ' ' : '') + 'PC in stock costs about <strong>' + peso(minimum) + '</strong>. ' +
                            '<button type="button" class="tm-budget-use" data-amount="' + minimum + '">Use ' + peso(minimum) + '</button>';
                        err.hidden = false;
                        err.querySelector('[data-amount]').onclick = function () {
                            input.value = minimum;
                            err.hidden = true;
                            input.focus();
                        };
                        return;
                    }
                    err.hidden = true;
                    go.disabled = true;
                    go.textContent = 'Building…';
                    requestAutoBuild(v)
                        .then(function (data) {
                            close();
                            applyAutoBuild(data, v);
                        })
                        .catch(function () {
                            go.disabled = false;
                            go.innerHTML = '<i class="fas fa-magic" aria-hidden="true"></i> Build it for me';
                            err.textContent = 'Could not build a PC right now. Please try again.';
                            err.hidden = false;
                        });
                };
                input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go.click(); } });
            }
        );
    }

    function applyAutoBuild(data, newBudget) {
        var before = buildSnapshot();
        mergeAutoBuild(data);
        budget = newBudget;
        budgetSet = true;
        budgetTier = tierFor(newBudget);
        budgetNotice = null;
        activeStep = 1;
        refresh();
        installParts(Object.keys(data.added || {}));
        showAutoBuildResult(data, before);
    }

    function showAutoBuildResult(data, before) {
        var added = data.added || {};
        var reasons = data.reasons || {};
        var fallback = data.fallback || [];
        var slots = SUMMARY_ORDER.filter(function (slot) { return added[slot]; });
        var total = totalPrice();
        var rows = slots.map(function (slot) {
            var meta = slotMeta(slot);
            var p = added[slot];
            var picked = fallback.indexOf(slot) === -1;
            return '<li class="' + (picked ? '' : 'is-fallback') + '">' +
                '<span class="tm-autobuild-slot">' + escapeHtml(meta ? meta.name : slot) + '</span>' +
                '<span class="tm-autobuild-part"><strong title="' + escapeHtml(p.name) + '">' + escapeHtml(p.name) + '</strong>' +
                '<small><i class="fas ' + (picked ? 'fa-check-circle' : 'fa-info-circle') + '" aria-hidden="true"></i> ' + escapeHtml(reasons[slot] || '') + '</small></span>' +
                '<em>' + peso(p.price) + '</em></li>';
        }).join('');
        var missing = (data.missing || []).map(function (slot) { var m = slotMeta(slot); return m ? m.name : slot; });
        var title = purpose !== 'balanced' ? 'Your ' + purposeLabel() + ' build is ready' : 'Your build is ready';
        openDialog(
            '<h3 id="tmDialogTitle">' + escapeHtml(title) + '</h3>' +
            '<p class="tm-save-lead">Total <strong>' + peso(total) + '</strong> of your ' + peso(budget) + ' budget. ' +
            (slots.length ? 'Each part was picked by the recommendation model for this build and checked for compatibility. ' +
                'For a different use or budget, change them and click Build it for me again.' : 'No parts could be added.') + '</p>' +
            (rows ? '<ul class="tm-autobuild-list">' + rows + '</ul>' : '') +
            (fallback.length ? '<p class="tm-budget-note"><i class="fas fa-info-circle" aria-hidden="true"></i> Parts marked with ⓘ are the closest options in stock; a bigger budget allows recommended picks.</p>' : '') +
            (missing.length ? '<div class="tm-save-error">Not available in stock for this build: ' + escapeHtml(missing.join(', ')) + '.</div>' : '') +
            (total > budget ? '<div class="tm-save-error">This build is over your budget.</div>' : '') +
            '<div class="tm-save-actions">' +
            '<button type="button" class="tm-save-cancel" id="tmAutoUndo"><i class="fas fa-undo" aria-hidden="true"></i> Undo</button>' +
            '<button type="button" class="tm-save-confirm" data-dialog-cancel>Looks good</button>' +
            '</div>',
            function (overlay, close) {
                $('tmAutoUndo').onclick = function () {
                    close();
                    restoreSnapshot(before);
                };
            },
            true
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
                compat: item.compat || null,
                warranty: item.warranty || ''
            };
            /* Picked by "Build it for me" (kept in the draft only; saved builds drop it). */
            if (item.auto) build[k].auto = true;
            if (item.owned) build[k].owned = true;
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
        applyPurpose((components || {})._purpose);
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

    /* ---------- Ask EasyPC about this build ---------- */

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }).catch(function () { return false; });
        }
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        ta.remove();
        return Promise.resolve(ok);
    }

    /* Short version of the build for a text message: one line per part, then the total. */
    function buildShortText() {
        var lines = ['Hi EasyPC! I\'d like to ask about this build' + (purpose !== 'balanced' ? ' (' + purposeLabel() + ')' : '') + ':'];
        SUMMARY_ORDER.forEach(function (id) {
            var s = slotMeta(id);
            var item = build[id];
            if (!s || !item) return;
            lines.push('- ' + s.name + ': ' + item.name + (item.owned ? ' (I have this)' : ' ' + peso(item.price)));
        });
        lines.push('Total: ' + peso(totalPrice()));
        return lines.join('\n');
    }

    /* Messenger, text, call or copy: the build summary goes along so staff see the exact parts. */
    function askStore() {
        var empty = filledCount() + addonCount() <= 0;
        var text = empty ? 'Hi EasyPC! I need help choosing parts for a new PC.' : buildSummaryText();
        var sms = empty ? text : buildShortText();
        openDialog(
            '<h3 id="tmDialogTitle"><i class="fas fa-headset" aria-hidden="true"></i> Ask EasyPC about this build</h3>' +
            '<p class="tm-save-lead">Our staff at EasyPC One Oasis can check your build, confirm stock or suggest changes. ' +
            (empty ? '' : 'Your build summary goes with your message.') + '</p>' +
            '<div class="tm-ask-options">' +
            '<a class="tm-ask-opt" href="' + STORE.messenger + '" target="_blank" rel="noopener" data-ask-copy>' +
            '<i class="fab fa-facebook-messenger" aria-hidden="true"></i><span><strong>Message us on Messenger</strong>' +
            '<small>We copy your build summary first. Paste it in the chat.</small></span></a>' +
            '<a class="tm-ask-opt" href="sms:' + STORE.phone + '?body=' + encodeURIComponent(sms) + '">' +
            '<i class="fas fa-sms" aria-hidden="true"></i><span><strong>Text ' + STORE.phoneLabel + '</strong>' +
            '<small>Opens your messages app with your build filled in.</small></span></a>' +
            '<a class="tm-ask-opt" href="tel:' + STORE.phone + '">' +
            '<i class="fas fa-phone-alt" aria-hidden="true"></i><span><strong>Call ' + STORE.phoneLabel + '</strong>' +
            '<small>' + STORE.hours + '.</small></span></a>' +
            '<button type="button" class="tm-ask-opt" data-ask-copy-only>' +
            '<i class="far fa-copy" aria-hidden="true"></i><span><strong>Copy build summary</strong>' +
            '<small>Paste it anywhere: Viber, email or a note.</small></span></button>' +
            '</div>' +
            '<p class="tm-ask-status" id="tmAskStatus" role="status" aria-live="polite"></p>',
            function (overlay) {
                var status = $('tmAskStatus');
                overlay.addEventListener('click', function (e) {
                    var copyOnly = e.target.closest('[data-ask-copy-only]');
                    var copyFirst = e.target.closest('[data-ask-copy]');
                    if (!copyOnly && !copyFirst) return;
                    /* The Messenger link still opens (new tab); the copy runs in the same click. */
                    copyText(text).then(function (ok) {
                        if (status) status.textContent = ok ? 'Build summary copied.' + (copyFirst ? ' Paste it in the Messenger chat.' : '') : 'Could not copy. Use Share to copy the summary.';
                    });
                });
            }
        );
    }

    /* ---------- Starter builds (tech_match_api.php action=starter_builds) ---------- */

    var STARTER_RUN = { school: 'everyday', office: 'video', balanced: 'esports', gaming: 'aaa', creation: 'video' };
    var CAN_RUN_SHORT = { everyday: 'Everyday use', esports: 'Esports games', popular: 'Popular games', aaa: 'Newest big games', photo: 'Photo editing', video: 'Video editing', stream: 'Streaming' };

    function purposeIcon(key) {
        var icon = document.querySelector('[data-purpose="' + key + '"] .bp-purpose-icon i');
        return icon ? icon.className : 'fas fa-desktop';
    }

    function setStartersOpen(open) {
        var list = $('bpStartersList');
        var btn = $('bpStartersToggle');
        if (list) list.hidden = !open;
        if (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.innerHTML = open ? 'Hide <i class="fas fa-chevron-up" aria-hidden="true"></i>' : 'Show builds <i class="fas fa-chevron-down" aria-hidden="true"></i>';
        }
    }

    function renderStarters() {
        var box = $('bpStartersList');
        if (!box) return;
        if (!starters || !starters.length) {
            if ($('bpStarters')) $('bpStarters').hidden = !!starters;
            return;
        }
        box.innerHTML = starters.map(function (b, i) {
            var key = STARTER_RUN[b.purpose];
            var r = (b.can_run || []).find(function (x) { return x.key === key; });
            var run = r ? '<p class="bp-starter-run is-' + escapeHtml(r.level) + '"><i class="fas ' + (CAN_RUN_ICON[r.level] || 'fa-circle') + '" aria-hidden="true"></i>' +
                '<span><strong>' + escapeHtml(CAN_RUN_SHORT[key] || r.label) + ':</strong> ' + escapeHtml(r.text) + '</span></p>' : '';
            return '<article class="bp-starter" role="listitem">' +
                '<div class="bp-starter-top">' +
                '<span class="bp-starter-icon" aria-hidden="true"><i class="' + escapeHtml(purposeIcon(b.purpose)) + '"></i></span>' +
                '<div class="bp-starter-title"><h3>' + escapeHtml(b.purpose === 'balanced' ? 'All-round PC' : b.purpose_label + ' PC') + '</h3>' +
                '<small>' + escapeHtml(b.level_label) + ' budget · ' + peso(b.budget) + '</small></div>' +
                '<span class="bp-starter-score" title="Build Score: ' + Number(b.score) + '/100, ' + escapeHtml(b.verdict) + '">' + Number(b.score) + '<small>/100</small></span>' +
                '</div>' +
                '<p class="bp-starter-specs">' + escapeHtml((b.specs || []).join(' · ')) + '</p>' +
                run +
                '<div class="bp-starter-foot"><strong>' + peso(b.total) + '</strong>' +
                '<button type="button" class="bp-starter-use" data-starter="' + i + '">Use this build</button></div>' +
                '</article>';
        }).join('');
        if ($('bpStarters')) $('bpStarters').hidden = false;
    }

    function loadStarters() {
        if (!$('bpStarters')) return;
        fetch('tech_match_api.php?action=starter_builds', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                starters = data && data.ok ? data.builds || [] : [];
                renderStarters();
            })
            .catch(function () {
                starters = [];
                renderStarters();
            });
    }

    function useStarter(index) {
        var b = starters && starters[index];
        if (!b) return;
        var apply = function () {
            var before = buildSnapshot();
            var kept = {};
            /* Add-ons stay; the PC parts are replaced by the starter build. */
            Object.keys(build).forEach(function (slot) {
                var meta = slotMeta(slot);
                if (meta && meta.addon) kept[slot] = build[slot];
            });
            build = kept;
            mergeAutoBuild({ added: b.parts });
            applyPurpose(b.purpose);
            budget = b.budget;
            budgetSet = true;
            budgetTier = BUDGET_TIERS[b.level] ? b.level : '';
            budgetNotice = null;
            activeStep = 1;
            setStartersOpen(false);
            refresh();
            installParts(Object.keys(b.parts || {}));
            var ws = $('ep-tech-match');
            if (ws) ws.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (typeof IAS_UI !== 'undefined') {
                IAS_UI.alert('Change any part you like, or add a monitor, keyboard and Windows under Add-ons.', 'success', 0, {
                    title: 'Starter build loaded',
                    detail: (b.purpose === 'balanced' ? 'All-round PC' : b.purpose_label + ' PC') + ' · ' + peso(b.total),
                    actions: [{ label: 'Undo', value: 'undo' }, { label: 'Start editing', primary: true }],
                    onClose: function (value) { if (value === 'undo') restoreSnapshot(before); }
                });
            }
        };
        var parts = PARTS.filter(function (s) { return build[s.id]; }).length;
        if (parts > 0) {
            confirmDialog('Replace your current build?', 'The ' + parts + ' part' + (parts === 1 ? '' : 's') + ' you picked will be replaced by this starter build. Your add-ons stay. You can Undo afterwards.', 'Use this build', apply);
            return;
        }
        apply();
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
                /* "I already have this": the part stays in the checks but leaves the total and the cart. */
                var own = e.target.closest('[data-own]');
                if (own) {
                    var ownSlot = own.getAttribute('data-own');
                    if (build[ownSlot]) {
                        build[ownSlot].owned = !build[ownSlot].owned;
                        refresh();
                        var again = document.querySelector('[data-own="' + ownSlot + '"]');
                        if (again) again.focus();
                    }
                    return;
                }
                var addons = e.target.closest('[data-addons-toggle]');
                if (addons) {
                    addonsOpen = addons.getAttribute('aria-expanded') !== 'true';
                    renderSlots();
                    var toggle = document.querySelector('[data-addons-toggle]');
                    if (toggle) toggle.focus();
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
        [$('tmBuildToCart'), $('tmMobileCart')].forEach(function (btn) {
            if (btn) btn.addEventListener('click', function () { purchaseBuild('add_to_cart'); });
        });
        if ($('tmBuildBuyNow')) $('tmBuildBuyNow').addEventListener('click', function () { purchaseBuild('buy_now'); });
        if ($('tmShareBuild')) $('tmShareBuild').addEventListener('click', shareBuild);
        if ($('tmLoadBuild')) $('tmLoadBuild').addEventListener('click', openLoadDialog);
        if ($('tmReportBuild')) $('tmReportBuild').addEventListener('click', reportBuild);
        if ($('tmBudgetEdit')) $('tmBudgetEdit').addEventListener('click', editBudget);
        if ($('tmAutoBuild')) $('tmAutoBuild').addEventListener('click', openAutoBuildDialog);
        if ($('tmBudgetNote')) {
            $('tmBudgetNote').addEventListener('click', function (e) {
                if (e.target.closest('[data-budget-undo]') && budgetNotice) {
                    /* Keep the earlier amount as the shopper's own budget. */
                    setBudget(budgetNotice.prev, '');
                    return;
                }
                var tiers = guideTiers();
                if (e.target.closest('[data-budget-use]') && tiers) setBudget(Number(tiers.recommended), 'recommended');
            });
        }
        var purposeChips = $('bpPurposeChips');
        if (purposeChips) {
            purposeChips.addEventListener('click', function (e) {
                var chip = e.target.closest('[data-purpose]');
                if (chip) setPurpose(chip.getAttribute('data-purpose'));
            });
            /* Radio group keys: arrows move and select, Home / End jump to the ends. */
            purposeChips.addEventListener('keydown', function (e) {
                var chips = Array.prototype.slice.call(purposeChips.querySelectorAll('[data-purpose]'));
                var i = chips.indexOf(document.activeElement);
                if (i === -1) return;
                var next = null;
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = chips[(i + 1) % chips.length];
                else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = chips[(i - 1 + chips.length) % chips.length];
                else if (e.key === 'Home') next = chips[0];
                else if (e.key === 'End') next = chips[chips.length - 1];
                if (!next) return;
                e.preventDefault();
                next.focus();
                setPurpose(next.getAttribute('data-purpose'));
            });
        }
        if ($('tmCompareGo')) $('tmCompareGo').addEventListener('click', openCompareDialog);
        if ($('tmAskStore')) $('tmAskStore').addEventListener('click', askStore);
        if ($('bpStartersToggle')) {
            $('bpStartersToggle').addEventListener('click', function () {
                setStartersOpen(this.getAttribute('aria-expanded') !== 'true');
            });
        }
        if ($('bpStartersList')) {
            $('bpStartersList').addEventListener('click', function (e) {
                var use = e.target.closest('[data-starter]');
                if (use) useStarter(Number(use.getAttribute('data-starter')));
            });
        }
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
        /* Budget suggestions: the budget hint, purpose switches and the budget dialog use them. */
        loadBudgetGuide().then(function () { renderBudget(totalPrice()); }).catch(function () {});
        /* Starter builds: open on an empty builder, folded away once the shopper has parts. */
        setStartersOpen(filledCount() === 0);
        loadStarters();
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
