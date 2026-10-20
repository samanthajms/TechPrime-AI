/* storefront.js — behaviour for the storefront v2 components (storefront.css), loaded by ep_footer.php.
 *
 * Rails ([data-sf-rail] > [data-sf-rail-track]#id): the arrows in the section head
 * ([data-sf-rail-prev|next|arrows="<track id>"], rendered by sf_section_head()) page by the visible width,
 * disable at either end and stay hidden while everything already fits.
 */
(function () {
    'use strict';

    function initRails(root) {
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        (root || document).querySelectorAll('[data-sf-rail]').forEach(function (rail) {
            if (rail.hasAttribute('data-sf-rail-ready')) return;
            var track = rail.querySelector('[data-sf-rail-track]');
            if (!track || !track.id) return;
            rail.setAttribute('data-sf-rail-ready', '');
            var arrows = document.querySelector('[data-sf-rail-arrows="' + track.id + '"]');
            var prev = document.querySelector('[data-sf-rail-prev="' + track.id + '"]');
            var next = document.querySelector('[data-sf-rail-next="' + track.id + '"]');
            var ticking = false;

            function update() {
                ticking = false;
                var max = track.scrollWidth - track.clientWidth - 2;
                var atStart = track.scrollLeft <= 2;
                var atEnd = track.scrollLeft >= max;
                if (arrows) arrows.hidden = max <= 0;
                if (prev) prev.disabled = atStart;
                if (next) next.disabled = atEnd;
                rail.classList.toggle('has-prev', !atStart);
                rail.classList.toggle('has-next', !atEnd);
            }
            function onScroll() {
                if (!ticking) { ticking = true; requestAnimationFrame(update); }
            }
            function page(dir) {
                track.scrollBy({
                    left: dir * Math.max(track.clientWidth * 0.9, 160),
                    behavior: reduceMotion.matches ? 'auto' : 'smooth'
                });
            }
            if (prev) prev.addEventListener('click', function () { page(-1); });
            if (next) next.addEventListener('click', function () { page(1); });
            track.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('resize', onScroll);
            update();
        });
    }

    window.sfInitRails = initRails;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initRails(); });
    } else {
        initRails();
    }
})();
