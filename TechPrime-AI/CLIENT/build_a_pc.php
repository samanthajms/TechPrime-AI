<?php
/**
 * Build a PC — dedicated Client/Buyer page (former Tech & Match builder).
 * Layout follows the EasyPC website builder; behaviour lives in tech_match.js,
 * styles in tech_match.css, products come from tech_match_api.php.
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/pc_purpose.php';
require_once __DIR__ . '/../includes/pc_rating.php';

$db = getDbConnection();
checkSessionTimeout();

// Open to guests too: they can build and compare; saving or buying a build needs a customer login.
$isLoggedIn = isset($_SESSION['user_id']);
$canSaveBuilds = $isLoggedIn && ($_SESSION['role'] ?? '') === 'client';
$buildLoginUrl = '../login.php?next=' . urlencode('CLIENT/build_a_pc.php');
$activePage = 'build_a_pc';
$isHomePage = false;
$pageTitle = 'Build a PC';
$csrf = generateCsrfToken();

$extraHead = '<link rel="stylesheet" href="tech_match.css?v=pcx-30">';

include __DIR__ . '/ep_header.php';
?>

<main class="ep-main sf-main" id="main">
    <h1 class="sf-sr">Build your PC</h1>
    <div class="sf-wrap sf-body">
    <div class="bp-page">
    <div class="bp-top">
        <div class="bp-budget">
            <span class="bp-budget-label">Budget</span>
            <div class="bp-budget-bar" aria-hidden="true"><span id="tmBudgetBar"></span></div>
            <span class="bp-budget-value">
                <?php /* "₱25,275 / ₱33,000", or "₱25,275 · No budget set" until the shopper sets one (tech_match.js renderBudget). */ ?>
                <strong id="tmBudgetUsed">₱0</strong><span id="tmBudgetAmount"> · No budget set</span>
            </span>
            <?php /* Which suggested budget is in use (e.g. "Recommended · Gaming"); it follows the purpose (tech_match.js). */ ?>
            <span class="bp-budget-tier" id="tmBudgetTier" hidden></span>
            <button type="button" class="bp-budget-edit is-unset" id="tmBudgetEdit" aria-haspopup="dialog">
                <i class="fas fa-wallet" aria-hidden="true"></i><span id="tmBudgetEditLabel">Set budget</span>
            </button>
        </div>
        <?php /* Budget hints after a purpose change: "updated, Undo" / "usually costs ..., Use ₱X" (tech_match.js renderBudgetNote). */ ?>
        <div class="bp-budget-note" id="tmBudgetNote" role="status" aria-live="polite" hidden></div>
        <?php /* Build purpose: steers which parts get the Recommended badge (tech_match.js keeps the choice). */ ?>
        <div class="bp-purpose">
            <div class="bp-purpose-head">
                <span class="bp-purpose-label" id="bpPurposeLabel">What will you use this PC for?</span>
            </div>
            <div class="bp-purpose-chips" id="bpPurposeChips" role="radiogroup" aria-labelledby="bpPurposeLabel" aria-describedby="bpPurposeHint">
                <?php $first = true; foreach (pc_purposes() as $key => $purpose): ?>
                <button type="button" class="bp-purpose-chip" role="radio" data-purpose="<?php echo h($key); ?>"
                        data-label="<?php echo h($purpose['label']); ?>" data-hint="<?php echo h($purpose['hint']); ?>"
                        aria-checked="<?php echo $first ? 'true' : 'false'; ?>" tabindex="<?php echo $first ? '0' : '-1'; ?>">
                    <span class="bp-purpose-icon" aria-hidden="true"><i class="fas <?php echo h($purpose['icon']); ?>"></i></span>
                    <span class="bp-purpose-text"><strong><?php echo h($purpose['label']); ?></strong></span>
                </button>
                <?php $first = false; endforeach; ?>
            </div>
            <p class="bp-purpose-hint"><i class="fas fa-check-circle" aria-hidden="true"></i><span><strong id="bpPurposeName"><?php echo h(pc_purposes()['balanced']['label']); ?>:</strong> <span id="bpPurposeHint"><?php echo h(pc_purposes()['balanced']['hint']); ?></span></span></p>
            <?php /* "Build it for me": fills a whole build with the recommender's picks (tech_match.js -> action=auto_build). */ ?>
            <button type="button" class="bp-autobuild" id="tmAutoBuild" aria-haspopup="dialog">
                <i class="fas fa-magic" aria-hidden="true"></i>Build it for me
            </button>
        </div>
        <nav class="tm-steps" id="tmSteps" aria-label="Build stages"></nav>
    </div>

    <?php /* First-visit guide (survey feedback). Shown until dismissed; "How it works" reopens it. */ ?>
    <section class="ep-howto" id="bpGuide" aria-labelledby="bpGuideTitle" hidden>
        <div class="ep-howto-head">
            <h2 id="bpGuideTitle"><i class="far fa-lightbulb" aria-hidden="true"></i> New here? Build your PC in 3 steps</h2>
            <button type="button" class="ep-howto-close" id="bpGuideClose">Got it</button>
        </div>
        <ol class="ep-howto-steps">
            <li>
                <strong>Pick your parts</strong>
                <span>Choose a component from the list on the left, or click a part inside the case. The picker shows parts that fit your build first. You can set a budget at the top.</span>
            </li>
            <li>
                <strong>Check the build</strong>
                <span>The compatibility status, power draw and Build Score on the right update as you add parts.</span>
            </li>
            <li>
                <strong>Buy or save it</strong>
                <span>Use <b>Add to Cart</b> or <b>Buy Now</b> to order the whole build, or <b>Save Build</b> (needs a login) to finish it later.</span>
            </li>
        </ol>
    </section>
    <script>
    (function () {
        var KEY = 'ep_bp_guide_done';
        var guide = document.getElementById('bpGuide');
        var openBtn = document.getElementById('bpGuideOpen');
        var closeBtn = document.getElementById('bpGuideClose');
        var done = false;
        try { done = localStorage.getItem(KEY) === '1'; } catch (e) {}
        guide.hidden = done;
        if (openBtn) openBtn.setAttribute('aria-expanded', done ? 'false' : 'true');
        closeBtn.addEventListener('click', function () {
            guide.hidden = true;
            if (openBtn) { openBtn.setAttribute('aria-expanded', 'false'); openBtn.focus(); }
            try { localStorage.setItem(KEY, '1'); } catch (e) {}
        });
        if (openBtn) openBtn.addEventListener('click', function () {
            guide.hidden = !guide.hidden;
            openBtn.setAttribute('aria-expanded', guide.hidden ? 'false' : 'true');
            if (!guide.hidden) guide.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    })();
    </script>

    <?php /* Ready-made starter builds: "Build it for me" at the suggested budgets, built live from stock (tech_match.js loadStarters). */ ?>
    <section class="bp-starters" id="bpStarters" aria-labelledby="bpStartersTitle" hidden>
        <div class="bp-starters-head">
            <div>
                <h2 id="bpStartersTitle"><i class="fas fa-rocket" aria-hidden="true"></i> Start from a ready-made build</h2>
                <p>Complete PCs picked from today's stock. Load one, then change any part.</p>
            </div>
            <button type="button" class="bp-starters-toggle" id="bpStartersToggle" aria-expanded="true" aria-controls="bpStartersList">Hide</button>
        </div>
        <div class="bp-starters-list" id="bpStartersList" role="list"></div>
    </section>

    <div class="tm-edit-banner" id="tmEditBanner" hidden>
        <i class="fas fa-pen" aria-hidden="true"></i>
        <span>Editing saved build <strong id="tmEditName"></strong>. Saving will update it.</span>
        <button type="button" id="tmEditCancel">Start a new build instead</button>
    </div>

    <div class="tm-workspace" id="ep-tech-match">
        <aside class="tm-left" aria-label="Component selection">
            <div id="tmSlotList"></div>
        </aside>

        <section class="tm-center" aria-label="PC build visualization">
            <div class="tm-viz pcx" id="tmPcStage" data-view="3d">
                <div class="pcx-top">
                    <div class="pcx-legend">
                        <span><i class="pcx-dot is-on"></i>Installed</span>
                        <span><i class="pcx-dot"></i>Missing</span>
                    </div>
                    <div class="pcx-toolbar" role="toolbar" aria-label="Preview controls">
                        <div class="pcx-seg">
                            <button type="button" data-pcx-view="3d" class="is-on">3D</button>
                            <button type="button" data-pcx-view="2d">2D</button>
                        </div>
                        <button type="button" class="pcx-tool" id="pcxExplode" aria-pressed="false" title="Exploded view"><i class="fas fa-layer-group" aria-hidden="true"></i></button>
                        <button type="button" class="pcx-tool" id="pcxReset" title="Reset view"><i class="fas fa-undo-alt" aria-hidden="true"></i></button>
                    </div>
                </div>

                <div class="pcx-scene" id="pcxScene">
                    <div class="pcx-floor" aria-hidden="true"></div>
                    <div class="pcx-rig" id="pcxRig">
                        <div class="pcx-case" id="tmPcViz">
                            <div class="pcx-face pcx-back" aria-hidden="true"></div>
                            <div class="pcx-face pcx-top-face" aria-hidden="true"></div>
                            <div class="pcx-face pcx-bottom" aria-hidden="true"></div>
                            <div class="pcx-face pcx-front" aria-hidden="true"><span class="pcx-power"></span></div>
                            <div class="pcx-face pcx-rear" aria-hidden="true"></div>

                            <!-- Side-panel view of an ATX mid-tower: rear (I/O) on the left, front intake on the right. -->
                            <button type="button" class="pcx-part pcx-casebadge" data-slot="case" style="--x:0%;--y:0%;--w:100%;--h:100%;--z:1px;--lift:0px"><span class="pcx-case-label"><i class="fas fa-cube" aria-hidden="true"></i><span class="pcx-name">Case</span></span><span class="pcx-tag">PC Case</span></button>
                            <div class="pcx-shroud" aria-hidden="true"><span class="pcx-cage"></span></div>
                            <div class="pcx-rearfan" aria-hidden="true"></div>
                            <button type="button" class="pcx-part pcx-mobo" data-slot="motherboard" style="--x:5%;--y:12%;--w:57%;--h:56%;--z:3px;--lift:0px"><span class="pcx-mb pcx-mb-io"></span><span class="pcx-mb pcx-mb-vrm-top"></span><span class="pcx-mb pcx-mb-vrm-side"></span><span class="pcx-mb pcx-mb-socket"></span><span class="pcx-mb pcx-mb-atx"></span><span class="pcx-mb pcx-mb-pcie" style="top:52%"></span><span class="pcx-mb pcx-mb-pcie" style="top:76%"></span><span class="pcx-mb pcx-mb-pcie pcx-mb-pcie-s" style="top:88%"></span><span class="pcx-mb pcx-mb-chipset"></span><span class="pcx-name pcx-name-corner">Motherboard</span><span class="pcx-tag">Motherboard</span></button>
                            <button type="button" class="pcx-part pcx-cpu" data-slot="processor" style="--x:22%;--y:20%;--w:13%;--h:11.6%;--z:8px"><span class="pcx-chip-die"></span><span class="pcx-name">CPU</span><span class="pcx-tag">Processor</span></button>
                            <button type="button" class="pcx-part pcx-ram" data-slot="memory" style="--x:43%;--y:15.5%;--w:9.5%;--h:27%;--z:14px"><span class="pcx-stick"></span><span class="pcx-stick"></span><span class="pcx-stick"></span><span class="pcx-stick"></span><span class="pcx-name pcx-name-ram">RAM</span><span class="pcx-tag">Memory</span></button>
                            <button type="button" class="pcx-part pcx-m2" data-slot="ssd" style="--x:18%;--y:35.3%;--w:19%;--h:4.4%;--z:6px"><span class="pcx-name">M.2</span><span class="pcx-tag">SSD (M.2)</span></button>
                            <button type="button" class="pcx-part pcx-gpu" data-slot="gpu" style="--x:2%;--y:41%;--w:72%;--h:11.5%;--z:26px"><span class="pcx-gpu-bracket"></span><span class="pcx-gpu-power"></span><span class="pcx-gpu-bar"><span class="pcx-name">Graphics</span></span><span class="pcx-tag">Graphics Card</span></button>
                            <button type="button" class="pcx-part pcx-cooler" data-slot="cooler" style="--x:18%;--y:1.8%;--w:55%;--h:9%;--z:18px"><span class="pcx-rad-fins"></span><span class="pcx-rad-fans"><span></span><span></span></span><span class="pcx-name pcx-name-rad">Cooler</span><span class="pcx-tag">CPU Cooler</span></button>
                            <svg class="pcx-tubes" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><path d="M29.5 24 C 31 16, 60 18, 66 10.5"/><path d="M27.5 24 C 28 13, 52 14, 58 10.5"/></svg>
                            <div class="pcx-pump" aria-hidden="true"></div>
                            <button type="button" class="pcx-part pcx-fans" data-slot="case_fan" style="--x:82%;--y:8%;--w:15%;--h:60%;--z:20px"><span class="pcx-fan"></span><span class="pcx-fan"></span><span class="pcx-fan"></span><span class="pcx-name pcx-name-fans">Fans</span><span class="pcx-tag">Case Fans</span></button>
                            <button type="button" class="pcx-part pcx-psu" data-slot="psu" style="--x:3%;--y:77%;--w:38%;--h:18%;--z:20px"><span class="pcx-psu-vent"></span><span class="pcx-psu-label"><span class="pcx-name">PSU</span></span><span class="pcx-psu-plugs"></span><span class="pcx-tag">Power Supply</span></button>
                            <button type="button" class="pcx-part pcx-drive pcx-sata" data-slot="ssd_sata" style="--x:45%;--y:74%;--w:21%;--h:13%;--z:24px"><span class="pcx-name">SATA SSD</span><span class="pcx-tag">SSD (SATA)</span></button>
                            <button type="button" class="pcx-part pcx-drive pcx-hdd" data-slot="hdd" style="--x:69.5%;--y:80%;--w:27%;--h:7.5%;--z:24px"><span class="pcx-name">HDD</span><span class="pcx-tag">Hard Disk</span></button>
                            <div class="pcx-glass" aria-hidden="true"></div>
                        </div>
                    </div>
                </div>

                <div class="pcx-dock" id="pcxInfo" aria-live="polite"></div>
            </div>
            <div class="tm-power-card">
                <div class="tm-power-head">
                    <span><i class="fas fa-bolt" aria-hidden="true"></i> Estimated Power Draw</span>
                    <strong id="tmPowerDraw">~0W</strong>
                </div>
                <div class="tm-power-track" id="tmPowerTrack" role="meter" aria-label="Estimated power draw" aria-valuemin="0" aria-valuemax="650" aria-valuenow="0">
                    <div class="tm-bar tm-bar-power"><span id="tmPowerBar"></span></div>
                    <span class="tm-power-mark" id="tmPowerMark" aria-hidden="true" hidden></span>
                </div>
                <div class="tm-power-scale" id="tmPowerScale" aria-hidden="true" hidden>
                    <span>0W</span>
                    <span class="tm-power-scale-mark" id="tmPowerScaleMark"></span>
                    <span id="tmPowerScaleMax"></span>
                </div>
                <small class="tm-power-hint" id="tmPowerHint"></small>
            </div>
            <div class="tm-disclaimer tm-notes">
                <details class="tm-note-more">
                    <summary><i class="fas fa-magic" aria-hidden="true"></i><span>How does <strong>Build it for me</strong> pick parts?</span></summary>
                    <p>Build it for me suggests parts from EasyPC's current stock using the recommendation model. It fills the required parts first; optional parts (case fans, and a graphics card for School or Office builds) are added only when they suit your purpose and budget, so some slots may stay empty. A CPU cooler is added only when the processor does not come with one. Any money left over then goes to the upgrades that raise the Build Score most, such as more memory or a bigger drive. A required part can also be left out if no compatible one is in stock. Prices and stock may change, so review each part before checkout.</p>
                </details>
            </div>
        </section>

        <aside class="tm-right" aria-label="Build information">
            <div class="tm-card tm-score-card">
                <h3>Build Score</h3>
                <div class="tm-score">
                    <span class="tm-score-num" id="tmBuildScore">0</span><span class="tm-score-den">/100</span>
                </div>
                <p class="tm-score-verdict" id="tmScoreVerdict" aria-live="polite"></p>
                <ul class="tm-score-notes" id="tmScoreNotes" hidden></ul>
                <?php /* Breakdown of the score (pc_rating.php: points per part, deductions, limits), filled by tech_match.js. */ ?>
                <details class="tm-score-why" id="tmScoreWhy" hidden>
                    <summary>How is this scored?</summary>
                    <div id="tmScoreWhyBody"></div>
                </details>
                <div class="tm-score-actions">
                    <span id="tmScoreBadgeScore" title="Score"><i class="fas fa-star" aria-hidden="true"></i>Score</span>
                    <span id="tmScoreBadgeCompat" title="Compatibility"><i class="fas fa-microchip" aria-hidden="true"></i>Fit</span>
                    <span id="tmScoreBadgePower" title="Power"><i class="fas fa-plug" aria-hidden="true"></i>Power</span>
                </div>
            </div>
            <div class="tm-card">
                <h3>Performance Balance</h3>
                <div class="tm-radar-wrap">
                    <svg id="tmRadarSvg" viewBox="0 0 180 180" role="img" aria-label="Performance radar chart"></svg>
                </div>
                <ul class="tm-radar-legend">
                    <li class="is-parts"><span aria-hidden="true"></span>Your parts</li>
                    <li class="is-need"><span aria-hidden="true"></span>Needed for <strong id="tmRadarNeed">everyday use</strong></li>
                </ul>
            </div>
            <div class="tm-card tm-summary-card">
                <h3>Build Summary</h3>
                <div class="tm-summary-total">
                    <span>Total <small>(VAT-inclusive)</small></span>
                    <strong id="tmSummaryTotal">₱0</strong>
                </div>
                <p class="tm-summary-asof" id="tmSummaryOwned" hidden></p>
                <p class="tm-summary-asof" id="tmSummaryAsOf" hidden></p>
                <div class="tm-compat" id="tmCompatStatus"></div>
                <div class="tm-platform" id="tmPlatform" hidden></div>
                <?php /* Per-part prices are already on the parts list, so the breakdown here is folded. */ ?>
                <details class="tm-summary-more">
                    <summary><span>Price breakdown</span><span class="tm-summary-count"><strong id="tmSummaryCount">0 / 11</strong> parts</span></summary>
                    <div class="tm-summary-list" id="tmSummaryList">
                        <p class="tm-summary-empty">No components selected yet.</p>
                    </div>
                </details>
                <?php /* Purchase actions: Buy Now is the one primary action; the rest are secondary. */ ?>
                <div class="tm-checkout">
                    <button type="button" class="tm-btn-primary tm-btn-buy" id="tmBuildBuyNow">
                        <i class="fas fa-bolt" aria-hidden="true"></i> Buy Now
                    </button>
                    <button type="button" class="tm-btn-outline" id="tmBuildToCart">
                        <i class="fas fa-cart-plus" aria-hidden="true"></i> Add to Cart
                    </button>
                    <button type="button" class="tm-btn-outline tm-btn-missing" id="tmAddMissing">
                        <i class="fas fa-puzzle-piece" aria-hidden="true"></i> Add Missing Components
                    </button>
                </div>
                <button type="button" class="tm-ask" id="tmAskStore" aria-haspopup="dialog"><i class="fas fa-headset" aria-hidden="true"></i> Ask EasyPC about this build</button>
                <div class="tm-tools" role="group" aria-label="Build tools">
                    <button type="button" class="tm-tool" id="tmSaveBuild"><i class="far fa-save" aria-hidden="true"></i><span id="tmSaveLabel">Save Build</span></button>
                    <button type="button" class="tm-tool" id="tmShareBuild"><i class="fas fa-share-alt" aria-hidden="true"></i><span>Share</span></button>
                    <button type="button" class="tm-tool" id="tmReportBuild"><i class="fas fa-print" aria-hidden="true"></i><span>Print</span></button>
                    <button type="button" class="tm-tool" id="tmLoadBuild"><i class="far fa-folder-open" aria-hidden="true"></i><span>Load</span></button>
                </div>
                <div class="tm-tools-foot">
                    <a class="tm-link" href="<?php echo $canSaveBuilds ? 'saved_builds.php' : h($buildLoginUrl); ?>"><i class="fas fa-folder" aria-hidden="true"></i> My Saved Builds</a>
                    <button type="button" class="tm-link tm-link-danger" id="tmClearBuild"><i class="far fa-trash-alt" aria-hidden="true"></i> Clear build</button>
                </div>
                <p class="tm-summary-disclaimer">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <span>Prices and stock can change until you check out. Compatibility checks and the Build Score are guides; EasyPC staff can confirm before you buy.</span>
                </p>
            </div>
            <?php /* Plain-language estimates for common uses (pc_rating.php pc_rating_can_run, via the build check);
                     the uses that matter for the purpose come first (tech_match.js renderCanRun). */ ?>
            <div class="tm-card tm-canrun-card">
                <h3>What can it run?</h3>
                <p class="tm-canrun-empty" id="tmCanRunEmpty">Pick a processor to see what this PC can handle.</p>
                <ul class="tm-canrun" id="tmCanRun" hidden></ul>
                <button type="button" class="tm-canrun-more" id="tmCanRunMore" aria-controls="tmCanRun" aria-expanded="false" hidden></button>
                <p class="tm-canrun-note"><i class="fas fa-info-circle" aria-hidden="true"></i><span>Rough estimates at typical settings, not benchmarks.</span></p>
            </div>
            <details class="tm-card tm-compare-card">
                <summary><h3>Compare Builds</h3><span>Put two saved builds side by side</span></summary>
                <div class="tm-compare-slots" id="tmCompareSlots"></div>
                <button type="button" class="tm-btn-outline tm-btn-sm tm-compare-go" id="tmCompareGo" hidden>
                    <i class="fas fa-columns" aria-hidden="true"></i> Compare
                </button>
            </details>
        </aside>
    </div>
    </div>
    </div>
</main>

<div class="tm-drawer-backdrop" id="tmDrawerBackdrop" hidden></div>
<aside class="tm-drawer" id="tmPickerView" role="dialog" aria-modal="true" aria-labelledby="tmPickerTitle" aria-hidden="true">
    <div class="tm-drawer-head">
        <h2 id="tmPickerTitle">Select Component</h2>
        <button type="button" class="tm-drawer-close" id="tmPickerBack" aria-label="Close"><i class="fas fa-times" aria-hidden="true"></i></button>
    </div>
    <div class="tm-drawer-tools">
        <input type="search" class="tm-drawer-search" id="tmPickerSearch" placeholder="Search products..." autocomplete="off" aria-label="Search products">
        <div class="tm-drawer-row">
            <select id="tmPickerSort" class="tm-picker-sort" aria-label="Sort products">
                <option value="recommended">Recommended</option>
                <option value="price-asc">Price: Low to High</option>
                <option value="price-desc">Price: High to Low</option>
                <option value="name">Name: A to Z</option>
                <option value="stock">Most in Stock</option>
            </select>
            <label class="tm-picker-toggle"><input type="checkbox" id="tmPickerInStock"> In stock only</label>
            <button type="button" class="tm-filters-btn" id="tmFiltersBtn" aria-expanded="false">Filters</button>
        </div>
        <div class="tm-filters" id="tmFilters" hidden>
            <label class="tm-picker-toggle"><input type="checkbox" id="tmPickerCompatOnly" checked> Compatible with my build only</label>
            <div class="tm-brand-chips" id="tmBrandChips"></div>
        </div>
        <div class="tm-picker-current" id="tmPickerCurrent" hidden></div>
        <p class="tm-picker-count" id="tmPickerCount" aria-live="polite"></p>
    </div>
    <div class="tm-drawer-list" id="tmProductGrid"></div>
</aside>

<div class="tm-mobile-bar" id="tmMobileBar">
    <div>
        <strong id="tmMobileTotal">₱0</strong>
        <span id="tmMobileCount">0 / 11 components</span>
    </div>
    <div class="tm-mobile-actions">
        <button type="button" class="tm-btn-outline" id="tmMobileSave" aria-label="Save build"><i class="far fa-save" aria-hidden="true"></i> Save</button>
        <button type="button" class="tm-btn-primary" id="tmMobileCart"><i class="fas fa-cart-plus" aria-hidden="true"></i> Add to Cart</button>
    </div>
</div>

<script>
window.EP_CSRF = <?php echo json_encode($csrf); ?>;
window.EP_TM_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
/* What each purpose needs, for the radar's dashed outline before any part is picked (pc_rating.php). */
window.EP_TM_TARGETS = <?php echo json_encode(pc_rating_all_targets()); ?>;
window.EP_TM_CAN_SAVE = <?php echo $canSaveBuilds ? 'true' : 'false'; ?>;
window.EP_TM_LOGIN_URL = <?php echo json_encode($buildLoginUrl); ?>;
</script>
<script src="tech_match.js?v=pcx-40" defer></script>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
