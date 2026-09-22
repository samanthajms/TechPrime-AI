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
$pageTitle = 'Saved Build';
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
    'ssd' => 'SSD',
    'ssd_sata' => 'SSD (SATA)',
    'hdd' => 'Hard Disk',
    'gpu' => 'Graphics Card',
    'psu' => 'Power Supply',
    'case' => 'Case',
    'cooler' => 'CPU Cooler',
    'extras' => 'Extras',
];

$stmt = $db->prepare(
    'SELECT id, build_name, components_json, total_price, component_count, created_at
     FROM saved_builds WHERE user_id = ? ORDER BY created_at DESC, id DESC'
);
$stmt->execute([$uid]);
$res = $stmt;
$builds = [];
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
    $comps = json_decode((string)$row['components_json'], true);
    $builds[] = [
        'id' => (int)$row['id'],
        'name' => (string)$row['build_name'],
        'total' => (float)$row['total_price'],
        'count' => (int)$row['component_count'],
        'created_at' => (string)$row['created_at'],
        'components' => is_array($comps) ? $comps : [],
    ];
}
$buildsJson = json_encode($builds, JSON_UNESCAPED_UNICODE);
$slotsJson = json_encode($slotLabels, JSON_UNESCAPED_UNICODE);

$extraHead = '<style>
.sb-page { max-width: 1180px; margin: 0 auto; padding: 24px 18px 80px; }
.sb-hero {
  background: linear-gradient(135deg, #eef8e6 0%, #fff 70%);
  border: 1px solid var(--ep-border, #e4e8ea);
  border-radius: 16px;
  padding: 22px 24px;
  margin-bottom: 22px;
  box-shadow: 0 8px 22px rgba(75,139,42,0.07);
}
.sb-hero h1 { margin: 0 0 6px; font-size: 22px; font-weight: 800; color: var(--ep-green-dark, #4b8b2a); }
.sb-hero p { margin: 0; color: #6b7280; font-size: 14px; font-weight: 500; }

.sb-stage {
  width: 100%;
  max-width: 960px;
  margin: 0 auto;
}

.sb-table-wrap {
  min-width: 0;
  background: #fff;
  border: 1px solid var(--ep-border, #e4e8ea);
  border-radius: 14px;
  box-shadow: 0 8px 24px rgba(15,23,42,0.06);
  overflow: auto;
}

.sb-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 720px;
}
.sb-table th,
.sb-table td {
  padding: 14px 16px;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid #eef1f4;
}
.sb-table th {
  font-size: 11px;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  color: #5b7c99;
  background: #f7faf4;
  font-weight: 800;
  white-space: nowrap;
}
.sb-table tbody tr:last-child td { border-bottom: 0; }
.sb-table tbody tr:hover td { background: #fbfcfb; }
.sb-name { font-size: 15px; font-weight: 800; color: #1f2328; }
.sb-price { font-size: 15px; font-weight: 800; color: var(--ep-green-dark, #4b8b2a); white-space: nowrap; }
.sb-btn {
  border: 0;
  border-radius: 9px;
  padding: 8px 12px;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}
.sb-btn-view { background: #eef8e6; color: var(--ep-green-dark, #4b8b2a); }
.sb-btn-view:hover { background: #e2f2d4; }
.sb-btn-edit { background: #f0f4f8; color: #334155; }
.sb-btn-edit:hover { background: #e2e8f0; }
.sb-btn-delete { background: #fef2f2; color: #b91c1c; }
.sb-btn-delete:hover { background: #fee2e2; }
.sb-btn-delete:disabled { opacity: 0.65; cursor: wait; }
.sb-btn-cart { background: var(--ep-green, #62b236); color: #fff; }
.sb-btn-cart:hover { background: var(--ep-green-dark, #4b8b2a); }
.sb-btn-cart:disabled { opacity: 0.65; cursor: wait; }

.sb-pagination {
  margin-top: 18px;
}
.sb-pagination .ep-page-link {
  cursor: pointer;
  background: #fff;
  border: 1px solid transparent;
  font-family: inherit;
}
.sb-pagination button.ep-page-link {
  appearance: none;
  -webkit-appearance: none;
}
.sb-pagination .ep-page-link.active {
  background: var(--ep-black, #171717);
  color: #fff;
}
.sb-pagination .ep-page-nav {
  border: 1px solid var(--ep-border, #e4e8ea);
}
.sb-pagination .ep-page-nav.disabled {
  opacity: 0.4;
  pointer-events: none;
}

.sb-empty {
  text-align: center;
  padding: 56px 20px;
  background: #fff;
  border: 1px dashed #dfe3e8;
  border-radius: 14px;
  color: #6b7280;
}
.sb-empty i { font-size: 28px; color: var(--ep-green-dark); margin-bottom: 10px; display: block; }
.sb-empty h3 { margin: 0 0 6px; color: #1f2328; font-size: 17px; }
.sb-empty p { margin: 0 0 16px; }
.sb-btn-primary { background: var(--ep-green, #62b236); color: #fff; text-decoration: none; padding: 10px 14px; border-radius: 10px; font-weight: 700; display: inline-flex; gap: 6px; align-items: center; }

/* Read-only View modal */
.sb-view-overlay {
  position: fixed; inset: 0; z-index: 1300;
  background: rgba(15,23,42,0.45);
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.sb-view-dialog {
  width: min(560px, 100%);
  max-height: min(86vh, 720px);
  overflow: auto;
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 24px 60px rgba(0,0,0,0.28);
  padding: 22px 22px 18px;
}
.sb-view-dialog h3 { margin: 0 0 4px; font-size: 18px; color: #1f2328; }
.sb-view-meta { margin: 0 0 14px; color: #6b7280; font-size: 13px; }
.sb-view-list { display: grid; gap: 8px; margin-bottom: 16px; }
.sb-view-row {
  display: grid; grid-template-columns: 120px 1fr auto; gap: 10px;
  padding: 10px 12px; background: #f7f8f9; border-radius: 10px; border: 1px solid #eef1f4;
}
.sb-view-row strong { font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; color: #5b7c99; }
.sb-view-row span { font-size: 13px; font-weight: 600; color: #1f2328; }
.sb-view-row em { font-style: normal; font-weight: 800; color: var(--ep-green-dark); font-size: 13px; }
.sb-view-total {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 0 4px; border-top: 1px solid #eef1f4; font-weight: 800;
}
.sb-view-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
.sb-view-close {
  border: 1px solid #dfe3e8; background: #fff; border-radius: 10px;
  padding: 10px 16px; font-weight: 700; cursor: pointer; font-family: inherit;
}
</style>';

include __DIR__ . '/ep_header.php';
?>

<main class="sb-page">
    <section class="sb-hero">
        <h1><i class="fas fa-desktop" aria-hidden="true"></i> Saved Build</h1>
        <p>Your saved Tech &amp; Match PC configurations. Only you can see these builds.</p>
    </section>

    <?php if (empty($builds)): ?>
        <div class="sb-empty" id="sbEmpty">
            <i class="fas fa-box-open" aria-hidden="true"></i>
            <h3>No saved builds yet</h3>
            <p>Open Build a PC, select components, then click Save Build.</p>
            <a class="sb-btn-primary" href="build_a_pc.php">Go to Build a PC</a>
        </div>
    <?php else: ?>
        <div class="sb-stage" id="sbStage">
            <div class="sb-table-wrap">
                <table class="sb-table">
                    <thead>
                        <tr>
                            <th>Build Name</th>
                            <th>View</th>
                            <th>Edit</th>
                            <th>Delete</th>
                            <th>Price</th>
                            <th>Add to Cart</th>
                        </tr>
                    </thead>
                    <tbody id="sbTableBody"></tbody>
                </table>
            </div>
            <nav class="ep-pagination sb-pagination" id="sbPagination" aria-label="Saved builds pagination" hidden></nav>
        </div>
        <div class="sb-empty" id="sbEmpty" hidden>
            <i class="fas fa-box-open" aria-hidden="true"></i>
            <h3>No saved builds yet</h3>
            <p>Open Build a PC, select components, then click Save Build.</p>
            <a class="sb-btn-primary" href="build_a_pc.php">Go to Build a PC</a>
        </div>
    <?php endif; ?>
</main>

<script>
window.EP_CSRF = <?php echo json_encode($csrf); ?>;
window.SB_BUILDS = <?php echo $buildsJson ?: '[]'; ?>;
window.SB_SLOTS = <?php echo $slotsJson ?: '{}'; ?>;

document.addEventListener('DOMContentLoaded', function () {
    var builds = Array.isArray(window.SB_BUILDS) ? window.SB_BUILDS.slice() : [];
    var slotLabels = window.SB_SLOTS || {};
    var pageSize = 10;
    var currentPage = 1;
    var tbody = document.getElementById('sbTableBody');
    var pagination = document.getElementById('sbPagination');
    var stage = document.getElementById('sbStage');
    var emptyEl = document.getElementById('sbEmpty');

    function buildsByIdMap() {
        var map = {};
        builds.forEach(function (b) { map[b.id] = b; });
        return map;
    }

    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function totalPages() {
        return Math.max(1, Math.ceil(builds.length / pageSize));
    }

    function showEmptyState() {
        if (stage) stage.hidden = true;
        if (emptyEl) emptyEl.hidden = false;
    }

    function renderPagination() {
        if (!pagination) return;
        var pages = totalPages();
        if (builds.length <= pageSize) {
            pagination.hidden = true;
            pagination.innerHTML = '';
            return;
        }
        pagination.hidden = false;
        var html = '';
        html += '<button type="button" class="ep-page-link ep-page-nav' + (currentPage <= 1 ? ' disabled' : '') +
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

    function renderTable() {
        if (!tbody) return;
        if (!builds.length) {
            showEmptyState();
            return;
        }
        if (stage) stage.hidden = false;
        if (emptyEl && stage) emptyEl.hidden = true;

        var pages = totalPages();
        if (currentPage > pages) currentPage = pages;
        if (currentPage < 1) currentPage = 1;

        var start = (currentPage - 1) * pageSize;
        var slice = builds.slice(start, start + pageSize);
        var html = '';
        slice.forEach(function (b) {
            html += '<tr data-build-id="' + b.id + '">' +
                '<td class="sb-name">' + escapeHtml(b.name) + '</td>' +
                '<td><button type="button" class="sb-btn sb-btn-view sb-view" data-id="' + b.id + '">' +
                '<i class="fas fa-eye" aria-hidden="true"></i> View</button></td>' +
                '<td><button type="button" class="sb-btn sb-btn-edit sb-edit" data-id="' + b.id + '">' +
                '<i class="fas fa-pen" aria-hidden="true"></i> Edit</button></td>' +
                '<td><button type="button" class="sb-btn sb-btn-delete sb-delete" data-id="' + b.id + '">' +
                '<i class="fas fa-trash-alt" aria-hidden="true"></i> Delete</button></td>' +
                '<td class="sb-price">' + peso(b.total) + '</td>' +
                '<td><button type="button" class="sb-btn sb-btn-cart sb-cart" data-id="' + b.id + '">' +
                '<i class="fas fa-shopping-cart" aria-hidden="true"></i> Add to Cart</button></td>' +
                '</tr>';
        });
        tbody.innerHTML = html;
        renderPagination();
    }

    function openViewModal(build) {
        var existing = document.getElementById('sbViewOverlay');
        if (existing) existing.remove();

        var ts = build.created_at ? new Date(String(build.created_at).replace(' ', 'T')) : null;
        var dateLabel = ts && !isNaN(ts.getTime())
            ? ts.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
            : (build.created_at || '');

        var rows = '';
        Object.keys(slotLabels).forEach(function (key) {
            var c = build.components && build.components[key];
            if (!c) return;
            rows += '<div class="sb-view-row">' +
                '<strong>' + escapeHtml(slotLabels[key]) + '</strong>' +
                '<span>' + escapeHtml(c.name || '') + '</span>' +
                '<em>' + peso(c.price) + '</em></div>';
        });

        var overlay = document.createElement('div');
        overlay.id = 'sbViewOverlay';
        overlay.className = 'sb-view-overlay';
        overlay.innerHTML =
            '<div class="sb-view-dialog" role="dialog" aria-modal="true" aria-labelledby="sbViewTitle">' +
            '<h3 id="sbViewTitle">' + escapeHtml(build.name || 'Saved Build') + '</h3>' +
            '<p class="sb-view-meta">Saved: ' + escapeHtml(dateLabel) + ' · Read-only view</p>' +
            '<div class="sb-view-list">' + rows + '</div>' +
            '<div class="sb-view-total"><span>Total Price</span><span>' + peso(build.total) + '</span></div>' +
            '<div class="sb-view-actions"><button type="button" class="sb-view-close" id="sbViewClose">Close</button></div>' +
            '</div>';
        document.body.appendChild(overlay);
        var close = function () { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); };
        document.getElementById('sbViewClose').onclick = close;
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    }

    function loadIntoTechMatch(id, mode) {
        fetch('saved_builds_api.php?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.build) {
                    if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not open this build.', 'error');
                    return;
                }
                try {
                    sessionStorage.setItem('ep_tm_load_build', JSON.stringify(data.build.components || {}));
                    sessionStorage.setItem('ep_tm_load_name', data.build.name || '');
                    if (mode === 'edit') {
                        sessionStorage.setItem('ep_tm_edit_id', String(data.build.id));
                    } else {
                        sessionStorage.removeItem('ep_tm_edit_id');
                    }
                } catch (e) {}
                window.location.href = 'build_a_pc.php';
            })
            .catch(function () {
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not open this build.', 'error');
            });
    }

    function deleteBuild(id, btn) {
        if (!window.confirm('Are you sure you want to delete this saved build?')) {
            return;
        }
        btn.disabled = true;
        fetch('saved_builds_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: Number(id), csrf_token: window.EP_CSRF || '' })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    btn.disabled = false;
                    if (typeof IAS_UI !== 'undefined') {
                        IAS_UI.alert((data && data.message) || 'Could not delete this build.', 'error');
                    }
                    return;
                }
                builds = builds.filter(function (b) { return Number(b.id) !== Number(id); });
                window.SB_BUILDS = builds;
                renderTable();
            })
            .catch(function () {
                btn.disabled = false;
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not delete this build.', 'error');
            });
    }

    if (tbody) {
        tbody.addEventListener('click', function (e) {
            var viewBtn = e.target.closest('.sb-view');
            var editBtn = e.target.closest('.sb-edit');
            var delBtn = e.target.closest('.sb-delete');
            var cartBtn = e.target.closest('.sb-cart');

            if (viewBtn) {
                var vid = Number(viewBtn.getAttribute('data-id'));
                var local = buildsByIdMap()[vid];
                if (local) {
                    openViewModal(local);
                    return;
                }
                fetch('saved_builds_api.php?action=get&id=' + encodeURIComponent(vid), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok || !data.build) {
                            if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not open this build.', 'error');
                            return;
                        }
                        openViewModal({
                            id: data.build.id,
                            name: data.build.name,
                            total: data.build.total_price,
                            created_at: data.build.created_at,
                            components: data.build.components || {}
                        });
                    })
                    .catch(function () {
                        if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not open this build.', 'error');
                    });
                return;
            }

            if (editBtn) {
                loadIntoTechMatch(editBtn.getAttribute('data-id'), 'edit');
                return;
            }

            if (delBtn) {
                deleteBuild(delBtn.getAttribute('data-id'), delBtn);
                return;
            }

            if (cartBtn) {
                var id = cartBtn.getAttribute('data-id');
                cartBtn.disabled = true;
                fetch('saved_builds_api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ action: 'add_to_cart', id: Number(id), csrf_token: window.EP_CSRF || '' })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        cartBtn.disabled = false;
                        if (!data || !data.ok) {
                            if (typeof IAS_UI !== 'undefined') {
                                IAS_UI.alert((data && data.message) || 'Could not add to cart.', 'error');
                            }
                            return;
                        }
                        if (data.cart && typeof window.epUpdateCartPreview === 'function') {
                            window.epUpdateCartPreview(data.cart);
                        }
                        if (typeof IAS_UI !== 'undefined') IAS_UI.alert(data.message || 'Added to cart!', 'success');
                    })
                    .catch(function () {
                        cartBtn.disabled = false;
                        if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not add to cart.', 'error');
                    });
            }
        });
    }

    if (pagination) {
        pagination.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-sb-page]');
            if (!btn || btn.disabled || btn.classList.contains('disabled')) return;
            var page = Number(btn.getAttribute('data-sb-page'));
            if (!page || page === currentPage) return;
            currentPage = page;
            renderTable();
        });
    }

    if (tbody) renderTable();
});
</script>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
