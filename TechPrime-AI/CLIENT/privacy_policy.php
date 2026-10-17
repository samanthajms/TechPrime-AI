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

/* 'body' is one paragraph per entry. */
$legalSections = [
    ['id' => 'data-collection', 'icon' => 'fa-database', 'title' => 'Information We Collect', 'body' => [
        'Account details: when you register we collect your first name, surname, age, mobile number, delivery address, email address and password. Your password is never stored as typed; we keep only a one-way encrypted (hashed) version of it.',
        'Orders: the products and quantities you buy, order totals, your chosen delivery address or store pickup, a contact number, and the order and payment status.',
        'Shopping activity: items in your cart, your wishlist, PC builds you save in Build a PC, your notifications, and an optional profile picture you upload.',
        'Reviews: the rating, comment, tags and any photos or videos you attach when you review a completed order.',
        'Primo conversations: the messages you send to our Primo shopping assistant and its replies.',
        'Security records: sign-in attempts, account lockouts and a log of important account actions.',
    ]],
    ['id' => 'use-of-data', 'icon' => 'fa-sliders-h', 'title' => 'How We Use Your Information', 'body' => [
        'We use your information to create and activate your account, keep your cart and saved builds across visits, process and prepare your orders for delivery or pickup, and send you notifications about your orders and payments.',
        'Your reviews help us improve our products and service. Activity and sign-in records are used to keep accounts secure, investigate problems and prevent fraud.',
        'We do not sell your personal information, and we do not use it for third-party advertising.',
    ]],
    ['id' => 'payments', 'icon' => 'fa-credit-card', 'title' => 'Payments', 'body' => [
        'We do not collect or store credit card, debit card, bank or e-wallet details on this website. Your orders record only the payment method used and whether the order has been paid.',
    ]],
    ['id' => 'primo', 'icon' => 'fa-robot', 'title' => 'Primo Shopping Assistant', 'body' => [
        'Primo answers questions about our products and helps you find parts. Your messages are processed by EasyPC\'s own assistant service and are not used to identify you outside your account.',
        'Primo chat history is saved to your account for 7 days so you can continue a conversation, and you can delete a conversation at any time. Please do not share passwords or other sensitive details in the chat.',
    ]],
    ['id' => 'sharing', 'icon' => 'fa-users', 'title' => 'Who Can See Your Information', 'body' => [
        'Only authorized EasyPC One Oasis staff can see your information, and each staff role sees only what it needs: for example, inventory staff handle your orders and read your reviews. If you post a review anonymously, your name is not shown with it.',
        'We rely on a small number of service providers to run this website: a secure cloud database that stores your account and order data, and an email service that sends your account activation email. They process your information only to provide these services to us.',
        'We may disclose information when required by law or by a lawful order of a government authority.',
    ]],
    ['id' => 'security', 'icon' => 'fa-shield-alt', 'title' => 'Security Measures', 'body' => [
        'Passwords are stored only in hashed form. New accounts must be activated through a link sent to the registered email address, and the link expires after 24 hours.',
        'An account is locked after 3 failed sign-in attempts and can only be unlocked by our team. You are signed out automatically after 15 minutes of inactivity, and your session is renewed every time you sign in.',
        'Forms are protected against cross-site request forgery, and access to every page and request is checked against your account role.',
    ]],
    ['id' => 'cookies', 'icon' => 'fa-cookie-bite', 'title' => 'Cookies and Browser Storage', 'body' => [
        'We use one essential session cookie to keep you signed in and to hold your cart while you browse. Your browser also remembers display preferences, such as dark mode and how the Build a PC list is shown.',
        'We do not use advertising or tracking cookies. Because these cookies are needed for the site to work, signing in and using the cart requires them.',
    ]],
    ['id' => 'retention', 'icon' => 'fa-history', 'title' => 'How Long We Keep Your Data', 'body' => [
        'Primo chat history is deleted after 7 days. Your account details, cart, wishlist and saved builds are kept while your account is active.',
        'Order, payment and activity records are kept for as long as we need them for business, accounting and security purposes, including after an account is closed.',
    ]],
    ['id' => 'your-rights', 'icon' => 'fa-user-check', 'title' => 'Your Rights', 'body' => [
        'Under the Data Privacy Act of 2012 (Republic Act No. 10173), you have the right to be informed about, access, correct and request the deletion of your personal data, and to object to its processing.',
        'You can update your name, age, mobile number, address, email and profile picture yourself in My Account, and delete your Primo conversations from the chat. For anything else, including closing your account, contact us using the details below.',
    ]],
    ['id' => 'changes', 'icon' => 'fa-file-alt', 'title' => 'Changes to This Policy', 'body' => [
        'We may update this policy when our services change. The date at the top of this page shows when it was last updated.',
    ]],
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
                <p class="ep-wish-subtitle">This policy explains what personal data EasyPC One Oasis Branch collects when you use this online store, how we use and protect it, and the choices you have.</p>
            </div>
            <span class="ep-legal-updated">
                <i class="far fa-calendar-alt" aria-hidden="true"></i>
                Last updated <time datetime="2026-10-13">October 13, 2026</time>
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
                            <?php foreach ($s['body'] as $para): ?>
                                <p><?php echo h($para); ?></p>
                            <?php endforeach; ?>
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
