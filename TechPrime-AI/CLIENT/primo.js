/**
 * Primo — AI Product Assistant UI + SVM-backed chat.
 * Visual behavior unchanged; chat submit calls PHP → SVM API.
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
    var form = document.getElementById('primoChatForm');
    var input = document.getElementById('primoChatInput');
    var soon = document.getElementById('primoSoon');
    var body = document.getElementById('primoPanelBody');
    var sendBtn = form ? form.querySelector('.primo-send-btn') : null;

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var soonTimer = null;
    var chatBusy = false;
    var CHAT_URL = '../backend/api/primo_chat.php';

    function isExpanded() {
        return root.classList.contains('is-expanded');
    }

    function isChatOpen() {
        return root.classList.contains('is-chat-open');
    }

    function setExpanded(on) {
        root.classList.toggle('is-expanded', on);
        if (mascot) mascot.setAttribute('aria-expanded', on ? 'true' : 'false');
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

    function openChat() {
        if (backdrop) backdrop.removeAttribute('hidden');
        if (panel) panel.removeAttribute('hidden');
        requestAnimationFrame(function () {
            root.classList.add('is-chat-open');
        });
        wave();
        if (closeBtn) closeBtn.focus();
        document.body.style.overflow = 'hidden';
    }

    function closeChat() {
        root.classList.remove('is-chat-open');
        document.body.style.overflow = '';
        window.setTimeout(function () {
            if (isChatOpen()) return;
            if (panel) panel.setAttribute('hidden', '');
            if (backdrop) backdrop.setAttribute('hidden', '');
            if (soon) soon.setAttribute('hidden', '');
        }, 280);
        if (mascot) mascot.focus();
    }

    function onMascotClick(e) {
        e.preventDefault();
        if (isChatOpen()) {
            closeChat();
            return;
        }
        if (!isExpanded()) {
            setExpanded(true);
            wave();
            return;
        }
        openChat();
    }

    function onTuckClick(e) {
        e.preventDefault();
        e.stopPropagation();
        if (isChatOpen()) closeChat();
        setExpanded(false);
    }

    // Hover only controls peek ↔ full visibility (click behavior unchanged).
    var stage = root.querySelector('.primo-stage');
    if (stage) {
        stage.addEventListener('mouseenter', function () {
            setExpanded(true);
        });
        stage.addEventListener('mouseleave', function () {
            setExpanded(false);
        });
    }

    function miniAvatarHtml() {
        return '<div class="primo-msg-avatar" aria-hidden="true">' +
            '<span class="primo-mini primo-mini-sm">' +
            '<span class="primo-mini-head"><span class="primo-mini-eye"></span><span class="primo-mini-eye"></span></span>' +
            '<span class="primo-mini-body"></span></span></div>';
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatBubbleText(text) {
        return escapeHtml(text).replace(/\n/g, '<br>');
    }

    function appendMessage(role, text) {
        if (!body) return;
        var wrap = document.createElement('div');
        wrap.className = 'primo-msg ' + (role === 'user' ? 'primo-msg-user' : 'primo-msg-bot');
        var bubble = '<div class="primo-msg-bubble">' + formatBubbleText(text) + '</div>';
        wrap.innerHTML = role === 'user' ? bubble : (miniAvatarHtml() + bubble);
        body.appendChild(wrap);
        body.scrollTop = body.scrollHeight;
    }

    function setBusy(on) {
        chatBusy = on;
        if (input) input.disabled = on;
        if (sendBtn) sendBtn.disabled = on;
    }

    async function sendChat(message) {
        appendMessage('user', message);
        setBusy(true);
        try {
            var res = await fetch(CHAT_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ message: message })
            });
            var data = null;
            try {
                data = await res.json();
            } catch (err) {
                data = null;
            }
            if (!data || typeof data.reply !== 'string') {
                appendMessage('bot', 'Primo is temporarily unavailable. Please try again.');
            } else {
                appendMessage('bot', data.reply);
            }
        } catch (e) {
            appendMessage('bot', 'Primo is temporarily unavailable. Please try again.');
        } finally {
            setBusy(false);
            if (input) input.focus();
        }
    }

    if (mascot) mascot.addEventListener('click', onMascotClick);
    if (tuck) tuck.addEventListener('click', onTuckClick);
    if (closeBtn) closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        closeChat();
    });
    if (backdrop) backdrop.addEventListener('click', closeChat);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (isChatOpen()) {
                closeChat();
            } else if (isExpanded()) {
                setExpanded(false);
            }
        }
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (chatBusy) return;
            var msg = input ? String(input.value || '').trim() : '';
            if (!msg) return;
            if (input) input.value = '';
            sendChat(msg);
        });
    }

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
