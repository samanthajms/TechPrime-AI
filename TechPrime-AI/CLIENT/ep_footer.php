<?php
/**
 * ep_footer.php — Shared EasyPC footer for all CLIENT pages.
 * Variables expected:
 *   $isLoggedIn  (bool)
 */
?>
    <button class="messages-float-btn ep-chat-float" onclick="location.href='messages.php'">
        <i class="fas fa-comment-dots"></i>
    </button>

    <footer class="ep-footer full-width">
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
                <a href="products.php">All Products</a>
            </div>
            <div class="ep-footer-col">
                <h5>Explore</h5>
                <a href="index.php">Home</a>
                <a href="cart.php">Cart</a>
                <a href="user_dashboard.php">My Orders</a>
                <a href="messages.php">EasyFix Support</a>
            </div>
            <div class="ep-footer-col">
                <h5>Resources</h5>
                <a href="privacy_policy.php">Privacy Policy</a>
                <a href="<?php echo ($isLoggedIn ?? false) ? 'user_dashboard.php' : '../login.php'; ?>">My Account</a>
                <a href="messages.php">Help Center</a>
            </div>
        </div>
        <div class="ep-footer-bottom">&copy; 2026 EASYPC One Oasis. All Rights Reserved.</div>
    </footer>

    <script src="../includes/ui_alerts.js"></script>
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
