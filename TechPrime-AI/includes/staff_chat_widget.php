<?php
/**
 * Floating staff chat widget. Included from staff_page_end() for
 * admin / retail_officer / inventory_custodian only.
 */
require_once __DIR__ . '/staff_chat_lib.php';

if (!isset($_SESSION['user_id']) || !staff_chat_role_ok((string)($_SESSION['role'] ?? ''))) {
    return;
}

$_cw_csrf = generateCsrfToken();
$_cw_endpoint = staff_chat_endpoint_href();
$_cw_messages = staff_messages_page_href();
$_cw_openStaff = (int)($_GET['staff_id'] ?? $_GET['chat_seller'] ?? 0);
?>
<style>
:root {
    --cw-teal: #61b337; --cw-teal-dk: #4b8b2a; --cw-yellow: #f3c400; --cw-yellow2: #f3c400;
    --cw-bg: #f3f4f5; --cw-surface: #ffffff; --cw-border: #e4e8ea; --cw-text: #1c1e21;
    --cw-muted: #65676b; --cw-sent-bg: #61b337; --cw-recv-bg: #e9ecef; --cw-radius: 16px;
    --cw-w: 340px; --cw-h: 480px;
}
#cwFab {
    position: fixed; right: 22px; bottom: 76px; z-index: 9000;
    display: flex; align-items: center; gap: 9px; padding: 11px 18px 11px 14px;
    background: var(--cw-teal); color: #fff; border: none; border-radius: 999px;
    cursor: pointer; font-family: inherit; font-size: 14px; font-weight: 700;
    box-shadow: 0 4px 18px rgba(97,179,55,.38); transition: transform .2s, box-shadow .2s; user-select: none;
}
#cwFab:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(97,179,55,.45); }
#cwFab .cw-fab-icon { font-size: 16px; color: var(--cw-yellow); line-height: 1; }
#cwFabAvatars { display: flex; margin-left: 4px; }
.cw-fab-av {
    width: 26px; height: 26px; border-radius: 50%; background: var(--cw-teal-dk);
    border: 2px solid var(--cw-teal); display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800; color: var(--cw-yellow2); margin-left: -8px; flex-shrink: 0;
}
.cw-fab-av:first-child { margin-left: 0; }
#cwFabBadge {
    position: absolute; top: -4px; right: -4px; background: #e53935; color: #fff;
    font-size: 10px; font-weight: 800; min-width: 18px; height: 18px; border-radius: 9px;
    display: none; align-items: center; justify-content: center; padding: 0 4px; border: 2px solid #fff;
}
#cwFabBadge.show { display: flex; }
#cwPanel {
    position: fixed; right: 22px; bottom: 140px; z-index: 9001; width: var(--cw-w); height: var(--cw-h);
    background: var(--cw-surface); border-radius: var(--cw-radius);
    box-shadow: 0 12px 48px rgba(0,0,0,.2); display: none; flex-direction: column; overflow: hidden;
    font-family: inherit; transform-origin: bottom right;
    animation: cwSlideIn .22s cubic-bezier(.34,1.3,.64,1);
}
#cwPanel.open { display: flex; }
@keyframes cwSlideIn { from { opacity: 0; transform: scale(.9) translateY(12px); } to { opacity: 1; transform: scale(1) translateY(0); } }
#cwHeader { background: var(--cw-teal); padding: 13px 14px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
#cwHeaderBack, #cwExpandBtn, #cwCloseBtn {
    background: rgba(255,255,255,.18); border: none; border-radius: 50%;
    width: 30px; height: 30px; color: #fff; font-size: 15px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0; text-decoration: none;
}
#cwHeaderBack { display: none; }
#cwHeaderBack.show { display: flex; }
#cwHeaderBack:hover, #cwExpandBtn:hover, #cwCloseBtn:hover { background: rgba(255,255,255,.3); }
#cwHeaderAvatar {
    width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,.2);
    display: none; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: var(--cw-yellow2); flex-shrink: 0;
}
#cwHeaderAvatar.show { display: flex; }
#cwHeaderTitle { font-size: 15px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#cwHeaderSub { font-size: 11px; color: rgba(255,255,255,.75); display: none; }
#cwHeaderSub.show { display: block; }
#cwSellerList { flex: 1; overflow-y: auto; display: flex; flex-direction: column; }
.cw-seller-search { padding: 10px 12px 8px; border-bottom: 1px solid var(--cw-border); flex-shrink: 0; }
.cw-seller-search input {
    width: 100%; padding: 7px 12px; border: 1.5px solid var(--cw-border); border-radius: 999px;
    font-size: 13px; outline: none; font-family: inherit; color: var(--cw-text); background: var(--cw-bg);
}
.cw-seller-search input:focus { border-color: var(--cw-teal); }
.cw-seller-item { display: flex; align-items: center; gap: 11px; padding: 11px 14px; cursor: pointer; border-bottom: 1px solid var(--cw-border); }
.cw-seller-item:hover { background: #f0f9fa; }
.cw-s-av {
    width: 42px; height: 42px; border-radius: 50%;
    background: linear-gradient(135deg, var(--cw-teal) 0%, var(--cw-teal-dk) 100%);
    display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 800;
    color: var(--cw-yellow2); flex-shrink: 0;
}
.cw-s-body { flex: 1; min-width: 0; }
.cw-s-name { font-size: 14px; font-weight: 700; color: var(--cw-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cw-s-preview { font-size: 12px; color: var(--cw-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.cw-s-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.cw-s-time { font-size: 11px; color: var(--cw-muted); }
.cw-s-unread {
    background: var(--cw-teal); color: #fff; font-size: 10px; font-weight: 800;
    min-width: 18px; height: 18px; border-radius: 9px; display: flex; align-items: center; justify-content: center; padding: 0 4px;
}
.cw-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--cw-muted); font-size: 13px; gap: 8px; text-align: center; padding: 20px; }
.cw-empty-icon { font-size: 28px; opacity: .55; }
.cw-loading { flex: 1; display: flex; align-items: center; justify-content: center; }
.cw-spinner { width: 22px; height: 22px; border: 3px solid var(--cw-border); border-top-color: var(--cw-teal); border-radius: 50%; animation: cwSpin .7s linear infinite; }
@keyframes cwSpin { to { transform: rotate(360deg); } }
#cwChatPane { flex: 1; display: none; flex-direction: column; overflow: hidden; }
#cwChatPane.open { display: flex; }
#cwMessages { flex: 1; overflow-y: auto; padding: 12px 14px; display: flex; flex-direction: column; gap: 6px; background: #f7f8fa; }
.cw-msg-row { display: flex; align-items: flex-end; gap: 6px; }
.cw-msg-row.mine { justify-content: flex-end; }
.cw-bubble-av {
    width: 26px; height: 26px; border-radius: 50%;
    background: linear-gradient(135deg, var(--cw-teal) 0%, var(--cw-teal-dk) 100%);
    display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: var(--cw-yellow2);
}
.cw-bubble { max-width: 78%; padding: 9px 13px; border-radius: 18px; font-size: 13.5px; line-height: 1.45; word-break: break-word; }
.cw-msg-row.mine .cw-bubble { background: var(--cw-sent-bg); color: #fff; border-bottom-right-radius: 4px; }
.cw-msg-row.theirs .cw-bubble { background: var(--cw-recv-bg); color: var(--cw-text); border-bottom-left-radius: 4px; }
.cw-bubble-time { font-size: 10px; display: block; text-align: right; margin-top: 3px; color: rgba(255,255,255,.65); }
.cw-msg-row.theirs .cw-bubble-time { color: var(--cw-muted); }
.cw-date-sep { text-align: center; font-size: 11px; color: var(--cw-muted); margin: 8px 0 4px; }
#cwInputBar { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-top: 1px solid var(--cw-border); background: var(--cw-surface); }
#cwMsgInput {
    flex: 1; padding: 9px 14px; border: 1.5px solid var(--cw-border); border-radius: 999px;
    font-size: 13.5px; font-family: inherit; outline: none; resize: none; max-height: 80px;
}
#cwMsgInput:focus { border-color: var(--cw-teal); }
#cwSendBtn {
    width: 36px; height: 36px; border-radius: 50%; background: var(--cw-teal); border: none; color: #fff;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
}
#cwSendBtn:hover { background: var(--cw-teal-dk); }
body.sidebar-open #cwFab, body.sidebar-open #cwPanel { display: none; }
/* Phones: icon-only button, panel spans the screen width */
@media (max-width: 600px) {
    #cwFab { right: 14px; bottom: 16px; padding: 14px; }
    #cwFab .cw-fab-label, #cwFabAvatars { display: none; }
    #cwFab .cw-fab-icon { font-size: 20px; }
    #cwPanel {
        left: 8px; right: 8px; bottom: 80px; width: auto;
        height: min(var(--cw-h), calc(100vh - 100px));
        height: min(var(--cw-h), calc(100dvh - 100px));
    }
    #cwMsgInput { font-size: 16px; } /* stops iOS zooming into the field */
}
</style>

<button id="cwFab" onclick="CW.toggle()" aria-label="Open Messages">
    <span class="cw-fab-icon"><i class="fas fa-comments"></i></span>
    <span class="cw-fab-label">Messages</span>
    <div id="cwFabAvatars"></div>
    <span id="cwFabBadge"></span>
</button>

<div id="cwPanel" role="dialog" aria-label="Staff chat">
    <div id="cwHeader">
        <button type="button" id="cwHeaderBack" onclick="CW.backToList()" title="Back">‹</button>
        <div id="cwHeaderAvatar"></div>
        <div style="flex:1;min-width:0;">
            <div id="cwHeaderTitle">Messages</div>
            <div id="cwHeaderSub">Staff</div>
        </div>
        <a id="cwExpandBtn" href="<?php echo h($_cw_messages); ?>" title="Open full Messages"><i class="fas fa-expand"></i></a>
        <button type="button" id="cwCloseBtn" onclick="CW.close()" title="Close">×</button>
    </div>
    <div id="cwSellerList">
        <div class="cw-seller-search">
            <input type="text" id="cwSellerSearch" placeholder="Search staff…" oninput="CW.filterStaff(this.value)">
        </div>
        <div id="cwSellerItems" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;">
            <div class="cw-loading"><div class="cw-spinner"></div></div>
        </div>
    </div>
    <div id="cwChatPane">
        <div id="cwMessages"></div>
        <div id="cwInputBar">
            <textarea id="cwMsgInput" placeholder="Write a message…" rows="1"
                oninput="CW.autoResize(this)"
                onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();CW.send();}"></textarea>
            <button type="button" id="cwSendBtn" onclick="CW.send()" title="Send">➤</button>
        </div>
    </div>
