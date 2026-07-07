<?php
/**
 * chat_widget.php — Floating Messenger-like Chat Widget
 * Include at the bottom of any CLIENT page BEFORE </body>:
 *     <?php include __DIR__ . '/../CLIENT/chat_widget.php'; ?>
 *
 * Requires: session with user_id set, styles.css already loaded.
 * Only shows to logged-in users.
 */
if (!isset($_SESSION['user_id'])) return;

$_cw_userId   = (int)$_SESSION['user_id'];
$_cw_userName = htmlspecialchars($_SESSION['name'] ?? 'User', ENT_QUOTES, 'UTF-8');

// Pre-load open seller from query param (e.g. from Admin "Contact" button)
$_cw_openSeller = isset($_GET['chat_seller']) ? (int)$_GET['chat_seller'] : 0;
?>

<!-- ══ FLOATING CHAT WIDGET ════════════════════════════════════════════════ -->
<style>
/* ── Tokens ─────────────────────────────────────────────────────────────── */
:root {
    --cw-teal:       #0998a8;
    --cw-teal-dk:    #077a87;
    --cw-yellow:     #f5f500;
    --cw-yellow2:    #eaf41f;
    --cw-bg:         #f0f2f5;
    --cw-surface:    #ffffff;
    --cw-border:     #e4e6eb;
    --cw-text:       #1c1e21;
    --cw-muted:      #65676b;
    --cw-sent-bg:    #0998a8;
    --cw-recv-bg:    #e9ecef;
    --cw-radius:     16px;
    --cw-w:          340px;
    --cw-h:          480px;
}

/* ── Fab button ─────────────────────────────────────────────────────────── */
#cwFab {
    position: fixed;
    right: 22px;
    bottom: 76px;
    z-index: 9000;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 11px 18px 11px 14px;
    background: var(--cw-teal);
    color: #fff;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    font-family: inherit;
    font-size: 14px;
    font-weight: 700;
    box-shadow: 0 4px 18px rgba(9,152,168,.38);
    transition: transform .2s, box-shadow .2s;
    user-select: none;
}
#cwFab:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(9,152,168,.45); }
#cwFab .cw-fab-icon { font-size: 18px; color: var(--cw-yellow); line-height: 1; }
#cwFab .cw-fab-label { letter-spacing: .2px; }

/* Avatar cluster on the button */
#cwFabAvatars {
    display: flex;
    margin-left: 4px;
}
.cw-fab-av {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: var(--cw-teal-dk);
    border: 2px solid var(--cw-teal);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800; color: var(--cw-yellow2);
    margin-left: -8px;
    flex-shrink: 0;
}
.cw-fab-av:first-child { margin-left: 0; }

/* Unread badge on FAB */
#cwFabBadge {
    position: absolute;
    top: -4px; right: -4px;
    background: #e53935;
    color: #fff;
    font-size: 10px; font-weight: 800;
    min-width: 18px; height: 18px;
    border-radius: 9px;
    display: none;
    align-items: center; justify-content: center;
    padding: 0 4px;
    border: 2px solid #fff;
    line-height: 1;
}
#cwFabBadge.show { display: flex; }

/* ── Panel ──────────────────────────────────────────────────────────────── */
#cwPanel {
    position: fixed;
    right: 22px;
    bottom: 140px;
    z-index: 9001;
    width: var(--cw-w);
    height: var(--cw-h);
    background: var(--cw-surface);
    border-radius: var(--cw-radius);
    box-shadow: 0 12px 48px rgba(0,0,0,.2);
    display: none;
    flex-direction: column;
    overflow: hidden;
    font-family: 'Inter', 'Segoe UI', sans-serif;
    transform-origin: bottom right;
    animation: cwSlideIn .22s cubic-bezier(.34,1.3,.64,1);
}
#cwPanel.open { display: flex; }

