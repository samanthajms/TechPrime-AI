/*
 * Cascading Province -> City/Municipality -> Barangay dropdowns (PSGC data).
 *
 *   <div class="ph-address" data-psgc="assets/data/psgc"
 *        data-province="Metro Manila" data-city="City of Pasig" data-barangay="Kapitolyo">
 *     <select data-ph="province" name="addr_province"></select>
 *     <select data-ph="city" name="addr_city"></select>
 *     <select data-ph="barangay" name="addr_barangay"></select>
 *   </div>
 *
 * Option values are PSGC names (validated server-side in includes/address_helpers.php).
 * data-* values pre-select a saved address. Every .ph-address on the page is
 * initialised on load; call PHAddress.init(el) for ones added later.
 */
(function () {
    'use strict';

    var cache = {};   // url -> Promise<json>

    function load(url) {
        if (!cache[url]) {
            cache[url] = fetch(url, { credentials: 'same-origin' }).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            }).catch(function (e) { delete cache[url]; throw e; });
        }
        return cache[url];
    }

    function fill(select, placeholder, items, selected) {
        select.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = placeholder;
        select.appendChild(ph);
        var found = false;
        items.forEach(function (it) {
            var o = document.createElement('option');
            o.value = it.name;
            o.textContent = it.name;
            if (it.code) o.dataset.code = it.code;
            if (selected && it.name === selected) { o.selected = true; found = true; }
            select.appendChild(o);
        });
        select.disabled = items.length === 0;
        return found;
    }

    function placeholderOnly(select, text) {
        fill(select, text, [], null);
    }

    // Our own change notifications (so page scripts can react to reloaded lists)
    // must not re-trigger the cascade handlers below.
    var internal = false;
    function fire(el) {
        internal = true;
        try { el.dispatchEvent(new Event('change', { bubbles: true })); }
        finally { internal = false; }
    }

    function init(root) {
        if (!root || root.dataset.phReady) return;
        root.dataset.phReady = '1';

        var base = (root.dataset.psgc || 'assets/data/psgc').replace(/\/$/, '');
        var prov = root.querySelector('[data-ph=province]');
        var city = root.querySelector('[data-ph=city]');
        var brgy = root.querySelector('[data-ph=barangay]');
        if (!prov || !city || !brgy) return;

        var provinces = [];
        var initial = {
            province: root.dataset.province || '',
            city: root.dataset.city || '',
            barangay: root.dataset.barangay || ''
        };

        function provinceByName(name) {
            for (var i = 0; i < provinces.length; i++) if (provinces[i].n === name) return provinces[i];
            return null;
        }

        function onProvince(preCity, preBrgy) {
            var p = provinceByName(prov.value);
            placeholderOnly(brgy, 'Select city first');
            if (!p) {
                placeholderOnly(city, 'Select province first');
            } else if (fill(city, 'Select city / municipality', p.cities.map(function (c) {
                return { name: c[0], code: c[1] };
            }), preCity)) {
                onCity(preBrgy);
            }
            // Let page scripts (progress/validation) see that city + barangay were reset.
            fire(city);
            fire(brgy);
        }

        function onCity(preBrgy) {
            var p = provinceByName(prov.value);
            var opt = city.options[city.selectedIndex];
            var code = opt && opt.dataset.code;
            if (!p || !code) {
                placeholderOnly(brgy, 'Select city first');
                return;
            }
            placeholderOnly(brgy, 'Loading barangays…');
            fire(brgy);
            var wantProv = prov.value, wantCity = city.value;
            load(base + '/barangays/' + p.c + '.json').then(function (map) {
                if (prov.value !== wantProv || city.value !== wantCity) return;   // changed meanwhile
                fill(brgy, 'Select barangay', (map[code] || []).map(function (n) { return { name: n }; }), preBrgy);
                fire(brgy);
            }).catch(function () {
                placeholderOnly(brgy, 'Could not load barangays — retry');
                brgy.disabled = false;
            });
        }

        placeholderOnly(prov, 'Loading provinces…');
        placeholderOnly(city, 'Select province first');
        placeholderOnly(brgy, 'Select city first');

        load(base + '/locations.json').then(function (data) {
            provinces = data.provinces || [];
            fill(prov, 'Select province', provinces.map(function (p) { return { name: p.n }; }), initial.province);
            onProvince(initial.city, initial.barangay);
            [prov, city, brgy].forEach(fire);
        }).catch(function () {
            placeholderOnly(prov, 'Could not load provinces — refresh the page');
        });

        prov.addEventListener('change', function () { if (!internal) onProvince(null, null); });
        city.addEventListener('change', function () { if (!internal) onCity(null); });
        brgy.addEventListener('focus', function () {
            // Retry after a failed barangay load.
            if (brgy.options.length === 1 && city.value) onCity(null);
        });
    }

    function initAll() {
        document.querySelectorAll('.ph-address').forEach(init);
    }

    window.PHAddress = { init: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
    else initAll();
})();
