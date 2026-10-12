<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
$isLoggedIn = isset($_SESSION['user_id']);
$activePage = 'privacy';
$pageTitle = 'Privacy Policy';
$searchQuery = '';
$bodyClass = 'ep-shop-page-body';

$legalSections = [
    ['id' => 'data-collection', 'icon' => 'fa-database', 'title' => 'Data Collection',
     'body' => 'We collect information you provide directly to us, such as your name, email address, physical address, and phone number when you register an account or place an order.'],
    ['id' => 'use-of-data', 'icon' => 'fa-sliders-h', 'title' => 'Use of Data',
     'body' => 'Your data is used to process orders, manage your account, and provide a secure shopping experience. We also use activity logs to monitor system security and prevent fraud.'],
    ['id' => 'security', 'icon' => 'fa-shield-alt', 'title' => 'Security Measures',
     'body' => 'We implement professional-grade security features including password hashing, Multi-Factor Authentication (MFA), and encrypted sessions to protect your information.'],
    ['id' => 'cookies', 'icon' => 'fa-cookie-bite', 'title' => 'Cookies',
     'body' => 'We use essential cookies to maintain your login session and store your shopping cart items. By using our site, you consent to our use of these technical cookies.'],
    ['id' => 'your-rights', 'icon' => 'fa-user-check', 'title' => 'Your Rights',
     'body' => 'You have the right to access, correct, or delete your personal data. Please contact our support team for any privacy-related requests.'],
];

/* Highlights the section index entry for the section being read. */
$extraScripts = <<<'JS'
<script>
(function () {
    var links = document.querySelectorAll('.ep-legal-toc a[href^="#"]');
    if (!links.length) return;
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function setActive(id) {
        links.forEach(function (a) {
            var on = a.getAttribute('href') === '#' + id;
            a.classList.toggle('is-active', on);
            if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
        });
    }

    links.forEach(function (a) {
        a.addEventListener('click', function (e) {
            var target = document.getElementById(a.getAttribute('href').slice(1));
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            history.replaceState(null, '', '#' + target.id);
            pinned = target.id;
            setActive(target.id);
        });
    });

    /* Active = last section whose top passed the line under the fixed header. A clicked
     * entry stays active until the user scrolls themselves, and the page bottom selects the
     * last section (short last sections can never reach the line). */
    var sections = document.querySelectorAll('.ep-legal-section');
    var pinned = null, ticking = false;
    function unpin() { pinned = null; }
    ['wheel', 'touchstart', 'keydown'].forEach(function (t) { window.addEventListener(t, unpin, { passive: true }); });
    window.addEventListener('scroll', function () {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () {
            ticking = false;
            if (pinned) return;
            var id = sections[0].id;
            sections.forEach(function (s) { if (s.getBoundingClientRect().top <= 160) id = s.id; });
            if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
                id = sections[sections.length - 1].id;
            }
            setActive(id);
        });
    }, { passive: true });
})();
</script>
JS;
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner ep-legal-page">
        <a href="index.php" class="ep-profile-back-arrow" data-ep-back aria-label="Back">&lt;</a>

        <header class="ep-wish-hero ep-legal-hero">
            <div class="ep-wish-hero-icon ep-notif-hero-icon" aria-hidden="true">
                <i class="fas fa-user-shield"></i>
            </div>
            <div class="ep-wish-hero-copy">
                <p class="ep-wish-kicker">Legal</p>
                <h1 class="ep-wish-title">Privacy Policy</h1>
                <p class="ep-wish-subtitle">Welcome to EasyPC. Your privacy is critically important to us. This policy explains how we collect, use, and protect your personal data.</p>
            </div>
            <span class="ep-legal-updated">
                <i class="far fa-calendar-alt" aria-hidden="true"></i>
                Last updated <time datetime="2026-05-05">May 5, 2026</time>
            </span>
        </header>

        <div class="ep-legal-layout">
            <nav class="ep-legal-toc" aria-label="On this page">
                <p class="ep-legal-toc-label">On this page</p>
                <ol>
                    <?php foreach ($legalSections as $i => $s): ?>
                        <li><a href="#<?php echo h($s['id']); ?>"<?php echo $i === 0 ? ' class="is-active" aria-current="true"' : ''; ?>>
                            <span class="ep-legal-toc-num"><?php echo $i + 1; ?></span><?php echo h($s['title']); ?>
                        </a></li>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <div class="ep-legal-content">
                <?php foreach ($legalSections as $i => $s): ?>
                    <section class="ep-legal-section" id="<?php echo h($s['id']); ?>" style="--i: <?php echo $i; ?>">
                        <div class="ep-legal-section-icon" aria-hidden="true"><i class="fas <?php echo h($s['icon']); ?>"></i></div>
                        <div class="ep-legal-section-copy">
                            <h2><span class="ep-legal-section-num"><?php echo $i + 1; ?>.</span> <?php echo h($s['title']); ?></h2>
                            <p><?php echo h($s['body']); ?></p>
                        </div>
                    </section>
                <?php endforeach; ?>

                <aside class="ep-legal-contact" style="--i: <?php echo count($legalSections); ?>">
                    <div>
                        <h2>Questions about your data?</h2>
                        <p>Reach the EasyPC One Oasis team for access, correction, or deletion requests.</p>
                    </div>
                    <div class="ep-legal-contact-actions">
                        <a class="ep-legal-contact-btn" href="tel:+639176255078"><i class="fas fa-phone-alt" aria-hidden="true"></i> 0917 625 5078</a>
                        <a class="ep-legal-contact-btn is-ghost" href="https://www.facebook.com/EASYPCOneOasisPasigBranch" target="_blank" rel="noopener"><i class="fab fa-facebook-f" aria-hidden="true"></i> Message us</a>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>
