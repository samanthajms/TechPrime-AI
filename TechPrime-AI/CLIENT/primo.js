/**
 * Primo — AI Product Assistant UI + SVM-backed chat.
 * Reset Chat clears the active transcript only; Recent Chats persist 7 days.
 *
 * Panel: docked + non-blocking on desktop/tablet, full-screen sheet on phones.
 * "Expand" (is-wide) is remembered per browser in localStorage (primo_wide).
 */
(function () {
    'use strict';

    var root = document.getElementById('primoRoot');
    if (!root) return;

    var mascot = document.getElementById('primoMascot');
    var figure = document.getElementById('primoFigure');
    var tuck = document.getElementById('primoTuck');
    var panel = document.getElementById('primoPanel');
    var backdrop = document.getElementById('primoBackdrop');
    var closeBtn = document.getElementById('primoClose');
    var resetBtn = document.getElementById('primoResetBtn');
    var sizeBtn = document.getElementById('primoSizeBtn');
    var historyBtn = document.getElementById('primoHistoryBtn');
    var historyPanel = document.getElementById('primoHistory');
    var historyBody = document.getElementById('primoHistoryBody');
    var historyBack = document.getElementById('primoHistoryBack');
    var historyNew = document.getElementById('primoHistoryNew');
    var historyCount = document.getElementById('primoHistoryCount');
    var historySearch = document.getElementById('primoHistorySearch');
    var historySearchWrap = document.getElementById('primoHistorySearchWrap');
    var form = document.getElementById('primoChatForm');
    var input = document.getElementById('primoChatInput');
    var counter = document.getElementById('primoCount');
    var sendBtn = document.getElementById('primoSendBtn');
    var body = document.getElementById('primoPanelBody');
    var jumpBtn = document.getElementById('primoJump');
    var statusText = document.getElementById('primoStatusText');
    var welcomeBubble = document.getElementById('primoWelcomeBubble');

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var mqPhone = window.matchMedia ? window.matchMedia('(max-width: 640px)') : null;
    var mqFinePointer = window.matchMedia ? window.matchMedia('(hover: hover) and (pointer: fine)') : null;

    var chatBusy = false;
    var greetingPicked = false;
    var conversationId = null;
    var typingTimer = null;
    var closeTimer = null;
    var CHAT_URL = '../backend/api/primo_chat.php';
    var HISTORY_URL = 'primo_history_api.php';
    var MAX_LEN = input ? (parseInt(input.getAttribute('maxlength'), 10) || 400) : 400;
    var COUNT_FROM = Math.floor(MAX_LEN * 0.8);
    var STATUS_IDLE = 'AI Product Assistant';
    var WIDE_KEY = 'primo_wide';
    /* Guests chat normally, but nothing is saved to Recent chats (history API is client-only). */
    var IS_GUEST = root.getAttribute('data-guest') === '1';

    var GREETINGS = [
        'Hi! 👋 I&rsquo;m <strong>Primo</strong>, your EasyPC assistant!<br><br>How can I help you today? 💚',
        'Hello! 💚 Welcome to EasyPC! I&rsquo;m <strong>Primo</strong>. What are you looking for?',
        'Hey there! 👋 I&rsquo;m <strong>Primo</strong>. Looking for something for your setup?',
        'Hi! 👋 I&rsquo;m <strong>Primo</strong>, your EasyPC assistant. How can I help you today?'
    ];

    /* Quick replies: shown on a fresh chat and after Primo asks for clarification. */
    var STARTERS = [
        { icon: 'fa-laptop', label: 'Find a laptop', text: 'Show me laptops' },
        { icon: 'fa-box', label: 'Track my order', text: 'Where is my order?' },
        { icon: 'fa-tools', label: 'Build a PC', text: 'Help me build a PC' },
        { icon: 'fa-undo-alt', label: 'Returns & refunds', text: 'How do returns and refunds work?' }
    ];

    /* Store destinations Primo mentions by name → links in its replies. */
    var LINKS = [
        { re: /\bBuild a PC\b/g, href: 'build_a_pc.php' },
        { re: /\bShop Now\b/g, href: 'shop.php' },
        { re: /\bMy Orders\b/g, href: 'user_dashboard.php' }
    ];

    function csrf() {
        return window.EP_CSRF || '';
    }

    function isPhone() {
        return !!(mqPhone && mqPhone.matches);
    }

    function hasFinePointer() {
        return !mqFinePointer || mqFinePointer.matches;
    }

    function isExpanded() {
        return root.classList.contains('is-expanded');
    }

    function isChatOpen() {
        return root.classList.contains('is-chat-open');
    }

    function isHistoryOpen() {
        return !!(panel && panel.classList.contains('is-history-open'));
    }

    function setExpanded(on) {
        root.classList.toggle('is-expanded', on);
        root.classList.toggle('is-collapsed', !on);
        if (tuck) {
            if (on) tuck.removeAttribute('hidden');
            else tuck.setAttribute('hidden', '');
        }
    }

    function wave() {
        if (!figure || reduceMotion) return;
        figure.classList.remove('is-waving');
        void figure.offsetWidth;
        figure.classList.add('is-waving');
        window.setTimeout(function () {
            figure.classList.remove('is-waving');
        }, 900);
    }

    function wink() {
        if (!figure || reduceMotion) return;
        figure.classList.add('is-winking');
        window.setTimeout(function () {
            figure.classList.remove('is-winking');
        }, 280);
    }

    function getHeaderBottom() {
        var header = document.querySelector('.ep-header, .top-header, header.top-header');
        if (!header) return 96;
        var rect = header.getBoundingClientRect();
        return Math.max(72, Math.ceil(rect.bottom));
    }

    /* The panel's max height stops just below the (fixed) site header. */
    function positionChatPanel() {
        if (!panel) return;
        panel.style.setProperty('--primo-top', getHeaderBottom() + 'px');
    }

    /* Phones get a modal full-screen sheet; larger screens keep the page usable. */
    function applyModality() {
        if (!panel) return;
        var modal = isPhone();
        panel.setAttribute('aria-modal', modal ? 'true' : 'false');
        document.body.style.overflow = (modal && isChatOpen()) ? 'hidden' : '';
    }

    function setStatus(text) {
        if (statusText) statusText.textContent = text || STATUS_IDLE;
    }

    function pickWelcomeGreeting() {
        if (!welcomeBubble || greetingPicked) return;
        greetingPicked = true;
        welcomeBubble.innerHTML = GREETINGS[Math.floor(Math.random() * GREETINGS.length)];
    }

    /* ---------- Size (compact / expanded) ---------- */

    function readWidePref() {
        try { return window.localStorage.getItem(WIDE_KEY) === '1'; } catch (e) { return false; }
    }

    function setWide(on, persist) {
        var stick = nearBottom();
        root.classList.toggle('is-wide', on);
        if (sizeBtn) {
            sizeBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            sizeBtn.setAttribute('aria-label', on ? 'Shrink chat' : 'Expand chat');
            sizeBtn.setAttribute('title', on ? 'Shrink' : 'Expand');
            var icon = sizeBtn.querySelector('i');
            if (icon) icon.className = 'fas ' + (on ? 'fa-compress-alt' : 'fa-expand-alt');
        }
        if (persist) {
            try { window.localStorage.setItem(WIDE_KEY, on ? '1' : '0'); } catch (e) { /* private mode */ }
        }
        if (stick) {
            /* Keep the latest message in view while the panel animates to its new size. */
            window.setTimeout(function () { scrollToBottom(false); }, reduceMotion ? 0 : 380);
        }
    }

    /* ---------- Scrolling ---------- */

    function nearBottom() {
        if (!body) return true;
        return body.scrollHeight - body.scrollTop - body.clientHeight < 72;
    }

    function scrollToBottom(smooth) {
        if (!body) return;
        if (smooth && !reduceMotion && typeof body.scrollTo === 'function') {
            body.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });
        } else {
            body.scrollTop = body.scrollHeight;
        }
        if (jumpBtn) jumpBtn.setAttribute('hidden', '');
    }

    /* Follow new content only when the reader is already at the bottom. */
    function revealNew(force) {
        if (force || nearBottom()) {
            scrollToBottom(true);
        } else if (jumpBtn) {
            jumpBtn.removeAttribute('hidden');
        }
    }

    /* A long reply (product cards) is shown from its first line, not scrolled past. */
    function revealReply(el, follow) {
        if (!body || !el) return;
        if (!follow) {
            if (jumpBtn) jumpBtn.removeAttribute('hidden');
            return;
        }
        var top = el.offsetTop - 12;
        var tall = el.offsetHeight > body.clientHeight - 48;
        var target = tall ? top : body.scrollHeight;
        if (!reduceMotion && typeof body.scrollTo === 'function') {
            body.scrollTo({ top: target, behavior: 'smooth' });
        } else {
            body.scrollTop = target;
        }
        if (jumpBtn) jumpBtn.setAttribute('hidden', '');
        if (!tall && el.classList.contains('is-new')) {
            /* The arrival transform changes the scroll extent; settle at the true bottom. */
            el.addEventListener('animationend', function () { scrollToBottom(true); }, { once: true });
        }
    }

    /* ---------- History overlay ---------- */

    function hideHistory() {
        if (!panel || !isHistoryOpen()) return;
        var hadFocus = historyPanel && historyPanel.contains(document.activeElement);
        panel.classList.remove('is-history-open');
        if (historyPanel) historyPanel.setAttribute('inert', '');
        if (historyBtn) historyBtn.setAttribute('aria-expanded', 'false');
        if (hadFocus && historyBtn) historyBtn.focus();
    }

    function showHistory() {
        if (!panel) return;
        panel.classList.add('is-history-open');
        if (historyPanel) historyPanel.removeAttribute('inert');
        if (historyBtn) historyBtn.setAttribute('aria-expanded', 'true');
        loadHistoryList();
        if (historyBack) historyBack.focus({ preventScroll: true });
    }

    /* ---------- Message rendering ---------- */

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function linkify(escaped) {
        LINKS.forEach(function (l) {
            escaped = escaped.replace(l.re, function (m) {
                return '<a href="' + l.href + '" class="primo-link">' + m + '</a>';
            });
        });
        return escaped;
    }

    /* Plain text → paragraphs + bullet lists ("• " lines). Text is escaped first. */
    function formatBotText(text) {
        var lines = String(text || '').split(/\n/);
        var html = '';
        var list = [];
        var para = [];
        function flushPara() {
            if (para.length) html += '<p>' + para.join('<br>') + '</p>';
            para = [];
        }
        function flushList() {
            if (list.length) html += '<ul class="primo-list">' + list.join('') + '</ul>';
            list = [];
        }
        lines.forEach(function (raw) {
            var line = raw.trim();
            if (/^[•\-\*]\s+/.test(line)) {
                flushPara();
                list.push('<li>' + linkify(escapeHtml(line.replace(/^[•\-\*]\s+/, ''))) + '</li>');
            } else if (line === '') {
                flushPara();
                flushList();
            } else {
                flushList();
                para.push(linkify(escapeHtml(line)));
            }
        });
        flushPara();
        flushList();
        return html || '<p></p>';
    }

    function miniAvatarHtml() {
        return '<div class="primo-msg-avatar" aria-hidden="true">' +
            '<span class="primo-mini primo-mini-sm">' +
            '<span class="primo-mini-head"><span class="primo-mini-eye"></span><span class="primo-mini-eye"></span></span>' +
            '<span class="primo-mini-body"></span></span></div>';
    }

    function peso(n) {
        var v = Number(n) || 0;
        return '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function productCardsHtml(products) {
        var html = '<div class="primo-products">';
        products.forEach(function (p) {
            var id = Number(p.id) || 0;
            var stock = Number(p.stock) || 0;
            var stockCls = stock <= 0 ? ' is-out' : (stock <= 5 ? ' is-low' : '');
            var stockText = stock <= 0 ? 'Out of stock' : (stock <= 5 ? 'Only ' + stock + ' left' : 'In stock');
            var img = typeof p.image === 'string' && p.image !== ''
                ? '<img src="' + escapeHtml(p.image) + '" alt="" loading="lazy" decoding="async">'
                : '<i class="fas fa-box-open" aria-hidden="true"></i>';
            html += '<a class="primo-product" href="products.php?id=' + id + '">' +
                '<span class="primo-product-thumb">' + img + '</span>' +
                '<span class="primo-product-info">' +
                '<span class="primo-product-name">' + escapeHtml(p.name || 'Product') + '</span>' +
                '<span class="primo-product-meta">' +
                '<span class="primo-product-price">' + peso(p.price) + '</span>' +
                '<span class="primo-product-stock' + stockCls + '">' + stockText + '</span>' +
                '</span>' +
                (p.category ? '<span class="primo-product-cat">' + escapeHtml(p.category) + '</span>' : '') +
                '</span>' +
                '<i class="fas fa-chevron-right primo-product-go" aria-hidden="true"></i>' +
                '</a>';
        });
        return html + '</div>';
    }

    /* Bot reply with product data: the bullet lines become cards. */
    function botContentHtml(text, products) {
        if (!products || !products.length) {
            return '<div class="primo-msg-bubble">' + formatBotText(text) + '</div>';
        }
        var lines = String(text || '').split(/\n/);
        var before = [];
        var after = [];
        var seenList = false;
        lines.forEach(function (line) {
            if (/^\s*•\s+/.test(line)) { seenList = true; return; }
            (seenList ? after : before).push(line);
        });
        var html = '';
        var intro = before.join('\n').trim();
        var outro = after.join('\n').trim();
        if (intro) html += '<div class="primo-msg-bubble">' + formatBotText(intro) + '</div>';
        html += productCardsHtml(products);
        if (outro) html += '<p class="primo-msg-note">' + linkify(escapeHtml(outro)) + '</p>';
        return html;
    }

    function timeLabel(date) {
        try {
            return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    /* DB timestamps are UTC without a zone suffix. */
    function parseDbTime(value) {
        if (!value) return null;
        var s = String(value).replace(' ', 'T');
        if (!/[zZ]|[+\-]\d\d:?\d\d$/.test(s)) s += 'Z';
        var d = new Date(s);
        return isNaN(d.getTime()) ? null : d;
    }

    function lastMessageEl() {
        if (!body) return null;
        var el = body.lastElementChild;
        while (el && !el.classList.contains('primo-msg')) {
            if (el.classList.contains('primo-chips')) return null;
            el = el.previousElementSibling;
        }
        return el;
    }

    /**
     * @param {'user'|'bot'} role
     * @param {string} text
     * @param {{products?:Array, error?:boolean, retryText?:string, time?:Date, animate?:boolean}} [opts]
     */
    function appendMessage(role, text, opts) {
        if (!body) return null;
        opts = opts || {};
        var isUser = role === 'user';
        var wrap = document.createElement('div');
        wrap.className = 'primo-msg ' + (isUser ? 'primo-msg-user' : 'primo-msg-bot');
        if (opts.error) wrap.className += ' is-error';
        if (opts.animate !== false && !reduceMotion) wrap.className += ' is-new';

        var prev = lastMessageEl();
        if (prev && prev.classList.contains(isUser ? 'primo-msg-user' : 'primo-msg-bot')) {
            wrap.classList.add('is-grouped');
            prev.classList.add('is-grouped-next');
        }

        var content = isUser
            ? '<div class="primo-msg-bubble">' + escapeHtml(text) + '</div>'
            : botContentHtml(text, opts.products);
        var when = timeLabel(opts.time || new Date());
        var meta = '<div class="primo-msg-meta">' +
            (when ? '<time>' + escapeHtml(when) + '</time>' : '') +
            (opts.retryText ? '<button type="button" class="primo-retry"><i class="fas fa-redo-alt" aria-hidden="true"></i>Try again</button>' : '') +
            '</div>';

        wrap.innerHTML = (isUser ? '' : miniAvatarHtml()) +
            '<div class="primo-msg-content">' + content + meta + '</div>';

        if (opts.retryText) {
            var retry = wrap.querySelector('.primo-retry');
            if (retry) {
                retry.addEventListener('click', function () {
                    if (chatBusy) return;
                    removeMessage(wrap);
                    sendChat(opts.retryText, { retry: true });
                });
            }
        }
        wrap.querySelectorAll('.primo-product-thumb img').forEach(function (img) {
            img.addEventListener('error', function () {
                var thumb = img.parentNode;
                if (thumb) thumb.innerHTML = '<i class="fas fa-box-open" aria-hidden="true"></i>';
            }, { once: true });
        });

        body.appendChild(wrap);
        return wrap;
    }

    function removeMessage(el) {
        if (!el || !el.parentNode) return;
        var prev = el.previousElementSibling;
        el.parentNode.removeChild(el);
        if (prev && prev.classList.contains('primo-msg')) {
            var next = prev.nextElementSibling;
            var sameRoleNext = next && next.classList.contains('primo-msg') &&
                next.classList.contains(prev.classList.contains('primo-msg-user') ? 'primo-msg-user' : 'primo-msg-bot');
            if (!sameRoleNext) prev.classList.remove('is-grouped-next');
        }
    }

    function removeChips() {
        if (!body) return;
        body.querySelectorAll('.primo-chips').forEach(function (c) { c.parentNode.removeChild(c); });
    }

    function appendChips(items) {
        if (!body || !items || !items.length) return;
        removeChips();
        var wrap = document.createElement('div');
        wrap.className = 'primo-chips' + (reduceMotion ? '' : ' is-new');
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-label', 'Suggested questions');
        items.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'primo-chip';
            b.setAttribute('data-text', it.text);
            b.innerHTML = '<i class="fas ' + it.icon + '" aria-hidden="true"></i><span>' + escapeHtml(it.label) + '</span>';
            wrap.appendChild(b);
        });
        body.appendChild(wrap);
    }

    function showTyping() {
        if (!body) return;
        removeTyping();
        var wrap = document.createElement('div');
        wrap.className = 'primo-msg primo-msg-bot primo-typing' + (reduceMotion ? '' : ' is-new');
        wrap.id = 'primoTyping';
        wrap.innerHTML = miniAvatarHtml() +
            '<div class="primo-msg-content">' +
            '<div class="primo-msg-bubble" role="status" aria-label="Primo is typing">' +
            '<span class="primo-typing-dot"></span><span class="primo-typing-dot"></span><span class="primo-typing-dot"></span>' +
            '</div></div>';
        body.appendChild(wrap);
        revealNew(true);
        /* The hosted intent service sleeps when idle; say so instead of looking frozen. */
        typingTimer = window.setTimeout(function () {
            var content = wrap.querySelector('.primo-msg-content');
            if (!content || !wrap.parentNode) return;
            var note = document.createElement('p');
            note.className = 'primo-typing-note';
            note.textContent = 'Still working on it — the first reply can take up to a minute while Primo wakes up.';
            content.appendChild(note);
            setStatus('Waking up…');
            revealNew(false);
        }, 5000);
    }

    function removeTyping() {
        if (typingTimer) {
            window.clearTimeout(typingTimer);
            typingTimer = null;
        }
        var t = document.getElementById('primoTyping');
        if (t && t.parentNode) t.parentNode.removeChild(t);
    }

    function welcomeHtml() {
        return '<div class="primo-msg primo-msg-bot" id="primoWelcomeMsg">' +
            miniAvatarHtml() +
            '<div class="primo-msg-content"><div class="primo-msg-bubble" id="primoWelcomeBubble">' +
            GREETINGS[Math.floor(Math.random() * GREETINGS.length)] +
            '</div></div></div>';
    }

    function hasUserMessages() {
        return !!(body && body.querySelector('.primo-msg-user'));
    }

    /* ---------- Composer ---------- */

    function autosize() {
        if (!input) return;
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight + 2, 132) + 'px';
    }

    function syncComposer() {
        if (!input) return;
        var len = input.value.length;
        var hasText = input.value.trim() !== '';
        if (sendBtn) sendBtn.disabled = chatBusy || !hasText;
        if (counter) {
            var show = len >= COUNT_FROM;
            counter.hidden = !show;
            counter.textContent = show ? (len + '/' + MAX_LEN) : '';
            counter.classList.toggle('is-limit', len >= MAX_LEN);
            if (counter.parentNode) counter.parentNode.classList.toggle('has-count', show);
        }
        autosize();
    }

    function setBusy(on) {
        chatBusy = on;
        if (panel) panel.classList.toggle('is-busy', on);
        if (body) body.setAttribute('aria-busy', on ? 'true' : 'false');
        setStatus(on ? 'Thinking…' : STATUS_IDLE);
        if (body) {
            body.querySelectorAll('.primo-chip').forEach(function (c) { c.disabled = on; });
        }
        syncComposer();
    }

    function focusComposer() {
        if (input && hasFinePointer()) input.focus({ preventScroll: true });
    }

    /* ---------- Open / close ---------- */

    function resetActiveChat() {
        hideHistory();
        removeTyping();
        conversationId = null;
        greetingPicked = true;
        if (body) {
            body.innerHTML = welcomeHtml();
            welcomeBubble = document.getElementById('primoWelcomeBubble');
            appendChips(STARTERS);
            scrollToBottom(false);
        }
        fetch(HISTORY_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action: 'reset_context', csrf_token: csrf() })
        }).catch(function () {});
        if (input) {
            input.value = '';
            syncComposer();
        }
        focusComposer();
    }

    function openChat() {
        if (closeTimer) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }
        setExpanded(true);
        pickWelcomeGreeting();
        if (!hasUserMessages() && body && !body.querySelector('.primo-chips')) appendChips(STARTERS);
        if (backdrop) backdrop.removeAttribute('hidden');
        if (panel) panel.removeAttribute('hidden');
        positionChatPanel();
        requestAnimationFrame(function () {
            root.classList.add('is-chat-open');
            applyModality();
        });
        if (mascot) mascot.setAttribute('aria-expanded', 'true');
        wave();
        scrollToBottom(false);
        if (hasFinePointer()) {
            focusComposer();
        } else if (body) {
            body.focus({ preventScroll: true });
        }
    }

    function closeChat() {
        hideHistory();
        root.classList.remove('is-chat-open');
        applyModality();
        if (mascot) mascot.setAttribute('aria-expanded', 'false');
        closeTimer = window.setTimeout(function () {
            closeTimer = null;
            if (isChatOpen()) return;
            if (panel) panel.setAttribute('hidden', '');
            if (backdrop) backdrop.setAttribute('hidden', '');
        }, reduceMotion ? 0 : 380);
        if (mascot) mascot.focus({ preventScroll: true });
    }

    function onMascotClick(e) {
        e.preventDefault();
        if (isChatOpen()) closeChat();
        else openChat();
    }

    function onTuckClick(e) {
        e.preventDefault();
        e.stopPropagation();
        if (isChatOpen()) closeChat();
        setExpanded(false);
    }

    var stage = root.querySelector('.primo-stage');
    if (stage) {
        stage.addEventListener('mouseenter', function () {
            setExpanded(true);
        });
        stage.addEventListener('mouseleave', function () {
            if (!isChatOpen()) setExpanded(false);
        });
    }

    /* ---------- History persistence ---------- */

    function persistExchange(userMsg, botMsg) {
        if (IS_GUEST) return;
        fetch(HISTORY_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                action: 'append',
                conversation_id: conversationId || 0,
                user_message: userMsg || '',
                bot_message: botMsg || '',
                csrf_token: csrf()
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok && data.conversation_id) {
                    conversationId = data.conversation_id;
                }
            })
            .catch(function () {});
    }

    var historyGroups = [];

    /* "Just now" / "12 min ago" for the last hour, clock time after that. */
    function relativeTime(iso) {
        var d = parseDbTime(iso);
        if (!d) return '';
        var mins = Math.floor((Date.now() - d.getTime()) / 60000);
        if (mins < 1) return 'Just now';
        if (mins < 60) return mins + ' min ago';
        return timeLabel(d);
    }

    function historyStateHtml(icon, title, text, withNew) {
        return '<div class="primo-history-state">' +
            '<span class="primo-history-state-icon" aria-hidden="true"><i class="' + icon + '"></i></span>' +
            '<strong>' + escapeHtml(title) + '</strong>' +
            (text ? '<p>' + escapeHtml(text) + '</p>' : '') +
            (withNew ? '<button type="button" class="primo-history-new" data-action="new"><i class="fas fa-plus" aria-hidden="true"></i><span>Start a new chat</span></button>' : '') +
            '</div>';
    }

    function setHistorySearchVisible(on) {
        if (historySearchWrap) historySearchWrap.hidden = !on;
    }

    function setHistoryCount(n) {
        if (!historyCount) return;
        historyCount.textContent = n > 0
            ? n + (n === 1 ? ' chat' : ' chats') + ' · last 7 days'
            : 'Last 7 days';
    }

    function renderHistoryList() {
        if (!historyBody) return;
        var q = historySearch ? historySearch.value.trim().toLowerCase() : '';
        var html = '';
        var shown = 0;
        historyGroups.forEach(function (g) {
            var items = (g.items || []).filter(function (item) {
                if (!q) return true;
                return String(item.title || '').toLowerCase().indexOf(q) !== -1 ||
                    String(item.preview || '').toLowerCase().indexOf(q) !== -1;
            });
            if (!items.length) return;
            html += '<section class="primo-history-group" aria-label="' + escapeHtml(g.label || '') + '">';
            html += '<h3 class="primo-history-group-label">' + escapeHtml(g.label || '') + '</h3>';
            html += '<div class="primo-history-list">';
            items.forEach(function (item) {
                shown++;
                var active = conversationId && Number(item.id) === Number(conversationId);
                var title = item.title || 'Chat';
                var preview = item.preview || '';
                var who = item.last_role === 'bot' ? 'Primo: ' : (item.last_role === 'user' ? 'You: ' : '');
                html += '<div class="primo-history-item' + (active ? ' is-active' : '') + '" data-id="' + Number(item.id) + '">' +
                    '<button type="button" class="primo-history-open"' + (active ? ' aria-current="true"' : '') + '>' +
                    '<span class="primo-history-icon" aria-hidden="true"><i class="fas ' + (active ? 'fa-comment-dots' : 'fa-comments') + '"></i></span>' +
                    '<span class="primo-history-text">' +
                    '<span class="primo-history-line">' +
                    '<span class="primo-history-title">' + escapeHtml(title) + '</span>' +
                    '<span class="primo-history-time">' + escapeHtml(relativeTime(item.updated_at || item.created_at || '')) + '</span>' +
                    '</span>' +
                    '<span class="primo-history-preview">' +
                    (active ? '<span class="primo-history-tag">Open</span>' : '') +
                    escapeHtml(preview ? who + preview : 'No messages yet') +
                    '</span>' +
                    '</span>' +
                    '</button>' +
                    '<button type="button" class="primo-history-del" title="Delete chat" aria-label="Delete chat: ' + escapeHtml(title) + '">' +
                    '<i class="fas fa-trash-alt" aria-hidden="true"></i></button></div>';
            });
            html += '</div></section>';
        });
        if (!shown) {
            html = q
                ? historyStateHtml('fas fa-search', 'No matching chats', 'Nothing in your last 7 days mentions “' + q + '”.', false)
                : historyStateHtml('far fa-comments', 'No recent chats yet', 'Your conversations with Primo from the last 7 days will show up here.', true);
        }
        historyBody.innerHTML = html;
    }

    function showSignedOutHistory() {
        historyGroups = [];
        setHistoryCount(0);
        setHistorySearchVisible(false);
        historyBody.innerHTML = historyStateHtml('fas fa-user-lock', 'Sign in to keep your chats',
            'Log in to your EasyPC account and your Primo chats from the last 7 days are saved here.', false);
    }

    function loadHistoryList() {
        if (!historyBody) return;
        if (IS_GUEST) {
            showSignedOutHistory();
            return;
        }
        if (!historyGroups.length) {
            historyBody.innerHTML = '<div class="primo-history-skeleton" aria-label="Loading recent chats"><span></span><span></span><span></span></div>';
        }
        fetch(HISTORY_URL + '?action=list', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    showSignedOutHistory();
                    return;
                }
                historyGroups = data.grouped || [];
                var total = (data.conversations || []).length;
                setHistoryCount(total);
                setHistorySearchVisible(total > 3);
                renderHistoryList();
            })
            .catch(function () {
                historyBody.innerHTML = historyStateHtml('fas fa-wifi', 'Could not load your chats', 'Check your connection, then open Recent chats again.', false);
            });
    }

    function openStoredConversation(id) {
        fetch(HISTORY_URL + '?action=get&id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.conversation) {
                    if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not open this conversation.', 'error');
                    return;
                }
                var conv = data.conversation;
                conversationId = conv.id;
                greetingPicked = true;
                removeTyping();
                hideHistory();
                if (!body) return;
                body.innerHTML = '';
                var msgs = conv.messages || [];
                if (!msgs.length) {
                    body.innerHTML = welcomeHtml();
                    welcomeBubble = document.getElementById('primoWelcomeBubble');
                    appendChips(STARTERS);
                    return;
                }
                msgs.forEach(function (m) {
                    appendMessage(m.role === 'user' ? 'user' : 'bot', m.content || '', {
                        products: Array.isArray(m.products) ? m.products : [],
                        time: parseDbTime(m.created_at) || undefined,
                        animate: false
                    });
                });
                scrollToBottom(false);
                focusComposer();
            })
            .catch(function () {
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not open this conversation.', 'error');
            });
    }

    function deleteStoredConversation(id) {
        if (typeof IAS_UI === 'undefined') {
            if (window.confirm('Delete this conversation?')) sendDeleteConversation(id);
            return;
        }
        IAS_UI.confirm('This chat will be removed from your history.', {
            title: 'Delete this conversation?', confirmLabel: 'Delete', type: 'danger'
        }).then(function (ok) { if (ok) sendDeleteConversation(id); });
    }

    function sendDeleteConversation(id) {
        var row = historyBody ? historyBody.querySelector('.primo-history-item[data-id="' + Number(id) + '"]') : null;
        if (row) row.classList.add('is-removing');
        fetch(HISTORY_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: Number(id), csrf_token: csrf() })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    if (row) row.classList.remove('is-removing');
                    if (typeof IAS_UI !== 'undefined') IAS_UI.alert((data && data.message) || 'Delete failed.', 'error');
                    return;
                }
                if (Number(conversationId) === Number(id)) {
                    var keepHistoryOpen = isHistoryOpen();
                    resetActiveChat();
                    if (keepHistoryOpen) showHistory();
                    return;
                }
                loadHistoryList();
            })
            .catch(function () {
                if (row) row.classList.remove('is-removing');
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Delete failed.', 'error');
            });
    }

    /* ---------- Chat ---------- */

    var UNAVAILABLE = 'Primo is temporarily unavailable. Please try again.';

    async function sendChat(message, opts) {
        opts = opts || {};
        if (chatBusy) return;
        removeChips();
        if (!opts.retry) {
            appendMessage('user', message);
        }
        setBusy(true);
        showTyping();

        var data = null;
        var networkFailed = false;
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var abortTimer = controller ? window.setTimeout(function () { controller.abort(); }, 75000) : null;
        try {
            var res = await fetch(CHAT_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ message: message, csrf_token: csrf() }),
                signal: controller ? controller.signal : undefined
            });
            try {
                data = await res.json();
            } catch (err) {
                data = null;
            }
        } catch (e) {
            networkFailed = true;
        } finally {
            if (abortTimer) window.clearTimeout(abortTimer);
        }

        var follow = nearBottom();
        removeTyping();
        setBusy(false);

        var reply;
        if (!networkFailed && data && typeof data.reply === 'string' && data.ok) {
            reply = appendMessage('bot', data.reply, { products: Array.isArray(data.products) ? data.products : [] });
            persistExchange(message, data.reply);
            if (data.intent === 'fallback') appendChips(STARTERS);
        } else if (!networkFailed && data && typeof data.reply === 'string' && data.error !== 'svm_unavailable') {
            /* Session expired / invalid message: retrying the same text won't help. */
            reply = appendMessage('bot', data.reply, { error: true });
        } else {
            /* Service down or network error: offer a one-tap retry, keep it out of history. */
            reply = appendMessage('bot', UNAVAILABLE, { error: true, retryText: message });
        }
        revealReply(reply, follow);
        if ((panel && panel.contains(document.activeElement)) || document.activeElement === document.body) {
            focusComposer();
        }
    }

    function submitFromComposer() {
        if (chatBusy || !input) return;
        var msg = String(input.value || '').trim();
        if (!msg) return;
        input.value = '';
        syncComposer();
        sendChat(msg);
    }

    /* ---------- Wiring ---------- */

    if (mascot) mascot.addEventListener('click', onMascotClick);
    if (tuck) tuck.addEventListener('click', onTuckClick);
    if (closeBtn) closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        closeChat();
    });
    if (backdrop) backdrop.addEventListener('click', closeChat);
    if (resetBtn) {
        resetBtn.addEventListener('click', function (e) {
            e.preventDefault();
            resetActiveChat();
        });
    }
    if (sizeBtn) {
        sizeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            setWide(!root.classList.contains('is-wide'), true);
        });
    }
    if (historyBtn) {
        historyBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (isHistoryOpen()) hideHistory();
            else showHistory();
        });
    }
    if (historyBack) {
        historyBack.addEventListener('click', function (e) {
            e.preventDefault();
            hideHistory();
        });
    }
    if (historyNew) {
        historyNew.addEventListener('click', function (e) {
            e.preventDefault();
            resetActiveChat();
        });
    }
    if (historySearch) {
        historySearch.addEventListener('input', renderHistoryList);
        historySearch.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && historySearch.value) {
                /* First Esc clears the search; the next one closes Recent chats. */
                e.preventDefault();
                e.stopPropagation();
                historySearch.value = '';
                renderHistoryList();
            }
        });
    }
    if (historyBody) {
        historyBody.addEventListener('click', function (e) {
            if (e.target.closest('[data-action="new"]')) {
                e.preventDefault();
                resetActiveChat();
                return;
            }
            var del = e.target.closest('.primo-history-del');
            var open = e.target.closest('.primo-history-open');
            var row = e.target.closest('.primo-history-item');
            if (!row) return;
            var id = row.getAttribute('data-id');
            if (del) {
                e.preventDefault();
                deleteStoredConversation(id);
                return;
            }
            if (open) {
                e.preventDefault();
                openStoredConversation(id);
            }
        });
    }
    if (body) {
        body.addEventListener('click', function (e) {
            var chip = e.target.closest('.primo-chip');
            if (!chip || chip.disabled || chatBusy) return;
            e.preventDefault();
            sendChat(chip.getAttribute('data-text') || chip.textContent.trim());
        });
        body.addEventListener('scroll', function () {
            if (jumpBtn && !jumpBtn.hasAttribute('hidden') && nearBottom()) jumpBtn.setAttribute('hidden', '');
        }, { passive: true });
    }
    if (jumpBtn) {
        jumpBtn.addEventListener('click', function () {
            scrollToBottom(true);
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var tmModal = document.getElementById('epTechMatchModal');
        if (tmModal && !tmModal.hidden) return;
        if (document.getElementById('ias-alert-overlay')) return; /* IAS_UI dialog handles its own Esc */
        if (isHistoryOpen()) {
            hideHistory();
            return;
        }
        if (isChatOpen()) {
            closeChat();
        } else if (isExpanded()) {
            setExpanded(false);
        }
    });

    window.addEventListener('resize', function () {
        if (isChatOpen()) {
            positionChatPanel();
            applyModality();
        }
    });

    if (input) {
        input.addEventListener('input', syncComposer);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                submitFromComposer();
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitFromComposer();
        });
    }

    if (historyPanel) historyPanel.setAttribute('inert', '');
    setWide(readWidePref(), false);
    syncComposer();

    if (!reduceMotion) {
        function scheduleWink() {
            var delay = 7000 + Math.random() * 9000;
            window.setTimeout(function () {
                if (!isChatOpen()) wink();
                scheduleWink();
            }, delay);
        }
        function scheduleWave() {
            var delay = 14000 + Math.random() * 16000;
            window.setTimeout(function () {
                if (!isChatOpen() && isExpanded()) wave();
                else if (!isChatOpen() && Math.random() > 0.55) wave();
                scheduleWave();
            }, delay);
        }
        scheduleWink();
        scheduleWave();
    }
})();