@keyframes cwSlideIn {
    from { opacity: 0; transform: scale(.9) translateY(12px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

/* ── Panel header ───────────────────────────────────────────────────────── */
#cwHeader {
    background: var(--cw-teal);
    padding: 13px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
#cwHeaderBack {
    background: rgba(255,255,255,.18);
    border: none;
    border-radius: 50%;
    width: 30px; height: 30px;
    color: #fff; font-size: 16px;
    cursor: pointer; display: none;
    align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: background .15s;
}
#cwHeaderBack:hover { background: rgba(255,255,255,.3); }
#cwHeaderBack.show { display: flex; }
#cwHeaderAvatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,.2);
    display: none;
    align-items: center; justify-content: center;
    font-size: 14px; font-weight: 800;
    color: var(--cw-yellow2);
    flex-shrink: 0;
}
#cwHeaderAvatar.show { display: flex; }
#cwHeaderTitle {
    flex: 1;
    font-size: 15px; font-weight: 700;
    color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
#cwHeaderSub {
    font-size: 11px; color: rgba(255,255,255,.75);
    display: none;
}
#cwHeaderSub.show { display: block; }
#cwCloseBtn {
    background: rgba(255,255,255,.18);
    border: none; border-radius: 50%;
    width: 30px; height: 30px;
    color: #fff; font-size: 17px;
    cursor: pointer; display: flex;
    align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: background .15s;
}
#cwCloseBtn:hover { background: rgba(255,255,255,.3); }

/* ── Seller list pane ───────────────────────────────────────────────────── */
#cwSellerList {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
#cwSellerList::-webkit-scrollbar { width: 4px; }
#cwSellerList::-webkit-scrollbar-thumb { background: var(--cw-border); border-radius: 4px; }

.cw-seller-search {
    padding: 10px 12px 8px;
    border-bottom: 1px solid var(--cw-border);
    flex-shrink: 0;
}
.cw-seller-search input {
    width: 100%;
    padding: 7px 12px 7px 32px;
    border: 1.5px solid var(--cw-border);
    border-radius: 999px;
    font-size: 13px;
    outline: none;
    font-family: inherit;
    color: var(--cw-text);
    background: var(--cw-bg) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='13' height='13' fill='%2365676b' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.415l-3.868-3.833zm-5.242 1.156a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E") no-repeat 11px center;
    transition: border-color .15s;
}
.cw-seller-search input:focus { border-color: var(--cw-teal); }

.cw-seller-item {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 11px 14px;
    cursor: pointer;
    border-bottom: 1px solid var(--cw-border);
    transition: background .12s;
    text-decoration: none;
}
.cw-seller-item:hover { background: #f0f9fa; }
.cw-seller-item:last-child { border-bottom: none; }

.cw-s-av {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--cw-teal) 0%, var(--cw-teal-dk) 100%);
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 800;
    color: var(--cw-yellow2);
    flex-shrink: 0;
    position: relative;
}
.cw-s-av-online {
    position: absolute;
    bottom: 1px; right: 1px;
    width: 11px; height: 11px;
    border-radius: 50%;
    background: #31a24c;
    border: 2px solid #fff;
}

