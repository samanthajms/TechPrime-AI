/**
 * Tech & Match — in-frame PC builder + product picker (no page redirects).
 */
(function () {
    'use strict';

    var TOTAL_SLOTS = 11;
    var SLOTS = [
        { id: 'processor', group: 'CORE COMPONENTS', label: 'PROCESSOR', select: 'Select Processor', icon: 'fa-microchip', step: 1, watts: 65 },
        { id: 'motherboard', group: 'CORE COMPONENTS', label: 'MOTHERBOARD', select: 'Select Motherboard', icon: 'fa-server', step: 1, watts: 40 },
        { id: 'memory', group: 'MEMORY & STORAGE', label: 'MEMORY', select: 'Select Memory', icon: 'fa-memory', step: 2, watts: 10 },
        { id: 'ssd', group: 'MEMORY & STORAGE', label: 'SSD', select: 'Select SSD', icon: 'fa-hdd', step: 4, watts: 5 },
        { id: 'ssd_sata', group: 'MEMORY & STORAGE', label: 'SSD (SATA)', select: 'Select SSD (SATA)', icon: 'fa-hdd', step: 4, watts: 5 },
        { id: 'hdd', group: 'MEMORY & STORAGE', label: 'HARD DISK', select: 'Select Hard Disk', icon: 'fa-database', step: 4, watts: 8 },
        { id: 'gpu', group: 'GRAPHICS & POWER', label: 'GRAPHICS CARD', select: 'Select Graphics Card', icon: 'fa-tv', step: 3, watts: 180 },
        { id: 'psu', group: 'GRAPHICS & POWER', label: 'POWER SUPPLY', select: 'Select Power Supply', icon: 'fa-plug', step: 5, watts: 0 },
        { id: 'case', group: 'CHASSIS & COOLING', label: 'CASE', select: 'Select Case', icon: 'fa-cube', step: 6, watts: 0 },
        { id: 'cooler', group: 'CHASSIS & COOLING', label: 'CPU COOLER', select: 'Select CPU Cooler', icon: 'fa-fan', step: 7, watts: 15 },
        { id: 'extras', group: 'EXTRAS', label: 'EXTRAS', select: 'Select Extra', icon: 'fa-plus-circle', step: 8, watts: 5 }
    ];

    var STEPS = [
        { id: 1, label: 'Core' },
        { id: 2, label: 'Memory' },
        { id: 3, label: 'Graphics' },
        { id: 4, label: 'Storage' },
        { id: 5, label: 'Power' },
        { id: 6, label: 'Case' },
        { id: 7, label: 'Cooling' },
        { id: 8, label: 'Extras' }
    ];

    var build = {};
    var activeStep = 1;
    var activeSlot = null;
    var searchTimer = null;
    var editingBuildId = 0;
    var pendingBuildName = '';

    function $(id) { return document.getElementById(id); }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function filledCount() {
        return SLOTS.reduce(function (n, s) { return n + (build[s.id] ? 1 : 0); }, 0);
    }

    function totalPrice() {
        return SLOTS.reduce(function (sum, s) {
            return sum + (build[s.id] ? Number(build[s.id].price || 0) : 0);
        }, 0);
    }

    function powerDraw() {
        return SLOTS.reduce(function (sum, s) {
            return sum + (build[s.id] ? Number(s.watts || 0) : 0);
        }, 0);
    }

    function buildScore() {
        var filled = filledCount();
        var base = Math.round((filled / TOTAL_SLOTS) * 70);
        var bonus = 0;
        if (build.processor && build.motherboard) bonus += 8;
        if (build.memory) bonus += 6;
        if (build.gpu) bonus += 8;
        if (build.psu) bonus += 4;
        if (build.case) bonus += 4;
        return Math.min(100, base + bonus);
    }

    function axisScore(keys) {
        var hit = 0;
        keys.forEach(function (k) { if (build[k]) hit++; });
        return keys.length ? hit / keys.length : 0;
    }

    function showBuilder() {
        var builder = $('tmBuilderView');
        var picker = $('tmPickerView');
        if (builder) builder.hidden = false;
        if (picker) picker.hidden = true;
        activeSlot = null;
    }

    function showPicker(slotId) {
        var builder = $('tmBuilderView');
        var picker = $('tmPickerView');
        if (builder) builder.hidden = true;
        if (picker) picker.hidden = false;
        activeSlot = slotId;
        var meta = SLOTS.find(function (s) { return s.id === slotId; });
        var title = $('tmPickerTitle');
        var sub = $('tmPickerSub');
        if (title) title.textContent = meta ? meta.label : 'Select Component';
        if (sub) sub.textContent = 'Choose a real EasyPC product for this slot. No page redirect.';
        var search = $('tmPickerSearch');
        if (search) search.value = '';
        loadProducts(slotId, '');
    }

    function renderSteps() {
        var el = $('tmSteps');
        if (!el) return;
        el.innerHTML = STEPS.map(function (st) {
            var related = SLOTS.filter(function (s) { return s.step === st.id; });
            var done = related.length && related.every(function (s) { return !!build[s.id]; });
            var cls = 'tm-step';
            if (st.id === activeStep) cls += ' is-active';
            else if (done) cls += ' is-done';
            return '<button type="button" class="' + cls + '" data-step="' + st.id + '">' +
                '<span class="tm-step-num">' + st.id + '</span>' +
                '<span class="tm-step-label">' + escapeHtml(st.label) + '</span></button>';
        }).join('');
    }

    function renderSlots() {
        var el = $('tmSlotList');
        if (!el) return;
        var html = '';
        var lastGroup = '';
        SLOTS.forEach(function (s) {
            if (s.group !== lastGroup) {
                html += '<div class="tm-section-label">' + escapeHtml(s.group) + '</div>';
                lastGroup = s.group;
            }
            var selected = build[s.id];
            var hint = selected
                ? (selected.name + ' · ' + peso(selected.price))
                : ('+ ' + s.select);
            html += '<div class="tm-slot' + (selected ? ' is-filled' : '') + '" data-slot="' + s.id + '" role="button" tabindex="0">' +
                '<span class="tm-slot-icon"><i class="fas ' + s.icon + '" aria-hidden="true"></i></span>' +
                '<span class="tm-slot-text">' +
                '<span class="tm-slot-name">' + escapeHtml(s.label) + '</span>' +
                '<span class="tm-slot-hint">' + escapeHtml(hint) + '</span>' +
                (selected
                    ? '<span class="tm-slot-actions">' +
                      '<button type="button" class="tm-change" data-change="' + s.id + '">Change</button>' +
                      '<button type="button" class="tm-remove" data-remove="' + s.id + '">Remove</button>' +
                      '</span>'
                    : '') +
                '</span></div>';
        });
        el.innerHTML = html;
    }

    function renderViz() {
        var pc = $('tmPcViz');
        if (!pc) return;
        pc.className = 'tm-pc';
        SLOTS.forEach(function (s) {
            if (build[s.id]) pc.classList.add('has-' + s.id);
        });
        var count = filledCount();
        var countEl = $('tmComponentCount');
        var bar = $('tmComponentBar');
        if (countEl) countEl.textContent = count + ' / ' + TOTAL_SLOTS + ' components';
        if (bar) bar.style.width = Math.round((count / TOTAL_SLOTS) * 100) + '%';
        var watts = powerDraw();
        var powerEl = $('tmPowerDraw');
        if (powerEl) powerEl.textContent = '~' + watts + 'W';
        var pbar = $('tmPowerBar');
        if (pbar) pbar.style.width = Math.min(100, Math.round((watts / 650) * 100)) + '%';
    }

    function renderRight() {
        var score = buildScore();
        var scoreEl = $('tmBuildScore');
        if (scoreEl) scoreEl.textContent = String(score);

        var sumCount = $('tmSummaryCount');
        var sumBar = $('tmSummaryBar');
        var sumTotal = $('tmSummaryTotal');
        var filled = filledCount();
        if (sumCount) sumCount.textContent = filled + ' / ' + TOTAL_SLOTS;
        if (sumBar) sumBar.style.width = Math.round((filled / TOTAL_SLOTS) * 100) + '%';
        if (sumTotal) sumTotal.textContent = peso(totalPrice());

        renderRadar();
    }

    function renderRadar() {
        var svg = $('tmRadarSvg');
        if (!svg) return;
        var axes = [
            { key: 'cpu', label: 'CPU', v: axisScore(['processor', 'motherboard', 'cooler']) },
            { key: 'ram', label: 'RAM', v: axisScore(['memory']) },
            { key: 'gpu', label: 'GPU', v: axisScore(['gpu']) },
            { key: 'ssd', label: 'SSD', v: axisScore(['ssd', 'ssd_sata', 'hdd']) },
            { key: 'psu', label: 'PSU', v: axisScore(['psu', 'case']) }
        ];
        var cx = 90, cy = 90, r = 58;
        var grid = '';
        for (var ring = 1; ring <= 3; ring++) {
            var pts = axes.map(function (_, i) {
                var ang = (-Math.PI / 2) + (i * 2 * Math.PI / axes.length);
                var rr = r * (ring / 3);
                return (cx + Math.cos(ang) * rr) + ',' + (cy + Math.sin(ang) * rr);
            }).join(' ');
            grid += '<polygon points="' + pts + '" fill="none" stroke="#e4e8ea" stroke-width="1"/>';
        }
        var dataPts = axes.map(function (a, i) {
            var ang = (-Math.PI / 2) + (i * 2 * Math.PI / axes.length);
            var rr = r * Math.max(0.08, a.v);
            return (cx + Math.cos(ang) * rr) + ',' + (cy + Math.sin(ang) * rr);
        }).join(' ');
        var labels = axes.map(function (a, i) {
            var ang = (-Math.PI / 2) + (i * 2 * Math.PI / axes.length);
            var lx = cx + Math.cos(ang) * (r + 18);
            var ly = cy + Math.sin(ang) * (r + 18);
            return '<text x="' + lx + '" y="' + ly + '" text-anchor="middle" dominant-baseline="middle" fill="#5b7c99" font-size="10" font-weight="700">' + a.label + '</text>';
        }).join('');
        svg.innerHTML = grid +
            '<polygon points="' + dataPts + '" fill="rgba(98,178,54,0.22)" stroke="#62b236" stroke-width="2"/>' +
            '<circle cx="' + cx + '" cy="' + cy + '" r="3" fill="#62b236"/>' +
            labels;
    }

    function refresh() {
        renderSteps();
        renderSlots();
        renderViz();
        renderRight();
    }

    function loadProducts(slotId, q) {
        var grid = $('tmProductGrid');
        if (!grid) return;
        grid.innerHTML = '<div class="tm-picker-loading">Loading products…</div>';
        var url = 'tech_match_api.php?slot=' + encodeURIComponent(slotId);
        if (q) url += '&q=' + encodeURIComponent(q);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (activeSlot !== slotId) return;
                var products = (data && data.products) ? data.products : [];
                if (!products.length) {
                    grid.innerHTML = '<div class="tm-picker-empty"><i class="fas fa-box-open" aria-hidden="true"></i><br><br>No matching products found for this component.<br>Try another slot or check inventory stock.</div>';
                    return;
                }
                grid.innerHTML = products.map(function (p) {
                    var out = Number(p.stock || 0) <= 0;
                    return '<article class="tm-product-card" data-id="' + p.id + '">' +
                        '<img src="' + escapeHtml(p.image || '') + '" alt="' + escapeHtml(p.name || '') + '">' +
                        '<h4>' + escapeHtml(p.name || '') + '</h4>' +
                        '<div class="tm-product-meta">' +
                        '<span class="tm-product-price">' + peso(p.price) + '</span>' +
                        '<span class="tm-product-stock' + (out ? ' out' : '') + '">' + (out ? 'Out of stock' : ('Stock: ' + p.stock)) + '</span>' +
                        '</div>' +
                        '<button type="button" class="tm-select-btn" data-select="' + p.id + '"' + (out ? ' disabled' : '') + '>Select</button>' +
                        '</article>';
                }).join('');
                grid._products = products;
            })
            .catch(function () {
                if (activeSlot !== slotId) return;
                grid.innerHTML = '<div class="tm-picker-empty">Unable to load products. Please try again.</div>';
            });
    }

    function selectProduct(product) {
        if (!activeSlot || !product) return;
        build[activeSlot] = {
            id: product.id,
            name: product.name,
            price: product.price,
            image: product.image,
            category: product.category
        };
        var meta = SLOTS.find(function (s) { return s.id === activeSlot; });
        if (meta) activeStep = meta.step;
        showBuilder();
        refresh();
    }

    function bind() {
        var steps = $('tmSteps');
        if (steps) {
            steps.addEventListener('click', function (e) {
                var btn = e.target.closest('.tm-step');
                if (!btn) return;
                activeStep = parseInt(btn.getAttribute('data-step'), 10) || 1;
                renderSteps();
            });
        }

        var list = $('tmSlotList');
        if (list) {
            list.addEventListener('click', function (e) {
                var remove = e.target.closest('[data-remove]');
                if (remove) {
                    e.preventDefault();
                    e.stopPropagation();
                    delete build[remove.getAttribute('data-remove')];
                    refresh();
                    return;
                }
                var change = e.target.closest('[data-change]');
                if (change) {
                    e.preventDefault();
                    e.stopPropagation();
                    showPicker(change.getAttribute('data-change'));
                    return;
                }
                var slot = e.target.closest('.tm-slot');
                if (!slot) return;
                showPicker(slot.getAttribute('data-slot'));
            });
            list.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var slot = e.target.closest('.tm-slot');
                if (!slot || e.target.closest('button')) return;
                e.preventDefault();
                showPicker(slot.getAttribute('data-slot'));
            });
        }

        var back = $('tmPickerBack');
        if (back) back.addEventListener('click', function () { showBuilder(); refresh(); });

        var search = $('tmPickerSearch');
        if (search) {
            search.addEventListener('input', function () {
                if (searchTimer) clearTimeout(searchTimer);
                var slot = activeSlot;
                var q = search.value.trim();
                searchTimer = setTimeout(function () {
                    if (slot) loadProducts(slot, q);
                }, 200);
            });
        }

        var grid = $('tmProductGrid');
        if (grid) {
            grid.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-select]');
                if (!btn || btn.disabled) return;
                var id = parseInt(btn.getAttribute('data-select'), 10);
                var products = grid._products || [];
                var product = products.find(function (p) { return Number(p.id) === id; });
                if (product) selectProduct(product);
            });
        }

        var addMissing = $('tmAddMissing');
        if (addMissing) {
            addMissing.addEventListener('click', function () {
                var next = SLOTS.find(function (s) { return !build[s.id]; });
                if (next) showPicker(next.id);
            });
        }

        var saveBtn = $('tmSaveBuild');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                openSaveNameModal();
            });
        }
    }

    function openSaveNameModal() {
        if (filledCount() <= 0) {
            if (typeof IAS_UI !== 'undefined') {
                IAS_UI.alert('Select at least one component before saving.', 'info');
            }
            return;
        }
        var existing = document.getElementById('tmSaveNameOverlay');
        if (existing) existing.remove();

        var isEdit = editingBuildId > 0;
        var overlay = document.createElement('div');
        overlay.id = 'tmSaveNameOverlay';
        overlay.className = 'tm-save-overlay';
        overlay.innerHTML =
            '<div class="tm-save-dialog" role="dialog" aria-modal="true" aria-labelledby="tmSaveTitle">' +
            '<button type="button" class="tm-save-x" aria-label="Close">&times;</button>' +
            '<h3 id="tmSaveTitle">' + (isEdit ? 'Update Your Build' : 'Save Your Build') + '</h3>' +
            '<p class="tm-save-lead">Give your Build a name:</p>' +
            '<input type="text" id="tmSaveNameInput" class="tm-save-input" maxlength="120" placeholder="e.g. My PC Build" autocomplete="off">' +
            '<div class="tm-save-error" id="tmSaveError" hidden>Please enter a name for your build.</div>' +
            '<div class="tm-save-actions">' +
            '<button type="button" class="tm-save-cancel" id="tmSaveCancel">Cancel</button>' +
            '<button type="button" class="tm-save-confirm" id="tmSaveConfirm">' + (isEdit ? 'Update Build' : 'Save Build') + '</button>' +
            '</div></div>';
        document.body.appendChild(overlay);

        var input = document.getElementById('tmSaveNameInput');
        var err = document.getElementById('tmSaveError');
        var close = function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); };
        overlay.querySelector('.tm-save-x').onclick = close;
        document.getElementById('tmSaveCancel').onclick = close;
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        if (input) {
            if (pendingBuildName) input.value = pendingBuildName;
            input.focus();
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('tmSaveConfirm').click();
                }
            });
        }
        document.getElementById('tmSaveConfirm').onclick = function () {
            var name = input ? String(input.value || '').trim() : '';
            if (!name) {
                if (err) { err.hidden = false; }
                if (input) input.focus();
                return;
            }
            if (err) err.hidden = true;
            persistBuild(name, close);
        };
    }

    function clearCurrentBuild() {
        build = {};
        activeStep = 1;
        activeSlot = null;
        editingBuildId = 0;
        pendingBuildName = '';
        try {
            localStorage.removeItem('easypc_tech_match_build');
            sessionStorage.removeItem('ep_tm_edit_id');
            sessionStorage.removeItem('ep_tm_load_name');
        } catch (e) {}
        showBuilder();
        refresh();
    }

    function persistBuild(name, onDone) {
        var csrf = window.EP_CSRF || '';
        var confirmBtn = document.getElementById('tmSaveConfirm');
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.textContent = editingBuildId > 0 ? 'Updating…' : 'Saving…';
        }
        var payload = {
            action: 'save',
            build_name: name,
            components: build,
            csrf_token: csrf
        };
        if (editingBuildId > 0) {
            payload.id = editingBuildId;
        }
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
                    if (res.status === 401) {
                        if (typeof IAS_UI !== 'undefined') {
                            IAS_UI.alert('Please log in to save your build.', 'info');
                        }
                    } else if (typeof IAS_UI !== 'undefined') {
                        IAS_UI.alert(data.message || 'Could not save your build.', 'error');
                    }
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = editingBuildId > 0 ? 'Update Build' : 'Save Build';
                    }
                    return;
                }
                var wasUpdate = !!data.updated;
                clearCurrentBuild();
                if (typeof onDone === 'function') onDone();
                if (typeof IAS_UI !== 'undefined') {
                    IAS_UI.alert(
                        wasUpdate
                            ? ('Build Updated Successfully!\n\n"' + name + '"\n\nYour changes have been saved. Your builder is now ready for a new PC build.')
                            : ('Build Saved Successfully!\n\n"' + name + '"\n\nYour build has been saved. Your builder is now ready for a new PC build.'),
                        'success'
                    );
                }
            })
            .catch(function () {
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not save your build.', 'error');
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = editingBuildId > 0 ? 'Update Build' : 'Save Build';
                }
            });
    }

    function applyLoadedBuild(components) {
        if (!components || typeof components !== 'object') return;
        build = {};
        Object.keys(components).forEach(function (k) {
            var item = components[k];
            if (!item || typeof item !== 'object') return;
            build[k] = {
                id: item.id,
                name: item.name,
                price: item.price,
                image: item.image || '',
                category: item.category || ''
            };
        });
        showBuilder();
        refresh();
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

    function init() {
        if (!$('tmBuilderView')) return;
        try {
            var saved = localStorage.getItem('easypc_tech_match_build');
            if (saved) build = JSON.parse(saved) || {};
        } catch (e) { build = {}; }
        bind();
        showBuilder();
        refresh();
        loadPendingBuild();
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
