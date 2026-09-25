<?php
/**
 * ep_header.php — Shared EasyPC header & nav for all CLIENT pages.
 *
 * Expected: $isLoggedIn (bool), $activePage (string), optional $searchQuery, $pageTitle, $bodyClass
 * Home-only: $isHomePage = true
 * Category pages: $categoryHeroTitle (string), $currentCategory (string)
 */
if (!function_exists('ias_inventory_allowed_categories')) {
    require_once __DIR__ . '/../includes/product_categories.php';
}
$currentCategory = $currentCategory ?? '';
$epWishCount = 0;

$searchQuery = $searchQuery ?? '';
$isHomePage  = ($activePage ?? '') === 'home' || !empty($isHomePage);
$bodyClass   = $bodyClass ?? '';

if (!isset($epCartPreview)) {
    require_once __DIR__ . '/../includes/client_helpers.php';
    if (!empty($_SESSION['user_id']) && isset($db)) {
        ep_ensure_session_cart($db);
    } elseif (!empty($_SESSION['user_id'])) {
        ep_ensure_session_cart(getDbConnection());
    }
    $epCartPreview = ep_get_cart_preview($db ?? getDbConnection());
}
if (function_exists('ep_ensure_session_wishlist')) {
    $wishDb = $db ?? (function_exists('getDbConnection') ? getDbConnection() : null);
    if ($wishDb) {
        ep_ensure_session_wishlist($wishDb);
    }
    $epWishCount = count(ep_wishlist_ids());
}
$epCartItems  = $epCartPreview['items'];
$epCartTotal  = $epCartPreview['total'];
$epCartCount  = $epCartPreview['count'];

$epNotifItems = [];
$epNotifUnread = 0;
$epNotifCsrf = '';
if (!empty($isLoggedIn) && !empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../includes/inventory_alerts.php';
    $notifDb = $db ?? getDbConnection();
    $pack = inv_user_notifications($notifDb, (int)$_SESSION['user_id'], 30);
    $epNotifItems = $pack['items'] ?? [];
    $epNotifUnread = (int)($pack['unread'] ?? 0);
    $epNotifCsrf = generateCsrfToken();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($pageTitle)): ?>
        <title><?php echo h($pageTitle); ?> | EasyPC</title>
    <?php else: ?>
        <title>EasyPC</title>
    <?php endif; ?>
    <link rel="stylesheet" href="styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body class="ep-body <?php echo h($bodyClass); ?>">

