<?php
/**
 * Primo — AI Product Assistant (CLIENT storefront).
 * CSS robot UI + SVM-backed chat via backend/api/primo_chat.php
 *
 * Included by ep_footer.php on the pages ep_header.php allows ($epShowPrimo).
 * The homepage shows the full robot; every other page gets the compact round launcher.
 */
require_once __DIR__ . '/../includes/security.php';
$primoCompact = empty($isHomePage);
$primoCsrf = generateCsrfToken(); // guests too: the Primo chat endpoint requires it
// Chat history is saved for logged-in clients only (same check as primo_history_api.php).
$primoGuest = empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client';
?>
<div id="primoRoot" class="primo-root is-collapsed<?php echo $primoCompact ? ' is-compact' : ''; ?>"<?php echo $primoGuest ? ' data-guest="1"' : ''; ?>>
    <div class="primo-stage">
        <button type="button" class="primo-mascot" id="primoMascot" aria-label="Chat with Primo, AI Product Assistant" aria-expanded="false" aria-controls="primoPanel"<?php echo $primoCompact ? ' title="Chat with Primo"' : ''; ?>>
            <?php if ($primoCompact): ?>
            <span class="primo-launcher" aria-hidden="true">
                <span class="primo-launcher-face">
                    <span class="primo-launcher-antenna"></span>
                    <span class="primo-launcher-head">
                        <span class="primo-launcher-visor">
                            <span class="primo-launcher-eye"></span>
                            <span class="primo-launcher-eye"></span>
                        </span>
                    </span>
                </span>
                <i class="fas fa-times primo-launcher-close"></i>
            </span>
            <?php else: ?>
            <span class="primo-figure" id="primoFigure" aria-hidden="true">
                <!-- Compact robot built from CSS shapes -->
                <span class="primo-antenna"><span class="primo-antenna-tip"></span></span>
                <span class="primo-head">
                    <span class="primo-visor">
                        <span class="primo-eye primo-eye-l"></span>
                        <span class="primo-eye primo-eye-r"></span>
                        <span class="primo-mouth"></span>
                    </span>
                </span>
                <span class="primo-neck"></span>
                <span class="primo-torso">
                    <span class="primo-chest-light"></span>
                    <span class="primo-arm primo-arm-l"></span>
                    <span class="primo-arm primo-arm-r">
                        <span class="primo-hand"></span>
                    </span>
                </span>
                <span class="primo-base">
                    <span class="primo-foot primo-foot-l"></span>
                    <span class="primo-foot primo-foot-r"></span>
                </span>
            </span>
            <span class="primo-label">Primo</span>
            <?php endif; ?>
        </button>
        <?php if (!$primoCompact): ?>
        <button type="button" class="primo-tuck" id="primoTuck" aria-label="Hide Primo" title="Hide Primo" hidden>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </button>
        <?php endif; ?>
    </div>

    <div class="primo-backdrop" id="primoBackdrop" hidden></div>

    <section class="primo-panel" id="primoPanel" role="dialog" aria-modal="false" aria-labelledby="primoPanelTitle" hidden>
        <header class="primo-panel-header">
            <div class="primo-panel-identity">
                <span class="primo-mini" aria-hidden="true">
                    <span class="primo-mini-head">
                        <span class="primo-mini-eye"></span>
                        <span class="primo-mini-eye"></span>
                    </span>
                    <span class="primo-mini-body"></span>
                </span>
                <div class="primo-panel-titles">
                    <h2 id="primoPanelTitle">Primo</h2>
                    <p class="primo-status" id="primoStatus"><span class="primo-status-dot" aria-hidden="true"></span><span id="primoStatusText">AI Product Assistant</span></p>
                </div>
            </div>
            <div class="primo-panel-actions">
                <button type="button" class="primo-icon-btn" id="primoResetBtn" aria-label="Start a new chat" title="New chat">
                    <i class="fas fa-plus" aria-hidden="true"></i>
                </button>
                <button type="button" class="primo-icon-btn" id="primoHistoryBtn" aria-label="Recent chats" title="Recent chats" aria-expanded="false" aria-controls="primoHistory">
                    <i class="fas fa-history" aria-hidden="true"></i>
                </button>
                <button type="button" class="primo-icon-btn primo-size-btn" id="primoSizeBtn" aria-label="Expand chat" title="Expand" aria-pressed="false">
                    <i class="fas fa-expand-alt" aria-hidden="true"></i>
                </button>
                <button type="button" class="primo-icon-btn" id="primoClose" aria-label="Close Primo chat" title="Close">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <div class="primo-history" id="primoHistory" aria-label="Recent chats" inert>
            <div class="primo-history-head">
                <button type="button" class="primo-history-back" id="primoHistoryBack" aria-label="Back to chat" title="Back to chat">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </button>
                <div class="primo-history-titles">
                    <strong>Recent chats</strong>
                    <span id="primoHistoryCount">Last 7 days</span>
                </div>
                <button type="button" class="primo-history-new" id="primoHistoryNew">
                    <i class="fas fa-plus" aria-hidden="true"></i><span>New chat</span>
                </button>
            </div>
            <div class="primo-history-search" id="primoHistorySearchWrap" hidden>
                <i class="fas fa-search" aria-hidden="true"></i>
                <label class="sr-only" for="primoHistorySearch">Search recent chats</label>
                <input type="search" id="primoHistorySearch" placeholder="Search chats" autocomplete="off">
            </div>
            <div class="primo-history-body" id="primoHistoryBody">
                <p class="primo-history-empty">Loading…</p>
            </div>
            <p class="primo-history-note"><i class="far fa-clock" aria-hidden="true"></i>Chats are kept for 7 days. New chat starts fresh without deleting these.</p>
        </div>

        <div class="primo-panel-body" id="primoPanelBody" role="log" aria-live="polite" aria-relevant="additions" tabindex="-1">
            <div class="primo-msg primo-msg-bot" id="primoWelcomeMsg">
                <div class="primo-msg-avatar" aria-hidden="true">
                    <span class="primo-mini primo-mini-sm">
                        <span class="primo-mini-head">
                            <span class="primo-mini-eye"></span>
                            <span class="primo-mini-eye"></span>
                        </span>
                        <span class="primo-mini-body"></span>
                    </span>
                </div>
                <div class="primo-msg-content">
                    <div class="primo-msg-bubble" id="primoWelcomeBubble">
                        Hi! 👋 I&rsquo;m <strong>Primo</strong>, your EasyPC assistant!<br><br>
                        How can I help you today? 💚
                    </div>
                </div>
            </div>
        </div>

        <button type="button" class="primo-jump" id="primoJump" hidden>
            <i class="fas fa-arrow-down" aria-hidden="true"></i> New message
        </button>

        <form class="primo-panel-input" id="primoChatForm" action="#" method="post" autocomplete="off">
            <label class="sr-only" for="primoChatInput">Message Primo</label>
            <div class="primo-compose">
                <textarea id="primoChatInput" name="primo_message" rows="1"
                          placeholder="Ask Primo about a product…" maxlength="400"
                          aria-describedby="primoComposeHint"></textarea>
                <span class="primo-count" id="primoCount" aria-live="polite" hidden></span>
            </div>
            <button type="submit" class="primo-send-btn" id="primoSendBtn" aria-label="Send message" disabled>
                <i class="fas fa-paper-plane" aria-hidden="true"></i>
            </button>
            <p class="primo-compose-hint" id="primoComposeHint">Enter to send · Shift + Enter for a new line</p>
        </form>
    </section>
</div>
<script>window.EP_CSRF = window.EP_CSRF || <?php echo json_encode($primoCsrf); ?>;</script>
<script src="primo.js?v=ux-4" defer></script>