.cw-s-body { flex: 1; min-width: 0; }
.cw-s-name {
    font-size: 14px; font-weight: 700;
    color: var(--cw-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cw-s-preview {
    font-size: 12px; color: var(--cw-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-top: 1px;
}
.cw-s-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.cw-s-time { font-size: 11px; color: var(--cw-muted); white-space: nowrap; }
.cw-s-unread {
    background: var(--cw-teal);
    color: #fff;
    font-size: 10px; font-weight: 800;
    min-width: 18px; height: 18px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px;
}

.cw-empty {
    flex: 1;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: var(--cw-muted);
    font-size: 13px;
    gap: 8px;
    text-align: center;
    padding: 20px;
}
.cw-empty-icon { font-size: 36px; opacity: .5; }

.cw-loading {
    flex: 1;
    display: flex; align-items: center; justify-content: center;
    color: var(--cw-muted);
}
.cw-spinner {
    width: 22px; height: 22px;
    border: 3px solid var(--cw-border);
    border-top-color: var(--cw-teal);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Chat pane ──────────────────────────────────────────────────────────── */
#cwChatPane {
    flex: 1;
    display: none;
    flex-direction: column;
    overflow: hidden;
}
#cwChatPane.open { display: flex; }

#cwMessages {
    flex: 1;
    overflow-y: auto;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: #f7f8fa;
}
#cwMessages::-webkit-scrollbar { width: 4px; }
#cwMessages::-webkit-scrollbar-thumb { background: var(--cw-border); border-radius: 4px; }

.cw-msg-row {
    display: flex;
    align-items: flex-end;
    gap: 6px;
}
.cw-msg-row.mine { justify-content: flex-end; }
.cw-msg-row.theirs { justify-content: flex-start; }

.cw-bubble-av {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--cw-teal) 0%, var(--cw-teal-dk) 100%);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800;
    color: var(--cw-yellow2);
    flex-shrink: 0;
}
.cw-bubble {
    max-width: 78%;
    padding: 9px 13px;
    border-radius: 18px;
    font-size: 13.5px;
    line-height: 1.45;
    word-break: break-word;
    position: relative;
}
.cw-msg-row.mine  .cw-bubble { background: var(--cw-sent-bg); color: #fff; border-bottom-right-radius: 4px; }
.cw-msg-row.theirs .cw-bubble { background: var(--cw-recv-bg); color: var(--cw-text); border-bottom-left-radius: 4px; }
.cw-bubble-time {
    font-size: 10px;
    color: rgba(255,255,255,.65);
    display: block;
    text-align: right;
    margin-top: 3px;
}
.cw-msg-row.theirs .cw-bubble-time { color: var(--cw-muted); }

.cw-date-sep {
    text-align: center;
    font-size: 11px;
    color: var(--cw-muted);
    margin: 8px 0 4px;
    position: relative;
}
.cw-date-sep span {
    background: #f7f8fa;
    padding: 0 10px;
    position: relative; z-index: 1;
}
.cw-date-sep::before {
    content: '';
    position: absolute;
    left: 0; right: 0; top: 50%;
    height: 1px;
    background: var(--cw-border);
}

/* ── Input bar ──────────────────────────────────────────────────────────── */
#cwInputBar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border-top: 1px solid var(--cw-border);
    background: var(--cw-surface);
    flex-shrink: 0;
}
#cwMsgInput {
    flex: 1;
    padding: 9px 14px;
    border: 1.5px solid var(--cw-border);
    border-radius: 999px;
    font-size: 13.5px;
    font-family: inherit;
    outline: none;
    resize: none;
    color: var(--cw-text);
    transition: border-color .15s;
    max-height: 80px;
    overflow-y: auto;
    line-height: 1.4;
}
#cwMsgInput:focus { border-color: var(--cw-teal); }
#cwSendBtn {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: var(--cw-teal);
    border: none;
    color: #fff;
    font-size: 16px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: background .15s, transform .15s;
}
#cwSendBtn:hover { background: var(--cw-teal-dk); transform: scale(1.08); }
#cwSendBtn:active { transform: scale(.95); }
</style>

<!-- FAB -->
<button id="cwFab" onclick="CW.toggle()" aria-label="Open Messages">
    <span class="cw-fab-icon">🔔</span>
    <span class="cw-fab-label">Messages</span>
    <div id="cwFabAvatars"></div>
    <span id="cwFabBadge"></span>
</button>

<!-- Panel -->
<div id="cwPanel" role="dialog" aria-label="Chat">
    <!-- Header -->
    <div id="cwHeader">
        <button id="cwHeaderBack" onclick="CW.backToList()" title="Back">‹</button>
        <div id="cwHeaderAvatar"></div>
        <div style="flex:1;min-width:0;">
            <div id="cwHeaderTitle">Messages</div>
            <div id="cwHeaderSub">Seller</div>
        </div>
        <button id="cwCloseBtn" onclick="CW.close()" title="Close">×</button>
    </div>

    <!-- Seller list pane -->
    <div id="cwSellerList">
        <div class="cw-seller-search">
            <input type="text" id="cwSellerSearch" placeholder="Search sellers…" oninput="CW.filterSellers(this.value)">
        </div>
        <div id="cwSellerItems" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;">
            <div class="cw-loading"><div class="cw-spinner"></div></div>
        </div>
    </div>

    <!-- Chat pane -->
    <div id="cwChatPane">
        <div id="cwMessages"></div>
        <div id="cwInputBar">
            <textarea id="cwMsgInput" placeholder="Write a message…" rows="1"
                oninput="CW.autoResize(this)"
                onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();CW.send();}"></textarea>
            <button id="cwSendBtn" onclick="CW.send()" title="Send">➤</button>
        </div>
    </div>
