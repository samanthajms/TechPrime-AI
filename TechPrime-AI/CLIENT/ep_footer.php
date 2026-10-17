<?php
/**
 * ep_footer.php — Shared EasyPC footer for all CLIENT pages.
 * Variables expected:
 *   $isLoggedIn  (bool)
 *   $epShowPrimo (bool, set by ep_header.php) — adds the Primo chat widget
 */
?>
    <?php $epFootLoggedIn = !empty($isLoggedIn); ?>
    <footer class="ep-footer sf-footer full-width">
        <div class="sf-wrap sf-footer-grid">
            <div class="sf-footer-brand">
                <a href="index.php" class="sf-footer-logo"><img src="../assets/logo.png" alt="EasyPC home" width="137" height="32" loading="lazy"></a>
                <p class="sf-footer-tagline">Laptops, PC parts and accessories from EasyPC One Oasis in Rosario, Pasig City.</p>
                <ul class="sf-footer-contact">
                    <li><i class="fas fa-phone-alt" aria-hidden="true"></i><a href="tel:+639176255078">0917 625 5078</a></li>
                    <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i><a href="https://maps.app.goo.gl/ygnCyGTcDvdcJZxJ9" target="_blank" rel="noopener">One Oasis, Rosario, Pasig City<span class="sf-sr"> (opens Google Maps in a new tab)</span></a></li>
                    <li><i class="fab fa-facebook-f" aria-hidden="true"></i><a href="https://www.facebook.com/EASYPCOneOasisPasigBranch" target="_blank" rel="noopener">EasyPC One Oasis on Facebook<span class="sf-sr"> (opens in a new tab)</span></a></li>
                </ul>
            </div>
            <nav class="sf-footer-col" aria-label="Shop">
                <h2 class="sf-footer-head">Shop</h2>
                <ul>
                    <li><a href="shop.php">All products</a></li>
                    <li><a href="build_a_pc.php">Build a PC</a></li>
                    <li><a href="cart.php">Cart</a></li>
                    <li><a href="wishlist.php">Wishlist</a></li>
                </ul>
            </nav>
            <nav class="sf-footer-col" aria-label="Account">
                <h2 class="sf-footer-head">Account</h2>
                <ul>
                    <?php if ($epFootLoggedIn): ?>
                        <li><a href="user_dashboard.php">My orders</a></li>
                        <li><a href="saved_builds.php">Saved builds</a></li>
                        <li><a href="notifications.php">Notifications</a></li>
                        <li><a href="user_dashboard.php?settings=1">Settings</a></li>
                    <?php else: ?>
                        <li><a href="../login.php">Log in</a></li>
                        <li><a href="../register.php">Create an account</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <nav class="sf-footer-col" aria-label="Help">
                <h2 class="sf-footer-head">Help</h2>
                <ul>
                    <li><a href="privacy_policy.php">Privacy policy</a></li>
                    <li><a href="tel:+639176255078">Call the store</a></li>
                </ul>
            </nav>
        </div>
        <div class="sf-wrap sf-footer-bottom">
            <span>&copy; 2026 EasyPC One Oasis. All rights reserved.</span>
            <span class="sf-footer-pay"><i class="fas fa-money-bill-wave" aria-hidden="true"></i> Cash on delivery &middot; Online payment &middot; Prices include VAT</span>
        </div>
    </footer>

<?php if (!empty($epShowPrimo)) include __DIR__ . '/primo.php'; ?>
<?php if (empty($GLOBALS['ias_ui_alerts_loaded'])): $GLOBALS['ias_ui_alerts_loaded'] = true; // shared with ias_alert_footer() ?>
    <script src="../includes/ui_alerts.js"></script>
<?php endif; ?>
    <script src="ep_back.js"></script>
    <script src="storefront.js?v=sf-1"></script>
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

    </script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
