<?php
/**
 * Two-pane staff Messages UI (shared by Admin / Retail / Inventory pages).
 * Backend: includes/staff_chat_messages.php
 */
require_once __DIR__ . '/staff_chat_lib.php';

if (!function_exists('staff_messages_extra_head')) {
    function staff_messages_extra_head(): string
    {
        return <<<'CSS'
<style>
[hidden] { display: none !important; }
.chat-container { display: flex; height: calc(100vh - 180px); min-height: 460px; }
.chat-card {
    background: #fff; border-radius: 14px; border: 1px solid var(--border);
    box-shadow: var(--card-shadow); display: flex; width: 100%; overflow: hidden;
}

/* ── Contacts column ── */
.contacts-column {
    width: 320px; border-right: 1px solid var(--border); display: flex;
    flex-direction: column; flex-shrink: 0; background: #fff;
}
.contacts-head { padding: 18px 18px 14px; border-bottom: 1px solid var(--border); }
.contacts-title {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    font-size: 15px; font-weight: 800; color: var(--text-main); margin: 0 0 12px;
}
.contacts-title .sm-total {
    background: var(--ep-green); color: #fff; font-size: 11px; font-weight: 700;
    border-radius: 999px; padding: 2px 9px; line-height: 1.5;
}
.sm-search { position: relative; display: block; }
.sm-search i {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    color: var(--slate-400); font-size: 13px; pointer-events: none;
}
.sm-search input {
    width: 100%; box-sizing: border-box; padding: 9px 12px 9px 34px;
    border: 1px solid var(--border); border-radius: 10px; background: var(--slate-50);
    font-family: inherit; font-size: 13px; color: var(--text-main); outline: none;
    transition: border-color .15s, box-shadow .15s, background .15s;
}
.sm-search input:focus {
    border-color: var(--ep-green); background: #fff;
    box-shadow: 0 0 0 3px rgba(98, 178, 54, .15);
}
.contacts-list { overflow-y: auto; flex: 1; padding: 6px 8px 10px; }
.contacts-note { padding: 28px 16px; text-align: center; color: var(--slate-400); font-size: 13px; }

.contact-link {
    display: flex; align-items: center; gap: 12px; width: 100%;
    padding: 10px 10px; margin: 2px 0; border: 0; border-radius: 10px;
    background: none; cursor: pointer; text-align: left; font-family: inherit;
    color: var(--text-main); transition: background .15s;
}
.contact-link:hover { background: var(--slate-50); }
.contact-link:focus-visible { outline: 2px solid var(--ep-green); outline-offset: -2px; }
/* Open chat is neutral grey so it can't be confused with the green unread highlight */
.contact-link.active, .contact-link.active:hover { background: var(--slate-100); }
.contact-body { flex: 1; min-width: 0; }
.contact-top, .contact-bottom { display: flex; align-items: center; gap: 8px; }
.contact-name {
    flex: 1; min-width: 0; font-size: 14px; font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.contact-time { font-size: 11px; color: var(--slate-400); flex-shrink: 0; }
.contact-preview {
    flex: 1; min-width: 0; font-size: 12.5px; color: var(--text-muted); margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.contact-preview.is-empty { font-style: italic; color: var(--slate-400); }
.contact-preview .you { color: var(--slate-400); }
/* Unread conversation: whole row highlighted, text bold, count badge */
.contact-link.has-unread {
    background: #f2f9ec; box-shadow: inset 3px 0 0 var(--ep-green);
}
.contact-link.has-unread:hover { background: #e8f5de; }
.contact-link.has-unread .contact-name { font-weight: 800; color: var(--ep-black); }
.contact-link.has-unread .contact-preview { font-weight: 700; color: var(--ep-black); }
.contact-link.has-unread .contact-time { color: var(--ep-green-dark); font-weight: 700; }
.contact-link.has-unread .sm-avatar { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--ep-green); }
.unread-badge {
    min-width: 20px; height: 20px; padding: 0 6px; box-sizing: border-box;
    border-radius: 999px; background: var(--ep-green); color: #fff;
    font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* ── Avatars / role chips ── */
.sm-avatar {
    width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; letter-spacing: .02em; color: #fff;
    background: var(--slate-500); overflow: hidden;
}
.sm-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.sm-avatar.has-img { background: var(--slate-100); }
.sm-avatar.role-admin { background: var(--ep-green-dark); }
.sm-avatar.role-retail_officer { background: var(--ep-yellow-dark); }
.sm-avatar.role-inventory_custodian { background: var(--slate-600); }
.sm-avatar.sm-md { width: 40px; height: 40px; }
.sm-avatar.sm-sm { width: 34px; height: 34px; font-size: 12px; }
.sm-avatar.sm-xs { width: 28px; height: 28px; font-size: 10.5px; }
.role-chip {
    display: inline-block; font-size: 11px; font-weight: 600; border-radius: 999px;
    padding: 2px 9px; background: var(--slate-100); color: var(--slate-600);
}
.role-chip.role-admin { background: var(--ep-green-light); color: var(--ep-green-dark); }
.role-chip.role-retail_officer { background: var(--yellow-pale); color: #8a6d00; }

/* ── Conversation column ── */
.chat-column { flex: 1; display: flex; flex-direction: column; min-width: 0; background: #fff; position: relative; }
.chat-header {
    padding: 12px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 12px; background: #fff; min-height: 66px; box-sizing: border-box;
}
.chat-header-text { min-width: 0; display: flex; flex-direction: column; gap: 3px; align-items: flex-start; }
.chat-header-name {
    font-weight: 800; font-size: 15px; color: var(--text-main);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;
}
.chat-back {
    display: none; border: 0; background: none; cursor: pointer; padding: 6px 8px;
    margin-left: -8px; color: var(--text-muted); font-size: 16px; border-radius: 8px;
}
.chat-back:hover { background: var(--slate-100); color: var(--text-main); }

/* Pinned messages */
.pin-bar {
    display: flex; align-items: center; gap: 10px; padding: 8px 20px;
    border-bottom: 1px solid var(--border); background: #fff; font-size: 13px;
}
.pin-bar > i { color: var(--ep-green-dark); flex-shrink: 0; }
.pin-main {
    flex: 1; min-width: 0; border: 0; background: none; padding: 0; text-align: left;
    font-family: inherit; cursor: pointer; display: flex; flex-direction: column;
}
.pin-label { font-size: 11px; font-weight: 700; color: var(--ep-green-dark); text-transform: uppercase; letter-spacing: .04em; }
.pin-text { color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pin-toggle {
    border: 1px solid var(--border); background: #fff; border-radius: 999px; padding: 4px 11px;
    font-family: inherit; font-size: 12px; font-weight: 600; color: var(--text-muted); cursor: pointer; flex-shrink: 0;
}
.pin-toggle:hover { border-color: var(--ep-green); color: var(--ep-green-dark); }
.pin-list {
    border-bottom: 1px solid var(--border); background: var(--slate-50);
    max-height: 190px; overflow-y: auto; padding: 4px 12px;
}
.pin-item { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 8px; }
.pin-item:hover { background: #fff; }
.pin-item .pin-main { flex-direction: row; gap: 6px; font-size: 13px; }
.pin-item .pin-who { color: var(--text-muted); flex-shrink: 0; }
.pin-unpin {
    border: 0; background: none; cursor: pointer; color: var(--slate-400); width: 28px; height: 28px;
    border-radius: 50%; flex-shrink: 0;
}
.pin-unpin:hover { background: var(--slate-100); color: #b42318; }

.chat-messages {
    flex: 1; padding: 20px 24px; overflow-y: auto; overflow-x: hidden; background: var(--slate-50);
    display: flex; flex-direction: column;
}
.day-sep {
    display: flex; align-items: center; gap: 12px; margin: 14px 0 10px;
    font-size: 11px; font-weight: 600; color: var(--slate-400); text-transform: uppercase; letter-spacing: .05em;
}
.day-sep::before, .day-sep::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.day-sep:first-child { margin-top: 0; }

/* One message */
.msg-row { display: flex; align-items: flex-end; gap: 8px; margin-top: 12px; max-width: 100%; }
.msg-row.sent { justify-content: flex-end; }
.msg-row.grouped { margin-top: 3px; }
.msg-av { width: 28px; flex-shrink: 0; align-self: flex-end; margin-bottom: 18px; }
.msg-row.no-time .msg-av { margin-bottom: 0; }
.msg-main { display: flex; flex-direction: column; min-width: 0; max-width: 72%; }
.msg-row.sent .msg-main { align-items: flex-end; }
.msg-row.received .msg-main { align-items: flex-start; }
.msg-label {
    font-size: 11.5px; color: var(--text-muted); margin: 0 6px 3px; display: inline-flex; align-items: center; gap: 5px;
}
.msg-label i { font-size: 10px; }
.msg-label.pinned { color: var(--ep-green-dark); font-weight: 600; }
.reply-quote {
    display: block; max-width: 100%; border: 0; cursor: pointer; font-family: inherit; text-align: left;
    background: var(--slate-200); color: var(--slate-600); font-size: 12.5px; line-height: 1.4;
    padding: 7px 12px 16px; margin-bottom: -10px; border-radius: 14px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: .85;
}
.reply-quote:hover { opacity: 1; }
.reply-quote.is-deleted { font-style: italic; }
.msg-line { display: flex; align-items: center; gap: 6px; max-width: 100%; position: relative; }
.msg-row.sent .msg-line { flex-direction: row-reverse; }
.msg-content { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.msg-row.sent .msg-content { align-items: flex-end; }
.msg-row.received .msg-content { align-items: flex-start; }
.bubble {
    padding: 9px 14px; border-radius: 18px; font-size: 14px; line-height: 1.5;
    white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere; position: relative;
}
.msg-row.sent .bubble { background: var(--ep-green); color: #fff; }
.msg-row.received .bubble { background: #fff; color: var(--text-main); border: 1px solid var(--border); }
.msg-row.sent.grouped .bubble { border-top-right-radius: 6px; }
.msg-row.sent.has-next .bubble { border-bottom-right-radius: 6px; }
.msg-row.received.grouped .bubble { border-top-left-radius: 6px; }
.msg-row.received.has-next .bubble { border-bottom-left-radius: 6px; }
.msg-row .bubble.unsent {
    background: transparent !important; color: var(--slate-400) !important; font-style: italic;
    border: 1px solid var(--border) !important;
}
.msg-time { font-size: 10.5px; color: var(--slate-400); margin-top: 4px; padding: 0 6px; }
.msg-row.pending .msg-content { opacity: .6; }
.msg-row.flash .bubble, .msg-row.flash .att-file, .msg-row.flash .att-image img { animation: sm-flash 1.4s ease; }
@keyframes sm-flash { 0%, 40% { box-shadow: 0 0 0 4px rgba(243, 196, 0, .7); } 100% { box-shadow: 0 0 0 0 rgba(243, 196, 0, 0); } }

/* Hover toolbar: react / reply / more */
.msg-actions { display: flex; align-items: center; gap: 2px; opacity: 0; pointer-events: none; transition: opacity .12s; flex-shrink: 0; }
.msg-row:hover .msg-actions, .msg-row:focus-within .msg-actions,
.msg-row.show-actions .msg-actions, .msg-row.pop-open .msg-actions { opacity: 1; pointer-events: auto; }
.msg-act-btn {
    width: 30px; height: 30px; border-radius: 50%; border: 0; background: transparent; cursor: pointer;
    color: var(--slate-400); font-size: 14px; display: inline-flex; align-items: center; justify-content: center;
}
.msg-act-btn:hover, .msg-act-btn:focus-visible { background: var(--slate-200); color: var(--text-main); outline: none; }

/* Reactions */
.reactions { display: flex; gap: 3px; margin-top: -7px; position: relative; z-index: 1; padding: 0 8px; }
.react-chip {
    display: inline-flex; align-items: center; gap: 3px; border: 1px solid var(--border); background: #fff;
    border-radius: 999px; padding: 1px 7px; font-size: 13px; line-height: 1.5; cursor: pointer;
    box-shadow: 0 1px 3px rgba(0,0,0,.08); font-family: inherit;
}
.react-chip span { font-size: 11px; font-weight: 700; color: var(--text-muted); }
.react-chip.mine { border-color: var(--ep-green); background: var(--ep-green-light); }

/* Attachments */
.att-image { display: block; border-radius: 14px; overflow: hidden; line-height: 0; border: 1px solid var(--border); background: #fff; }
/* Fixed px caps: a % max-width doesn't limit the link's own width, which left a blank strip beside big photos. */
.att-image { max-width: 100%; }
.att-image img { max-width: 280px; max-height: 280px; width: auto; height: auto; display: block; }
.att-file {
    display: flex; align-items: center; gap: 10px; padding: 10px 14px 10px 10px; min-width: 200px; max-width: 300px;
    border-radius: 14px; border: 1px solid var(--border); background: #fff; color: var(--text-main); text-decoration: none;
}
.att-file:hover { border-color: var(--ep-green); }
.att-ico {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    background: var(--ep-green-light); color: var(--ep-green-dark); font-size: 17px;
}
.att-meta { min-width: 0; display: flex; flex-direction: column; }
.att-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.att-size { font-size: 11.5px; color: var(--text-muted); }

.chat-empty { margin: auto; text-align: center; color: var(--slate-400); padding: 20px; max-width: 320px; }
.chat-empty-icon {
    width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 14px;
    display: flex; align-items: center; justify-content: center;
    background: var(--ep-green-light); color: var(--ep-green); font-size: 28px;
}
.chat-empty .sm-avatar { width: 72px; height: 72px; font-size: 24px; margin: 0 auto 14px; }
.chat-empty h3 { margin: 0 0 4px; font-size: 15px; font-weight: 700; color: var(--text-main); }
.chat-empty p { margin: 0; font-size: 13px; color: var(--text-muted); }

/* ── Composer ── */
.chat-footer { padding: 10px 16px 12px; border-top: 1px solid var(--border); background: #fff; }
.compose-extra {
    display: flex; align-items: center; gap: 10px; margin: 0 0 8px; padding: 8px 10px 8px 14px;
    background: var(--slate-50); border: 1px solid var(--border); border-radius: 12px;
}
.compose-extra .ce-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.ce-title { font-size: 12.5px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ce-text { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ce-thumb {
    width: 38px; height: 38px; border-radius: 8px; flex-shrink: 0; overflow: hidden;
    display: flex; align-items: center; justify-content: center; background: var(--ep-green-light); color: var(--ep-green-dark);
}
.ce-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ce-close {
    width: 28px; height: 28px; border-radius: 50%; border: 0; background: transparent; cursor: pointer;
    color: var(--slate-400); font-size: 15px; flex-shrink: 0;
}
.ce-close:hover { background: var(--slate-200); color: var(--text-main); }
.composer-row { display: flex; align-items: flex-end; gap: 6px; }
.composer-tool, .composer-emoji {
    width: 38px; height: 38px; flex-shrink: 0; border-radius: 50%; border: 0; background: transparent;
    color: var(--ep-green-dark); cursor: pointer; font-size: 17px;
    display: inline-flex; align-items: center; justify-content: center; transition: background .15s;
}
.composer-tool:hover, .composer-emoji:hover, .composer-tool:focus-visible, .composer-emoji:focus-visible {
    background: var(--ep-green-light); outline: none;
}
.composer-emoji { width: 32px; height: 32px; margin: 3px 0; }
.input-box {
    flex: 1; min-width: 0; display: flex; align-items: flex-end; gap: 4px; background: var(--slate-50);
    padding: 0 4px 0 16px; border-radius: 20px; border: 1px solid var(--border);
    transition: border-color .15s, box-shadow .15s, background .15s;
}
.input-box:focus-within { border-color: var(--ep-green); background: #fff; box-shadow: 0 0 0 3px rgba(98, 178, 54, .15); }
.input-box textarea {
    flex: 1; border: none; background: transparent; outline: none; resize: none;
    padding: 9px 0; font-family: inherit; font-size: 14px; line-height: 1.45;
    color: var(--text-main); max-height: 120px; min-height: 21px;
}
.send-btn {
    width: 38px; height: 38px; flex-shrink: 0; border-radius: 50%; border: none;
    background: var(--ep-green); color: #fff; cursor: pointer; font-size: 14px;
    display: inline-flex; align-items: center; justify-content: center; transition: background .15s, opacity .15s;
}
.send-btn:hover:not(:disabled) { background: var(--ep-green-dark); }
.send-btn:disabled { opacity: .45; cursor: default; }
.send-btn:focus-visible { outline: 2px solid var(--ep-green-dark); outline-offset: 2px; }
.composer-hint { font-size: 11px; color: var(--slate-400); margin: 6px 4px 0 48px; }

/* ── Popovers (reactions, menus, emoji picker) ── */
.sm-pop {
    position: fixed; z-index: 1200; background: #fff; border: 1px solid var(--border);
    border-radius: 14px; box-shadow: 0 12px 32px rgba(15, 23, 42, .18); font-family: var(--font-base);
}
.sm-pop.quick { display: flex; align-items: center; gap: 2px; padding: 5px 6px; border-radius: 999px; }
.sm-pop .qr-btn {
    width: 38px; height: 38px; border: 0; background: none; border-radius: 50%; cursor: pointer;
    font-size: 22px; line-height: 1; transition: transform .12s, background .12s;
}
.sm-pop .qr-btn:hover, .sm-pop .qr-btn:focus-visible { transform: scale(1.2); background: var(--slate-100); outline: none; }
.sm-pop .qr-btn.mine { background: var(--ep-green-light); }
.sm-pop .qr-more { font-size: 15px; color: var(--text-muted); }
.sm-pop.menu { padding: 6px; min-width: 190px; }
.sm-menu-item {
    display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; border: 0; background: none;
    border-radius: 8px; cursor: pointer; font-family: inherit; font-size: 13.5px; font-weight: 600; color: var(--text-main); text-align: left;
}
.sm-menu-item i { width: 16px; color: var(--text-muted); text-align: center; }
.sm-menu-item:hover, .sm-menu-item:focus-visible { background: var(--slate-100); outline: none; }
.sm-menu-item.danger, .sm-menu-item.danger i { color: #b42318; }
.sm-pop.emoji { width: 320px; max-width: calc(100vw - 16px); display: flex; flex-direction: column; overflow: hidden; }
.emoji-tabs { display: flex; gap: 2px; padding: 6px 8px; border-bottom: 1px solid var(--border); }
.emoji-tab {
    flex: 1; border: 0; background: none; border-radius: 8px; padding: 6px 4px; cursor: pointer;
    font-family: inherit; font-size: 11.5px; font-weight: 600; color: var(--text-muted);
}
.emoji-tab:hover { background: var(--slate-100); color: var(--text-main); }
.emoji-scroll { height: 240px; overflow-y: auto; padding: 4px 8px 8px; }
.emoji-group-title { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin: 8px 4px 4px; }
.emoji-grid { display: grid; grid-template-columns: repeat(8, 1fr); }
.emoji-btn {
    border: 0; background: none; cursor: pointer; font-size: 22px; line-height: 1; padding: 5px 0; border-radius: 8px;
}
.emoji-btn:hover, .emoji-btn:focus-visible { background: var(--slate-100); outline: none; }

/* ── Dialogs (remove, forward) ── */
.sm-modal {
    position: fixed; inset: 0; z-index: 1300; background: rgba(15, 23, 42, .45);
    display: flex; align-items: center; justify-content: center; padding: 16px;
}
.sm-modal-card {
    background: #fff; border-radius: 16px; width: 100%; max-width: 440px; max-height: 85vh;
    display: flex; flex-direction: column; box-shadow: 0 24px 60px rgba(0,0,0,.25); overflow: hidden;
    font-family: var(--font-base);
}
.sm-modal-head {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 16px 20px; border-bottom: 1px solid var(--border);
}
.sm-modal-head h3 { margin: 0; font-size: 16px; font-weight: 800; color: var(--text-main); }
.sm-modal-body { padding: 16px 20px; overflow-y: auto; }
.sm-modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 12px 20px; border-top: 1px solid var(--border); background: var(--slate-50); }
.sm-option {
    display: flex; gap: 12px; align-items: flex-start; padding: 12px; border: 1px solid var(--border);
    border-radius: 12px; cursor: pointer; margin-bottom: 10px;
}
.sm-option:has(input:checked) { border-color: var(--ep-green); background: var(--ep-green-light); }
.sm-option input { margin-top: 3px; accent-color: var(--ep-green); }
.sm-option b { display: block; font-size: 14px; color: var(--text-main); }
.sm-option span { display: block; font-size: 12.5px; color: var(--text-muted); margin-top: 2px; line-height: 1.45; }
.fwd-preview {
    font-size: 13px; color: var(--text-muted); background: var(--slate-50); border: 1px solid var(--border);
    border-radius: 10px; padding: 8px 12px; margin-bottom: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.fwd-list { margin-top: 10px; }
.fwd-item { display: flex; align-items: center; gap: 12px; padding: 8px 4px; border-bottom: 1px solid var(--slate-100); }
.fwd-item:last-child { border-bottom: 0; }
.fwd-name { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.fwd-name b { font-size: 13.5px; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fwd-name span { font-size: 12px; color: var(--text-muted); }

/* ── Header actions / load earlier ── */
.chat-card { position: relative; }
.chat-header-actions { margin-left: auto; display: flex; gap: 4px; flex-shrink: 0; }
.chat-icon-btn {
    width: 38px; height: 38px; border-radius: 50%; border: 0; background: transparent; cursor: pointer;
    color: var(--ep-green-dark); font-size: 19px; display: inline-flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s;
}
.chat-icon-btn:hover, .chat-icon-btn:focus-visible { background: var(--ep-green-light); outline: none; }
.chat-icon-btn[aria-pressed="true"] { background: var(--ep-green); color: #fff; }
.load-older {
    align-self: center; margin: 0 0 10px; border: 1px solid var(--border); background: #fff; border-radius: 999px;
    padding: 6px 14px; font-family: inherit; font-size: 12px; font-weight: 600; color: var(--text-muted); cursor: pointer;
}
.load-older:hover { border-color: var(--ep-green); color: var(--ep-green-dark); }
.load-older:disabled { opacity: .6; cursor: default; }

/* ── Conversation information panel ── */
.info-column {
    width: 300px; flex-shrink: 0; border-left: 1px solid var(--border); background: #fff;
    display: flex; flex-direction: column; min-height: 0;
}
.info-view { flex: 1; overflow-y: auto; display: flex; flex-direction: column; min-height: 0; }
.info-profile { position: relative; text-align: center; padding: 30px 20px 14px; }
.info-profile .sm-avatar { width: 84px; height: 84px; font-size: 28px; margin: 0 auto 12px; }
.info-name { font-size: 16px; font-weight: 800; color: var(--text-main); margin: 0 0 6px; overflow-wrap: anywhere; }
.info-close { position: absolute; top: 10px; right: 10px; }
.info-section { padding: 4px 10px 16px; }
.info-section-toggle {
    width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 8px; border: 0; background: none;
    padding: 12px 10px; border-radius: 10px; font-family: inherit; font-size: 14px; font-weight: 800; color: var(--text-main);
    cursor: pointer; text-align: left;
}
.info-section-toggle:hover, .info-section-toggle:focus-visible { background: var(--slate-50); outline: none; }
.info-section-toggle i { color: var(--text-muted); font-size: 12px; transition: transform .2s; }
.info-section-toggle[aria-expanded="false"] i { transform: rotate(-90deg); }
.info-item {
    width: 100%; display: flex; align-items: center; gap: 12px; border: 0; background: none; padding: 8px 10px;
    border-radius: 10px; font-family: inherit; font-size: 13.5px; font-weight: 600; color: var(--text-main);
    cursor: pointer; text-align: left;
}
.info-item:hover, .info-item:focus-visible { background: var(--slate-100); outline: none; }
.info-item > i {
    width: 34px; height: 34px; border-radius: 50%; background: var(--slate-100); flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; color: var(--ep-green-dark); font-size: 14px;
}
.info-item:hover > i { background: #fff; }
.info-count {
    margin-left: auto; min-width: 20px; padding: 1px 7px; border-radius: 999px; background: var(--ep-green-light);
    color: var(--ep-green-dark); font-size: 11.5px; font-weight: 700; text-align: center;
}
.info-sub-head { display: flex; align-items: center; gap: 6px; padding: 12px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.info-sub-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: var(--text-main); }
.info-back {
    width: 34px; height: 34px; border-radius: 50%; border: 0; background: none; cursor: pointer; flex-shrink: 0;
    color: var(--text-muted); font-size: 15px; display: inline-flex; align-items: center; justify-content: center;
}
.info-back:hover, .info-back:focus-visible { background: var(--slate-100); color: var(--text-main); outline: none; }
.info-search { padding: 12px; flex-shrink: 0; }
.info-search .sm-search input { padding-right: 84px; }
.info-search-count {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    font-size: 11px; font-weight: 600; color: var(--text-muted); pointer-events: none;
}
.info-results { padding: 0 8px 12px; }
.search-hit {
    display: flex; gap: 10px; align-items: flex-start; width: 100%; border: 0; background: none; text-align: left;
    padding: 9px 8px; border-radius: 10px; cursor: pointer; font-family: inherit;
}
.search-hit:hover, .search-hit:focus-visible { background: var(--slate-100); outline: none; }
.hit-body { min-width: 0; flex: 1; }
.hit-name { display: block; font-size: 13px; font-weight: 700; color: var(--text-main); }
.hit-text { display: block; font-size: 12.5px; color: var(--text-muted); overflow-wrap: anywhere; line-height: 1.4; }
.hit-text mark { background: var(--yellow-pale); color: var(--ep-black); font-weight: 700; padding: 0 1px; border-radius: 3px; }
.hit-date { font-size: 11px; color: var(--slate-400); white-space: nowrap; padding-top: 2px; }
.info-tabs { display: flex; gap: 4px; padding: 6px 12px 0; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.info-tab {
    flex: 1; border: 0; background: none; padding: 10px 6px; font-family: inherit; font-size: 13px; font-weight: 700;
    color: var(--text-muted); cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -1px;
}
.info-tab:hover { color: var(--text-main); }
.info-tab[aria-selected="true"] { color: var(--ep-green-dark); border-bottom-color: var(--ep-green); }
.media-body { padding: 2px 12px 16px; }
.media-month { font-size: 13px; font-weight: 800; color: var(--text-main); margin: 14px 2px 8px; }
.media-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
.media-thumb {
    aspect-ratio: 1; border: 0; padding: 0; cursor: pointer; border-radius: 6px; overflow: hidden; background: var(--slate-100);
}
.media-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .2s; }
.media-thumb:hover img { transform: scale(1.06); }
.media-thumb:focus-visible { outline: 2px solid var(--ep-green); outline-offset: 2px; }
.file-row { display: flex; align-items: center; gap: 10px; padding: 7px 4px 7px 6px; border-radius: 10px; }
.file-row:hover { background: var(--slate-50); }
.file-row .att-ico { width: 36px; height: 36px; font-size: 15px; }
.file-main { flex: 1; min-width: 0; text-decoration: none; color: inherit; display: flex; flex-direction: column; }
.file-name { font-size: 13px; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-main:hover .file-name { color: var(--ep-green-dark); text-decoration: underline; }
.file-meta { font-size: 11.5px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.info-empty { text-align: center; color: var(--slate-400); font-size: 13px; padding: 30px 12px; margin: 0; }
.info-empty i { font-size: 28px; display: block; margin-bottom: 8px; }
.info-more { display: block; margin: 14px auto 0; }

/* Pinned messages dialog */
.sm-modal-card.wide { max-width: 560px; }
.pinned-item { padding: 12px 0; border-bottom: 1px solid var(--slate-100); }
.pinned-item:first-child { padding-top: 0; }
.pinned-item:last-child { border-bottom: 0; }
.pinned-meta { display: flex; justify-content: space-between; gap: 8px; font-size: 11.5px; color: var(--text-muted); margin-bottom: 6px; }
.pinned-meta b { color: var(--text-main); font-size: 12.5px; }
.pinned-text {
    background: var(--slate-50); border: 1px solid var(--border); border-radius: 14px; padding: 10px 14px; margin-top: 6px;
    font-size: 13.5px; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; color: var(--text-main);
    max-height: 220px; overflow-y: auto;
}
.pinned-img { max-width: 100%; max-height: 200px; border-radius: 12px; display: block; border: 1px solid var(--border); }
.pinned-actions { display: flex; gap: 8px; margin-top: 8px; justify-content: flex-end; }

/* Image viewer */
.sm-modal.lightbox { background: rgba(8, 10, 12, .92); padding: 0; }
.sm-modal.lightbox .sm-modal-card {
    background: transparent; box-shadow: none; border-radius: 0; width: 100%; max-width: none; height: 100%; max-height: none;
}
.lb-top { display: flex; align-items: center; gap: 8px; color: #fff; padding: 14px 18px; flex-shrink: 0; }
.lb-stage { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; padding: 0 18px 18px; cursor: zoom-out; }
.lb-title { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.lb-name { font-size: 13.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lb-date { font-size: 12px; opacity: .7; }
.lb-btn {
    width: 38px; height: 38px; border-radius: 50%; border: 0; background: rgba(255, 255, 255, .14); color: #fff; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; font-size: 15px; text-decoration: none; flex-shrink: 0;
}
.lb-btn:hover, .lb-btn:focus-visible { background: rgba(255, 255, 255, .3); outline: none; }
.lb-img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; border-radius: 8px; cursor: default; }

@media (max-width: 1200px) {
    /* Not enough room for three columns: the panel slides over the conversation. */
    .info-column { position: absolute; top: 0; right: 0; bottom: 0; z-index: 6; box-shadow: -12px 0 32px rgba(15, 23, 42, .14); }
}
@media (max-width: 900px) {
    .info-column { width: 100%; border-left: 0; box-shadow: none; }
}
@media (hover: none) {
    .msg-row:hover .msg-actions { opacity: 0; pointer-events: none; }
    .msg-row.show-actions .msg-actions, .msg-row.pop-open .msg-actions { opacity: 1; pointer-events: auto; }
}
@media (max-width: 900px) {
    .chat-container { height: calc(100vh - 150px); min-height: 420px; }
    .contacts-column { width: 100%; border-right: none; }
    .chat-column { display: none; }
    .chat-card.show-chat .contacts-column { display: none; }
    .chat-card.show-chat .chat-column { display: flex; }
    .chat-back { display: inline-flex; }
}
@media (max-width: 600px) {
    .chat-messages { padding: 14px 10px; }
    .chat-footer { padding: 8px 8px 10px; }
    .msg-main { max-width: 80%; }
    .input-box textarea { font-size: 16px; } /* stops iOS zooming into the field */
    .composer-hint { display: none; }
    .pin-bar { padding: 8px 12px; }
    .att-image img { max-width: 200px; max-height: 220px; }
}
</style>
CSS;
    }
}

if (!function_exists('staff_messages_is_embed')) {
    /** `?embed=1`: the Messages page loaded inside the floating chat widget (staff_chat_widget.php). */
    function staff_messages_is_embed(): bool
    {
        return isset($_GET['embed']);
    }
}

if (!function_exists('staff_messages_embed_page')) {
    /**
     * Bare Messages UI (no sidebar/topbar) for the widget's iframe, then exit.
     * Same markup, styles and script as the full page, so both always behave the same.
     */
    function staff_messages_embed_page(): never
    {
        header('X-Frame-Options: SAMEORIGIN');
        $css = staff_css_href();
        $alertsJs = preg_replace('#staff_shared\.css$#', 'ui_alerts.js', $css);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages — EasyPC</title>
    <link rel="stylesheet" href="<?php echo h($css); ?>?v=ep-responsive-3">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <?php echo staff_messages_extra_head(); ?>
    <style>
    html, body.sm-embed { height: 100%; margin: 0; padding: 0; background: #fff; overflow: hidden; }
    body.sm-embed .chat-container { height: 100vh; height: 100dvh; min-height: 0; }
    body.sm-embed .chat-card { border: 0; border-radius: 0; box-shadow: none; }
    /* The widget's own green header already says "Messages" and shows the unread total. */
    body.sm-embed .contacts-title { display: none; }
    body.sm-embed .contacts-head { padding: 10px 12px; }
    body.sm-embed .contacts-list { padding: 4px 6px 8px; }
    body.sm-embed .chat-header { padding: 8px 10px 8px 12px; min-height: 56px; gap: 10px; }
    body.sm-embed .chat-back { margin-left: -4px; }
    body.sm-embed .chat-icon-btn { width: 34px; height: 34px; font-size: 17px; }
    body.sm-embed .pin-bar { padding: 7px 12px; }
    body.sm-embed .chat-messages { padding: 12px 12px 14px; }
    body.sm-embed .msg-main { max-width: 82%; }
    body.sm-embed .chat-footer { padding: 8px 10px 10px; }
    body.sm-embed .composer-tool { width: 34px; height: 34px; font-size: 16px; }
    body.sm-embed .send-btn { width: 36px; height: 36px; }
    body.sm-embed .att-image img { max-width: 200px; max-height: 200px; }
    body.sm-embed .att-file { min-width: 0; max-width: 230px; }
    body.sm-embed .chat-empty .sm-avatar { width: 60px; height: 60px; font-size: 20px; }
    </style>
</head>
<body class="sm-embed">
<?php staff_messages_render(); ?>
<script src="<?php echo h($alertsJs); ?>"></script>
</body>
</html>
        <?php
        exit;
    }
}

if (!function_exists('staff_messages_render')) {
    function staff_messages_render(): void
    {
        $csrf = generateCsrfToken();
        $endpoint = staff_chat_endpoint_href();
        $openId = (int)($_GET['staff_id'] ?? 0);
        $exts = array_keys(staff_chat_attachment_types());
        $accept = implode(',', array_map(fn($e) => '.' . $e, $exts));
        $config = [
            'endpoint' => $endpoint,
            'csrf' => $csrf,
            'openId' => $openId,
            'emojiGroups' => staff_chat_emoji_groups(),
            'quickReactions' => staff_chat_quick_reactions(),
            'exts' => $exts,
            'maxBytes' => staff_chat_max_upload_bytes(),
            'embed' => staff_messages_is_embed(),
        ];
        ?>
        <div class="chat-container">
            <div class="chat-card" id="smCard">
                <aside class="contacts-column">
                    <div class="contacts-head">
                        <h2 class="contacts-title">Conversations <span class="sm-total" id="smTotal" hidden></span></h2>
                        <label class="sm-search">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input type="search" id="smSearch" placeholder="Search staff…" autocomplete="off" aria-label="Search staff">
                        </label>
                    </div>
                    <div class="contacts-list" id="smContacts">
                        <p class="contacts-note">Loading…</p>
                    </div>
                </aside>
                <section class="chat-column">
                    <div class="chat-header" id="smHeader" hidden>
                        <button type="button" class="chat-back" id="smBack" aria-label="Back to conversations"><i class="fas fa-arrow-left"></i></button>
                        <span id="smAvatar"></span>
                        <div class="chat-header-text">
                            <span class="chat-header-name" id="smTitle"></span>
                            <span class="role-chip" id="smRole"></span>
                        </div>
                        <div class="chat-header-actions">
                            <button type="button" class="chat-icon-btn" id="smInfoBtn" aria-pressed="false" aria-controls="smInfo"
                                    title="Conversation information" aria-label="Conversation information"><i class="fas fa-info-circle"></i></button>
                        </div>
                    </div>
                    <div class="pin-bar" id="smPins" hidden>
                        <i class="fas fa-thumbtack" aria-hidden="true"></i>
                        <button type="button" class="pin-main" id="smPinMain">
                            <span class="pin-label">Pinned message</span>
                            <span class="pin-text" id="smPinText"></span>
                        </button>
                        <button type="button" class="pin-toggle" id="smPinToggle" aria-expanded="false" aria-controls="smPinList"></button>
                    </div>
                    <div class="pin-list" id="smPinList" hidden></div>
                    <div class="chat-messages" id="chatWindow" aria-live="polite">
                        <div class="chat-empty">
                            <div class="chat-empty-icon"><i class="fas fa-comments"></i></div>
                            <h3>Your messages</h3>
                            <p>Pick a staff member from the list to start chatting.</p>
                        </div>
                    </div>
                    <div class="chat-footer" id="smFooter" hidden>
                        <div class="compose-extra" id="smReplyBar" hidden>
                            <div class="ce-body">
                                <span class="ce-title" id="smReplyTitle"></span>
                                <span class="ce-text" id="smReplyText"></span>
                            </div>
                            <button type="button" class="ce-close" id="smReplyCancel" aria-label="Cancel reply"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="compose-extra" id="smAttachBar" hidden>
                            <span class="ce-thumb" id="smAttachThumb"></span>
                            <div class="ce-body">
                                <span class="ce-title" id="smAttachName"></span>
                                <span class="ce-text" id="smAttachSize"></span>
                            </div>
                            <button type="button" class="ce-close" id="smAttachCancel" aria-label="Remove attachment"><i class="fas fa-times"></i></button>
                        </div>
                        <form class="composer-row" id="smForm">
                            <input type="file" id="smFile" accept="<?php echo h($accept); ?>" hidden>
                            <button type="button" class="composer-tool" id="smAttachBtn" title="Attach a file (up to 10 MB)" aria-label="Attach a file"><i class="fas fa-paperclip"></i></button>
                            <div class="input-box">
                                <textarea id="smInput" name="message" rows="1" maxlength="4000" placeholder="Type your message…" autocomplete="off" aria-label="Message"></textarea>
                                <button type="button" class="composer-emoji" id="smEmojiBtn" title="Choose an emoji" aria-label="Choose an emoji"><i class="far fa-smile"></i></button>
                            </div>
                            <button type="submit" class="send-btn" id="smSend" aria-label="Send" disabled><i class="fas fa-paper-plane"></i></button>
                        </form>
                        <p class="composer-hint">Enter to send · Shift + Enter for a new line · Paste an image to attach it</p>
                    </div>
                </section>
                <aside class="info-column" id="smInfo" hidden aria-label="Conversation information">
                    <div class="info-view" id="smInfoMain">
                        <div class="info-profile">
                            <button type="button" class="info-back info-close" data-info="close" title="Close" aria-label="Close conversation information"><i class="fas fa-times"></i></button>
                            <div id="smInfoAvatar"></div>
                            <h3 class="info-name" id="smInfoName"></h3>
                            <span class="role-chip" id="smInfoRole"></span>
                        </div>
                        <div class="info-section">
                            <button type="button" class="info-section-toggle" id="smInfoToggle" aria-expanded="true" aria-controls="smInfoItems">
                                Conversation information <i class="fas fa-chevron-down" aria-hidden="true"></i>
                            </button>
                            <div id="smInfoItems">
                                <button type="button" class="info-item" data-info="search"><i class="fas fa-search" aria-hidden="true"></i> Search in conversation</button>
                                <button type="button" class="info-item" data-info="pins"><i class="fas fa-thumbtack" aria-hidden="true"></i> View pinned messages <span class="info-count" id="smInfoPinCount" hidden></span></button>
                                <button type="button" class="info-item" data-info="media"><i class="far fa-image" aria-hidden="true"></i> Media</button>
                                <button type="button" class="info-item" data-info="files"><i class="far fa-file-alt" aria-hidden="true"></i> Files</button>
                            </div>
                        </div>
                    </div>
                    <div class="info-view" id="smInfoSearch" hidden>
                        <div class="info-sub-head">
                            <button type="button" class="info-back" data-info="main" aria-label="Back"><i class="fas fa-arrow-left"></i></button>
                            <h3>Search</h3>
                        </div>
                        <div class="info-search">
                            <label class="sm-search">
                                <i class="fas fa-search" aria-hidden="true"></i>
                                <input type="search" id="smChatSearch" placeholder="Search in conversation" autocomplete="off" aria-label="Search in conversation" maxlength="100">
                                <span class="info-search-count" id="smChatSearchCount" aria-live="polite"></span>
                            </label>
                        </div>
                        <div class="info-results" id="smChatSearchResults"></div>
                    </div>
                    <div class="info-view" id="smInfoMedia" hidden>
                        <div class="info-sub-head">
                            <button type="button" class="info-back" data-info="main" aria-label="Back"><i class="fas fa-arrow-left"></i></button>
                            <h3>Media and files</h3>
                        </div>
                        <div class="info-tabs" role="tablist">
                            <button type="button" class="info-tab" role="tab" data-mtab="media" aria-selected="true">Media</button>
                            <button type="button" class="info-tab" role="tab" data-mtab="files" aria-selected="false">Files</button>
                        </div>
                        <div class="media-body" id="smMediaBody"></div>
                    </div>
                </aside>
            </div>
        </div>
        <div class="sm-pop" id="smPop" hidden></div>
        <div class="sm-modal" id="smModal" hidden><div class="sm-modal-card" role="dialog" aria-modal="true" id="smModalCard"></div></div>
        <script>
        const SM = (() => {
            const CFG = <?php echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
            const ENDPOINT = CFG.endpoint;
            const TZ = 'Asia/Manila';
            const GROUP_MS = 5 * 60 * 1000;
            const BASE_TITLE = document.title.replace(/^\(\d+\+?\) /, '');
            const EMBED = !!CFG.embed && window.parent !== window;
            let paused = false;       // embedded widget is closed: no polling, so nothing gets marked read

            const $ = (id) => document.getElementById(id);
            const card = $('smCard');
            const contactsEl = $('smContacts');
            const win = $('chatWindow');
            const input = $('smInput');
            const sendBtn = $('smSend');
            const fileInput = $('smFile');
            const pop = $('smPop');
            const modal = $('smModal');
            const modalCard = $('smModalCard');
            document.body.appendChild(pop);
            document.body.appendChild(modal);

            let staff = [];
            let active = null;
            let msgs = [];
            let pins = [];
            let lastId = 0;
            let stateV = '';
            let query = '';
            let pollTimer = null;
            let polling = false;
            let pollAgain = false;
            let forceNext = false;
            let sending = false;
            let lastContactsHtml = '';
            let replyTo = null;
            let pendingFile = null;
            let pendingThumbUrl = '';
            let popMode = null;
            let popAnchor = null;
            let stickBottom = true;
            let pinsOpen = false;
            let me = null;            // signed-in user (name / avatar for "You" rows)
            let hasOlder = false;     // more history exists above the loaded page
            let loadingOlder = false;
            let infoView = 'main';
            let mediaTab = 'media';
            let mediaItems = [];
            let mediaMore = false;
            let searchTimer = null;
            let searchSeq = 0;
            const info = $('smInfo');

            // ── Helpers ──
            function esc(s) {
                return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
            }
            // DB timestamps are UTC without a zone ("YYYY-MM-DD HH:MM:SS[.ffffff]").
            function parseTs(s) {
                if (!s) return null;
                const d = new Date(String(s).replace(' ', 'T').slice(0, 19) + 'Z');
                return isNaN(d) ? null : d;
            }
            function nowUtc() { return new Date().toISOString().replace('T', ' ').slice(0, 19); }
            function dayKey(d) { return d.toLocaleDateString('en-CA', { timeZone: TZ }); }
            function fmtTime(d) {
                return d ? d.toLocaleTimeString('en-US', { timeZone: TZ, hour: 'numeric', minute: '2-digit' }) : '';
            }
            function fmtFull(d) {
                return d ? d.toLocaleString('en-US', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short' }) : '';
            }
            function fmtDay(d) {
                const now = new Date();
                const k = dayKey(d);
                if (k === dayKey(now)) return 'Today';
                if (k === dayKey(new Date(now.getTime() - 86400000))) return 'Yesterday';
                const sameYear = k.slice(0, 4) === dayKey(now).slice(0, 4);
                return d.toLocaleDateString('en-US', { timeZone: TZ, month: 'short', day: 'numeric', year: sameYear ? undefined : 'numeric' });
            }
            function fmtListTime(s) {
                const d = parseTs(s);
                if (!d) return '';
                const now = new Date();
                if (dayKey(d) === dayKey(now)) return fmtTime(d);
                if (now - d < 6 * 86400000) return d.toLocaleDateString('en-US', { timeZone: TZ, weekday: 'short' });
                return d.toLocaleDateString('en-US', { timeZone: TZ, month: 'short', day: 'numeric' });
            }
            function fmtSize(n) {
                n = Number(n) || 0;
                if (n < 1024) return n + ' B';
                if (n < 1048576) return (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB';
                return (n / 1048576).toFixed(1) + ' MB';
            }
            function initials(p) {
                const a = String(p.name || '').trim().charAt(0);
                const b = String(p.surname || '').trim().charAt(0);
                return (a + b).toUpperCase() || '?';
            }
            function fullName(p) { return (String(p.name || '') + ' ' + String(p.surname || '')).trim(); }
            function roleClass(p) { return 'role-' + String(p.role || '').replace(/[^a-z_]/g, ''); }
            function avatarHtml(p, size) {
                const cls = `sm-avatar ${roleClass(p)} ${size || ''}`;
                return p.avatar
                    ? `<span class="${cls} has-img" aria-hidden="true"><img src="${esc(p.avatar)}" alt="" data-ini="${esc(initials(p))}"></span>`
                    : `<span class="${cls}" aria-hidden="true">${esc(initials(p))}</span>`;
            }
            function fileUrl(id, inline) {
                return ENDPOINT + '?action=file&id=' + encodeURIComponent(id) + (inline ? '&inline=1' : '');
            }
            function fileIcon(name, mime) {
                const ext = String(name || '').split('.').pop().toLowerCase();
                if (String(mime || '').startsWith('image/')) return 'fa-file-image';
                return ({ pdf: 'fa-file-pdf', docx: 'fa-file-word', xlsx: 'fa-file-excel', csv: 'fa-file-csv',
                          pptx: 'fa-file-powerpoint', txt: 'fa-file-alt' })[ext] || 'fa-file';
            }
            function msgById(id) { return msgs.find(m => m.id === id); }
            function snippet(m) {
                if (m.deleted) return 'Message unsent';
                if (m.text) return m.text.length > 90 ? m.text.slice(0, 90) + '…' : m.text;
                if (m.attachment) return '📎 ' + m.attachment.name;
                return '';
            }
            // ui_alerts.js declares `const IAS_UI`, which is not a window property.
            function toast(msg, type) {
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert(msg, type || 'error'); else window.alert(msg);
            }

            // Broken avatar image → fall back to initials.
            document.addEventListener('error', (e) => {
                const img = e.target;
                if (img && img.tagName === 'IMG' && img.dataset && img.dataset.ini) {
                    const holder = img.parentNode;
                    holder.classList.remove('has-img');
                    holder.textContent = img.dataset.ini;
                }
            }, true);

            async function post(action, data, file) {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('csrf_token', CFG.csrf);
                Object.keys(data || {}).forEach(k => fd.append(k, String(data[k])));
                if (file) fd.append('attachment', file);
                const r = await fetch(ENDPOINT, { method: 'POST', body: fd });
                let json = null;
                try { json = await r.json(); } catch (e) { /* non-JSON (e.g. upload too large) */ }
                if (!json || !json.ok) throw new Error((json && json.error) || 'Something went wrong. Please try again.');
                return json;
            }

            // ── Contacts ──
            function renderContacts() {
                const total = staff.reduce((n, s) => n + (s.unread || 0), 0);
                const totalEl = $('smTotal');
                totalEl.hidden = total === 0;
                totalEl.textContent = total > 99 ? '99+' : String(total);
                document.title = (total ? '(' + (total > 99 ? '99+' : total) + ') ' : '') + BASE_TITLE;
                notifyParent(total);

                if (!staff.length) {
                    contactsEl.innerHTML = lastContactsHtml = '<p class="contacts-note">No staff available.</p>';
                    return;
                }
                const q = query.toLowerCase();
                const list = q
                    ? staff.filter(s => (fullName(s) + ' ' + (s.role_label || '')).toLowerCase().includes(q))
                    : staff;
                if (!list.length) {
                    contactsEl.innerHTML = lastContactsHtml = '<p class="contacts-note">No staff match “' + esc(query) + '”.</p>';
                    return;
                }
                const html = list.map(s => {
                    const isActive = active && active.id === s.id;
                    const unread = isActive ? 0 : (s.unread || 0);
                    const preview = s.last_msg
                        ? `<span class="contact-preview">${s.last_mine ? '<span class="you">You: </span>' : ''}${esc(s.last_msg)}</span>`
                        : `<span class="contact-preview is-empty">${esc(s.role_label || '')} · No messages yet</span>`;
                    return `
                    <button type="button" class="contact-link ${isActive ? 'active' : ''} ${unread ? 'has-unread' : ''}" data-id="${s.id}" ${isActive ? 'aria-current="true"' : ''}>
                        ${avatarHtml(s)}
                        <span class="contact-body">
                            <span class="contact-top">
                                <span class="contact-name">${esc(fullName(s))}</span>
                                <span class="contact-time">${esc(fmtListTime(s.last_time))}</span>
                            </span>
                            <span class="contact-bottom">
                                ${preview}
                                ${unread ? `<span class="unread-badge" aria-label="${unread} unread">${unread > 99 ? '99+' : unread}</span>` : ''}
                            </span>
                        </span>
                    </button>`;
                }).join('');
                // Skip identical re-renders from the background refresh (keeps hover/focus intact).
                if (html !== lastContactsHtml) contactsEl.innerHTML = lastContactsHtml = html;
            }

            contactsEl.addEventListener('click', (e) => {
                const btn = e.target.closest('.contact-link');
                if (btn) openChat(parseInt(btn.getAttribute('data-id'), 10));
            });
            $('smSearch').addEventListener('input', (e) => {
                query = e.target.value.trim();
                renderContacts();
            });
            $('smBack').addEventListener('click', () => {
                // Leaving the conversation: stop polling so new messages stay unread.
                card.classList.remove('show-chat');
                active = null;
                info.hidden = true;
                closePop();
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                renderContacts();
                loadStaff().catch(() => {});
            });

            async function loadStaff() {
                const r = await fetch(ENDPOINT + '?action=get_staff');
                const data = await r.json();
                staff = data.staff || [];
                if (data.me) me = data.me;
                if (active) {
                    const fresh = staff.find(s => s.id === active.id);
                    if (fresh) { fresh.unread = 0; active = fresh; }
                }
                renderContacts();
            }

            // ── Conversation ──
            async function openChat(id) {
                const person = staff.find(s => s.id === id);
                if (!person) return;
                active = person;
                person.unread = 0;
                msgs = [];
                pins = [];
                lastId = 0;
                stateV = '';
                pinsOpen = false;
                hasOlder = false;
                closePop();
                clearReply();
                clearFile();
                resetInfo();
                $('smHeader').hidden = false;
                $('smAvatar').innerHTML = avatarHtml(person, 'sm-md');
                $('smTitle').textContent = fullName(person);
                const role = $('smRole');
                role.className = 'role-chip ' + roleClass(person);
                role.textContent = person.role_label || '';
                $('smFooter').hidden = false;
                card.classList.add('show-chat');
                syncComposer(); // size the textarea now that it is visible (it measured 0 while hidden)
                renderContacts();
                renderPins();
                win.innerHTML = '<p class="contacts-note" style="margin:auto;">Loading conversation…</p>';
                try {
                    const r = await fetch(ENDPOINT + `?action=get_history&staff_id=${id}`);
                    const data = await r.json();
                    if (!active || active.id !== id) return; // switched chats meanwhile
                    msgs = data.messages || [];
                    pins = data.pins || [];
                    stateV = data.v || '';
                    hasOlder = !!data.has_more;
                    if (msgs.length) lastId = msgs[msgs.length - 1].id;
                    renderPins();
                    renderMessages(true);
                } catch (e) {
                    win.innerHTML = '<p class="contacts-note" style="margin:auto;">Could not load this conversation.</p>';
                }
                startPoll();
                if (window.matchMedia('(min-width: 901px)').matches || (EMBED && window.matchMedia('(pointer: fine)').matches)) input.focus();
            }

            function actionsHtml(m) {
                if (m.pending) return '';
                if (m.deleted) {
                    return m.mine ? `<div class="msg-actions">
                        <button type="button" class="msg-act-btn" data-act="more" title="More" aria-label="More actions"><i class="fas fa-ellipsis-v"></i></button>
                    </div>` : '';
                }
                return `<div class="msg-actions">
                    <button type="button" class="msg-act-btn" data-act="react" title="React" aria-label="React"><i class="far fa-smile"></i></button>
                    <button type="button" class="msg-act-btn" data-act="reply" title="Reply" aria-label="Reply"><i class="fas fa-reply"></i></button>
                    <button type="button" class="msg-act-btn" data-act="more" title="More" aria-label="More actions"><i class="fas fa-ellipsis-v"></i></button>
                </div>`;
            }

            function attachmentHtml(m) {
                const a = m.attachment;
                if (m.pending || typeof m.id !== 'number') {
                    return `<span class="att-file"><span class="att-ico"><i class="fas ${fileIcon(a.name, a.mime)}"></i></span>
                        <span class="att-meta"><span class="att-name">${esc(a.name)}</span><span class="att-size">${fmtSize(a.size)} · Uploading…</span></span></span>`;
                }
                if (a.is_image) {
                    return `<a class="att-image" href="${esc(fileUrl(m.id, true))}" target="_blank" rel="noopener" title="${esc(a.name)}">
                        <img src="${esc(fileUrl(m.id, true))}" alt="${esc(a.name)}" loading="lazy"></a>`;
                }
                return `<a class="att-file" href="${esc(fileUrl(m.id))}" download title="Download ${esc(a.name)}">
                    <span class="att-ico"><i class="fas ${fileIcon(a.name, a.mime)}"></i></span>
                    <span class="att-meta"><span class="att-name">${esc(a.name)}</span><span class="att-size">${fmtSize(a.size)} · Download</span></span></a>`;
            }

            function msgHtml(m, o) {
                const peer = esc(active.name);
                const d = parseTs(m.created_at);
                let top = '';
                if (!m.deleted && pins.some(p => p.id === m.id)) {
                    top += '<span class="msg-label pinned"><i class="fas fa-thumbtack"></i> Pinned</span>';
                }
                if (m.forwarded && !m.deleted) {
                    top += `<span class="msg-label"><i class="fas fa-share"></i> ${m.mine ? 'You forwarded a message' : peer + ' forwarded a message'}</span>`;
                }
                if (m.reply && !m.deleted) {
                    const who = m.mine
                        ? (m.reply.mine ? 'You replied to yourself' : 'You replied to ' + peer)
                        : (m.reply.mine ? peer + ' replied to you' : peer + ' replied to themselves');
                    top += `<span class="msg-label"><i class="fas fa-reply"></i> ${who}</span>
                        <button type="button" class="reply-quote ${m.reply.deleted ? 'is-deleted' : ''}" data-act="jump" data-target="${m.reply.id}">${esc(m.reply.text)}</button>`;
                }
                let body = '';
                if (m.deleted) {
                    body = `<div class="bubble unsent">${m.mine ? 'You unsent a message' : peer + ' unsent a message'}</div>`;
                } else {
                    if (m.attachment) body += attachmentHtml(m);
                    if (m.text) body += `<div class="bubble">${esc(m.text)}</div>`;
                }
                const reacts = (!m.deleted && m.reactions && m.reactions.length)
                    ? `<div class="reactions">${m.reactions.map(r => `<button type="button" class="react-chip ${r.mine ? 'mine' : ''}" data-act="react-chip" data-emoji="${esc(r.emoji)}" title="${r.mine ? 'Your reaction (click to remove)' : 'React with ' + esc(r.emoji)}">${esc(r.emoji)}${r.count > 1 ? `<span>${r.count}</span>` : ''}</button>`).join('')}</div>`
                    : '';
                const time = (o.last || m.pending)
                    ? `<span class="msg-time">${m.pending ? 'Sending…' : esc(fmtTime(d))}</span>` : '';
                const avatar = m.mine ? '' : `<div class="msg-av">${o.last ? avatarHtml(active, 'sm-xs') : ''}</div>`;
                const cls = ['msg-row', m.mine ? 'sent' : 'received', o.grouped ? 'grouped' : '', o.last ? '' : 'has-next',
                             time ? '' : 'no-time', m.pending ? 'pending' : ''].join(' ');
                return `<div class="${cls}" data-id="${esc(m.id)}">
                    ${avatar}
                    <div class="msg-main">
                        ${top}
                        <div class="msg-line">
                            <div class="msg-content" title="${esc(fmtFull(d))}">${body}</div>
                            ${actionsHtml(m)}
                        </div>
                        ${reacts}
                        ${time}
                    </div>
                </div>`;
            }

            function renderMessages(stick) {
                if (!active) return;
                if (!msgs.length) {
                    win.innerHTML = `<div class="chat-empty">
                        ${avatarHtml(active)}
                        <h3>${esc(fullName(active))}</h3>
                        <p>No messages yet. Say hello to ${esc(active.name)}!</p>
                    </div>`;
                    return;
                }
                // Group consecutive messages from the same person within a few minutes.
                const meta = msgs.map(() => ({ grouped: false, last: true, sep: '' }));
                let prevDay = '';
                msgs.forEach((m, i) => {
                    const d = parseTs(m.created_at);
                    const day = d ? dayKey(d) : prevDay;
                    if (d && day !== prevDay) { meta[i].sep = fmtDay(d); prevDay = day; }
                    const p = msgs[i - 1];
                    const pd = p ? parseTs(p.created_at) : null;
                    if (p && !meta[i].sep && p.mine === m.mine && d && pd && (d - pd) < GROUP_MS
                        && !m.reply && !m.forwarded && !p.deleted && !m.deleted) {
                        meta[i].grouped = true;
                        meta[i - 1].last = false;
                    }
                });
                const html = (hasOlder ? '<button type="button" class="load-older" data-act="older">Load earlier messages</button>' : '')
                    + msgs.map((m, i) =>
                        (meta[i].sep ? `<div class="day-sep">${esc(meta[i].sep)}</div>` : '') + msgHtml(m, meta[i])
                    ).join('');
                const keepTop = win.scrollTop;
                const openFor = popAnchor ? popAnchor.closest('.msg-row')?.getAttribute('data-id') : null;
                win.innerHTML = html;
                if (stick || stickBottom) {
                    win.scrollTop = win.scrollHeight;
                    stickBottom = true;
                } else {
                    win.scrollTop = keepTop;
                }
                // Re-anchor an open popover to the freshly rendered row.
                if (openFor && popMode) {
                    const row = win.querySelector(`.msg-row[data-id="${CSS.escape(openFor)}"]`);
                    if (row) {
                        row.classList.add('pop-open');
                        const btn = row.querySelector(`[data-act="${popMode.anchorAct}"]`);
                        if (btn) popAnchor = btn;
                    } else {
                        closePop();
                    }
                }
            }

            win.addEventListener('scroll', () => {
                stickBottom = win.scrollHeight - win.scrollTop - win.clientHeight < 80;
                if (popMode && popMode.kind !== 'insert') closePop();
            });
            // Images change the height after they load; keep the view pinned to the newest message.
            win.addEventListener('load', (e) => {
                if (e.target.tagName === 'IMG' && stickBottom) win.scrollTop = win.scrollHeight;
            }, true);

            /** Prepend the previous page of history, keeping the reader's position. Returns true if anything was added. */
            async function loadOlder() {
                if (!active || loadingOlder || !hasOlder) return false;
                const oldest = msgs.find(m => typeof m.id === 'number');
                if (!oldest) return false;
                loadingOlder = true;
                const btn = win.querySelector('.load-older');
                if (btn) { btn.disabled = true; btn.textContent = 'Loading…'; }
                const id = active.id;
                try {
                    const r = await fetch(ENDPOINT + `?action=get_history&staff_id=${id}&before_id=${oldest.id}`);
                    const data = await r.json();
                    if (!active || active.id !== id) return false;
                    const older = (data.messages || []).filter(m => !msgById(m.id));
                    hasOlder = !!data.has_more;
                    const prevHeight = win.scrollHeight;
                    const prevTop = win.scrollTop;
                    msgs = older.concat(msgs);
                    stickBottom = false;
                    renderMessages();
                    win.scrollTop = win.scrollHeight - prevHeight + prevTop;
                    return older.length > 0;
                } catch (e) {
                    toast('Could not load earlier messages.');
                    return false;
                } finally {
                    loadingOlder = false;
                }
            }

            async function jumpTo(id) {
                const sel = `.msg-row[data-id="${CSS.escape(String(id))}"]`;
                // Older than the loaded window (e.g. from search or media): page back until it's there.
                for (let i = 0; i < 60 && !win.querySelector(sel) && hasOlder; i++) {
                    if (!await loadOlder()) break;
                }
                const row = win.querySelector(sel);
                if (!row) { toast('That message is no longer available.', 'info'); return; }
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.classList.remove('flash');
                void row.offsetWidth;
                row.classList.add('flash');
                setTimeout(() => row.classList.remove('flash'), 1500);
            }

            // ── Pins ──
            function renderPins() {
                const bar = $('smPins');
                const list = $('smPinList');
                const pinCount = $('smInfoPinCount');
                pinCount.hidden = !pins.length;
                pinCount.textContent = String(pins.length);
                if (!active || !pins.length) { bar.hidden = true; list.hidden = true; pinsOpen = false; return; }
                bar.hidden = false;
                $('smPinText').textContent = pins[0].text;
                $('smPinMain').setAttribute('data-target', pins[0].id);
                const toggle = $('smPinToggle');
                toggle.textContent = pinsOpen ? 'Hide' : (pins.length === 1 ? 'Manage' : `All ${pins.length}`);
                toggle.setAttribute('aria-expanded', pinsOpen ? 'true' : 'false');
                list.hidden = !pinsOpen;
                list.innerHTML = pins.map(p => `
                    <div class="pin-item">
                        <button type="button" class="pin-main" data-target="${p.id}">
                            <span class="pin-who">${p.mine ? 'You:' : esc(active.name) + ':'}</span>
                            <span class="pin-text">${esc(p.text)}</span>
                        </button>
                        <button type="button" class="pin-unpin" data-unpin="${p.id}" title="Unpin" aria-label="Unpin message"><i class="fas fa-times"></i></button>
                    </div>`).join('');
            }
            $('smPinMain').addEventListener('click', (e) => jumpTo(parseInt(e.currentTarget.getAttribute('data-target'), 10)));
            $('smPinToggle').addEventListener('click', () => { pinsOpen = !pinsOpen; renderPins(); });
            $('smPinList').addEventListener('click', (e) => {
                const un = e.target.closest('[data-unpin]');
                if (un) { doPin(parseInt(un.getAttribute('data-unpin'), 10), false); return; }
                const jump = e.target.closest('[data-target]');
                if (jump) jumpTo(parseInt(jump.getAttribute('data-target'), 10));
            });

            // ── Sync ──
            function applyState(state) {
                stateV = state.v || '';
                pins = state.pins || [];
                const items = state.items || {};
                const ids = Object.keys(items).map(Number);
                const minId = ids.length ? Math.min(...ids) : Infinity;
                // Messages removed for me (e.g. from another tab) disappear from the window.
                msgs = msgs.filter(m => typeof m.id !== 'number' || m.id < minId || items[m.id]);
                msgs.forEach(m => {
                    const it = items[m.id];
                    if (!it) return;
                    m.reactions = it.reactions || [];
                    if (it.deleted && !m.deleted) {
                        Object.assign(m, { deleted: true, text: '', attachment: null, reply: null, forwarded: false,
                                           message: m.mine ? 'You unsent a message' : 'Message unsent' });
                    }
                    if (m.reply && items[m.reply.id] && items[m.reply.id].deleted) {
                        m.reply.deleted = true;
                        m.reply.text = 'Message unsent';
                    }
                });
                renderPins();
            }

            function touchContact(m) {
                if (!active || !m) return;
                active.last_msg = m.message;
                active.last_time = m.created_at;
                active.last_mine = !!m.mine;
                staff = [active].concat(staff.filter(s => s.id !== active.id));
                renderContacts();
            }

            async function pollOnce(forceState) {
                // Polling marks the peer's messages read, so only do it while the tab is visible.
                if (!active || document.hidden || paused) return;
                if (polling) { pollAgain = true; forceNext = forceNext || !!forceState; return; }
                polling = true;
                const id = active.id;
                try {
                    const v = forceState ? 'force' : stateV;
                    const r = await fetch(ENDPOINT + `?action=poll&staff_id=${id}&last_id=${lastId}&v=${encodeURIComponent(v)}`);
                    const data = await r.json();
                    if (!active || active.id !== id) return;
                    const got = data.messages || [];
                    got.forEach(m => {
                        const i = msgs.findIndex(x => x.id === m.id);
                        if (i >= 0) msgs[i] = m; else msgs.push(m);
                        lastId = Math.max(lastId, m.id);
                    });
                    if (got.length) {
                        msgs.sort((a, b) => (typeof a.id === 'number' ? a.id : Infinity) - (typeof b.id === 'number' ? b.id : Infinity));
                    }
                    if (data.state) applyState(data.state);
                    if (got.length || data.state) {
                        renderMessages(got.some(m => m.mine));
                        if (got.length) touchContact(got[got.length - 1]);
                    }
                    if (!info.hidden && infoView === 'media' && got.some(m => m.attachment)) loadMedia(true);
                } catch (e) {
                    /* transient network error — next tick retries */
                } finally {
                    polling = false;
                    if (pollAgain) {
                        pollAgain = false;
                        const f = forceNext;
                        forceNext = false;
                        pollOnce(f);
                    }
                }
            }

            function startPoll() {
                if (pollTimer) clearInterval(pollTimer);
                pollTimer = setInterval(() => pollOnce(false), 3500);
            }

            // ── Sending ──
            async function send() {
                const text = input.value.trim();
                const file = pendingFile;
                const reply = replyTo;
                if (!active || sending || (!text && !file)) return;
                sending = true;
                const id = active.id;
                const tmp = {
                    id: 'p' + Date.now(), mine: true, pending: true, text, message: text || (file ? '📎 ' + file.name : ''),
                    created_at: nowUtc(), reactions: [], deleted: false, forwarded: false,
                    reply: reply ? { id: reply.id, mine: reply.mine, text: reply.text, deleted: false } : null,
                    attachment: file ? { name: file.name, size: file.size, mime: file.type, is_image: false } : null,
                };
                input.value = '';
                clearReply();
                clearFile();
                msgs.push(tmp);
                renderMessages(true);
                try {
                    const data = await post('send', { staff_id: id, message: text, reply_to_id: reply ? reply.id : '' }, file);
                    tmp.id = data.id; // the poll replaces this placeholder with the stored message
                    sending = false;
                    if (msgs.some(x => x !== tmp && x.id === data.id)) {
                        // A background poll already delivered the stored copy.
                        msgs = msgs.filter(x => x !== tmp);
                        renderMessages();
                    }
                    await pollOnce(false);
                } catch (e) {
                    sending = false;
                    msgs = msgs.filter(m => m !== tmp);
                    if (active && active.id === id) {
                        renderMessages();
                        if (!input.value) input.value = text;
                        if (reply) setReply(reply);
                        if (file) setFile(file);
                        syncComposer();
                    }
                    toast(e.message);
                }
            }

            // ── Reply ──
            function setReply(r) {
                replyTo = r;
                $('smReplyTitle').textContent = r.mine ? 'Replying to yourself' : 'Replying to ' + active.name;
                $('smReplyText').textContent = r.text;
                $('smReplyBar').hidden = false;
                input.focus();
            }
            function clearReply() {
                replyTo = null;
                $('smReplyBar').hidden = true;
            }
            $('smReplyCancel').addEventListener('click', clearReply);

            // ── Attachments ──
            function setFile(file) {
                const ext = String(file.name || '').split('.').pop().toLowerCase();
                if (!CFG.exts.includes(ext)) {
                    toast('Only JPG, PNG, WEBP, GIF, PDF, DOCX, XLSX, PPTX, CSV and TXT files are allowed.');
                    return;
                }
                if (file.size > CFG.maxBytes) { toast('Files must be 10 MB or smaller.'); return; }
                if (file.size === 0) { toast('The file is empty.'); return; }
                clearFile();
                pendingFile = file;
                const thumb = $('smAttachThumb');
                if (/^image\/(jpeg|png|webp|gif)$/.test(file.type)) {
                    pendingThumbUrl = URL.createObjectURL(file);
                    thumb.innerHTML = `<img src="${esc(pendingThumbUrl)}" alt="">`;
                } else {
                    thumb.innerHTML = `<i class="fas ${fileIcon(file.name, file.type)}"></i>`;
                }
                $('smAttachName').textContent = file.name;
                $('smAttachSize').textContent = fmtSize(file.size);
                $('smAttachBar').hidden = false;
                syncComposer();
                input.focus();
            }
            function clearFile() {
                pendingFile = null;
                fileInput.value = '';
                if (pendingThumbUrl) URL.revokeObjectURL(pendingThumbUrl);
                pendingThumbUrl = '';
                $('smAttachBar').hidden = true;
                $('smAttachThumb').innerHTML = '';
                syncComposer();
            }
            $('smAttachBtn').addEventListener('click', () => fileInput.click());
            $('smAttachCancel').addEventListener('click', () => clearFile());
            fileInput.addEventListener('change', () => { if (fileInput.files[0]) setFile(fileInput.files[0]); });
            input.addEventListener('paste', (e) => {
                const f = e.clipboardData && e.clipboardData.files && e.clipboardData.files[0];
                if (f) { e.preventDefault(); setFile(f); }
            });

            // ── Composer ──
            function syncComposer() {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 120) + 'px';
                sendBtn.disabled = input.value.trim() === '' && !pendingFile;
            }
            input.addEventListener('input', syncComposer);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                    e.preventDefault();
                    send();
                } else if (e.key === 'Escape' && replyTo && !popMode) {
                    clearReply(); // with a popover open, Escape only closes the popover
                }
            });
            $('smForm').addEventListener('submit', (e) => { e.preventDefault(); send(); });
            function insertEmoji(emoji) {
                const s = input.selectionStart ?? input.value.length;
                const en = input.selectionEnd ?? input.value.length;
                input.setRangeText(emoji, s, en, 'end');
                syncComposer();
                input.focus();
            }

            // ── Popovers ──
            function openPop(anchor, html, kind, extra) {
                closePop();
                popMode = Object.assign({ kind }, extra || {});
                popAnchor = anchor;
                pop.className = 'sm-pop ' + kind.replace('insert', 'emoji').replace('react-full', 'emoji');
                pop.innerHTML = html;
                pop.hidden = false;
                const row = anchor.closest('.msg-row');
                if (row) row.classList.add('pop-open');
                const r = anchor.getBoundingClientRect();
                const pw = pop.offsetWidth;
                const ph = pop.offsetHeight;
                let top = r.top - ph - 8;
                if (top < 8) top = Math.min(r.bottom + 8, window.innerHeight - ph - 8);
                let left = r.left + r.width / 2 - pw / 2;
                left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
                pop.style.top = Math.max(8, top) + 'px';
                pop.style.left = left + 'px';
                const first = pop.querySelector('button');
                if (first && kind !== 'insert') first.focus({ preventScroll: true });
            }
            function closePop() {
                if (!popMode) return;
                pop.hidden = true;
                pop.innerHTML = '';
                win.querySelectorAll('.msg-row.pop-open').forEach(r => r.classList.remove('pop-open'));
                if (popAnchor && document.body.contains(popAnchor) && pop.contains(document.activeElement)) popAnchor.focus();
                popMode = null;
                popAnchor = null;
            }
            document.addEventListener('mousedown', (e) => {
                if (popMode && !pop.contains(e.target) && !(popAnchor && popAnchor.contains(e.target))) closePop();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                if (!modal.hidden) closeModal();
                else if (popMode) closePop();
            });
            window.addEventListener('resize', closePop);

            function emojiPickerHtml() {
                const groups = CFG.emojiGroups;
                const names = Object.keys(groups);
                return `<div class="emoji-tabs">${names.map((n, i) => `<button type="button" class="emoji-tab" data-tab="${i}">${esc(n)}</button>`).join('')}</div>
                    <div class="emoji-scroll">${names.map((n, i) => `
                        <div class="emoji-group-title" data-group="${i}">${esc(n)}</div>
                        <div class="emoji-grid">${groups[n].map(e => `<button type="button" class="emoji-btn" data-emoji="${esc(e)}" aria-label="${esc(e)}">${esc(e)}</button>`).join('')}</div>`).join('')}
                    </div>`;
            }
            function quickReactHtml(m) {
                const mineEmoji = (m.reactions || []).find(r => r.mine)?.emoji;
                return CFG.quickReactions.map(e =>
                    `<button type="button" class="qr-btn ${e === mineEmoji ? 'mine' : ''}" data-emoji="${esc(e)}" aria-label="React ${esc(e)}">${esc(e)}</button>`
                ).join('') + '<button type="button" class="qr-btn qr-more" data-more-emoji="1" aria-label="More reactions" title="More"><i class="fas fa-plus"></i></button>';
            }
            function menuHtml(m) {
                const items = [];
                if (m.mine) items.push(['remove', 'fa-trash-alt', 'Remove', 'danger']);
                if (!m.deleted) {
                    items.push(['forward', 'fa-share', 'Forward', '']);
                    const pinned = pins.some(p => p.id === m.id);
                    items.push([pinned ? 'unpin' : 'pin', 'fa-thumbtack', pinned ? 'Unpin' : 'Pin', '']);
                }
                return items.map(([k, icon, label, cls]) =>
                    `<button type="button" class="sm-menu-item ${cls}" data-menu="${k}"><i class="fas ${icon}"></i> ${label}</button>`).join('');
            }

            pop.addEventListener('click', (e) => {
                if (!popMode) return;
                const tab = e.target.closest('[data-tab]');
                if (tab) {
                    const g = pop.querySelector(`[data-group="${tab.getAttribute('data-tab')}"]`);
                    if (g) g.parentNode.scrollTop = g.offsetTop - g.parentNode.offsetTop;
                    return;
                }
                if (e.target.closest('[data-more-emoji]')) {
                    const { id } = popMode;
                    openPop(popAnchor, emojiPickerHtml(), 'react-full', { id, anchorAct: 'react' });
                    return;
                }
                const em = e.target.closest('[data-emoji]');
                if (em) {
                    const emoji = em.getAttribute('data-emoji');
                    if (popMode.kind === 'insert') { insertEmoji(emoji); return; } // picker stays open
                    const id = popMode.id;
                    closePop();
                    doReact(id, emoji);
                    return;
                }
                const item = e.target.closest('[data-menu]');
                if (item) {
                    const m = msgById(popMode.id);
                    closePop();
                    if (!m) return;
                    const k = item.getAttribute('data-menu');
                    if (k === 'remove') openRemoveDialog(m);
                    else if (k === 'forward') openForwardDialog(m);
                    else if (k === 'pin') doPin(m.id, true);
                    else if (k === 'unpin') doPin(m.id, false);
                }
            });

            $('smEmojiBtn').addEventListener('click', (e) => {
                if (popMode && popMode.kind === 'insert') { closePop(); return; }
                openPop(e.currentTarget, emojiPickerHtml(), 'insert');
            });

            // Message toolbar + reaction chips + reply quotes
            win.addEventListener('click', (e) => {
                const pic = e.target.closest('.att-image');
                if (pic && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                    const m = msgById(Number(pic.closest('.msg-row').getAttribute('data-id')));
                    if (m && m.attachment) {
                        e.preventDefault();
                        openLightbox({ id: m.id, name: m.attachment.name, created_at: m.created_at, mine: m.mine });
                        return;
                    }
                }
                const btn = e.target.closest('[data-act]');
                const row = e.target.closest('.msg-row');
                if (!btn) {
                    // Touch screens have no hover: tapping a message shows its toolbar.
                    if (row && window.matchMedia('(hover: none)').matches && e.target.closest('.msg-content') && !e.target.closest('a')) {
                        const on = !row.classList.contains('show-actions');
                        win.querySelectorAll('.msg-row.show-actions').forEach(r => r.classList.remove('show-actions'));
                        row.classList.toggle('show-actions', on);
                    }
                    return;
                }
                const act = btn.getAttribute('data-act');
                if (act === 'jump') { jumpTo(parseInt(btn.getAttribute('data-target'), 10)); return; }
                if (act === 'older') { loadOlder(); return; }
                const m = row ? msgById(Number(row.getAttribute('data-id'))) : null;
                if (!m) return;
                if (act === 'react') {
                    if (popMode && popAnchor === btn) { closePop(); return; }
                    openPop(btn, quickReactHtml(m), 'quick', { id: m.id, anchorAct: 'react' });
                } else if (act === 'more') {
                    if (popMode && popAnchor === btn) { closePop(); return; }
                    openPop(btn, menuHtml(m), 'menu', { id: m.id, anchorAct: 'more' });
                } else if (act === 'reply') {
                    setReply({ id: m.id, mine: m.mine, text: snippet(m) });
                } else if (act === 'react-chip') {
                    doReact(m.id, btn.getAttribute('data-emoji'));
                }
            });

            // ── Message actions ──
            async function doReact(id, emoji) {
                const m = msgById(id);
                if (!m) return;
                // Optimistic: one reaction per person, clicking the same one again removes it.
                const list = (m.reactions || []).map(r => Object.assign({}, r));
                const prev = list.find(r => r.mine);
                if (prev) { prev.count--; prev.mine = false; }
                if (!prev || prev.emoji !== emoji) {
                    const same = list.find(r => r.emoji === emoji);
                    if (same) { same.count++; same.mine = true; } else list.push({ emoji, count: 1, mine: true });
                }
                const before = m.reactions;
                m.reactions = list.filter(r => r.count > 0);
                renderMessages();
                try {
                    await post('react', { message_id: id, emoji });
                } catch (e) {
                    m.reactions = before;
                    renderMessages();
                    toast(e.message);
                }
                pollOnce(true);
            }

            async function doPin(id, pin) {
                try {
                    await post(pin ? 'pin' : 'unpin', { message_id: id });
                    await pollOnce(true);
                } catch (e) {
                    toast(e.message);
                }
            }

            // ── Dialogs ──
            function openModal(html) {
                modalCard.innerHTML = html;
                modal.hidden = false;
                const focusEl = modalCard.querySelector('[data-autofocus]') || modalCard.querySelector('input, button');
                if (focusEl) focusEl.focus();
            }
            function closeModal() {
                modal.hidden = true;
                modal.classList.remove('lightbox');
                modalCard.classList.remove('wide');
                modalCard.innerHTML = '';
                modalCard.onclick = null;
                modalCard.oninput = null;
                input.focus();
            }
            modal.addEventListener('mousedown', (e) => { if (e.target === modal) closeModal(); });

            function openRemoveDialog(m) {
                const peer = esc(active.name);
                const options = m.deleted
                    ? [['me', 'Remove for you', `This message will be removed from your view. ${peer} will still see that a message was unsent.`]]
                    : [['all', 'Unsend for everyone', `This message will be unsent for everyone in the chat. ${peer} may have already seen it.`],
                       ['me', 'Remove for you', `This will remove the message from your view. ${peer} will still be able to see it.`]];
                openModal(`
                    <div class="sm-modal-head"><h3 id="smModalTitle">Who do you want to remove this message for?</h3>
                        <button type="button" class="ce-close" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
                    <div class="sm-modal-body">
                        ${options.map(([v, t, d], i) => `<label class="sm-option"><input type="radio" name="smRemove" value="${v}" ${i === 0 ? 'checked' : ''}>
                            <div><b>${t}</b><span>${d}</span></div></label>`).join('')}
                    </div>
                    <div class="sm-modal-foot">
                        <button type="button" class="btn btn-outline btn-sm" data-close>Cancel</button>
                        <button type="button" class="btn btn-danger btn-sm" data-remove data-autofocus><i class="fas fa-trash-alt"></i> Remove</button>
                    </div>`);
                modalCard.setAttribute('aria-labelledby', 'smModalTitle');
                modalCard.onclick = async (e) => {
                    if (e.target.closest('[data-close]')) { closeModal(); return; }
                    const go = e.target.closest('[data-remove]');
                    if (!go) return;
                    const scope = (modalCard.querySelector('input[name="smRemove"]:checked') || {}).value;
                    go.disabled = true;
                    try {
                        await post('unsend', { message_id: m.id, scope });
                        closeModal();
                        if (scope === 'me') {
                            msgs = msgs.filter(x => x.id !== m.id);
                        } else {
                            Object.assign(m, { deleted: true, text: '', attachment: null, reply: null, forwarded: false, reactions: [],
                                               message: 'You unsent a message' });
                        }
                        if (replyTo && replyTo.id === m.id) clearReply();
                        renderMessages();
                        if (!info.hidden && infoView === 'media' && m.attachment === null) loadMedia(true);
                        pollOnce(true);
                        loadStaff().catch(() => {});
                    } catch (err) {
                        go.disabled = false;
                        toast(err.message);
                    }
                };
            }

            function openForwardDialog(m) {
                const render = (q) => {
                    const ql = q.toLowerCase();
                    const list = staff.filter(s => !ql || (fullName(s) + ' ' + (s.role_label || '')).toLowerCase().includes(ql));
                    return list.length ? list.map(s => `
                        <div class="fwd-item">
                            ${avatarHtml(s, 'sm-sm')}
                            <div class="fwd-name"><b>${esc(fullName(s))}</b><span>${esc(s.role_label || '')}</span></div>
                            <button type="button" class="btn btn-primary btn-sm" data-fwd="${s.id}">Send</button>
                        </div>`).join('') : '<p class="contacts-note">No staff match.</p>';
                };
                openModal(`
                    <div class="sm-modal-head"><h3 id="smModalTitle">Forward message</h3>
                        <button type="button" class="ce-close" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
                    <div class="sm-modal-body">
                        <div class="fwd-preview">${esc(snippet(m))}</div>
                        <label class="sm-search"><i class="fas fa-search" aria-hidden="true"></i>
                            <input type="search" id="smFwdSearch" placeholder="Search staff…" autocomplete="off" aria-label="Search staff" data-autofocus></label>
                        <div class="fwd-list" id="smFwdList">${render('')}</div>
                    </div>
                    <div class="sm-modal-foot"><button type="button" class="btn btn-outline btn-sm" data-close>Done</button></div>`);
                modalCard.setAttribute('aria-labelledby', 'smModalTitle');
                const sent = new Set();
                const refreshSent = () => sent.forEach(sid => {
                    const b = modalCard.querySelector(`[data-fwd="${sid}"]`);
                    if (b) { b.disabled = true; b.textContent = 'Sent'; b.className = 'btn btn-outline btn-sm'; }
                });
                modalCard.oninput = (e) => {
                    if (e.target.id !== 'smFwdSearch') return;
                    $('smFwdList').innerHTML = render(e.target.value.trim());
                    refreshSent();
                };
                modalCard.onclick = async (e) => {
                    if (e.target.closest('[data-close]')) { closeModal(); return; }
                    const b = e.target.closest('[data-fwd]');
                    if (!b || b.disabled) return;
                    const to = parseInt(b.getAttribute('data-fwd'), 10);
                    b.disabled = true;
                    b.textContent = 'Sending…';
                    try {
                        await post('forward', { message_id: m.id, to_staff_id: to });
                        sent.add(to);
                        refreshSent();
                        if (active && to === active.id) pollOnce(false);
                        loadStaff().catch(() => {});
                    } catch (err) {
                        b.disabled = false;
                        b.textContent = 'Send';
                        toast(err.message);
                    }
                };
            }

            // ── Conversation information panel ──
            function infoOpen() { return card.classList.contains('info-open'); }
            function overlayMode() { return window.matchMedia('(max-width: 1200px)').matches; }
            function setInfoOpen(open) {
                card.classList.toggle('info-open', open);
                info.hidden = !(open && active);
                $('smInfoBtn').setAttribute('aria-pressed', open ? 'true' : 'false');
                try { localStorage.setItem('sm_info_open', open ? '1' : '0'); } catch (e) { /* storage blocked */ }
                if (open && active) showInfoView(infoView);
            }
            function showInfoView(view) {
                infoView = view;
                $('smInfoMain').hidden = view !== 'main';
                $('smInfoSearch').hidden = view !== 'search';
                $('smInfoMedia').hidden = view !== 'media';
            }
            function resetInfo() {
                if (!active) return;
                $('smInfoAvatar').innerHTML = avatarHtml(active);
                $('smInfoName').textContent = fullName(active);
                const role = $('smInfoRole');
                role.className = 'role-chip ' + roleClass(active);
                role.textContent = active.role_label || '';
                $('smChatSearch').value = '';
                $('smChatSearchCount').textContent = '';
                $('smChatSearchResults').innerHTML = '';
                mediaItems = [];
                showInfoView('main');
                info.hidden = !infoOpen();
                $('smInfoBtn').setAttribute('aria-pressed', infoOpen() ? 'true' : 'false');
            }
            // In the overlay layout the panel covers the chat, so close it before showing a message.
            function gotoMessage(id) {
                if (overlayMode()) setInfoOpen(false);
                jumpTo(id);
            }
            $('smInfoBtn').addEventListener('click', () => setInfoOpen(!infoOpen()));
            $('smInfoToggle').addEventListener('click', (e) => {
                const open = e.currentTarget.getAttribute('aria-expanded') !== 'true';
                e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
                $('smInfoItems').hidden = !open;
            });
            info.addEventListener('click', (e) => {
                const nav = e.target.closest('[data-info]');
                if (nav) {
                    const k = nav.getAttribute('data-info');
                    if (k === 'close') setInfoOpen(false);
                    else if (k === 'main') showInfoView('main');
                    else if (k === 'search') { showInfoView('search'); $('smChatSearch').focus(); }
                    else if (k === 'pins') openPinnedDialog();
                    else if (k === 'media' || k === 'files') { mediaTab = k; showInfoView('media'); loadMedia(true); }
                    return;
                }
                const tab = e.target.closest('[data-mtab]');
                if (tab) {
                    if (tab.getAttribute('data-mtab') !== mediaTab) { mediaTab = tab.getAttribute('data-mtab'); loadMedia(true); }
                    return;
                }
                const hit = e.target.closest('[data-hit]');
                if (hit) { gotoMessage(parseInt(hit.getAttribute('data-hit'), 10)); return; }
                const thumb = e.target.closest('[data-media]');
                if (thumb) {
                    const it = mediaItems.find(x => x.id === parseInt(thumb.getAttribute('data-media'), 10));
                    if (it) openLightbox(it);
                    return;
                }
                const go = e.target.closest('[data-goto]');
                if (go) { gotoMessage(parseInt(go.getAttribute('data-goto'), 10)); return; }
                if (e.target.closest('[data-media-more]')) loadMedia(false);
            });

            // Search in conversation (server-side, so older messages are found too)
            function highlight(text, q) {
                const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                let out = '';
                let last = 0;
                String(text).replace(re, (match, offset) => {
                    out += esc(text.slice(last, offset)) + '<mark>' + esc(match) + '</mark>';
                    last = offset + match.length;
                    return match;
                });
                return out + esc(String(text).slice(last));
            }
            $('smChatSearch').addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(runSearch, 300);
            });
            async function runSearch() {
                if (!active) return;
                const q = $('smChatSearch').value.trim();
                const box = $('smChatSearchResults');
                const count = $('smChatSearchCount');
                const seq = ++searchSeq;
                if (q.length < 2) {
                    count.textContent = '';
                    box.innerHTML = q ? '<p class="info-empty">Type at least 2 characters.</p>' : '';
                    return;
                }
                const id = active.id;
                box.innerHTML = '<p class="info-empty">Searching…</p>';
                try {
                    const r = await fetch(ENDPOINT + `?action=search&staff_id=${id}&q=${encodeURIComponent(q)}`);
                    const data = await r.json();
                    if (seq !== searchSeq || !active || active.id !== id) return;
                    const res = data.results || [];
                    count.textContent = data.total > 100 ? '99+ results' : `${res.length} result${res.length === 1 ? '' : 's'}`;
                    box.innerHTML = res.length ? res.map(h => {
                        const who = h.mine ? (me || { name: 'You', role: '' }) : active;
                        return `<button type="button" class="search-hit" data-hit="${h.id}">
                            ${avatarHtml(who, 'sm-sm')}
                            <span class="hit-body">
                                <span class="hit-name">${h.mine ? 'You' : esc(fullName(active))}</span>
                                <span class="hit-text">${highlight(h.snippet, q)}</span>
                            </span>
                            <span class="hit-date">${esc(fmtListTime(h.created_at))}</span>
                        </button>`;
                    }).join('') : '<p class="info-empty"><i class="fas fa-search"></i>No messages found.</p>';
                } catch (e) {
                    if (seq === searchSeq) box.innerHTML = '<p class="info-empty">Search failed. Please try again.</p>';
                }
            }

            // Media and files
            function monthLabel(d) {
                const sameYear = dayKey(d).slice(0, 4) === dayKey(new Date()).slice(0, 4);
                return d.toLocaleDateString('en-US', { timeZone: TZ, month: 'long', year: sameYear ? undefined : 'numeric' });
            }
            async function loadMedia(reset) {
                if (!active) return;
                const id = active.id;
                const tab = mediaTab;
                const body = $('smMediaBody');
                info.querySelectorAll('[data-mtab]').forEach(t => t.setAttribute('aria-selected', t.getAttribute('data-mtab') === tab ? 'true' : 'false'));
                if (reset) {
                    mediaItems = [];
                    mediaMore = false;
                    body.innerHTML = '<p class="info-empty">Loading…</p>';
                }
                const before = reset || !mediaItems.length ? 0 : mediaItems[mediaItems.length - 1].id;
                try {
                    const r = await fetch(ENDPOINT + `?action=media&staff_id=${id}&kind=${tab}&before_id=${before}`);
                    const data = await r.json();
                    if (!active || active.id !== id || tab !== mediaTab) return;
                    const seen = new Set(mediaItems.map(x => x.id));
                    mediaItems = mediaItems.concat((data.items || []).filter(x => !seen.has(x.id)));
                    mediaMore = !!data.has_more;
                    renderMedia();
                } catch (e) {
                    body.innerHTML = '<p class="info-empty">Could not load. Please try again.</p>';
                }
            }
            function renderMedia() {
                const body = $('smMediaBody');
                const isMedia = mediaTab === 'media';
                if (!mediaItems.length) {
                    body.innerHTML = `<p class="info-empty"><i class="far ${isMedia ? 'fa-image' : 'fa-file-alt'}"></i>
                        No ${isMedia ? 'photos' : 'files'} shared in this conversation yet.</p>`;
                    return;
                }
                const groups = [];
                mediaItems.forEach(it => {
                    const d = parseTs(it.created_at);
                    const label = d ? monthLabel(d) : '';
                    let g = groups[groups.length - 1];
                    if (!g || g.label !== label) { g = { label, items: [] }; groups.push(g); }
                    g.items.push(it);
                });
                body.innerHTML = groups.map(g => `<div class="media-month">${esc(g.label)}</div>` + (isMedia
                    ? `<div class="media-grid">${g.items.map(it => `
                        <button type="button" class="media-thumb" data-media="${it.id}" title="${esc(it.name)}" aria-label="View ${esc(it.name)}">
                            <img src="${esc(fileUrl(it.id, true))}" alt="" loading="lazy"></button>`).join('')}</div>`
                    : g.items.map(it => `
                        <div class="file-row">
                            <span class="att-ico"><i class="fas ${fileIcon(it.name, it.mime)}"></i></span>
                            <a class="file-main" href="${esc(fileUrl(it.id))}" download title="Download ${esc(it.name)}">
                                <span class="file-name">${esc(it.name)}</span>
                                <span class="file-meta">${fmtSize(it.size)} · ${esc(fmtListTime(it.created_at))} · ${it.mine ? 'You' : esc(active.name)}</span>
                            </a>
                            <button type="button" class="info-back" data-goto="${it.id}" title="Show in chat" aria-label="Show ${esc(it.name)} in chat"><i class="far fa-comment-dots"></i></button>
                        </div>`).join('')
                )).join('') + (mediaMore ? '<button type="button" class="btn btn-outline btn-sm info-more" data-media-more="1">Load more</button>' : '');
            }

            // Image viewer (media grid + images in the chat)
            function openLightbox(it) {
                const d = parseTs(it.created_at);
                modal.classList.add('lightbox');
                openModal(`
                    <div class="lb-top">
                        <div class="lb-title">
                            <span class="lb-name">${esc(it.name)}</span>
                            <span class="lb-date">${it.mine ? 'You' : esc(fullName(active))} · ${esc(fmtFull(d))}</span>
                        </div>
                        <button type="button" class="lb-btn" data-goto="${it.id}" title="Show in chat" aria-label="Show in chat"><i class="far fa-comment-dots"></i></button>
                        <a class="lb-btn" href="${esc(fileUrl(it.id))}" download title="Download" aria-label="Download"><i class="fas fa-download"></i></a>
                        <button type="button" class="lb-btn" data-close title="Close" aria-label="Close" data-autofocus><i class="fas fa-times"></i></button>
                    </div>
                    <div class="lb-stage" data-stage><img class="lb-img" src="${esc(fileUrl(it.id, true))}" alt="${esc(it.name)}"></div>`);
                modalCard.setAttribute('aria-label', it.name);
                modalCard.onclick = (e) => {
                    // Close button, or a click on the dark area around the picture.
                    if (e.target.closest('[data-close]') || e.target.hasAttribute('data-stage')) { closeModal(); return; }
                    const go = e.target.closest('[data-goto]');
                    if (go) { closeModal(); gotoMessage(parseInt(go.getAttribute('data-goto'), 10)); }
                };
            }

            // Pinned messages dialog (full text, attachments, unpin / go to message)
            function openPinnedDialog() {
                const render = () => pins.length ? pins.map(p => {
                    const d = parseTs(p.created_at);
                    const a = p.attachment;
                    const media = !a ? '' : a.is_image
                        ? `<img class="pinned-img" src="${esc(fileUrl(p.id, true))}" alt="${esc(a.name)}" loading="lazy">`
                        : `<a class="att-file" href="${esc(fileUrl(p.id))}" download><span class="att-ico"><i class="fas ${fileIcon(a.name, a.mime)}"></i></span>
                            <span class="att-meta"><span class="att-name">${esc(a.name)}</span><span class="att-size">${fmtSize(a.size)} · Download</span></span></a>`;
                    return `<div class="pinned-item">
                        <div class="pinned-meta"><b>${p.mine ? 'You' : esc(fullName(active))}</b><span>${esc(d ? fmtFull(d) : '')}</span></div>
                        ${media}
                        ${p.full_text ? `<div class="pinned-text">${esc(p.full_text)}</div>` : ''}
                        <div class="pinned-actions">
                            <button type="button" class="btn btn-outline btn-sm" data-unpin="${p.id}"><i class="fas fa-thumbtack"></i> Unpin</button>
                            <button type="button" class="btn btn-primary btn-sm" data-goto="${p.id}">Go to message</button>
                        </div>
                    </div>`;
                }).join('') : '<p class="info-empty"><i class="fas fa-thumbtack"></i>No pinned messages yet. Pin one from a message’s ⋮ menu.</p>';
                modalCard.classList.add('wide');
                openModal(`
                    <div class="sm-modal-head"><h3 id="smModalTitle">Pinned messages</h3>
                        <button type="button" class="ce-close" data-close aria-label="Close" data-autofocus><i class="fas fa-times"></i></button></div>
                    <div class="sm-modal-body" id="smPinnedBody">${render()}</div>`);
                modalCard.setAttribute('aria-labelledby', 'smModalTitle');
                modalCard.onclick = async (e) => {
                    if (e.target.closest('[data-close]')) { closeModal(); return; }
                    const go = e.target.closest('[data-goto]');
                    if (go) { closeModal(); gotoMessage(parseInt(go.getAttribute('data-goto'), 10)); return; }
                    const un = e.target.closest('[data-unpin]');
                    if (un) {
                        un.disabled = true;
                        await doPin(parseInt(un.getAttribute('data-unpin'), 10), false);
                        const b = $('smPinnedBody');
                        if (b) b.innerHTML = render();
                    }
                };
            }

            // Remember the panel on wide screens (it would cover the chat on narrow ones).
            try {
                if (localStorage.getItem('sm_info_open') === '1' && !overlayMode()) card.classList.add('info-open');
            } catch (e) { /* storage blocked */ }

            // ── Floating widget (embed) bridge ──
            function notifyParent(total) {
                if (!EMBED) return;
                window.parent.postMessage({
                    source: 'staff-messages', unread: total, activeId: active ? active.id : 0,
                    recent: staff.slice(0, 3).map(s => ({ name: s.name, surname: s.surname, role: s.role, avatar: s.avatar })),
                }, location.origin);
            }
            if (EMBED) {
                window.addEventListener('message', (e) => {
                    if (e.origin !== location.origin || e.source !== window.parent || !e.data || e.data.source !== 'staff-chat-widget') return;
                    if (e.data.type === 'pause') {
                        paused = true;
                        closePop();
                        if (!modal.hidden) closeModal();
                    } else if (e.data.type === 'resume') {
                        paused = false;
                        pollOnce(false);
                        loadStaff().catch(() => {});
                    }
                });
            }

            // ── Start ──
            loadStaff()
                .then(() => { if (CFG.openId) openChat(CFG.openId); })
                .catch(() => {
                    contactsEl.innerHTML = lastContactsHtml = '<p class="contacts-note">Could not load staff.</p>';
                });
            // Keep unread counts and previews for other conversations fresh.
            setInterval(() => { if (!document.hidden && !paused) loadStaff().catch(() => {}); }, 5000);
            document.addEventListener('visibilitychange', () => {
                if (document.hidden || paused) return;
                pollOnce(false);
                loadStaff().catch(() => {});
            });
        })();
        </script>
        <?php
    }
}
