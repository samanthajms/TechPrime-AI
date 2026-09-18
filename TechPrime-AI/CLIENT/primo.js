/**
 * Primo — AI Product Assistant UI + SVM-backed chat.
 * Reset Chat clears the active transcript only; Recent Chats persist 7 days.
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
    var historyBtn = document.getElementById('primoHistoryBtn');
    var historyPanel = document.getElementById('primoHistory');
    var historyBody = document.getElementById('primoHistoryBody');
    var historyBack = document.getElementById('primoHistoryBack');
    var form = document.getElementById('primoChatForm');
    var input = document.getElementById('primoChatInput');
    var soon = document.getElementById('primoSoon');
    var body = document.getElementById('primoPanelBody');
    var welcomeBubble = document.getElementById('primoWelcomeBubble');
    var sendBtn = form ? form.querySelector('.primo-send-btn') : null;

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var chatBusy = false;
    var promoShown = false;
    var userEngaged = false;
    var promoTimer = null;
    var greetingPicked = false;
    var conversationId = null;
    var CHAT_URL = '../backend/api/primo_chat.php';
    var HISTORY_URL = 'primo_history_api.php';

    var GREETINGS = [
        'Hi! 👋 I&rsquo;m <strong>Primo</strong>, your EasyPC assistant!<br><br>How can I help you today? 💚',
        'Hello! 💚 Welcome to EasyPC! I&rsquo;m <strong>Primo</strong>. What are you looking for?',
        'Hey there! 👋 I&rsquo;m <strong>Primo</strong>. Looking for something for your setup?',
        'Hi! 👋 I&rsquo;m <strong>Primo</strong>, your EasyPC assistant. How can I help you today?'
    ];

    function csrf() {
        return window.EP_CSRF || '';
    }

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

    function getHeaderBottom() {
        var header = document.querySelector('.ep-header, .top-header, header.top-header');
        if (!header) return 96;
        var rect = header.getBoundingClientRect();
        return Math.max(72, Math.ceil(rect.bottom));
    }

    function positionChatPanel() {
        if (!panel) return;
        var headerBottom = getHeaderBottom() + 12;
        root.style.setProperty('--primo-chat-top', headerBottom + 'px');
        panel.style.top = headerBottom + 'px';
        panel.style.bottom = 'auto';
        panel.style.height = '';
        panel.style.maxHeight = '';
    }

    function openTechMatchFromPrimo(e) {
        if (e) e.preventDefault();
        if (typeof window.epOpenTechMatch === 'function') {
            window.epOpenTechMatch();
        }
    }

    function pickWelcomeGreeting() {
        if (!welcomeBubble || greetingPicked) return;
        greetingPicked = true;
        welcomeBubble.innerHTML = GREETINGS[Math.floor(Math.random() * GREETINGS.length)];
    }

    function clearPromoTimer() {
        if (promoTimer) {
            window.clearTimeout(promoTimer);
            promoTimer = null;
        }
    }

    function scheduleTechMatchPromo() {
        clearPromoTimer();
        if (promoShown) return;
        promoTimer = window.setTimeout(function () {
            promoTimer = null;
            if (!promoShown && !userEngaged) {
                appendTechMatchPromo();
            }
        }, 1800);
    }

    function hideHistory() {
        if (historyPanel) historyPanel.setAttribute('hidden', '');
    }

    function showHistory() {
        if (historyPanel) historyPanel.removeAttribute('hidden');
        loadHistoryList();
    }

    function welcomeHtml() {
        return '<div class="primo-msg primo-msg-bot" id="primoWelcomeMsg">' +
            miniAvatarHtml() +
            '<div class="primo-msg-bubble" id="primoWelcomeBubble">' +
            GREETINGS[Math.floor(Math.random() * GREETINGS.length)] +
            '</div></div>';
    }

    function resetActiveChat(opts) {
        opts = opts || {};
        clearPromoTimer();
        hideHistory();
        conversationId = null;
        promoShown = false;
        userEngaged = false;
        greetingPicked = true;
        if (body) {
            body.innerHTML = welcomeHtml();
            welcomeBubble = document.getElementById('primoWelcomeBubble');
        }
        fetch(HISTORY_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action: 'reset_context', csrf_token: csrf() })
        }).catch(function () {});
        if (!opts.silent && typeof IAS_UI !== 'undefined') {
            /* Keep quiet — reset is local; history stays. */
        }
        if (!opts.skipPromo) scheduleTechMatchPromo();
        if (input) {
            input.value = '';
            input.focus();
        }
    }

    function openChat() {
        pickWelcomeGreeting();
        hideHistory();
        if (backdrop) backdrop.removeAttribute('hidden');
        if (panel) panel.removeAttribute('hidden');
        positionChatPanel();
        requestAnimationFrame(function () {
            root.classList.add('is-chat-open');
            positionChatPanel();
        });
        wave();
        if (closeBtn) closeBtn.focus();
        document.body.style.overflow = 'hidden';
        scheduleTechMatchPromo();
    }

    function closeChat() {
        clearPromoTimer();
        hideHistory();
        root.classList.remove('is-chat-open');
        document.body.style.overflow = '';
        if (panel) {
            panel.style.top = '';
            panel.style.bottom = '';
            panel.style.height = '';
            panel.style.maxHeight = '';
        }
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

    function appendTechMatchPromo() {
        if (!body || promoShown) return;
        promoShown = true;
        clearPromoTimer();

        var wrap = document.createElement('div');
        wrap.className = 'primo-msg primo-msg-bot';
        wrap.innerHTML =
            miniAvatarHtml() +
            '<div class="primo-msg-bubble primo-msg-bubble-stack">' +
            '<p class="primo-promo-lead">By the way! 💻✨<br><br>' +
            'Want to build and customize your own setup?<br><br>' +
            'You can try <strong>Tech &amp; Match</strong>! I can help you find and match the right PC parts, laptops, and accessories based on what you need and your budget.</p>' +
            '<div class="primo-tm-card">' +
            '<div class="primo-tm-card-head"><span class="primo-tm-bolt" aria-hidden="true">⚡</span> TECH &amp; MATCH</div>' +
            '<p class="primo-tm-tagline">Build easy. Match smart.</p>' +
            '<p class="primo-tm-copy">Customize your setup with real EasyPC products.</p>' +
            '<button type="button" class="primo-tm-btn" id="primoTryTechMatch">' +
            '<span aria-hidden="true">⚡</span> Try Tech &amp; Match</button>' +
            '</div></div>';

        body.appendChild(wrap);
        body.scrollTop = body.scrollHeight;
        var tmBtn = wrap.querySelector('#primoTryTechMatch, .primo-tm-btn');
        if (tmBtn) tmBtn.addEventListener('click', openTechMatchFromPrimo);
    }

    function setBusy(on) {
        chatBusy = on;
        if (input) input.disabled = on;
        if (sendBtn) sendBtn.disabled = on;
    }

    function persistExchange(userMsg, botMsg) {
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

    function formatHistoryTime(iso) {
        try {
            var d = new Date(iso.replace(' ', 'T'));
            if (isNaN(d.getTime())) return '';
            return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    function loadHistoryList() {
        if (!historyBody) return;
        historyBody.innerHTML = '<p class="primo-history-empty">Loading…</p>';
        fetch(HISTORY_URL + '?action=list', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    historyBody.innerHTML = '<p class="primo-history-empty">Sign in to see your recent chats.</p>';
                    return;
                }
                var grouped = data.grouped || [];
                if (!grouped.length) {
                    historyBody.innerHTML = '<p class="primo-history-empty">No recent chats yet.<br>Chats from the last 7 days appear here.</p>';
                    return;
                }
                var html = '';
                grouped.forEach(function (g) {
                    html += '<div class="primo-history-group">';
                    html += '<div class="primo-history-group-label">' + escapeHtml(g.label || '') + '</div>';
                    (g.items || []).forEach(function (item) {
                        html += '<div class="primo-history-item" data-id="' + Number(item.id) + '">' +
                            '<button type="button" class="primo-history-open">' +
                            '<strong>' + escapeHtml(item.title || 'Chat') + '</strong>' +
                            '<span>' + escapeHtml(formatHistoryTime(item.updated_at || item.created_at || '')) + '</span>' +
                            '</button>' +
                            '<button type="button" class="primo-history-del" title="Delete conversation" aria-label="Delete conversation">' +
                            '<i class="fas fa-trash-alt" aria-hidden="true"></i></button></div>';
                    });
                    html += '</div>';
                });
                historyBody.innerHTML = html;
            })
            .catch(function () {
                historyBody.innerHTML = '<p class="primo-history-empty">Could not load chat history.</p>';
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
                promoShown = true;
                userEngaged = true;
                greetingPicked = true;
                clearPromoTimer();
                hideHistory();
                if (!body) return;
                body.innerHTML = '';
                var msgs = conv.messages || [];
                if (!msgs.length) {
                    body.innerHTML = welcomeHtml();
                    welcomeBubble = document.getElementById('primoWelcomeBubble');
                    return;
                }
                msgs.forEach(function (m) {
                    appendMessage(m.role === 'user' ? 'user' : 'bot', m.content || '');
                });
                if (input) input.focus();
            })
            .catch(function () {
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not open this conversation.', 'error');
            });
    }

    function deleteStoredConversation(id) {
        if (!window.confirm('Delete this conversation?')) return;
        fetch(HISTORY_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: Number(id), csrf_token: csrf() })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    if (typeof IAS_UI !== 'undefined') IAS_UI.alert((data && data.message) || 'Delete failed.', 'error');
                    return;
                }
                if (conversationId === Number(id)) {
                    resetActiveChat({ silent: true, skipPromo: true });
                }
                loadHistoryList();
            })
            .catch(function () {
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Delete failed.', 'error');
            });
    }

    async function sendChat(message) {
        userEngaged = true;
        clearPromoTimer();
        appendMessage('user', message);
        setBusy(true);
        var replyText = '';
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
                replyText = 'Primo is temporarily unavailable. Please try again.';
                appendMessage('bot', replyText);
            } else {
                replyText = data.reply;
                appendMessage('bot', data.reply);
                if (data.show_tech_match && !promoShown) {
                    window.setTimeout(function () {
                        appendTechMatchPromo();
                    }, 450);
                }
            }
            persistExchange(message, replyText);
        } catch (e) {
            replyText = 'Primo is temporarily unavailable. Please try again.';
            appendMessage('bot', replyText);
            persistExchange(message, replyText);
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
    if (resetBtn) {
        resetBtn.addEventListener('click', function (e) {
            e.preventDefault();
            resetActiveChat({ silent: true });
        });
    }
    if (historyBtn) {
        historyBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (historyPanel && !historyPanel.hasAttribute('hidden')) {
                hideHistory();
            } else {
                showHistory();
            }
        });
    }
    if (historyBack) {
        historyBack.addEventListener('click', function (e) {
            e.preventDefault();
            hideHistory();
        });
    }
    if (historyBody) {
        historyBody.addEventListener('click', function (e) {
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

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var tmModal = document.getElementById('epTechMatchModal');
            if (tmModal && !tmModal.hidden) {
                return;
            }
            if (historyPanel && !historyPanel.hasAttribute('hidden')) {
                hideHistory();
                return;
            }
            if (isChatOpen()) {
                closeChat();
            } else if (isExpanded()) {
                setExpanded(false);
            }
        }
    });

    window.addEventListener('resize', function () {
        if (isChatOpen()) positionChatPanel();
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
