<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$uid = (int)$_SESSION['user_id'];
$isLoggedIn = true;
$activePage = 'saved_builds';
$isHomePage = false;
$pageTitle = 'Saved Builds';
$csrf = generateCsrfToken();

$db->exec(
    "CREATE TABLE IF NOT EXISTS saved_builds (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL,
        build_name VARCHAR(150) NOT NULL,
        components_json TEXT NOT NULL,
        total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        component_count INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )"
);
$db->exec("CREATE INDEX IF NOT EXISTS idx_saved_builds_user ON saved_builds (user_id)");

$slotLabels = [
    'processor' => 'Processor',
    'motherboard' => 'Motherboard',
    'memory' => 'Memory',
    'gpu' => 'Graphics Card',
    'ssd' => 'SSD (NVMe / M.2)',
    'ssd_sata' => 'SSD (SATA)',
    'hdd' => 'Hard Disk',
    'psu' => 'Power Supply',
    'case' => 'PC Case',
    'cooler' => 'CPU Cooler',
    'case_fan' => 'Case Fan',
    'extras' => 'Extras',
];

$stmt = $db->prepare(
    'SELECT id, build_name, components_json, total_price, component_count, created_at
     FROM saved_builds WHERE user_id = ? ORDER BY created_at DESC, id DESC'
);
$stmt->execute([$uid]);
$builds = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $comps = json_decode((string)$row['components_json'], true);
    /* created_at holds UTC; show it in Manila time. */
    $savedLabel = '';
    $savedTs = 0;
    try {
        $dt = new DateTimeImmutable((string)$row['created_at'], new DateTimeZone('UTC'));
        $savedTs = $dt->getTimestamp();
        $savedLabel = $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A');
    } catch (Throwable $e) {
    }
    $builds[] = [
        'id' => (int)$row['id'],
        'name' => (string)$row['build_name'],
        'total' => (float)$row['total_price'],
        'count' => (int)$row['component_count'],
        'saved_label' => $savedLabel,
        'saved_ts' => $savedTs,
        'components' => is_array($comps) ? $comps : [],
    ];
}
$buildsJson = json_encode($builds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$slotsJson = json_encode($slotLabels, JSON_UNESCAPED_UNICODE);

$extraHead = '<style>
.sb-page { max-width: 1180px; margin: 0 auto; padding: 24px 18px 80px; font-family: "Poppins", "Inter", Arial, sans-serif; color: #2a2f36; }
.sb-page [hidden], .sb-overlay [hidden] { display: none !important; }
.sb-hero {
  background: linear-gradient(135deg, var(--ep-green-light, #eef8e6) 0%, #fff 70%);
  border: 1px solid var(--ep-border, #e4e8ea);
  border-radius: 16px;
  padding: 20px 22px;
  margin-bottom: 18px;
  box-shadow: 0 8px 22px rgba(75,139,42,0.07);
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.sb-hero .ep-profile-back-arrow { margin: 0; flex-shrink: 0; }
.sb-hero-text { flex: 1 1 320px; min-width: 0; }
.sb-hero h1 { margin: 0 0 4px; font-size: 22px; font-weight: 800; color: var(--ep-green-dark, #4b8b2a); }
.sb-hero p { margin: 0; color: #6b7280; font-size: 14px; font-weight: 500; }
.sb-new-btn {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--ep-green, #62b236); color: #fff; text-decoration: none;
  border-radius: 12px; padding: 11px 18px; font-size: 13.5px; font-weight: 800;
  box-shadow: 0 6px 16px rgba(75,139,42,0.24); white-space: nowrap;
}
.sb-new-btn:hover { background: var(--ep-green-dark, #4b8b2a); color: #fff; }

.sb-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.sb-count { font-size: 13px; font-weight: 700; color: #5b6573; margin-right: auto; }
.sb-search { position: relative; flex: 0 1 280px; }
.sb-search i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #8a93a0; font-size: 13px; }
.sb-search input, .sb-sort {
  width: 100%; box-sizing: border-box; border: 1.5px solid #e4e8ea; border-radius: 10px;
  padding: 9px 12px 9px 36px; font-family: inherit; font-size: 13px; background: #fff; outline: none;
}
.sb-sort { width: auto; padding-left: 12px; cursor: pointer; }
.sb-search input:focus, .sb-sort:focus { border-color: var(--ep-green, #62b236); box-shadow: 0 0 0 3px rgba(98,178,54,0.15); }

.sb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; }
.sb-card {
  background: #fff; border: 1px solid var(--ep-border, #e4e8ea); border-radius: 16px;
  box-shadow: 0 6px 18px rgba(15,23,42,0.05); padding: 18px;
  display: flex; flex-direction: column; gap: 14px;
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.sb-card:hover { border-color: #cfe8bf; box-shadow: 0 12px 26px rgba(15,23,42,0.08); }
.sb-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.sb-card-head > div { min-width: 0; }
.sb-card-name { margin: 0; font-size: 17px; font-weight: 800; color: #1f2328; overflow-wrap: anywhere; }
.sb-card-meta { margin: 3px 0 0; font-size: 12px; font-weight: 500; color: #8a93a0; }
.sb-card-price { font-size: 18px; font-weight: 800; color: var(--ep-green-dark, #4b8b2a); white-space: nowrap; }

.sb-thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
.sb-thumb {
  width: 54px; height: 54px; border-radius: 10px; background: #f7f8f9; border: 1px solid #eef1f4; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center; overflow: hidden; color: #b4bcc6; font-size: 18px;
}
.sb-thumb img { width: 100%; height: 100%; object-fit: contain; padding: 4px; box-sizing: border-box; }
.sb-thumb.is-more { font-size: 12.5px; font-weight: 800; color: #5b6573; }

.sb-keyparts { list-style: none; margin: 0; padding: 0; display: grid; gap: 7px; }
.sb-keyparts li { display: grid; grid-template-columns: 22px 96px minmax(0, 1fr); align-items: center; gap: 6px; font-size: 12.5px; }
.sb-keyparts i { color: #8a93a0; text-align: center; }
.sb-keyparts span { color: #8a93a0; font-weight: 600; }
.sb-keyparts strong { color: #2a2f36; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.sb-status { display: inline-flex; align-items: flex-start; gap: 7px; font-size: 12px; font-weight: 600; border-radius: 9px; padding: 7px 10px; line-height: 1.4; }
.sb-status i { margin-top: 2px; }
.sb-status.is-complete { background: #f0f9eb; color: #3d7422; }
.sb-status.is-partial { background: #f7f8fa; color: #5b6573; }

.sb-card-actions { display: flex; gap: 8px; margin-top: auto; padding-top: 14px; border-top: 1px solid #eef1f4; flex-wrap: wrap; }
.sb-btn {
  border: 0; border-radius: 10px; padding: 9px 13px; font-family: inherit; font-size: 12.5px; font-weight: 700;
  cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; white-space: nowrap;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.sb-btn:disabled { opacity: 0.65; cursor: wait; }
.sb-btn-cart { background: var(--ep-green, #62b236); color: #fff; flex: 1 1 auto; }
.sb-btn-cart:hover { background: var(--ep-green-dark, #4b8b2a); }
.sb-btn-ghost { background: #fff; color: #334155; border: 1.5px solid #e2e8f0; }
.sb-btn-ghost:hover { border-color: var(--ep-green, #62b236); color: var(--ep-green-dark, #4b8b2a); }
.sb-btn-delete { background: #fff; color: #b91c1c; border: 1.5px solid #fde2e2; padding: 9px 11px; }
.sb-btn-delete:hover { background: #fef2f2; border-color: #fca5a5; }
.sb-btn-danger { background: #dc2626; color: #fff; }
.sb-btn-danger:hover { background: #b91c1c; }

.sb-pagination { margin-top: 20px; }
.sb-pagination .ep-page-link { cursor: pointer; background: #fff; border: 1px solid transparent; font-family: inherit; }
.sb-pagination button.ep-page-link { appearance: none; -webkit-appearance: none; }
.sb-pagination .ep-page-link.active { background: var(--ep-black, #171717); color: #fff; }
.sb-pagination .ep-page-nav { border: 1px solid var(--ep-border, #e4e8ea); }
.sb-pagination .ep-page-nav.disabled { opacity: 0.4; pointer-events: none; }

.sb-empty {
  text-align: center; padding: 56px 20px; background: #fff;
  border: 1px dashed #dfe3e8; border-radius: 16px; color: #6b7280;
}
.sb-empty-icon {
  width: 64px; height: 64px; margin: 0 auto 14px; border-radius: 18px;
  background: var(--ep-green-light, #eef8e6); color: var(--ep-green-dark, #4b8b2a);
  display: flex; align-items: center; justify-content: center; font-size: 26px;
}
.sb-empty h3 { margin: 0 0 6px; color: #1f2328; font-size: 17px; }
.sb-empty p { margin: 0 0 18px; font-size: 14px; }
.sb-no-match { grid-column: 1 / -1; text-align: center; padding: 36px 16px; color: #8a93a0; font-weight: 600; font-size: 13.5px; }

/* Dialogs (view / confirm delete) */
.sb-overlay {
  position: fixed; inset: 0; z-index: 1300; background: rgba(15,23,42,0.45);
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.sb-dialog {
  position: relative; width: min(620px, 100%); box-sizing: border-box; max-height: min(88vh, 760px); overflow: auto;
  background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(0,0,0,0.28); padding: 22px;
  font-family: "Poppins", "Inter", Arial, sans-serif;
}
.sb-dialog.is-small { width: min(420px, 100%); }
.sb-dialog-x { position: absolute; top: 10px; right: 14px; border: 0; background: none; font-size: 28px; line-height: 1; color: #888; cursor: pointer; }
.sb-dialog h3 { margin: 0 32px 4px 0; font-size: 18px; font-weight: 800; color: #1f2328; overflow-wrap: anywhere; }
.sb-dialog-meta { margin: 0 0 16px; color: #8a93a0; font-size: 12.5px; font-weight: 500; }
.sb-dialog-text { margin: 0 0 4px; color: #4b5563; font-size: 14px; line-height: 1.5; }
.sb-view-list { display: grid; gap: 8px; }
.sb-view-row {
  display: grid; grid-template-columns: 48px minmax(0, 1fr) auto; gap: 12px; align-items: center;
  padding: 9px 12px 9px 9px; background: #fafbfc; border-radius: 12px; border: 1px solid #eef1f4;
}
.sb-view-row .sb-thumb { width: 48px; height: 48px; background: #fff; }
.sb-view-row small { display: block; font-size: 10.5px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; color: #5b7c99; }
.sb-view-row a, .sb-view-row b { display: block; font-size: 13px; font-weight: 600; color: #1f2328; text-decoration: none; line-height: 1.35; }
.sb-view-row a:hover { color: var(--ep-green-dark, #4b8b2a); text-decoration: underline; }
.sb-view-row em { font-style: normal; font-weight: 800; color: var(--ep-green-dark, #4b8b2a); font-size: 13px; white-space: nowrap; }
.sb-view-total { display: flex; justify-content: space-between; align-items: center; padding: 14px 2px 0; margin-top: 12px; border-top: 1px solid #eef1f4; font-weight: 800; font-size: 15px; }
.sb-view-total strong { color: var(--ep-green-dark, #4b8b2a); font-size: 18px; }
.sb-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; flex-wrap: wrap; }
.sb-dialog-actions .sb-btn-cart { flex: 0 0 auto; }

@media (max-width: 640px) {
  .sb-page { padding: 14px 12px 64px; }
  .sb-hero { padding: 16px; }
  .sb-hero .sb-new-btn { width: 100%; justify-content: center; }
  .sb-grid { grid-template-columns: minmax(0, 1fr); }
  .sb-search { flex: 1 1 100%; }
  .sb-sort { flex: 1 1 auto; }
  .sb-card { padding: 14px; }
  .sb-keyparts li { grid-template-columns: 20px minmax(0, 1fr); }
  .sb-keyparts li span { display: none; }
  .sb-view-row { grid-template-columns: 42px minmax(0, 1fr); }
  .sb-view-row .sb-thumb { width: 42px; height: 42px; }
  .sb-view-row em { grid-column: 2; }
}
</style>';

include __DIR__ . '/ep_header.php';
?>

<main class="sb-page">
    <section class="sb-hero">
        <a class="ep-profile-back-arrow" href="user_dashboard.php" data-ep-back aria-label="Back">&lt;</a>
        <div class="sb-hero-text">
            <h1><i class="fas fa-folder-open" aria-hidden="true"></i> Saved Builds</h1>
            <p>PC configurations you saved from Build a PC. Only you can see these.</p>
        </div>
        <a class="sb-new-btn" href="build_a_pc.php"><i class="fas fa-plus" aria-hidden="true"></i> New Build</a>
    </section>

    <div id="sbStage"<?php echo empty($builds) ? ' hidden' : ''; ?>>
        <div class="sb-toolbar">
            <span class="sb-count" id="sbCount"></span>
            <label class="sb-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" id="sbSearch" placeholder="Search builds or parts" autocomplete="off" aria-label="Search saved builds">
            </label>
            <select class="sb-sort" id="sbSort" aria-label="Sort saved builds">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="price-asc">Price: Low to High</option>
                <option value="price-desc">Price: High to Low</option>
            </select>
        </div>
        <div class="sb-grid" id="sbGrid"></div>
        <nav class="ep-pagination sb-pagination" id="sbPagination" aria-label="Saved builds pagination" hidden></nav>
    </div>

    <div class="sb-empty" id="sbEmpty"<?php echo empty($builds) ? '' : ' hidden'; ?>>
        <div class="sb-empty-icon"><i class="fas fa-desktop" aria-hidden="true"></i></div>
        <h3>No saved builds yet</h3>
        <p>Pick parts in Build a PC, then click Save Build to keep them here.</p>
        <a class="sb-new-btn" href="build_a_pc.php"><i class="fas fa-plus" aria-hidden="true"></i> Start a Build</a>
    </div>
</main>

<script>
window.EP_CSRF = <?php echo json_encode($csrf); ?>;
window.SB_BUILDS = <?php echo $buildsJson ?: '[]'; ?>;
window.SB_SLOTS = <?php echo $slotsJson ?: '{}'; ?>;

document.addEventListener('DOMContentLoaded', function () {
    var builds = Array.isArray(window.SB_BUILDS) ? window.SB_BUILDS.slice() : [];
    var slotLabels = window.SB_SLOTS || {};
    var slotIcons = {
        processor: 'fa-microchip', motherboard: 'fa-server', memory: 'fa-memory', gpu: 'fa-tv',
        ssd: 'fa-hdd', ssd_sata: 'fa-hdd', hdd: 'fa-database', psu: 'fa-plug', 'case': 'fa-cube',
        cooler: 'fa-fan', case_fan: 'fa-wind', extras: 'fa-plus-circle'
    };
    var pageSize = 8;
    var currentPage = 1;
    var grid = document.getElementById('sbGrid');
    var pagination = document.getElementById('sbPagination');
    var stage = document.getElementById('sbStage');
    var emptyEl = document.getElementById('sbEmpty');
    var searchEl = document.getElementById('sbSearch');
    var sortEl = document.getElementById('sbSort');
    var countEl = document.getElementById('sbCount');

    function alertUi(msg, type) {
        if (typeof IAS_UI !== 'undefined') IAS_UI.alert(msg, type);
    }

    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function findBuild(id) {
        return builds.find(function (b) { return Number(b.id) === Number(id); }) || null;
    }

    /* Parts in builder order (processor first ... extras last). */
    function parts(build) {
        var comps = build.components || {};
        return Object.keys(slotLabels).filter(function (k) { return comps[k] && comps[k].id; })
            .map(function (k) { return { slot: k, item: comps[k] }; });
    }

    function missingEssentials(build) {
        var c = build.components || {};
        var out = [];
        if (!c.processor) out.push('Processor');
        if (!c.motherboard) out.push('Motherboard');
        if (!c.memory) out.push('Memory');
        if (!c.ssd && !c.ssd_sata && !c.hdd) out.push('Storage');
        if (!c.psu) out.push('Power Supply');
        if (!c['case']) out.push('PC Case');
        return out;
    }

    function thumb(slot, item) {
        var inner = item && item.image
            ? '<img src="' + escapeHtml(item.image) + '" alt="" loading="lazy">'
            : '<i class="fas ' + (slotIcons[slot] || 'fa-box') + '" aria-hidden="true"></i>';
        return '<span class="sb-thumb" title="' + escapeHtml(slotLabels[slot] || '') + (item ? ': ' + escapeHtml(item.name) : '') + '">' + inner + '</span>';
    }

    function filtered() {
        var q = String(searchEl ? searchEl.value : '').trim().toLowerCase();
        var list = builds.filter(function (b) {
            if (!q) return true;
            var hay = b.name + ' ' + parts(b).map(function (p) { return p.item.name; }).join(' ');
            return hay.toLowerCase().indexOf(q) !== -1;
        });
        var sort = sortEl ? sortEl.value : 'newest';
        list.sort(function (a, b) {
            if (sort === 'oldest') return a.saved_ts - b.saved_ts || a.id - b.id;
            if (sort === 'price-asc') return a.total - b.total;
            if (sort === 'price-desc') return b.total - a.total;
            return b.saved_ts - a.saved_ts || b.id - a.id;
        });
        return list;
    }

    function renderCard(b) {
        var list = parts(b);
        var thumbs = list.slice(0, 6).map(function (p) { return thumb(p.slot, p.item); }).join('');
        if (list.length > 6) thumbs += '<span class="sb-thumb is-more">+' + (list.length - 6) + '</span>';
        var key = ['processor', 'gpu', 'memory'].filter(function (k) { return b.components && b.components[k]; });
        if (!key.length) key = list.slice(0, 3).map(function (p) { return p.slot; });
        var keyHtml = key.map(function (k) {
            return '<li><i class="fas ' + (slotIcons[k] || 'fa-box') + '" aria-hidden="true"></i>' +
                '<span>' + escapeHtml(slotLabels[k] || k) + '</span>' +
                '<strong title="' + escapeHtml(b.components[k].name) + '">' + escapeHtml(b.components[k].name) + '</strong></li>';
        }).join('');
        var missing = missingEssentials(b);
        var status = missing.length
            ? '<span class="sb-status is-partial"><i class="fas fa-list-ul" aria-hidden="true"></i><span>Still needs: ' + escapeHtml(missing.join(', ')) + '</span></span>'
            : '<span class="sb-status is-complete"><i class="fas fa-check-circle" aria-hidden="true"></i><span>Complete build: all essential parts included</span></span>';
        var count = list.length || b.count;
        return '<article class="sb-card">' +
            '<header class="sb-card-head"><div>' +
            '<h3 class="sb-card-name">' + escapeHtml(b.name) + '</h3>' +
            '<p class="sb-card-meta">' + count + ' part' + (count === 1 ? '' : 's') + (b.saved_label ? ' · Saved ' + escapeHtml(b.saved_label) : '') + '</p>' +
            '</div><div class="sb-card-price">' + peso(b.total) + '</div></header>' +
            (thumbs ? '<div class="sb-thumbs">' + thumbs + '</div>' : '') +
            (keyHtml ? '<ul class="sb-keyparts">' + keyHtml + '</ul>' : '') +
            '<div>' + status + '</div>' +
            '<footer class="sb-card-actions">' +
            '<button type="button" class="sb-btn sb-btn-cart" data-cart="' + b.id + '"><i class="fas fa-shopping-cart" aria-hidden="true"></i> Add to Cart</button>' +
            '<button type="button" class="sb-btn sb-btn-ghost" data-view="' + b.id + '"><i class="fas fa-eye" aria-hidden="true"></i> View</button>' +
            '<button type="button" class="sb-btn sb-btn-ghost" data-edit="' + b.id + '"><i class="fas fa-pen" aria-hidden="true"></i> Edit</button>' +
            '<button type="button" class="sb-btn sb-btn-delete" data-delete="' + b.id + '" aria-label="Delete ' + escapeHtml(b.name) + '" title="Delete"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>' +
            '</footer></article>';
    }

    function renderPagination(total) {
        if (!pagination) return;
        var pages = Math.max(1, Math.ceil(total / pageSize));
        if (total <= pageSize) {
            pagination.hidden = true;
            pagination.innerHTML = '';
            return;
        }
        pagination.hidden = false;
        var html = '<button type="button" class="ep-page-link ep-page-nav' + (currentPage <= 1 ? ' disabled' : '') +
            '" data-sb-page="' + (currentPage - 1) + '"' + (currentPage <= 1 ? ' disabled' : '') + '>' +
            '<i class="fas fa-arrow-left" aria-hidden="true"></i> Previous</button>';
        for (var p = 1; p <= pages; p++) {
            html += '<button type="button" class="ep-page-link' + (p === currentPage ? ' active' : '') +
                '" data-sb-page="' + p + '">' + p + '</button>';
        }
        html += '<button type="button" class="ep-page-link ep-page-nav' + (currentPage >= pages ? ' disabled' : '') +
            '" data-sb-page="' + (currentPage + 1) + '"' + (currentPage >= pages ? ' disabled' : '') + '>' +
            'Next <i class="fas fa-arrow-right" aria-hidden="true"></i></button>';
        pagination.innerHTML = html;
    }

    function render() {
        if (!builds.length) {
            if (stage) stage.hidden = true;
            if (emptyEl) emptyEl.hidden = false;
            return;
        }
        if (stage) stage.hidden = false;
        if (emptyEl) emptyEl.hidden = true;
        var list = filtered();
        var pages = Math.max(1, Math.ceil(list.length / pageSize));
        if (currentPage > pages) currentPage = pages;
        if (countEl) countEl.textContent = builds.length + ' saved build' + (builds.length === 1 ? '' : 's');
        var slice = list.slice((currentPage - 1) * pageSize, currentPage * pageSize);
        grid.innerHTML = slice.length
            ? slice.map(renderCard).join('')
            : '<div class="sb-no-match">No saved builds match your search.</div>';
        renderPagination(list.length);
    }

    /* ---------- Dialogs ---------- */

    function openDialog(html, small, onReady) {
        var existing = document.getElementById('sbOverlay');
        if (existing) existing.remove();
        var overlay = document.createElement('div');
        overlay.id = 'sbOverlay';
        overlay.className = 'sb-overlay';
        overlay.innerHTML = '<div class="sb-dialog' + (small ? ' is-small' : '') + '" role="dialog" aria-modal="true" aria-labelledby="sbDialogTitle">' +
            '<button type="button" class="sb-dialog-x" aria-label="Close">&times;</button>' + html + '</div>';
        document.body.appendChild(overlay);
        var onKey = function (e) { if (e.key === 'Escape') close(); };
        var close = function () {
            document.removeEventListener('keydown', onKey);
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        };
        document.addEventListener('keydown', onKey);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.closest('.sb-dialog-x') || e.target.closest('[data-close]')) {
                close();
                return;
            }
            handleAction(e, close);
        });
        if (onReady) onReady(overlay, close);
    }

    function openViewModal(build) {
        var rows = parts(build).map(function (p) {
            var pid = parseInt(p.item.id, 10) || 0;
            var name = escapeHtml(p.item.name || '');
            return '<div class="sb-view-row">' + thumb(p.slot, p.item) +
                '<div><small>' + escapeHtml(slotLabels[p.slot] || p.slot) + '</small>' +
                (pid > 0 ? '<a href="products.php?id=' + pid + '">' + name + '</a>' : '<b>' + name + '</b>') + '</div>' +
                '<em>' + peso(p.item.price) + '</em></div>';
        }).join('');
        var missing = missingEssentials(build);
        openDialog(
            '<h3 id="sbDialogTitle">' + escapeHtml(build.name || 'Saved Build') + '</h3>' +
            '<p class="sb-dialog-meta">' + (build.saved_label ? 'Saved ' + escapeHtml(build.saved_label) + ' · ' : '') +
            (missing.length ? 'Still needs: ' + escapeHtml(missing.join(', ')) : 'All essential parts included') + '</p>' +
            '<div class="sb-view-list">' + rows + '</div>' +
            '<div class="sb-view-total"><span>Total</span><strong>' + peso(build.total) + '</strong></div>' +
            '<div class="sb-dialog-actions">' +
            '<button type="button" class="sb-btn sb-btn-ghost" data-close>Close</button>' +
            '<button type="button" class="sb-btn sb-btn-ghost" data-edit="' + build.id + '"><i class="fas fa-pen" aria-hidden="true"></i> Edit</button>' +
            '<button type="button" class="sb-btn sb-btn-cart" data-cart="' + build.id + '"><i class="fas fa-shopping-cart" aria-hidden="true"></i> Add to Cart</button>' +
            '</div>'
        );
    }

    function confirmDelete(build) {
        openDialog(
            '<h3 id="sbDialogTitle">Delete this build?</h3>' +
            '<p class="sb-dialog-text">"' + escapeHtml(build.name) + '" will be removed permanently. This can\'t be undone.</p>' +
            '<div class="sb-dialog-actions">' +
            '<button type="button" class="sb-btn sb-btn-ghost" data-close>Cancel</button>' +
            '<button type="button" class="sb-btn sb-btn-danger" data-confirm-delete="' + build.id + '"><i class="fas fa-trash-alt" aria-hidden="true"></i> Delete</button>' +
            '</div>',
            true,
            function (overlay) {
                var btn = overlay.querySelector('[data-confirm-delete]');
                if (btn) btn.focus();
            }
        );
    }

    /* ---------- Actions ---------- */

    function postJson(body) {
        body.csrf_token = window.EP_CSRF || '';
        return fetch('saved_builds_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function loadIntoBuilder(id) {
        fetch('saved_builds_api.php?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.build) {
                    alertUi('Could not open this build.', 'error');
                    return;
                }
                try {
                    sessionStorage.setItem('ep_tm_load_build', JSON.stringify(data.build.components || {}));
                    sessionStorage.setItem('ep_tm_load_name', data.build.name || '');
                    sessionStorage.setItem('ep_tm_edit_id', String(data.build.id));
                } catch (e) {}
                window.location.href = 'build_a_pc.php';
            })
            .catch(function () { alertUi('Could not open this build.', 'error'); });
    }

    function deleteBuild(id, btn, close) {
        btn.disabled = true;
        postJson({ action: 'delete', id: Number(id) })
            .then(function (data) {
                if (!data || !data.ok) {
                    btn.disabled = false;
                    alertUi((data && data.message) || 'Could not delete this build.', 'error');
                    return;
                }
                close();
                builds = builds.filter(function (b) { return Number(b.id) !== Number(id); });
                window.SB_BUILDS = builds;
                render();
            })
            .catch(function () {
                btn.disabled = false;
                alertUi('Could not delete this build.', 'error');
            });
    }

    function addToCart(id, btn) {
        btn.disabled = true;
        postJson({ action: 'add_to_cart', id: Number(id) })
            .then(function (data) {
                btn.disabled = false;
                if (!data || !data.ok) {
                    alertUi((data && data.message) || 'Could not add to cart.', 'error');
                    return;
                }
                if (data.cart && typeof window.epUpdateCartPreview === 'function') {
                    window.epUpdateCartPreview(data.cart);
                }
                alertUi(data.message || 'Added to cart!', 'success');
            })
            .catch(function () {
                btn.disabled = false;
                alertUi('Could not add to cart.', 'error');
            });
    }

    function handleAction(e, close) {
        var btn = e.target.closest('[data-view],[data-edit],[data-delete],[data-cart],[data-confirm-delete]');
        if (!btn || btn.disabled) return;
        var id;
        if ((id = btn.getAttribute('data-view'))) {
            var b = findBuild(id);
            if (b) openViewModal(b);
        } else if ((id = btn.getAttribute('data-edit'))) {
            loadIntoBuilder(id);
        } else if ((id = btn.getAttribute('data-delete'))) {
            var d = findBuild(id);
            if (d) confirmDelete(d);
        } else if ((id = btn.getAttribute('data-confirm-delete'))) {
            deleteBuild(id, btn, close || function () {});
        } else if ((id = btn.getAttribute('data-cart'))) {
            addToCart(id, btn);
        }
    }

    if (grid) grid.addEventListener('click', function (e) { handleAction(e); });
    if (searchEl) searchEl.addEventListener('input', function () { currentPage = 1; render(); });
    if (sortEl) sortEl.addEventListener('change', function () { currentPage = 1; render(); });
    if (pagination) {
        pagination.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-sb-page]');
            if (!btn || btn.disabled || btn.classList.contains('disabled')) return;
            var page = Number(btn.getAttribute('data-sb-page'));
            if (!page || page === currentPage) return;
            currentPage = page;
            render();
            if (stage) stage.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    render();
});
</script>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
