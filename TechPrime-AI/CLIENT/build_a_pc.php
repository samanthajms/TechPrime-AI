<?php
/**
 * Build a PC — dedicated Client/Buyer page (former Tech & Match builder).
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$isLoggedIn = true;
$activePage = 'build_a_pc';
$isHomePage = false;
$pageTitle = 'Build a PC';
$csrf = generateCsrfToken();

$extraHead = '<link rel="stylesheet" href="tech_match.css"><style>
.bap-page { max-width: 1280px; margin: 0 auto; padding: 22px 18px 96px; }
.bap-hero {
  background: linear-gradient(135deg, #eef8e6 0%, #fff 70%);
  border: 1px solid var(--ep-border, #e4e8ea);
  border-radius: 16px;
  padding: 20px 22px;
  margin-bottom: 18px;
  box-shadow: 0 8px 22px rgba(75,139,42,0.07);
}
.bap-hero h1 { margin: 0 0 6px; font-size: 22px; font-weight: 800; color: var(--ep-green-dark, #4b8b2a); }
.bap-hero p { margin: 0; color: #6b7280; font-size: 14px; font-weight: 500; }
.bap-shell {
  background: #fff;
  border: 1px solid var(--ep-border, #e4e8ea);
  border-radius: 16px;
  box-shadow: 0 8px 24px rgba(15,23,42,0.06);
  padding: 12px 12px 18px;
  overflow: hidden;
}
.bap-shell .tm-builder,
.bap-shell .tm-picker { min-height: 520px; }
.bap-saved-btn {
  position: fixed;
  right: 24px;
  bottom: 24px;
  z-index: 40;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--ep-green, #62b236);
  color: #fff;
  border: 0;
  border-radius: 999px;
  padding: 12px 18px;
  font-family: inherit;
  font-size: 13px;
  font-weight: 800;
  text-decoration: none;
  box-shadow: 0 10px 24px rgba(75,139,42,0.28);
}
.bap-saved-btn:hover { background: var(--ep-green-dark, #4b8b2a); color: #fff; }
.tm-product-card.is-incompatible { opacity: 0.72; }
.tm-compat-warn {
  margin: 0 0 8px;
  font-size: 11px;
  line-height: 1.35;
  color: #b91c1c;
  font-weight: 600;
}
@media (max-width: 700px) {
  .bap-saved-btn { right: 14px; bottom: 14px; padding: 10px 14px; font-size: 12px; }
}
</style>';

include __DIR__ . '/ep_header.php';
?>

<main class="bap-page">
    <section class="bap-hero">
        <h1><i class="fas fa-desktop" aria-hidden="true"></i> Build a PC</h1>
        <p>Customize your setup with real EasyPC products. Incompatible parts are blocked automatically when product details allow it.</p>
    </section>

    <div class="bap-shell" id="ep-tech-match">
        <div class="tm-builder" id="tmBuilderView">
            <nav class="tm-steps" id="tmSteps" aria-label="Build stages"></nav>
            <div class="tm-workspace">
                <aside class="tm-left" aria-label="Component selection">
                    <div id="tmSlotList"></div>
                </aside>
                <section class="tm-center" aria-label="PC build visualization">
                    <div class="tm-viz">
                        <div class="tm-pc" id="tmPcViz" aria-hidden="true">
                            <div class="tm-pc-mobo"></div>
                            <div class="tm-pc-cpu"></div>
                            <div class="tm-pc-ram"></div>
                            <div class="tm-pc-gpu"></div>
                            <div class="tm-pc-ssd"></div>
                            <div class="tm-pc-psu"></div>
                        </div>
                    </div>
                    <div class="tm-progress-wrap">
                        <div class="tm-progress-meta">
                            <span id="tmComponentCount">0 / 11 components</span>
                        </div>
                        <div class="tm-bar"><span id="tmComponentBar"></span></div>
                    </div>
                    <div class="tm-power-card">
                        <i class="fas fa-bolt" aria-hidden="true"></i>
                        <div>
                            <strong>Estimated Power Draw</strong>
                            <span id="tmPowerDraw">~0W</span>
                        </div>
                        <div class="tm-bar" style="flex:1;margin-left:8px;"><span id="tmPowerBar"></span></div>
                    </div>
                </section>
                <aside class="tm-right" aria-label="Build information">
                    <div class="tm-card">
                        <h3>Build Score</h3>
                        <div class="tm-score">
                            <span class="tm-score-num" id="tmBuildScore">0</span><span class="tm-score-den">/100</span>
                        </div>
                        <div class="tm-score-actions" aria-hidden="true">
                            <span><i class="fas fa-info"></i></span>
                            <span><i class="fas fa-share-alt"></i></span>
                            <span><i class="fas fa-ellipsis-h"></i></span>
                        </div>
                    </div>
                    <div class="tm-card">
                        <h3>Performance Balance</h3>
                        <div class="tm-radar-wrap">
                            <svg id="tmRadarSvg" viewBox="0 0 180 180" role="img" aria-label="Performance radar chart"></svg>
                        </div>
                    </div>
                    <div class="tm-card">
                        <h3>Build Summary</h3>
                        <div class="tm-summary-row">
                            <span>Components</span>
                            <span id="tmSummaryCount">0 / 11</span>
                        </div>
                        <div class="tm-bar"><span id="tmSummaryBar"></span></div>
                        <div class="tm-summary-total">
                            <span>Total</span>
                            <strong id="tmSummaryTotal">₱0</strong>
                        </div>
                    </div>
                    <button type="button" class="tm-btn-primary" id="tmAddMissing">
                        <i class="fas fa-cogs" aria-hidden="true"></i> Add Missing Components
                    </button>
                    <button type="button" class="tm-btn-secondary" id="tmSaveBuild">Save Build</button>
                </aside>
            </div>
        </div>

        <div class="tm-picker" id="tmPickerView" hidden>
            <div class="tm-picker-toolbar">
                <div>
                    <h3 class="tm-picker-title" id="tmPickerTitle">Select Component</h3>
                    <p class="tm-picker-sub" id="tmPickerSub">Choose a product for your build.</p>
                </div>
                <div class="tm-picker-search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="tmPickerSearch" placeholder="Search products…" autocomplete="off">
                </div>
                <button type="button" class="tm-back-btn" id="tmPickerBack">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Build
                </button>
            </div>
            <div class="tm-product-grid" id="tmProductGrid"></div>
        </div>
    </div>
</main>

<a class="bap-saved-btn" href="saved_builds.php">
    <i class="fas fa-folder-open" aria-hidden="true"></i> View Saved Build
</a>

<script>window.EP_CSRF = <?php echo json_encode($csrf); ?>;</script>
<script src="tech_match.js" defer></script>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
