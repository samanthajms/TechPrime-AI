<?php
/**
 * Floating staff chat widget. Included from staff_page_end() for
 * admin / retail_officer / inventory_custodian only.
 *
 * The panel embeds the role's Messages page in compact mode (`?embed=1`,
 * see staff_messages_embed_page()), so the mini chat has exactly the same
 * features and look as the full Messages page. The embedded page reports its
 * unread total to this widget, and is paused while the panel is closed so it
 * never marks messages as read in the background.
 */
require_once __DIR__ . '/staff_chat_lib.php';

if (!isset($_SESSION['user_id']) || !staff_chat_role_ok((string)($_SESSION['role'] ?? ''))) {
    return;
}

$_cw_endpoint = staff_chat_endpoint_href();
$_cw_messages = staff_messages_page_href();
$_cw_openStaff = (int)($_GET['staff_id'] ?? $_GET['chat_seller'] ?? 0);
?>
<style>
#cwFab {
    position: fixed; right: 22px; bottom: 76px; z-index: 9000;
    display: flex; align-items: center; gap: 9px; padding: 10px 14px 10px 16px;
    background: var(--ep-green, #62b236); color: #fff; border: none; border-radius: 999px;
    cursor: pointer; font-family: inherit; font-size: 14px; font-weight: 700;
    box-shadow: 0 4px 18px rgba(98,178,54,.38); transition: transform .2s, box-shadow .2s, background .2s; user-select: none;
}
#cwFab:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(98,178,54,.45); }
#cwFab:focus-visible { outline: 3px solid var(--ep-yellow, #f3c400); outline-offset: 2px; }
#cwFab[aria-expanded="true"] { background: var(--ep-green-dark, #4b8b2a); }
#cwFab .cw-fab-icon { font-size: 16px; color: var(--ep-yellow, #f3c400); line-height: 1; }
#cwFabAvatars { display: flex; margin-left: 2px; }
#cwFabAvatars:empty { display: none; }
.cw-fab-av {
    width: 26px; height: 26px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
    border: 2px solid var(--ep-green, #62b236); margin-left: -8px; box-sizing: border-box;
    display: flex; align-items: center; justify-content: center;
    font-size: 9.5px; font-weight: 700; color: #fff; background: var(--slate-500, #64748b);
}
.cw-fab-av:first-child { margin-left: 0; }
.cw-fab-av img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cw-fab-av.role-admin { background: var(--ep-green-dark, #4b8b2a); }
.cw-fab-av.role-retail_officer { background: var(--ep-yellow-dark, #c9a200); }
.cw-fab-av.role-inventory_custodian { background: var(--slate-600, #475569); }
#cwFabBadge {
    position: absolute; top: -5px; right: -5px; background: #e53935; color: #fff;
    font-size: 10.5px; font-weight: 800; min-width: 20px; height: 20px; border-radius: 10px; box-sizing: border-box;
    display: none; align-items: center; justify-content: center; padding: 0 5px; border: 2px solid #fff;
}
#cwFabBadge.show { display: flex; }

#cwPanel {
    position: fixed; right: 22px; bottom: 136px; z-index: 9001;
    width: 380px; height: min(600px, calc(100vh - 160px)); min-height: 360px;
    background: #fff; border-radius: 16px; border: 1px solid var(--border, #e4e8ea);
    box-shadow: 0 16px 48px rgba(15,23,42,.22); display: none; flex-direction: column; overflow: hidden;
    font-family: inherit; transform-origin: bottom right;
}
#cwPanel.open { display: flex; animation: cwSlideIn .22s cubic-bezier(.34,1.3,.64,1); }
@keyframes cwSlideIn { from { opacity: 0; transform: scale(.94) translateY(10px); } to { opacity: 1; transform: none; } }
#cwHeader {
    background: var(--ep-green, #62b236); color: #fff; padding: 10px 10px 10px 16px;
    display: flex; align-items: center; gap: 8px; flex-shrink: 0;
}
#cwHeader .cw-title { flex: 1; min-width: 0; display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 800; }
#cwHeader .cw-title i { color: var(--ep-yellow, #f3c400); font-size: 15px; }
#cwHeaderCount {
    background: #fff; color: var(--ep-green-dark, #4b8b2a); font-size: 11px; font-weight: 800;
    border-radius: 999px; padding: 1px 8px; line-height: 1.6;
}
#cwHeaderCount[hidden] { display: none; }
.cw-head-btn {
    width: 32px; height: 32px; border-radius: 50%; border: 0; background: rgba(255,255,255,.18); color: #fff;
    display: inline-flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer;
    text-decoration: none; flex-shrink: 0; transition: background .15s;
}
.cw-head-btn:hover, .cw-head-btn:focus-visible { background: rgba(255,255,255,.32); outline: none; color: #fff; }
#cwBody { position: relative; flex: 1; min-height: 0; background: #fff; }
#cwFrame { display: block; width: 100%; height: 100%; border: 0; }
#cwLoading {
    position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 10px; background: #fff; color: var(--text-muted, #64748b); font-size: 13px; text-align: center; padding: 20px;
}
#cwLoading[hidden] { display: none; }
.cw-spinner {
    width: 26px; height: 26px; border: 3px solid var(--border, #e4e8ea); border-top-color: var(--ep-green, #62b236);
    border-radius: 50%; animation: cwSpin .7s linear infinite;
}
@keyframes cwSpin { to { transform: rotate(360deg); } }
body.sidebar-open #cwFab, body.sidebar-open #cwPanel { display: none; }
/* Phones: icon-only button, panel spans the screen width */
@media (max-width: 600px) {
    #cwFab { right: 14px; bottom: 16px; padding: 14px; }
    #cwFab .cw-fab-label, #cwFabAvatars { display: none; }
    #cwFab .cw-fab-icon { font-size: 20px; }
    #cwPanel {
        left: 8px; right: 8px; bottom: 80px; width: auto; min-height: 0;
        height: calc(100vh - 96px);
        height: calc(100dvh - 96px);
    }
}
</style>

<button type="button" id="cwFab" aria-label="Open Messages" aria-expanded="false" aria-controls="cwPanel">
    <span class="cw-fab-icon"><i class="fas fa-comments" aria-hidden="true"></i></span>
    <span class="cw-fab-label">Messages</span>
    <span id="cwFabAvatars" aria-hidden="true"></span>
    <span id="cwFabBadge"></span>
</button>

<div id="cwPanel" role="dialog" aria-label="Messages">
    <div id="cwHeader">
        <div class="cw-title"><i class="fas fa-comments" aria-hidden="true"></i> Messages <span id="cwHeaderCount" hidden></span></div>
        <a class="cw-head-btn" id="cwExpandBtn" href="<?php echo h($_cw_messages); ?>" title="Open full Messages" aria-label="Open full Messages"><i class="fas fa-expand"></i></a>
        <button type="button" class="cw-head-btn" id="cwCloseBtn" title="Minimize" aria-label="Minimize Messages"><i class="fas fa-minus"></i></button>
    </div>
    <div id="cwBody">
        <div id="cwLoading"><div class="cw-spinner"></div><span>Loading messages…</span></div>
    </div>
</div>

<script>
(() => {
    const ENDPOINT = <?php echo json_encode($_cw_endpoint); ?>;
    const PAGE = <?php echo json_encode($_cw_messages); ?>;
    const OPEN_ID = <?php echo (int)$_cw_openStaff; ?>;
    const BADGE_POLL_MS = 20000;

    const fab = document.getElementById('cwFab');
    const panel = document.getElementById('cwPanel');
    const body = document.getElementById('cwBody');
    const loading = document.getElementById('cwLoading');
    const expandBtn = document.getElementById('cwExpandBtn');
    let frame = null;
    let isOpen = false;
    let activeId = 0;

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function initials(p) {
        return ((String(p.name || '').trim().charAt(0)) + (String(p.surname || '').trim().charAt(0))).toUpperCase() || '?';
    }

    // Unread badge + the three most recent conversation partners on the button.
    function setSummary(unread, recent) {
        const label = unread > 99 ? '99+' : String(unread);
        const badge = document.getElementById('cwFabBadge');
        badge.textContent = label;
        badge.classList.toggle('show', unread > 0);
        const count = document.getElementById('cwHeaderCount');
        count.textContent = label + ' unread';
        count.hidden = unread === 0;
        fab.setAttribute('aria-label', unread ? `Open Messages (${label} unread)` : 'Open Messages');
        if (recent) {
            document.getElementById('cwFabAvatars').innerHTML = recent.map(p => {
                const role = 'role-' + String(p.role || '').replace(/[^a-z_]/g, '');
                return p.avatar
                    ? `<span class="cw-fab-av ${role}"><img src="${esc(p.avatar)}" alt="" data-ini="${esc(initials(p))}"></span>`
                    : `<span class="cw-fab-av ${role}">${esc(initials(p))}</span>`;
            }).join('');
        }
    }
    document.getElementById('cwFabAvatars').addEventListener('error', (e) => {
        const img = e.target;
        if (img.tagName === 'IMG' && img.dataset.ini) img.parentNode.textContent = img.dataset.ini;
    }, true);

    // While the panel is closed, the widget checks for new messages itself.
    async function refreshBadge() {
        if (document.hidden || (isOpen && frame)) return;
        try {
            const r = await fetch(ENDPOINT + '?action=get_staff');
            if (!r.ok) return;
            const data = await r.json();
            const staff = data.staff || [];
            setSummary(staff.reduce((n, s) => n + (s.unread || 0), 0), staff.slice(0, 3));
        } catch (e) { /* offline: keep the last state */ }
    }

    function tellFrame(type) {
        if (frame && frame.contentWindow) {
            frame.contentWindow.postMessage({ source: 'staff-chat-widget', type }, location.origin);
        }
    }

    function createFrame() {
        frame = document.createElement('iframe');
        frame.id = 'cwFrame';
        frame.title = 'Messages';
        frame.addEventListener('load', () => {
            // Signed out / session expired: the page redirected to the login screen.
            let href = '';
            try { href = frame.contentWindow.location.href; } catch (e) { /* not same-origin */ }
            if (!/_messages\.php$/i.test(new URL(href || location.href).pathname)) {
                window.location.href = href || location.href;
                return;
            }
            loading.hidden = true;
            if (!isOpen) tellFrame('pause');
        });
        frame.src = PAGE + '?embed=1' + (OPEN_ID ? '&staff_id=' + OPEN_ID : '');
        body.appendChild(frame);
    }

    window.addEventListener('message', (e) => {
        if (!frame || e.source !== frame.contentWindow || e.origin !== location.origin) return;
        const d = e.data || {};
        if (d.source !== 'staff-messages') return;
        setSummary(Number(d.unread) || 0, d.recent || null);
        activeId = Number(d.activeId) || 0;
        expandBtn.href = PAGE + (activeId ? '?staff_id=' + activeId : '');
    });

    function open() {
        isOpen = true;
        panel.classList.add('open');
        fab.setAttribute('aria-expanded', 'true');
        if (!frame) createFrame(); else tellFrame('resume');
        if (frame && loading.hidden) frame.focus();
    }
    function close() {
        isOpen = false;
        panel.classList.remove('open');
        fab.setAttribute('aria-expanded', 'false');
        tellFrame('pause');
        refreshBadge();
    }

    fab.addEventListener('click', () => (isOpen ? close() : open()));
    document.getElementById('cwCloseBtn').addEventListener('click', () => { close(); fab.focus(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen && !document.getElementById('ias-alert-overlay')) { close(); fab.focus(); }
    });

    refreshBadge();
    setInterval(refreshBadge, BADGE_POLL_MS);
    document.addEventListener('visibilitychange', refreshBadge);
    if (OPEN_ID) open();
})();
</script>
