<?php
/**
 * ep_footer.php — Shared EasyPC footer for all CLIENT pages.
 * Variables expected:
 *   $isLoggedIn  (bool)
 *   $epShowPrimo (bool, set by ep_header.php) — adds the Primo chat widget
 */
?>
    <footer class="ep-footer full-width">
        <div class="ep-footer-grid">
            <div class="ep-footer-brand">
                <div class="ep-footer-logo">
                    <img src="../assets/logo.png" alt="EasyPC" class="ep-footer-logo-img">
                </div>
                <p class="ep-footer-tagline">PCs, laptops, parts and accessories from the One Oasis branch.</p>
                <ul class="ep-footer-contact">
                    <li><i class="fas fa-phone-alt" aria-hidden="true"></i><a href="tel:+639176255078">0917 625 5078</a></li>
                    <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i><span>One Oasis, Rosario, Pasig City</span></li>
                </ul>
                <div class="ep-social-row">
                    <a href="https://www.facebook.com/EASYPCOneOasisPasigBranch" target="_blank" rel="noopener" aria-label="EasyPC One Oasis on Facebook"><i class="fab fa-facebook-f" aria-hidden="true"></i></a>
                </div>
            </div>
            <nav class="ep-footer-col" aria-label="Explore">
                <h5>Explore</h5>
                <a href="index.php">Home</a>
                <a href="cart.php">Cart</a>
                <a href="<?php echo ($isLoggedIn ?? false) ? 'user_dashboard.php' : '../login.php'; ?>">My Orders</a>
            </nav>
            <nav class="ep-footer-col" aria-label="Resources">
                <h5>Resources</h5>
                <a href="privacy_policy.php">Privacy Policy</a>
                <a href="<?php echo ($isLoggedIn ?? false) ? 'user_dashboard.php' : '../login.php'; ?>">My Account</a>
            </nav>
        </div>
        <div class="ep-footer-bottom">&copy; 2026 EASYPC One Oasis. All Rights Reserved.</div>
    </footer>

<?php if (!empty($epShowPrimo)) include __DIR__ . '/primo.php'; ?>
    <script src="../includes/ui_alerts.js"></script>
    <script src="ep_back.js"></script>
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
    </script>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