<header class="top-header ep-header full-width">
<div class="ep-header-main">
    <div class="logo ep-logo" onclick="location.href='index.php'">
        <img src="../assets/logo.png" alt="EasyPC" class="ep-logo-img">
    </div>

    <div class="search-wrap" id="epSearchWrap">
        <form action="search.php" method="GET" id="epSearchForm" autocomplete="off">
            <input id="epSearchInput" name="q" type="text" placeholder="Search products..."
                   value="<?php echo h($searchQuery); ?>" aria-label="Search products"
                   aria-autocomplete="list" aria-controls="epSearchSuggest" aria-expanded="false"
                   autocomplete="off">
            <button type="submit" class="search-icon" aria-label="Search"><i class="fas fa-search"></i></button>
        </form>
        <div id="epSearchSuggest" class="ep-search-suggest" role="listbox" hidden></div>
    </div>

    <nav class="ep-nav-actions" aria-label="Primary">
        <a href="index.php"
           class="ep-nav-item<?php echo ($activePage ?? '') === 'home' ? ' active' : ''; ?>"
           <?php echo ($activePage ?? '') === 'home' ? 'aria-current="page"' : ''; ?>>
            <span class="ep-nav-item-icon"><i class="fas fa-home" aria-hidden="true"></i></span>
            <span class="ep-nav-item-label">Home</span>
        </a>

        <a href="shop.php"
           class="ep-nav-item<?php echo ($activePage ?? '') === 'shop' ? ' active' : ''; ?>"
           <?php echo ($activePage ?? '') === 'shop' ? 'aria-current="page"' : ''; ?>>
            <span class="ep-nav-item-icon"><i class="fas fa-store" aria-hidden="true"></i></span>
            <span class="ep-nav-item-label">Shop Now</span>
        </a>

        <a href="wishlist.php"
           class="ep-nav-item<?php echo ($activePage ?? '') === 'wishlist' ? ' active' : ''; ?>"
           <?php echo ($activePage ?? '') === 'wishlist' ? 'aria-current="page"' : ''; ?>>
            <span class="ep-nav-item-icon">
                <i class="far fa-heart" aria-hidden="true"></i>
                <?php if ($epWishCount > 0): ?>
                    <span class="badge"><?php echo (int)$epWishCount; ?></span>
                <?php endif; ?>
            </span>
            <span class="ep-nav-item-label">Wishlist</span>
        </a>

        <div class="ep-cart-wrap" id="epCartWrap">
            <button id="cartBtn" type="button" class="ep-nav-item ep-cart-trigger"
                    aria-haspopup="true" aria-expanded="false" aria-controls="epCartDropdown">
                <span class="ep-nav-item-icon">
                    <i class="fas fa-shopping-bag" aria-hidden="true"></i>
                    <?php if ($epCartCount > 0): ?>
                        <span class="badge"><?php echo (int)$epCartCount; ?></span>
                    <?php endif; ?>
                </span>
                <span class="ep-nav-item-label">Cart</span>
            </button>
            <div id="epCartDropdown" class="ep-cart-dropdown" role="menu" aria-label="Cart preview">
                <?php if (!empty($epCartItems)): ?>
                    <ul class="ep-cart-dropdown-list">
                        <?php foreach ($epCartItems as $ci): ?>
                            <li>
                                <span class="ep-cart-item-name"><?php echo h($ci['name']); ?></span>
                                <span class="ep-cart-item-meta">×<?php echo (int)$ci['qty']; ?> · ₱<?php echo number_format($ci['subtotal'], 2); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="ep-cart-dropdown-total">
                        <span>Total</span>
                        <strong>₱<?php echo number_format($epCartTotal, 2); ?></strong>
                    </div>
                    <a href="checkout.php" class="ep-btn ep-btn-primary ep-cart-checkout-btn">Checkout</a>
                    <a href="cart.php" class="ep-cart-view-link">View full cart</a>
                <?php else: ?>
                    <p class="ep-cart-empty">Your cart is empty.</p>
                    <a href="shop.php" class="ep-cart-view-link">Browse products</a>
                <?php endif; ?>
            </div>
        </div>

        <button id="notifBtn" type="button" class="ep-nav-item"
                onclick="document.getElementById('epNotifPanel').classList.toggle('hidden')"
                aria-haspopup="true" aria-controls="epNotifPanel">
            <span class="ep-nav-item-icon">
                <i class="far fa-bell" aria-hidden="true"></i>
                <?php if ($epNotifUnread > 0): ?>
                    <span class="badge ep-notif-badge"><?php echo $epNotifUnread > 99 ? '99+' : (int)$epNotifUnread; ?></span>
                <?php endif; ?>
            </span>
            <span class="ep-nav-item-label">Notifications</span>
        </button>

        <a href="<?php echo $isLoggedIn ? 'build_a_pc.php' : '../login.php'; ?>"
           class="ep-nav-item<?php echo (($activePage ?? '') === 'build_a_pc' || ($activePage ?? '') === 'saved_builds') ? ' active' : ''; ?>"
           <?php echo (($activePage ?? '') === 'build_a_pc' || ($activePage ?? '') === 'saved_builds') ? 'aria-current="page"' : ''; ?>>
            <span class="ep-nav-item-icon"><i class="fas fa-desktop" aria-hidden="true"></i></span>
            <span class="ep-nav-item-label">Build a PC</span>
        </a>

        <button id="profileBtn" type="button" class="ep-nav-item"
                onclick="location.href='<?php echo $isLoggedIn ? 'user_dashboard.php' : '../login.php'; ?>'">
            <span class="ep-nav-item-icon"><i class="far fa-user" aria-hidden="true"></i></span>
            <span class="ep-nav-item-label">My Profile</span>
        </button>
    </nav>
</div>

    <div id="epNotifPanel" class="notifications-panel hidden">
        <div class="ep-notif-head">
            <strong>Notifications</strong>
        </div>
        <?php if (empty($isLoggedIn)): ?>
            <p class="ep-notif-empty">Log in to see your notifications.</p>
        <?php elseif (empty($epNotifItems)): ?>
            <p class="ep-notif-empty">No notifications yet.</p>
        <?php else: ?>
            <ul class="ep-notif-list" id="epNotifList">
                <?php foreach ($epNotifItems as $n):
                    $nRead = (int)($n['is_read'] ?? 0) === 1;
                    $when = function_exists('inv_relative_time')
                        ? inv_relative_time((string)$n['created_at'])
                        : (string)($n['created_at'] ?? '');
                    ?>
                <li class="ep-notif-item<?php echo $nRead ? ' is-read' : ' is-unread'; ?>"
                    data-id="<?php echo (int)$n['id']; ?>">
                    <div class="ep-notif-body">
                        <span class="ep-notif-msg"><?php echo h((string)$n['message']); ?></span>
                        <span class="ep-notif-time"><?php echo h($when); ?></span>
                    </div>
                    <div class="ep-notif-actions">
                        <?php if (!$nRead): ?>
                        <button type="button" class="ep-notif-read" title="Mark as read" data-action="read">Read</button>
                        <?php endif; ?>
                        <button type="button" class="ep-notif-remove" title="Remove" data-action="delete">&times;</button>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</header>

