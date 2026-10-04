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

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$isLoggedIn = true;
$activePage = 'build_a_pc';
$isHomePage = false;
$pageTitle = 'Build a PC';
$csrf = generateCsrfToken();

$extraHead = '<link rel="stylesheet" href="tech_match.css?v=pcx-6">';

include __DIR__ . '/ep_header.php';
?>

<main class="bp-page">
    <div class="bp-top">
        <div class="bp-titlebar">
            <h1><span class="bp-title-icon"><i class="fas fa-microchip" aria-hidden="true"></i></span> Build Your PC</h1>
        </div>
        <div class="bp-budget">
            <span class="bp-budget-label">Budget</span>
            <div class="bp-budget-bar" aria-hidden="true"><span id="tmBudgetBar"></span></div>
            <span class="bp-budget-value">
                <strong id="tmBudgetUsed">₱0</strong> /
                <button type="button" id="tmBudgetEdit" title="Change budget">₱200,000</button>
            </span>
        </div>
        <nav class="tm-steps" id="tmSteps" aria-label="Build stages"></nav>
    </div>

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
                            <div class="pcx-face pcx-front" aria-hidden="true"></div>
                            <div class="pcx-face pcx-rear" aria-hidden="true"></div>
                            <div class="pcx-shroud" aria-hidden="true"></div>

                            <button type="button" class="pcx-part pcx-casebadge" data-slot="case" style="--x:3%;--y:2%;--w:17%;--h:6.5%;--z:4px;--lift:18px"><i class="fas fa-cube" aria-hidden="true"></i><span class="pcx-name">Case</span><span class="pcx-tag">PC Case</span></button>
                            <button type="button" class="pcx-part pcx-cooler" data-slot="cooler" style="--x:24%;--y:2%;--w:72%;--h:6.5%;--z:10px"><span class="pcx-fan"></span><span class="pcx-fan"></span><span class="pcx-fan"></span><span class="pcx-tag">CPU Cooler</span></button>
                            <button type="button" class="pcx-part pcx-fans" data-slot="case_fan" style="--x:3%;--y:11%;--w:17%;--h:52%;--z:10px"><span class="pcx-fan"></span><span class="pcx-fan"></span><span class="pcx-fan"></span><span class="pcx-tag">Case Fans</span></button>
                            <button type="button" class="pcx-part pcx-mobo" data-slot="motherboard" style="--x:24%;--y:11%;--w:72%;--h:52%;--z:2px;--lift:0px"><span class="pcx-name pcx-name-corner">Motherboard</span><span class="pcx-tag">Motherboard</span></button>
                            <button type="button" class="pcx-part pcx-cpu" data-slot="processor" style="--x:45%;--y:16%;--w:19%;--h:13.5%;--z:8px"><span class="pcx-chip-die"></span><span class="pcx-name">CPU</span><span class="pcx-tag">Processor</span></button>
                            <button type="button" class="pcx-part pcx-ram" data-slot="memory" style="--x:72%;--y:15%;--w:17%;--h:22%;--z:8px"><span class="pcx-stick"></span><span class="pcx-stick"></span><span class="pcx-stick"></span><span class="pcx-stick"></span><span class="pcx-tag">Memory</span></button>
                            <button type="button" class="pcx-part pcx-m2" data-slot="ssd" style="--x:29%;--y:32.5%;--w:17%;--h:4.4%;--z:6px"><span class="pcx-name">M.2</span><span class="pcx-tag">SSD (M.2)</span></button>
                            <button type="button" class="pcx-part pcx-gpu" data-slot="gpu" style="--x:27%;--y:41%;--w:66%;--h:15%;--z:14px"><span class="pcx-fan"></span><span class="pcx-fan"></span><span class="pcx-fan"></span><span class="pcx-tag">Graphics Card</span></button>
                            <button type="button" class="pcx-part pcx-psu" data-slot="psu" style="--x:4%;--y:69%;--w:46%;--h:26%;--z:10px"><span class="pcx-fan"></span><span class="pcx-name pcx-name-corner">PSU</span><span class="pcx-tag">Power Supply</span></button>
                            <button type="button" class="pcx-part pcx-drive" data-slot="ssd_sata" style="--x:55%;--y:69%;--w:41%;--h:11.5%;--z:10px"><span class="pcx-name">SATA SSD</span><span class="pcx-tag">SSD (SATA)</span></button>
                            <button type="button" class="pcx-part pcx-drive" data-slot="hdd" style="--x:55%;--y:83.5%;--w:41%;--h:11.5%;--z:10px"><span class="pcx-name">HDD</span><span class="pcx-tag">Hard Disk</span></button>
                            <div class="pcx-glass" aria-hidden="true"></div>
                        </div>
                    </div>
                </div>

                <div class="pcx-dock" id="pcxInfo" aria-live="polite"></div>
            </div>
            <div class="tm-progress-wrap">
                <div class="tm-progress-meta"><span id="tmComponentCount"><b>0</b> / 11 components</span></div>
                <div class="tm-bar"><span id="tmComponentBar"></span></div>
            </div>
            <div class="tm-power-card">
                <div class="tm-power-head">
                    <span><i class="fas fa-bolt" aria-hidden="true"></i> Estimated Power Draw</span>
                    <strong id="tmPowerDraw">~0W</strong>
                </div>
                <div class="tm-bar tm-bar-power"><span id="tmPowerBar"></span></div>
                <small class="tm-power-hint" id="tmPowerHint"></small>
            </div>
            <div class="tm-disclaimer">
                <i class="fas fa-info-circle" aria-hidden="true"></i>
                <span>Compatibility checks are for guidance only and may not cover all scenarios. Please verify component specifications before purchasing.</span>
            </div>
        </section>

        <aside class="tm-right" aria-label="Build information">
            <div class="tm-card tm-score-card">
                <h3>Build Score</h3>
                <div class="tm-score">
                    <span class="tm-score-num" id="tmBuildScore">0</span><span class="tm-score-den">/100</span>
                </div>
                <div class="tm-score-actions">
                    <span id="tmScoreBadgeScore" title="Score"><i class="fas fa-star" aria-hidden="true"></i></span>
                    <span id="tmScoreBadgeCompat" title="Compatibility"><i class="fas fa-microchip" aria-hidden="true"></i></span>
                    <span id="tmScoreBadgePower" title="Power"><i class="fas fa-plug" aria-hidden="true"></i></span>
                </div>
            </div>
            <div class="tm-card">
                <h3>Performance Balance</h3>
                <div class="tm-radar-wrap">
                    <svg id="tmRadarSvg" viewBox="0 0 180 180" role="img" aria-label="Performance radar chart"></svg>
                </div>
            </div>
            <div class="tm-card tm-summary-card">
                <h3>Build Summary</h3>
                <div class="tm-summary-row">
                    <span>Components</span>
                    <strong id="tmSummaryCount">0 / 11</strong>
                </div>
                <div class="tm-bar tm-bar-thin"><span id="tmSummaryBar"></span></div>
                <div class="tm-summary-total">
                    <span>Total</span>
                    <strong id="tmSummaryTotal">₱0</strong>
                </div>
                <div class="tm-compat" id="tmCompatStatus"></div>
                <div class="tm-platform" id="tmPlatform" hidden></div>
            </div>
            <button type="button" class="tm-btn-primary" id="tmAddMissing">
                <i class="fas fa-cog" aria-hidden="true"></i> Add Missing Components
            </button>
            <button type="button" class="tm-btn-outline" id="tmSaveBuild">
                <i class="far fa-save" aria-hidden="true"></i> <span id="tmSaveLabel">Save Build</span>
            </button>
            <button type="button" class="tm-btn-outline" id="tmShareBuild">
                <i class="fas fa-share-alt" aria-hidden="true"></i> Share
            </button>
            <a class="tm-btn-outline" href="saved_builds.php">
                <i class="fas fa-folder-open" aria-hidden="true"></i> My Saved Builds
            </a>
            <div class="tm-btn-row">
                <button type="button" class="tm-btn-outline tm-btn-sm" id="tmLoadBuild">Load</button>
                <button type="button" class="tm-btn-outline tm-btn-sm" id="tmReportBuild"><i class="far fa-flag" aria-hidden="true"></i> Report</button>
                <button type="button" class="tm-btn-outline tm-btn-sm tm-btn-danger" id="tmClearBuild"><i class="far fa-trash-alt" aria-hidden="true"></i> Clear</button>
            </div>
            <div class="tm-card tm-compare-card">
                <h3>Compare Builds</h3>
                <div class="tm-compare-slots" id="tmCompareSlots"></div>
                <button type="button" class="tm-btn-outline tm-btn-sm tm-compare-go" id="tmCompareGo" hidden>
                    <i class="fas fa-columns" aria-hidden="true"></i> Compare
                </button>
            </div>
        </aside>
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
                <option value="price-asc">Price: Low to High</option>
                <option value="price-desc">Price: High to Low</option>
                <option value="name">Name: A to Z</option>
                <option value="stock">Most in Stock</option>
            </select>
            <label class="tm-picker-toggle"><input type="checkbox" id="tmPickerInStock" checked> In Stock</label>
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
    <button type="button" class="tm-btn-primary" id="tmMobileSave"><i class="far fa-save" aria-hidden="true"></i> Save</button>
</div>

<script>
window.EP_CSRF = <?php echo json_encode($csrf); ?>;
window.EP_TM_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
</script>
<script src="tech_match.js?v=pcx-6" defer></script>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
