<?php
/**
 * ep_footer.php — Shared EasyPC footer for all CLIENT pages.
 * Variables expected:
 *   $isLoggedIn  (bool)
 */
?>
    <footer class="ep-footer full-width">
        <div class="ep-newsletter-bar">
            <div>
                <h4>Join Our Newsletter</h4>
                <p>Get the latest deals &amp; updates</p>
            </div>
            <form class="ep-newsletter-form" action="#" method="POST" onsubmit="return false;">
                <input type="email" name="newsletter_email" placeholder="Enter your email" required>
                <button type="submit">Subscribe</button>
            </form>
        </div>
        <div class="ep-footer-grid">
            <div class="ep-footer-brand">
                <div class="ep-footer-logo">
                    <img src="../assets/logo.png" alt="EasyPC" class="ep-footer-logo-img">
                </div>
                <div class="ep-social-row">
                    <a href="#" aria-label="X"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="ep-footer-col">
                <h5>Shop</h5>
                <a href="category.php?type=Desktop">Desktop</a>
                <a href="category.php?type=Laptops">Laptop</a>
                <a href="category.php?type=Accessories">Accessories</a>
                <a href="shop.php">Shop Now</a>
            </div>
            <div class="ep-footer-col">
                <h5>Explore</h5>
                <a href="index.php">Home</a>
                <a href="cart.php">Cart</a>
                <a href="user_dashboard.php">My Orders</a>
            </div>
            <div class="ep-footer-col">
                <h5>Resources</h5>
                <a href="privacy_policy.php">Privacy Policy</a>
                <a href="<?php echo ($isLoggedIn ?? false) ? 'user_dashboard.php' : '../login.php'; ?>">My Account</a>
            </div>
        </div>
        <div class="ep-footer-bottom">&copy; 2026 EASYPC One Oasis. All Rights Reserved.</div>
    </footer>

    <div id="epProductModal" class="ep-pvm" hidden>
        <div class="ep-pvm-overlay" data-pvm-close></div>
        <div class="ep-pvm-panel" role="dialog" aria-modal="true" aria-labelledby="epPvmTitle">
            <button type="button" class="ep-pvm-close" data-pvm-close aria-label="Close">&times;</button>
            <div id="epPvmBody" class="ep-pvm-body"></div>
        </div>
    </div>

    <script src="../includes/ui_alerts.js"></script>
    <script>
        window.EP_OPEN_PRODUCT_ID = <?php echo (int)($openProductModalId ?? 0); ?>;
        window.EP_PVM_CSRF = <?php echo json_encode(generateCsrfToken()); ?>;
    </script>
    <script>
        // ── Nav dropdown toggle ────────────────────────────────────────────
        function epToggleDropdown(btn) {
            const menu = btn.nextElementSibling;
            const isOpen = menu.classList.contains('open');
            document.querySelectorAll('.ep-dropdown-menu.open').forEach(m => m.classList.remove('open'));
            if (!isOpen) menu.classList.add('open');
        }
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.ep-nav-dropdown')) {
                document.querySelectorAll('.ep-dropdown-menu.open').forEach(m => m.classList.remove('open'));
            }
        });

        // ── Carousel scroll helper ─────────────────────────────────────────
        function epScroll(id, dir) {
            const row = document.getElementById(id);
            if (!row) return;
            row.scrollBy({ left: dir * 320, behavior: 'smooth' });
        }

        /* ---- Client product view modal ---- */
        (function () {
            var root = document.getElementById('epProductModal');
            var bodyEl = document.getElementById('epPvmBody');
            if (!root || !bodyEl) return;
            var csrf = window.EP_PVM_CSRF || '';
            var currentId = 0;

            function escapeHtml(str) {
                return String(str || '').replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }
            function productIdFromHref(href) {
                if (!href) return 0;
                try {
                    var u = new URL(href, window.location.href);
                    var path = (u.pathname || '').split('/').pop() || '';
                    if (path.toLowerCase() !== 'products.php') return 0;
                    return parseInt(u.searchParams.get('id'), 10) || 0;
                } catch (err) {
                    return 0;
                }
            }
            function closeModal() {
                root.hidden = true;
                document.body.classList.remove('ep-pvm-open');
                currentId = 0;
            }
            function openShell() {
                root.hidden = false;
                document.body.classList.add('ep-pvm-open');
            }
            function renderProduct(p) {
                currentId = p.id;
                var badges = [];
                if (p.category) badges.push('<span class="ep-pvm-badge">' + escapeHtml(p.category) + '</span>');
                if (p.sub_category && p.sub_category !== p.category) {
                    badges.push('<span class="ep-pvm-badge">' + escapeHtml(p.sub_category) + '</span>');
                }
                if (p.brand) badges.push('<span class="ep-pvm-badge ep-pvm-badge-brand">' + escapeHtml(p.brand) + '</span>');
                var meta = badges.length ? '<div class="ep-pvm-meta">' + badges.join('') + '</div>' : '';
                var desc = p.description
                    ? '<div class="ep-pvm-desc">' + escapeHtml(p.description).replace(/\n/g, '<br>') + '</div>'
                    : '';
                var specParts = [];
                if (p.sku) specParts.push('<li><span>SKU</span><strong>' + escapeHtml(p.sku) + '</strong></li>');
                if (p.seller_name) specParts.push('<li><span>Store</span><strong>' + escapeHtml(p.seller_name) + '</strong></li>');
                if (p.specifications) specParts.push('<li class="ep-pvm-spec-block"><span>Specifications</span><strong>' + escapeHtml(p.specifications).replace(/\n/g, '<br>') + '</strong></li>');
                var specs = specParts.length
                    ? '<div class="ep-pvm-specs"><h3>Specifications</h3><ul>' + specParts.join('') + '</ul></div>'
                    : '';
                var wishOn = !!p.in_wishlist;
                bodyEl.innerHTML =
                    '<div class="ep-pvm-media"><div class="ep-pvm-media-frame"><img src="' + escapeHtml(p.image) + '" alt="' + escapeHtml(p.name) + '"></div></div>' +
                    '<div class="ep-pvm-info">' +
                    '<h2 id="epPvmTitle" class="ep-pvm-name">' + escapeHtml(p.name) + '</h2>' +
                    meta +
                    desc +
                    '<div class="ep-pvm-price">' + escapeHtml(p.price_fmt) + '</div>' +
                    '<div class="ep-pvm-stock' + (p.in_stock ? '' : ' is-out') + '"><span class="ep-pvm-stock-dot" aria-hidden="true"></span>' + escapeHtml(p.stock_label) + '</div>' +
                    specs +
                    '</div>' +
                    '<div class="ep-pvm-actions">' +
                    '<button type="button" class="ep-btn ep-btn-primary" data-pvm-cart>Add to Cart</button>' +
                    '<button type="button" class="ep-btn ep-pvm-wish' + (wishOn ? ' is-on' : '') + '" data-pvm-wish>' +
                    '<i class="' + (wishOn ? 'fas' : 'far') + ' fa-heart" aria-hidden="true"></i> ' +
                    (wishOn ? 'In Wishlist' : 'Wishlist') + '</button>' +
                    '</div>';
            }
            window.epOpenProductModal = function (id) {
                id = parseInt(id, 10) || 0;
                if (id <= 0) return;
                currentId = id;
                openShell();
                bodyEl.innerHTML = '<div class="ep-pvm-loading">Loading product…</div>';
                fetch('product_view.php?id=' + encodeURIComponent(String(id)), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (currentId !== id) return;
                        if (data && data.csrf) csrf = data.csrf;
                        if (!data || !data.ok || !data.product) {
                            bodyEl.innerHTML = '<div class="ep-pvm-loading">This product is not available.</div>';
                            return;
                        }
                        renderProduct(data.product);
                    })
                    .catch(function () {
                        bodyEl.innerHTML = '<div class="ep-pvm-loading">Could not load this product.</div>';
                    });
            };

            root.addEventListener('click', function (e) {
                if (e.target.closest('[data-pvm-close]')) {
                    closeModal();
                    return;
                }
                if (e.target.closest('[data-pvm-cart]')) {
                    if (!currentId) return;
                    var cartBtn = e.target.closest('[data-pvm-cart]');
                    cartBtn.disabled = true;
                    var body = new URLSearchParams();
                    body.set('ajax', '1');
                    body.set('add_to_cart', '1');
                    body.set('product_id', String(currentId));
                    body.set('csrf_token', csrf);
                    fetch('products.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                        body: body.toString()
                    }).then(function (r) { return r.json(); })
                      .then(function (data) {
                          cartBtn.disabled = false;
                          if (data && data.ok) {
                              if (data.cart && typeof window.epUpdateCartPreview === 'function') {
                                  window.epUpdateCartPreview(data.cart);
                              }
                              if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Added to cart!', 'success');
                          } else if (typeof IAS_UI !== 'undefined') {
                              IAS_UI.alert('Could not add this product to cart.', 'error', 0);
                          }
                      })
                      .catch(function () { cartBtn.disabled = false; });
                    return;
                }
                if (e.target.closest('[data-pvm-wish]')) {
                    if (!currentId) return;
                    var wishBtn = e.target.closest('[data-pvm-wish]');
                    wishBtn.disabled = true;
                    var wbody = new URLSearchParams();
                    wbody.set('ajax', '1');
                    wbody.set('toggle_wishlist', '1');
                    wbody.set('product_id', String(currentId));
                    wbody.set('csrf_token', csrf);
                    fetch('wishlist.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
                        body: wbody.toString()
                    }).then(function (r) { return r.json(); })
                      .then(function (data) {
                          wishBtn.disabled = false;
                          if (!data || !data.ok) return;
                          var on = !!data.in_wishlist;
                          wishBtn.classList.toggle('is-on', on);
                          wishBtn.innerHTML = '<i class="' + (on ? 'fas' : 'far') + ' fa-heart" aria-hidden="true"></i> ' +
                              (on ? 'In Wishlist' : 'Wishlist');
                          if (!on && /wishlist\.php/i.test(window.location.pathname)) {
                              closeModal();
                              window.location.reload();
                          }
                      })
                      .catch(function () { wishBtn.disabled = false; });
                }
            });

            document.addEventListener('click', function (e) {
                if (e.defaultPrevented) return;
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                if (document.body.classList.contains('ep-cart-layout')) return;
                if (e.target.closest('.cart-item-row, #cartItems, .ep-cart-dropdown')) return;
                var a = e.target.closest('a[href]');
                if (!a || root.contains(a)) return;
                var id = productIdFromHref(a.getAttribute('href'));
                if (!id) return;
                e.preventDefault();
                window.epOpenProductModal(id);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !root.hidden) closeModal();
            });

            if (window.EP_OPEN_PRODUCT_ID) {
                window.epOpenProductModal(window.EP_OPEN_PRODUCT_ID);
            }
        })();
    </script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
