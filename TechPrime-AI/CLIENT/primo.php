<?php
/**
 * Primo — AI Product Assistant (CLIENT dashboard).
 * CSS robot UI + SVM-backed chat via backend/api/primo_chat.php
 */
?>
<div id="primoRoot" class="primo-root is-collapsed" aria-live="polite">
    <div class="primo-stage">
        <button type="button" class="primo-mascot" id="primoMascot" aria-label="Primo, AI Product Assistant. Click to expand or open chat." aria-expanded="false">
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
        </button>
        <button type="button" class="primo-tuck" id="primoTuck" aria-label="Hide Primo" title="Hide Primo" hidden>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </button>
    </div>

    <div class="primo-backdrop" id="primoBackdrop" hidden></div>

    <div class="primo-panel" id="primoPanel" role="dialog" aria-modal="true" aria-labelledby="primoPanelTitle" hidden>
        <header class="primo-panel-header">
            <div class="primo-panel-identity">
                <span class="primo-mini" aria-hidden="true">
                    <span class="primo-mini-head">
                        <span class="primo-mini-eye"></span>
                        <span class="primo-mini-eye"></span>
                    </span>
                    <span class="primo-mini-body"></span>
                </span>
                <div>
                    <h2 id="primoPanelTitle">Primo</h2>
                    <p>AI Product Assistant</p>
                </div>
            </div>
            <div class="primo-panel-actions">
                <button type="button" class="primo-panel-history" id="primoHistoryBtn" aria-label="Recent chats" title="Recent chats">
                    <i class="fas fa-clock" aria-hidden="true"></i>
                </button>
                <button type="button" class="primo-panel-reset" id="primoResetBtn" aria-label="Reset chat" title="Reset Chat">
                    <i class="fas fa-redo-alt" aria-hidden="true"></i>
                    <span>Reset</span>
                </button>
                <button type="button" class="primo-panel-close" id="primoClose" aria-label="Close Primo chat">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <div class="primo-history" id="primoHistory" hidden>
            <div class="primo-history-head">
                <strong>Recent Chats</strong>
                <button type="button" class="primo-history-back" id="primoHistoryBack" aria-label="Back to chat">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
                </button>
            </div>
            <div class="primo-history-body" id="primoHistoryBody">
                <p class="primo-history-empty">Loading…</p>
            </div>
            <p class="primo-history-note">Kept for 7 days. Reset Chat clears the current chat only.</p>
        </div>

        <div class="primo-panel-body" id="primoPanelBody">
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
                <div class="primo-msg-bubble" id="primoWelcomeBubble">
                    Hi! 👋 I&rsquo;m <strong>Primo</strong>, your EasyPC assistant!<br><br>
                    How can I help you today? 💚
                </div>
            </div>
        </div>

        <form class="primo-panel-input" id="primoChatForm" action="#" method="post" autocomplete="off">
            <label class="sr-only" for="primoChatInput">Ask Primo about a product</label>
            <input type="text" id="primoChatInput" name="primo_message"
                   placeholder="Ask Primo about a product..." maxlength="200">
            <button type="submit" class="primo-send-btn" aria-label="Send message">
                <i class="fas fa-paper-plane" aria-hidden="true"></i>
            </button>
        </form>
        <div class="primo-soon" id="primoSoon" hidden></div>
    </div>
</div>