</div>

<script>
const CW = (() => {
    const ENDPOINT = 'chat_messages.php';

    let isOpen       = false;
    let sellers      = [];
    let filteredSellers = [];
    let activeSeller = null;  // { id, name, surname }
    let messages     = [];
    let lastMsgId    = 0;
    let pollTimer    = null;
    let totalUnread  = 0;

    // ── Helpers ────────────────────────────────────────────────────────────
    function esc(s) {
        return String(s||'')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function initials(name, surname) {
        return ((name||'')[0]||'').toUpperCase() + ((surname||'')[0]||'').toUpperCase();
    }
    function fmtTime(ts) {
        if (!ts) return '';
        const d = new Date(ts.replace(' ', 'T'));
        const now = new Date();
        const diff = (now - d) / 1000;
        if (diff < 60)     return 'just now';
        if (diff < 3600)   return Math.floor(diff/60) + 'm';
        if (diff < 86400)  return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        return d.toLocaleDateString([], {month:'short', day:'numeric'});
    }
    function fmtMsgTime(ts) {
        if (!ts) return '';
        const d = new Date(ts.replace(' ', 'T'));
        return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    }
    function fmtDateSep(ts) {
        if (!ts) return '';
        const d = new Date(ts.replace(' ', 'T'));
        const today = new Date();
        const yesterday = new Date(today); yesterday.setDate(yesterday.getDate()-1);
        if (d.toDateString() === today.toDateString()) return 'Today';
        if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
        return d.toLocaleDateString([], {weekday:'long', month:'long', day:'numeric'});
    }

    // ── FAB ────────────────────────────────────────────────────────────────
    function updateFab() {
        const avatarEl = document.getElementById('cwFabAvatars');
        const badgeEl  = document.getElementById('cwFabBadge');

        // Show up to 3 seller avatars
        const shown = sellers.slice(0, 3);
        avatarEl.innerHTML = shown.map(s =>
            `<div class="cw-fab-av">${initials(s.name,s.surname)}</div>`
        ).join('');

        // Unread badge
        totalUnread = sellers.reduce((sum, s) => sum + (s.unread||0), 0);
        if (totalUnread > 0) {
            badgeEl.textContent = totalUnread > 99 ? '99+' : totalUnread;
            badgeEl.classList.add('show');
        } else {
            badgeEl.classList.remove('show');
        }
    }

    // ── Panel open/close ───────────────────────────────────────────────────
    function open() {
        isOpen = true;
        document.getElementById('cwPanel').classList.add('open');
        if (!sellers.length) loadSellers();
        else renderSellerList();

        // Open directly to a seller if URL has ?chat_seller=N
        const sp = new URLSearchParams(window.location.search);
        const cs = parseInt(sp.get('chat_seller')||'0');
        if (cs) {
            const found = sellers.find(s => s.id === cs);
            if (found) openChat(found);
        }
    }
    function close() {
        isOpen = false;
        document.getElementById('cwPanel').classList.remove('open');
        stopPoll();
    }
    function toggle() { isOpen ? close() : open(); }

    // ── Seller list ────────────────────────────────────────────────────────
    async function loadSellers() {
        const el = document.getElementById('cwSellerItems');
        el.innerHTML = '<div class="cw-loading"><div class="cw-spinner"></div></div>';
        try {
            const r = await fetch(ENDPOINT + '?action=get_sellers');
            const data = await r.json();
            sellers = data.sellers || [];
            filteredSellers = sellers;
            renderSellerList();
            updateFab();
        } catch(e) {
            el.innerHTML = '<div class="cw-empty"><div class="cw-empty-icon">⚠️</div><p>Could not load sellers.</p></div>';
        }
    }

    function renderSellerList() {
        // Show list pane, hide chat pane
        document.getElementById('cwSellerList').style.display = 'flex';
        document.getElementById('cwChatPane').classList.remove('open');
        document.getElementById('cwHeaderBack').classList.remove('show');
        document.getElementById('cwHeaderAvatar').classList.remove('show');
        document.getElementById('cwHeaderTitle').textContent = 'Messages';
        document.getElementById('cwHeaderSub').classList.remove('show');
        activeSeller = null;
        stopPoll();

        const el = document.getElementById('cwSellerItems');
        const list = filteredSellers;

        if (!list.length) {
            el.innerHTML = '<div class="cw-empty"><div class="cw-empty-icon">🏪</div><p>No sellers available.</p></div>';
            return;
        }
        el.innerHTML = list.map(s => {
            const ini = initials(s.name, s.surname);
            const preview = s.last_msg || (s.shop_desc ? `🏪 ${esc(s.shop_desc)}` : '<em style="color:#aaa">Start a conversation</em>');
            const unread  = s.unread ? `<div class="cw-s-unread">${s.unread > 99 ? '99+' : s.unread}</div>` : '';
            const time    = s.last_time ? `<div class="cw-s-time">${fmtTime(s.last_time)}</div>` : '';
            return `<div class="cw-seller-item" onclick="CW.openChatById(${s.id})">
                <div class="cw-s-av">${ini}<div class="cw-s-av-online"></div></div>
                <div class="cw-s-body">
                    <div class="cw-s-name">${esc(s.name)} ${esc(s.surname)}</div>
                    <div class="cw-s-preview">${preview}</div>
                </div>
                <div class="cw-s-meta">${time}${unread}</div>
            </div>`;
        }).join('');
    }

    function filterSellers(q) {
        const lq = q.toLowerCase();
        filteredSellers = sellers.filter(s =>
            (s.name + ' ' + s.surname).toLowerCase().includes(lq)
        );
        renderSellerList();
    }

    // ── Chat pane ──────────────────────────────────────────────────────────
    function openChatById(sellerId) {
        const s = sellers.find(s => s.id === sellerId);
        if (s) openChat(s);
    }

    async function openChat(seller) {
        activeSeller = seller;
        messages = [];
        lastMsgId = 0;

        // Update header
        const ini = initials(seller.name, seller.surname);
        const avatarEl = document.getElementById('cwHeaderAvatar');
        avatarEl.textContent = ini;
        avatarEl.classList.add('show');
        document.getElementById('cwHeaderBack').classList.add('show');
        document.getElementById('cwHeaderTitle').textContent = seller.name + ' ' + seller.surname;
        document.getElementById('cwHeaderSub').textContent = '🏪 Seller';
        document.getElementById('cwHeaderSub').classList.add('show');

        // Switch panes
        document.getElementById('cwSellerList').style.display = 'none';
        document.getElementById('cwChatPane').classList.add('open');

        // Focus input
        setTimeout(() => document.getElementById('cwMsgInput').focus(), 80);

        // Load history
        document.getElementById('cwMessages').innerHTML = '<div class="cw-loading" style="flex:1;"><div class="cw-spinner"></div></div>';
        try {
            const r = await fetch(ENDPOINT + `?action=get_history&seller_id=${seller.id}`);
            const data = await r.json();
            messages = data.messages || [];
            if (messages.length) lastMsgId = messages[messages.length-1].id;
            renderMessages();
            scrollBottom();
            // Update unread count in seller list
            const s = sellers.find(s => s.id === seller.id);
            if (s) { s.unread = 0; updateFab(); }
        } catch(e) {
            document.getElementById('cwMessages').innerHTML = '<div class="cw-empty"><div class="cw-empty-icon">⚠️</div><p>Could not load messages.</p></div>';
        }

        startPoll();
    }

    function backToList() {
        stopPoll();
        loadSellers(); // refresh list so unread counts update
    }

    // ── Message rendering ──────────────────────────────────────────────────
    function renderMessages() {
        const el = document.getElementById('cwMessages');
        if (!messages.length) {
            el.innerHTML = `<div class="cw-empty"><div class="cw-empty-icon">💬</div><p>Say hello to <strong>${esc(activeSeller.name)}</strong>!</p></div>`;
            return;
        }

        let html = '';
        let lastDate = null;
        messages.forEach(m => {
            const d = (m.created_at||'').split(' ')[0];
            if (d !== lastDate) {
                html += `<div class="cw-date-sep"><span>${fmtDateSep(m.created_at)}</span></div>`;
                lastDate = d;
            }
            const cls = m.mine ? 'mine' : 'theirs';
            const ini = m.mine ? '' : initials(activeSeller.name, activeSeller.surname);
            const av  = m.mine ? '' : `<div class="cw-bubble-av">${ini}</div>`;
            html += `<div class="cw-msg-row ${cls}" data-id="${m.id}">
                ${av}
                <div class="cw-bubble">
                    ${esc(m.message)}
                    <span class="cw-bubble-time">${fmtMsgTime(m.created_at)}</span>
                </div>
            </div>`;
        });
        el.innerHTML = html;
    }

    function scrollBottom(smooth) {
        const el = document.getElementById('cwMessages');
        el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }

    // ── Send ───────────────────────────────────────────────────────────────
    async function send() {
        if (!activeSeller) return;
        const input = document.getElementById('cwMsgInput');
        const text  = input.value.trim();
        if (!text) return;
        input.value = '';
        input.style.height = '';

        // Optimistic append
        const tempMsg = {
            id: Date.now(),
            mine: true,
            message: text,
            created_at: new Date().toISOString().replace('T',' ').slice(0,19)
        };
        messages.push(tempMsg);
        renderMessages();
        scrollBottom(true);

        try {
            const fd = new FormData();
            fd.append('action', 'send');
            fd.append('seller_id', activeSeller.id);
            fd.append('message', text);
            const r    = await fetch(ENDPOINT, { method: 'POST', body: fd });
            const data = await r.json();
            if (data.ok) {
                // Replace temp with real id
                const idx = messages.findIndex(m => m.id === tempMsg.id);
                if (idx >= 0) { messages[idx].id = data.id; lastMsgId = data.id; }
                // Update seller preview
                const s = sellers.find(s => s.id === activeSeller.id);
                if (s) { s.last_msg = text; s.last_time = tempMsg.created_at; }
            }
        } catch(e) { /* message stays optimistically */ }
    }

    // ── Polling ────────────────────────────────────────────────────────────
    function startPoll() {
        stopPoll();
        pollTimer = setInterval(poll, 3500);
    }
    function stopPoll() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }
    async function poll() {
        if (!activeSeller) return;
        try {
            const r    = await fetch(ENDPOINT + `?action=poll&seller_id=${activeSeller.id}&last_id=${lastMsgId}`);
            const data = await r.json();
            const newMsgs = data.messages || [];
            if (newMsgs.length) {
                messages.push(...newMsgs);
                lastMsgId = messages[messages.length-1].id;
                renderMessages();
                const el = document.getElementById('cwMessages');
                const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
                if (atBottom) scrollBottom(true);
            }
        } catch(e) {}
    }

    // ── Auto-resize textarea ───────────────────────────────────────────────
    function autoResize(el) {
        el.style.height = '';
        el.style.height = Math.min(el.scrollHeight, 80) + 'px';
    }

    // ── Public API ─────────────────────────────────────────────────────────
    return { open, close, toggle, openChatById, openChat, backToList, filterSellers, send, autoResize };
})();

// Open to specific seller if URL param present
document.addEventListener('DOMContentLoaded', () => {
    const cs = parseInt(new URLSearchParams(window.location.search).get('chat_seller')||'0');
    if (cs) { CW.open(); }

    // Background unread refresh every 30s even when panel is closed
    setInterval(async () => {
        try {
            const r    = await fetch(ENDPOINT + '?action=get_sellers');
            const data = await r.json();
            if (data.sellers) {
                const total = data.sellers.reduce((s, x) => s + (x.unread||0), 0);
                const badge = document.getElementById('cwFabBadge');
                if (total > 0) {
                    badge.textContent = total > 99 ? '99+' : total;
                    badge.classList.add('show');
                } else {
                    badge.classList.remove('show');
                }
            }
        } catch(e) {}
    }, 30000);
});
</script>
