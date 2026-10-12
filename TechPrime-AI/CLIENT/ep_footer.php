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
                <div class="ep-social-row">
                    <a href="https://www.facebook.com/EASYPCOneOasisPasigBranch" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                </div>
                <div class="ep-social-row">
                    <h5>Contact</h5>
                    <p>0917 625 5078</p>
                </div>
            </div>
            <div class="ep-footer-col">
                <h5>Explore</h5>
                <a href="index.php">Home</a>
                <a href="cart.php">Cart</a>
                <a href="<?php echo ($isLoggedIn ?? false) ? 'user_dashboard.php' : '../login.php'; ?>">My Orders</a>
            </div>
            <div class="ep-footer-col">
                <h5>Resources</h5>
                <a href="privacy_policy.php">Privacy Policy</a>
                <a href="<?php echo ($isLoggedIn ?? false) ? 'user_dashboard.php' : '../login.php'; ?>">My Account</a>
            </div>
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
