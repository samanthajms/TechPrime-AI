<?php
/**
 * Two-pane staff Messages UI (shared by Admin / Retail / Inventory pages).
 */
require_once __DIR__ . '/staff_chat_lib.php';

if (!function_exists('staff_messages_extra_head')) {
    function staff_messages_extra_head(): string
    {
        return <<<'CSS'
<style>
[hidden] { display: none !important; }
.chat-container { display: flex; height: calc(100vh - 180px); min-height: 420px; }
.chat-card {
    background: #fff; border-radius: 12px; border: 1px solid var(--border);
    box-shadow: var(--card-shadow); display: flex; width: 100%; overflow: hidden;
}
.contacts-column { width: 300px; border-right: 1px solid #eee; display: flex; flex-direction: column; flex-shrink: 0; }
.column-head {
    padding: 18px 20px; font-weight: 800; border-bottom: 1px solid #eee;
    font-size: 13px; background: var(--ep-green-light); color: var(--ep-green-dark);
    text-transform: uppercase; letter-spacing: 0.04em;
}
.contact-link {
    padding: 14px 20px; border-bottom: 1px solid #f5f5f5;
    text-decoration: none; color: #555; font-size: 14px; display: block; cursor: pointer; background: none; border-left: 4px solid transparent; width: 100%; text-align: left;
}
.contact-link:hover { background: var(--ep-green-light); }
.contact-link.active {
    background: var(--ep-green-light); border-left-color: var(--ep-green);
    font-weight: 700; color: var(--ep-green-dark);
}
.contact-role { display: block; font-size: 11px; font-weight: 600; color: var(--text-muted); margin-top: 2px; text-transform: none; letter-spacing: 0; }
.chat-column { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat-header {
    padding: 15px 22px; border-bottom: 1px solid #eee;
    display: flex; align-items: center; justify-content: space-between; background: #fff;
}
.chat-messages {
    flex: 1; padding: 22px; overflow-y: auto; background: #fafafa;
    display: flex; flex-direction: column; gap: 12px;
}
.bubble {
    max-width: 70%; padding: 12px 16px; border-radius: 18px;
    font-size: 14px; line-height: 1.45; box-shadow: 0 2px 5px rgba(0,0,0,0.04); white-space: pre-wrap; word-break: break-word;
}
.bubble.sent {
    align-self: flex-end; background: var(--ep-green); color: #fff;
    border-bottom-right-radius: 4px;
}
.bubble.received {
    align-self: flex-start; background: #f1f1f1; color: #444;
    border-bottom-left-radius: 4px;
}
.chat-footer { padding: 16px 20px; border-top: 1px solid #eee; background: #fff; }
.input-box {
    display: flex; gap: 12px; background: #f4f7f6; padding: 8px 14px;
    border-radius: 30px; border: 1px solid #eee;
}
.input-box input {
    flex: 1; border: none; background: transparent; outline: none;
    padding: 8px; font-family: inherit; font-size: 14px;
}
.send-btn {
    background: var(--ep-green); color: #fff; border: none;
    padding: 8px 22px; border-radius: 20px; cursor: pointer; font-weight: 700;
}
.send-btn:hover { background: var(--ep-green-dark); }
.chat-empty { margin: auto; text-align: center; color: #bbb; }
.chat-empty i { font-size: 48px; opacity: 0.4; margin-bottom: 10px; display: block; }
@media (max-width: 900px) {
    .chat-container { flex-direction: column; height: auto; }
    .contacts-column { width: 100%; max-height: 200px; border-right: none; border-bottom: 1px solid #eee; }
}
</style>
CSS;
    }
}

if (!function_exists('staff_messages_render')) {
    function staff_messages_render(): void
    {
        $csrf = generateCsrfToken();
        $endpoint = staff_chat_endpoint_href();
        $openId = (int)($_GET['staff_id'] ?? 0);
        ?>
        <div class="chat-container">
            <div class="chat-card">
                <aside class="contacts-column">
                    <div class="column-head"><i class="fas fa-comments"></i> Staff</div>
                    <div id="smContacts" style="overflow-y: auto; flex: 1;">
                        <p class="text-muted text-small" style="padding:20px;text-align:center;">Loading…</p>
                    </div>
                </aside>
                <section class="chat-column">
                    <div class="chat-header">
                        <span id="smTitle" style="font-weight: 800; color: #333;">Select a Chat</span>
                        <span id="smStatus" style="font-size: 12px; color: var(--ep-green); font-weight: 700;"></span>
                    </div>
                    <div class="chat-messages" id="chatWindow">
                        <div class="chat-empty">
                            <i class="fas fa-envelope-open-text"></i>
                            <p style="font-weight: 600;">Pick a staff member to start chatting</p>
                        </div>
                    </div>
                    <div class="chat-footer" id="smFooter" hidden>
                        <form class="input-box" id="smForm">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                            <input type="text" id="smInput" name="message" placeholder="Type your message…" required autocomplete="off">
                            <button type="submit" class="send-btn">Send</button>
                        </form>
                    </div>
                </section>
            </div>
        </div>
        <script>
        const SM = (() => {
            const ENDPOINT = <?php echo json_encode($endpoint); ?>;
            const CSRF = <?php echo json_encode($csrf); ?>;
            const OPEN_ID = <?php echo (int)$openId; ?>;
            let staff = [];
            let active = null;
            let lastId = 0;
            let pollTimer = null;

            function esc(s) {
                return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            function renderContacts() {
                const el = document.getElementById('smContacts');
                if (!staff.length) {
                    el.innerHTML = '<p style="padding:20px;color:#bbb;font-size:13px;text-align:center;">No staff available.</p>';
                    return;
                }
                el.innerHTML = staff.map(s => `
                    <button type="button" class="contact-link ${active && active.id === s.id ? 'active' : ''}" data-id="${s.id}">
                        ${esc(s.name)} ${esc(s.surname)}
                        <span class="contact-role">${esc(s.role_label || '')}${s.unread ? ' · ' + s.unread + ' new' : ''}</span>
                    </button>
                `).join('');
                el.querySelectorAll('.contact-link').forEach(btn => {
                    btn.addEventListener('click', () => openChat(parseInt(btn.getAttribute('data-id'), 10)));
                });
            }

            async function loadStaff() {
                const r = await fetch(ENDPOINT + '?action=get_staff');
                const data = await r.json();
                staff = data.staff || [];
                renderContacts();
                if (OPEN_ID) openChat(OPEN_ID);
            }

            async function openChat(id) {
                const person = staff.find(s => s.id === id);
                if (!person) return;
                active = person;
                lastId = 0;
                document.getElementById('smTitle').textContent = person.name + ' ' + person.surname;
                document.getElementById('smStatus').textContent = person.role_label || '';
                document.getElementById('smFooter').hidden = false;
                renderContacts();
                const win = document.getElementById('chatWindow');
                win.innerHTML = '<p class="text-muted text-small" style="margin:auto;">Loading…</p>';
                const r = await fetch(ENDPOINT + `?action=get_history&staff_id=${id}`);
                const data = await r.json();
                const msgs = data.messages || [];
                if (msgs.length) lastId = msgs[msgs.length - 1].id;
                renderMessages(msgs);
                person.unread = 0;
                startPoll();
            }

            function renderMessages(msgs) {
                const win = document.getElementById('chatWindow');
                if (!msgs.length) {
                    win.innerHTML = `<div class="chat-empty"><i class="fas fa-comment"></i><p style="font-weight:600;">Say hello to ${esc(active.name)}!</p></div>`;
                    return;
                }
                win.innerHTML = msgs.map(m => `
                    <div class="bubble ${m.mine ? 'sent' : 'received'}">
                        ${esc(m.message)}
                        <div style="font-size:9px;margin-top:5px;opacity:.7;text-align:right;">${esc((m.created_at||'').slice(11,16))}</div>
                    </div>
                `).join('');
                win.scrollTop = win.scrollHeight;
            }

            async function send(text) {
                if (!active || !text) return;
                const fd = new FormData();
                fd.append('action', 'send');
                fd.append('staff_id', String(active.id));
                fd.append('message', text);
                fd.append('csrf_token', CSRF);
                const r = await fetch(ENDPOINT, { method: 'POST', body: fd });
                const data = await r.json();
                if (data.ok) {
                    lastId = data.id || lastId;
                    await openChat(active.id);
                } else if (window.IAS_UI) {
                    IAS_UI.alert(data.error || 'Could not send.', 'error');
                }
            }

            function startPoll() {
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(async () => {
                    if (!active) return;
                    const r = await fetch(ENDPOINT + `?action=poll&staff_id=${active.id}&last_id=${lastId}`);
                    const data = await r.json();
                    const neu = data.messages || [];
                    if (neu.length) {
                        lastId = neu[neu.length - 1].id;
                        const win = document.getElementById('chatWindow');
                        neu.forEach(m => {
                            const div = document.createElement('div');
                            div.className = 'bubble ' + (m.mine ? 'sent' : 'received');
                            div.innerHTML = esc(m.message) + `<div style="font-size:9px;margin-top:5px;opacity:.7;text-align:right;">${esc((m.created_at||'').slice(11,16))}</div>`;
                            if (win.querySelector('.chat-empty')) win.innerHTML = '';
                            win.appendChild(div);
                        });
                        win.scrollTop = win.scrollHeight;
                    }
                }, 3500);
            }

            document.getElementById('smForm').addEventListener('submit', (e) => {
                e.preventDefault();
                const input = document.getElementById('smInput');
                const text = input.value.trim();
                input.value = '';
                send(text);
            });

            loadStaff().catch(() => {
                document.getElementById('smContacts').innerHTML = '<p style="padding:20px;color:#bbb;font-size:13px;text-align:center;">Could not load staff.</p>';
            });
        })();
        </script>
        <?php
    }
}