<script>
(function () {
    function epSetHeaderOffset() {
        var header = document.querySelector('.ep-header');
        var h = header ? header.offsetHeight : 0;
        document.body.style.paddingTop = h + 'px';
    }
    epSetHeaderOffset();
    window.addEventListener('resize', epSetHeaderOffset);

    var wrap = document.getElementById('epCartWrap');
    var trigger = document.getElementById('cartBtn');
    if (wrap && trigger) {
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = wrap.classList.toggle('open');
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        wrap.addEventListener('mouseenter', function () {
            wrap.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
        });
        wrap.addEventListener('mouseleave', function () {
            wrap.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#epCartWrap')) {
                wrap.classList.remove('open');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* Update header Cart badge + dropdown from cart preview JSON (no page reload). */
    window.epUpdateCartPreview = function (preview) {
        preview = preview || { items: [], total: 0, count: 0 };
        var cartWrap = document.getElementById('epCartWrap');
        var cartBtn = document.getElementById('cartBtn');
        var dropdown = document.getElementById('epCartDropdown');
        if (!cartWrap || !dropdown) return;

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
        function money(n) {
            return Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        var icon = cartBtn ? cartBtn.querySelector('.ep-nav-item-icon') : null;
        if (icon) {
            var badge = icon.querySelector('.badge');
            var count = parseInt(preview.count, 10) || 0;
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'badge';
                    icon.appendChild(badge);
                }
                badge.textContent = String(count);
            } else if (badge) {
                badge.remove();
            }
        }

        var items = Array.isArray(preview.items) ? preview.items : [];
        var html = '';
        if (items.length) {
            html += '<ul class="ep-cart-dropdown-list">';
            items.forEach(function (ci) {
                html += '<li>' +
                    '<span class="ep-cart-item-name">' + escapeHtml(ci.name || '') + '</span>' +
                    '<span class="ep-cart-item-meta">×' + (parseInt(ci.qty, 10) || 0) +
                    ' · ₱' + money(ci.subtotal) + '</span>' +
                    '</li>';
            });
            html += '</ul>';
            html += '<div class="ep-cart-dropdown-total"><span>Total</span><strong>₱' +
                money(preview.total) + '</strong></div>';
            html += '<a href="checkout.php" class="ep-btn ep-btn-primary ep-cart-checkout-btn">Checkout</a>';
            html += '<a href="cart.php" class="ep-cart-view-link">View full cart</a>';
        } else {
            html = '<p class="ep-cart-empty">Your cart is empty.</p>' +
                '<a href="shop.php" class="ep-cart-view-link">Browse products</a>';
        }
        dropdown.innerHTML = html;
    };

    /* ---- Search recommendations (existing search bar) ---- */
    var searchInput = document.getElementById('epSearchInput');
    var searchSuggest = document.getElementById('epSearchSuggest');
    var searchForm = document.getElementById('epSearchForm');
    var searchWrap = document.getElementById('epSearchWrap');
    if (searchInput && searchSuggest && searchForm) {
        var suggestTimer = null;
        var suggestAbort = null;
        var activeIdx = -1;

        function hideSuggest() {
            searchSuggest.hidden = true;
            searchSuggest.innerHTML = '';
            searchInput.setAttribute('aria-expanded', 'false');
            activeIdx = -1;
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function highlightMatch(label, q) {
            var safe = escapeHtml(label);
            var qi = String(q || '').trim();
            if (!qi) return safe;
            try {
                var re = new RegExp('(' + qi.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
                return safe.replace(re, '<mark>$1</mark>');
            } catch (e) {
                return safe;
            }
        }

        function renderSuggest(items, q) {
            if (!items || !items.length) {
                hideSuggest();
                return;
            }
            activeIdx = -1;
            searchSuggest.innerHTML = items.map(function (item, i) {
                return '<button type="button" class="ep-search-suggest-item" role="option" data-idx="' + i + '" data-label="' + escapeHtml(item.label) + '">' +
                    '<i class="fas fa-search" aria-hidden="true"></i>' +
                    '<span>' + highlightMatch(item.label, q) + '</span>' +
                    '</button>';
            }).join('');
            searchSuggest.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
        }

        function runSearch(term) {
            var t = String(term || '').trim();
            if (!t) return;
            hideSuggest();
            window.location.href = 'search.php?q=' + encodeURIComponent(t);
        }

        function fetchSuggest(q) {
            if (suggestAbort && suggestAbort.abort) {
                try { suggestAbort.abort(); } catch (e) {}
            }
            var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            suggestAbort = controller;
            var url = 'search_suggest.php?q=' + encodeURIComponent(q);
            fetch(url, { signal: controller ? controller.signal : undefined, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (searchInput.value.trim() !== q) return;
                    renderSuggest((data && data.suggestions) || [], q);
                })
                .catch(function () { /* ignore abort/network */ });
        }

        searchInput.addEventListener('input', function () {
            var q = searchInput.value.trim();
            if (suggestTimer) clearTimeout(suggestTimer);
            if (!q) {
                hideSuggest();
                return;
            }
            suggestTimer = setTimeout(function () { fetchSuggest(q); }, 180);
        });

        searchInput.addEventListener('keydown', function (e) {
            var items = searchSuggest.querySelectorAll('.ep-search-suggest-item');
            if (e.key === 'Escape') {
                hideSuggest();
                return;
            }
            if (searchSuggest.hidden || !items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = Math.min(items.length - 1, activeIdx + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = Math.max(0, activeIdx - 1);
            } else if (e.key === 'Enter' && activeIdx >= 0 && items[activeIdx]) {
                e.preventDefault();
                runSearch(items[activeIdx].getAttribute('data-label'));
                return;
            } else {
                return;
            }
            items.forEach(function (el, i) {
                el.classList.toggle('active', i === activeIdx);
            });
        });

        searchSuggest.addEventListener('mousedown', function (e) {
            var btn = e.target.closest('.ep-search-suggest-item');
            if (!btn) return;
            e.preventDefault();
            runSearch(btn.getAttribute('data-label'));
        });

        searchForm.addEventListener('submit', function () {
            hideSuggest();
        });

        document.addEventListener('click', function (e) {
            if (!searchWrap.contains(e.target)) hideSuggest();
        });
    }

    /* ---- Persistent notifications (DB-backed) ---- */
    var notifList = document.getElementById('epNotifList');
    var notifCsrf = <?php echo json_encode($epNotifCsrf ?? ''); ?>;
    if (notifList && notifCsrf) {
        function postClientNotif(action, id) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('csrf_token', notifCsrf);
            body.set('id', String(id));
            return fetch('client_notif_api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).catch(function () { return null; });
        }
        function refreshNotifBadge() {
            var badge = document.querySelector('#notifBtn .ep-notif-badge');
            var unread = notifList.querySelectorAll('.ep-notif-item.is-unread').length;
            var icon = document.querySelector('#notifBtn .ep-nav-item-icon');
            if (unread <= 0) {
                if (badge) badge.remove();
                return;
            }
            if (!badge && icon) {
                badge = document.createElement('span');
                badge.className = 'badge ep-notif-badge';
                icon.appendChild(badge);
            }
            if (badge) badge.textContent = unread > 99 ? '99+' : String(unread);
        }
        notifList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            e.stopPropagation();
            var item = btn.closest('.ep-notif-item');
            if (!item) return;
            var id = item.getAttribute('data-id');
            var action = btn.getAttribute('data-action');
            postClientNotif(action, id).then(function (data) {
                if (!data || !data.ok) return;
                if (action === 'delete') {
                    item.remove();
                    if (!notifList.querySelector('.ep-notif-item')) {
                        notifList.outerHTML = '<p class="ep-notif-empty">No notifications yet.</p>';
                    }
                } else if (action === 'read') {
                    item.classList.remove('is-unread');
                    item.classList.add('is-read');
                    btn.remove();
                }
                refreshNotifBadge();
            });
        });
    }
})();
</script>

<?php if ($isHomePage): ?>
<section class="ep-hero full-width">
    <div class="ep-hero-content">
        <p class="ep-hero-kicker">TECH IT EASY AT</p>
        <h1 class="ep-hero-title">Easy PC<br>One Oasis Branch</h1>
        <p class="ep-hero-subtitle">Explore the latest PCs, laptops &amp; accessories from EasyPC.</p>
    </div>
    <div class="ep-hero-visual" aria-hidden="true">
        <span class="ep-hero-icon md"><img src="../assets/headset.png" alt="Headset"></span>
        <span class="ep-hero-icon lg"><img src="../assets/desktop.png" alt="Desktop"></span>
        <span class="ep-hero-icon sm"><img src="../assets/mouse.png" alt="Mouse"></span>
    </div>
</section>
<section class="ep-feature-strip full-width">
    <span><i class="fas fa-shipping-fast"></i> Free Shipping on Orders Over &#8369;2,500</span>
    <span><i class="fas fa-undo"></i> 30-Day Money Back Guarantee</span>
    <span><i class="fas fa-headset"></i> 24/7 Customer Support</span>
</section>
<?php elseif (!empty($categoryHeroTitle)): ?>
<?php /* Category hero is rendered inside each category page main content for correct layout. */ ?>
<?php endif; ?>