</div>

<script>
const CW = (() => {
    const ENDPOINT = <?php echo json_encode($_cw_endpoint); ?>;
    const CSRF = <?php echo json_encode($_cw_csrf); ?>;
    const OPEN_ID = <?php echo (int)$_cw_openStaff; ?>;

    let isOpen = false;
    let staff = [];
    let filteredStaff = [];
    let active = null;
    let messages = [];
    let lastMsgId = 0;
    let pollTimer = null;

    function esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function initials(name, surname) {
        return ((name||'')[0]||'').toUpperCase() + ((surname||'')[0]||'').toUpperCase();
    }
    function fmtTime(ts) {
        if (!ts) return '';
        const d = new Date(String(ts).replace(' ', 'T'));
        const diff = (Date.now() - d) / 1000;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff/60) + 'm';
        if (diff < 86400) return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        return d.toLocaleDateString([], {month:'short', day:'numeric'});
    }
    function fmtMsgTime(ts) {
        if (!ts) return '';
        return new Date(String(ts).replace(' ', 'T')).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    }
    function fmtDateSep(ts) {
        if (!ts) return '';
        const d = new Date(String(ts).replace(' ', 'T'));
        const today = new Date();
        const yesterday = new Date(today); yesterday.setDate(yesterday.getDate()-1);
        if (d.toDateString() === today.toDateString()) return 'Today';
        if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
        return d.toLocaleDateString([], {weekday:'long', month:'long', day:'numeric'});
    }

    function updateFab() {
        const avatarEl = document.getElementById('cwFabAvatars');
        const badgeEl = document.getElementById('cwFabBadge');
        avatarEl.innerHTML = staff.slice(0, 3).map(s =>
            `<div class="cw-fab-av">${initials(s.name,s.surname)}</div>`
        ).join('');
        const total = staff.reduce((sum, s) => sum + (s.unread||0), 0);
        if (total > 0) {
            badgeEl.textContent = total > 99 ? '99+' : total;
            badgeEl.classList.add('show');
        } else {
            badgeEl.classList.remove('show');
        }
    }

    function open() {
        isOpen = true;
        document.getElementById('cwPanel').classList.add('open');
        if (!staff.length) loadStaff();
        else renderList();
        if (OPEN_ID) {
            const found = staff.find(s => s.id === OPEN_ID);
            if (found) openChat(found);
        }
    }
    function close() {
        isOpen = false;
        document.getElementById('cwPanel').classList.remove('open');
        stopPoll();
    }
    function toggle() { isOpen ? close() : open(); }

    async function loadStaff() {
        const el = document.getElementById('cwSellerItems');
        el.innerHTML = '<div class="cw-loading"><div class="cw-spinner"></div></div>';
        try {
            const r = await fetch(ENDPOINT + '?action=get_staff');
            const data = await r.json();
            staff = data.staff || [];
            filteredStaff = staff;
            renderList();
            updateFab();
            if (OPEN_ID) {
                const found = staff.find(s => s.id === OPEN_ID);
                if (found) openChat(found);
            }
        } catch (e) {
            el.innerHTML = '<div class="cw-empty"><div class="cw-empty-icon">⚠️</div><p>Could not load staff.</p></div>';
        }
    }

    function renderList() {
        document.getElementById('cwSellerList').style.display = 'flex';
        document.getElementById('cwChatPane').classList.remove('open');
        document.getElementById('cwHeaderBack').classList.remove('show');
        document.getElementById('cwHeaderAvatar').classList.remove('show');
        document.getElementById('cwHeaderTitle').textContent = 'Messages';
        document.getElementById('cwHeaderSub').classList.remove('show');
        active = null;
        stopPoll();

        const el = document.getElementById('cwSellerItems');
        if (!filteredStaff.length) {
            el.innerHTML = '<div class="cw-empty"><div class="cw-empty-icon"><i class="fas fa-user-friends"></i></div><p>No staff available.</p></div>';
            return;
        }
        el.innerHTML = filteredStaff.map(s => {
            const preview = s.last_msg ? esc(s.last_msg) : '<em style="color:#aaa">Start a conversation</em>';
            const unread = s.unread ? `<div class="cw-s-unread">${s.unread > 99 ? '99+' : s.unread}</div>` : '';
            const time = s.last_time ? `<div class="cw-s-time">${fmtTime(s.last_time)}</div>` : '';
            return `<div class="cw-seller-item" onclick="CW.openChatById(${s.id})">
                <div class="cw-s-av">${initials(s.name, s.surname)}</div>
                <div class="cw-s-body">
                    <div class="cw-s-name">${esc(s.name)} ${esc(s.surname)}</div>
                    <div class="cw-s-preview">${preview}</div>
                </div>
                <div class="cw-s-meta">${time}${unread}</div>
            </div>`;
        }).join('');
    }

    function filterStaff(q) {
        const lq = q.toLowerCase();
        filteredStaff = staff.filter(s => (s.name + ' ' + s.surname + ' ' + (s.role_label||'')).toLowerCase().includes(lq));
        renderList();
    }

    function openChatById(id) {
        const s = staff.find(x => x.id === id);
        if (s) openChat(s);
    }

    async function openChat(person) {
        active = person;
        messages = [];
        lastMsgId = 0;
        const ini = initials(person.name, person.surname);
        const avatarEl = document.getElementById('cwHeaderAvatar');
        avatarEl.textContent = ini;
        avatarEl.classList.add('show');
        document.getElementById('cwHeaderBack').classList.add('show');
        document.getElementById('cwHeaderTitle').textContent = person.name + ' ' + person.surname;
        document.getElementById('cwHeaderSub').textContent = person.role_label || 'Staff';
        document.getElementById('cwHeaderSub').classList.add('show');
        document.getElementById('cwSellerList').style.display = 'none';
        document.getElementById('cwChatPane').classList.add('open');
        setTimeout(() => document.getElementById('cwMsgInput').focus(), 80);
        document.getElementById('cwMessages').innerHTML = '<div class="cw-loading" style="flex:1;"><div class="cw-spinner"></div></div>';
        try {
            const r = await fetch(ENDPOINT + `?action=get_history&staff_id=${person.id}`);
            const data = await r.json();
            messages = data.messages || [];
            if (messages.length) lastMsgId = messages[messages.length-1].id;
            renderMessages();
            scrollBottom();
            const s = staff.find(x => x.id === person.id);
            if (s) { s.unread = 0; updateFab(); }
        } catch (e) {
            document.getElementById('cwMessages').innerHTML = '<div class="cw-empty"><div class="cw-empty-icon">⚠️</div><p>Could not load messages.</p></div>';
        }
        startPoll();
    }

    function backToList() {
        stopPoll();
        loadStaff();
    }

    function renderMessages() {
        const el = document.getElementById('cwMessages');
        if (!messages.length) {
            el.innerHTML = `<div class="cw-empty"><div class="cw-empty-icon">💬</div><p>Say hello to <strong>${esc(active.name)}</strong>!</p></div>`;
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
            const av = m.mine ? '' : `<div class="cw-bubble-av">${initials(active.name, active.surname)}</div>`;
            html += `<div class="cw-msg-row ${cls}" data-id="${m.id}">
                ${av}
                <div class="cw-bubble">${esc(m.message).replace(/\n/g,'<br>')}<span class="cw-bubble-time">${fmtMsgTime(m.created_at)}</span></div>
            </div>`;
        });
        el.innerHTML = html;
    }

    function scrollBottom(smooth) {
        const el = document.getElementById('cwMessages');
        el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }

    async function send() {
        if (!active) return;
        const input = document.getElementById('cwMsgInput');
        const text = input.value.trim();
        if (!text) return;
        input.value = '';
        input.style.height = '';
        const tempMsg = { id: Date.now(), mine: true, message: text, created_at: new Date().toISOString().replace('T',' ').slice(0,19) };
        messages.push(tempMsg);
        renderMessages();
        scrollBottom(true);
        try {
            const fd = new FormData();
            fd.append('action', 'send');
            fd.append('staff_id', String(active.id));
            fd.append('message', text);
            fd.append('csrf_token', CSRF);
            const r = await fetch(ENDPOINT, { method: 'POST', body: fd });
            const data = await r.json();
            if (data.ok) {
                const idx = messages.findIndex(m => m.id === tempMsg.id);
                if (idx >= 0) { messages[idx].id = data.id; lastMsgId = data.id; }
                const s = staff.find(x => x.id === active.id);
                if (s) { s.last_msg = text; s.last_time = tempMsg.created_at; }
            }
        } catch (e) {}
    }

    function startPoll() { stopPoll(); pollTimer = setInterval(poll, 3500); }
    function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
    async function poll() {
        if (!active) return;
        try {
            const r = await fetch(ENDPOINT + `?action=poll&staff_id=${active.id}&last_id=${lastMsgId}`);
            const data = await r.json();
            const newMsgs = data.messages || [];
            if (newMsgs.length) {
                messages.push(...newMsgs);
                lastMsgId = messages[messages.length-1].id;
                renderMessages();
                const el = document.getElementById('cwMessages');
                if (el.scrollHeight - el.scrollTop - el.clientHeight < 60) scrollBottom(true);
            }
        } catch (e) {}
    }

    function autoResize(el) {
        el.style.height = '';
        el.style.height = Math.min(el.scrollHeight, 80) + 'px';
    }

    return { open, close, toggle, openChatById, openChat, backToList, filterStaff, send, autoResize };
})();

document.addEventListener('DOMContentLoaded', () => {
    if (<?php echo (int)$_cw_openStaff; ?>) CW.open();
    setInterval(async () => {
        try {
            const r = await fetch(<?php echo json_encode($_cw_endpoint); ?> + '?action=get_staff');
            const data = await r.json();
            if (data.staff) {
                const total = data.staff.reduce((s, x) => s + (x.unread||0), 0);
                const badge = document.getElementById('cwFabBadge');
                if (!badge) return;
                if (total > 0) {
                    badge.textContent = total > 99 ? '99+' : total;
                    badge.classList.add('show');
                } else {
                    badge.classList.remove('show');
                }
            }
        } catch (e) {}
    }, 30000);
});
</script>
