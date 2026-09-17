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
            <i class="fas fa-chevron-left" aria-hidden="true"></i>
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
            <button type="button" class="primo-panel-close" id="primoClose" aria-label="Close Primo chat">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </header>

        <div class="primo-panel-body" id="primoPanelBody">
            <div class="primo-msg primo-msg-bot">
                <div class="primo-msg-avatar" aria-hidden="true">
                    <span class="primo-mini primo-mini-sm">
                        <span class="primo-mini-head">
                            <span class="primo-mini-eye"></span>
                            <span class="primo-mini-eye"></span>
                        </span>
                        <span class="primo-mini-body"></span>
                    </span>
                </div>
                <div class="primo-msg-bubble">
                    Hi! I&rsquo;m <strong>Primo</strong>.<br>
                    Your AI Product Assistant.<br><br>
                    I can help you with TechPrime products and hardware.
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
