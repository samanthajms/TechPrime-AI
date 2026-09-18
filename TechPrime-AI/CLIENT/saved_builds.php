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

$db->query(
    "CREATE TABLE IF NOT EXISTS saved_builds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        build_name VARCHAR(150) NOT NULL,
        components_json MEDIUMTEXT NOT NULL,
        total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        component_count INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_saved_builds_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

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
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$builds = [];
while ($row = $res->fetch_assoc()) {
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
$stmt->close();

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
  display: grid;
  grid-template-columns: minmax(0, 1fr) 220px;
  gap: 8px;
  align-items: end;
  position: relative;
}

.sb-table-wrap {
  min-width: 0;
  background: #fff;
  border: 1px solid var(--ep-border, #e4e8ea);
  border-radius: 14px;
  box-shadow: 0 8px 24px rgba(15,23,42,0.06);
  overflow: auto;
  /* Table stays perfectly level — never rotate this wrapper. */
  transform: none;
}

.sb-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 640px;
  transform: none;
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
.sb-btn-cart { background: var(--ep-green, #62b236); color: #fff; }
.sb-btn-cart:hover { background: var(--ep-green-dark, #4b8b2a); }
.sb-btn-cart:disabled { opacity: 0.65; cursor: wait; }

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

/* Large decorative Primo — slanted ONLY on the mascot, not the table. */
.sb-primo-aside {
  position: relative;
  height: 260px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  pointer-events: none;
  z-index: 2;
}
.sb-primo-figure {
  --sb-primo-green: var(--ep-green, #62b236);
  --sb-primo-dark: var(--ep-green-dark, #4b8b2a);
  --sb-primo-light: #8fd15a;
  width: 168px;
  height: 240px;
  position: relative;
  transform: rotate(-8deg) translate(-12px, 18px);
  transform-origin: 70% 100%;
  filter: drop-shadow(0 10px 18px rgba(75,139,42,0.28));
}
.sb-primo-antenna {
  position: absolute; top: 8px; left: 50%; width: 4px; height: 22px;
  margin-left: -2px; background: var(--sb-primo-dark); border-radius: 2px;
}
.sb-primo-antenna::after {
  content: ""; position: absolute; top: -8px; left: 50%; width: 12px; height: 12px;
  margin-left: -6px; border-radius: 50%; background: #fed700;
  box-shadow: 0 0 0 3px rgba(254,215,0,0.25);
}
.sb-primo-head {
  position: absolute; top: 28px; left: 50%; width: 92px; height: 72px;
  margin-left: -46px; background: linear-gradient(160deg, var(--sb-primo-light), var(--sb-primo-green));
  border-radius: 22px 22px 18px 18px; border: 3px solid var(--sb-primo-dark);
}
.sb-primo-visor {
  position: absolute; left: 10px; right: 10px; top: 16px; height: 34px;
  background: #1e1f26; border-radius: 12px; display: flex; align-items: center;
  justify-content: center; gap: 14px;
}
.sb-primo-eye {
  width: 12px; height: 12px; border-radius: 50%; background: #7CFF6B;
  box-shadow: 0 0 8px rgba(124,255,107,0.7);
}
.sb-primo-mouth {
  position: absolute; bottom: 10px; left: 50%; width: 28px; height: 4px;
  margin-left: -14px; background: rgba(255,255,255,0.55); border-radius: 4px;
}
.sb-primo-torso {
  position: absolute; top: 108px; left: 50%; width: 78px; height: 70px;
  margin-left: -39px; background: linear-gradient(180deg, var(--sb-primo-green), var(--sb-primo-dark));
  border-radius: 16px; border: 3px solid var(--sb-primo-dark);
}
.sb-primo-chest {
  position: absolute; left: 50%; top: 18px; width: 18px; height: 18px;
  margin-left: -9px; border-radius: 50%; background: #fed700;
  box-shadow: inset 0 0 0 3px rgba(255,255,255,0.35);
}
.sb-primo-arm-l, .sb-primo-arm-r {
  position: absolute; top: 112px; width: 18px; height: 58px;
  background: var(--sb-primo-green); border: 3px solid var(--sb-primo-dark); border-radius: 12px;
}
.sb-primo-arm-l { left: 18px; transform: rotate(18deg); }
.sb-primo-arm-r {
  right: 6px; height: 72px; transform: rotate(-42deg) translate(10px, 8px);
  transform-origin: top center;
}
.sb-primo-hand {
  position: absolute; bottom: -10px; left: 50%; width: 22px; height: 16px;
  margin-left: -11px; background: #eef8e6; border: 3px solid var(--sb-primo-dark);
  border-radius: 8px;
}
.sb-primo-base {
  position: absolute; bottom: 18px; left: 50%; width: 70px; height: 28px;
  margin-left: -35px; background: var(--sb-primo-dark); border-radius: 10px;
}
.sb-primo-label {
  position: absolute; bottom: -2px; left: 50%; transform: translateX(-50%);
  font-size: 12px; font-weight: 800; color: var(--sb-primo-dark);
  letter-spacing: 0.4px;
}

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

@media (max-width: 900px) {
  .sb-stage { grid-template-columns: 1fr; }
  .sb-primo-aside { height: 180px; order: -1; justify-content: flex-start; }
  .sb-primo-figure {
    width: 120px; height: 170px;
    transform: rotate(-6deg) translate(8px, 8px);
  }
}
@media (max-width: 560px) {
  .sb-primo-aside { display: none; }
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
        <div class="sb-empty">
            <i class="fas fa-box-open" aria-hidden="true"></i>
            <h3>No saved builds yet</h3>
            <p>Open Tech &amp; Match from Primo, select components, then click Save Build.</p>
            <a class="sb-btn-primary" href="index.php#ep-tech-match">Go to Home &amp; open Tech &amp; Match</a>
        </div>
    <?php else: ?>
        <div class="sb-stage">
            <div class="sb-table-wrap">
                <table class="sb-table">
                    <thead>
                        <tr>
                            <th>Build Name</th>
                            <th>View</th>
                            <th>Edit</th>
                            <th>Price</th>
                            <th>Add to Cart</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($builds as $b): ?>
                        <tr data-build-id="<?php echo (int)$b['id']; ?>">
                            <td class="sb-name"><?php echo h($b['name']); ?></td>
                            <td>
                                <button type="button" class="sb-btn sb-btn-view sb-view" data-id="<?php echo (int)$b['id']; ?>">
                                    <i class="fas fa-eye" aria-hidden="true"></i> View
                                </button>
                            </td>
                            <td>
                                <button type="button" class="sb-btn sb-btn-edit sb-edit" data-id="<?php echo (int)$b['id']; ?>">
                                    <i class="fas fa-pen" aria-hidden="true"></i> Edit
                                </button>
                            </td>
                            <td class="sb-price">₱<?php echo number_format((float)$b['total'], 2); ?></td>
                            <td>
                                <button type="button" class="sb-btn sb-btn-cart sb-cart" data-id="<?php echo (int)$b['id']; ?>">
                                    <i class="fas fa-shopping-cart" aria-hidden="true"></i> Add to Cart
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <aside class="sb-primo-aside" aria-hidden="true">
                <div class="sb-primo-figure">
                    <span class="sb-primo-antenna"></span>
                    <span class="sb-primo-head">
                        <span class="sb-primo-visor">
                            <span class="sb-primo-eye"></span>
                            <span class="sb-primo-eye"></span>
                        </span>
                        <span class="sb-primo-mouth"></span>
                    </span>
                    <span class="sb-primo-arm-l"></span>
                    <span class="sb-primo-torso"><span class="sb-primo-chest"></span></span>
                    <span class="sb-primo-arm-r"><span class="sb-primo-hand"></span></span>
                    <span class="sb-primo-base"></span>
                    <span class="sb-primo-label">Primo</span>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</main>

<script>
window.EP_CSRF = <?php echo json_encode($csrf); ?>;
window.SB_BUILDS = <?php echo $buildsJson ?: '[]'; ?>;
window.SB_SLOTS = <?php echo $slotsJson ?: '{}'; ?>;

document.addEventListener('DOMContentLoaded', function () {
    var buildsById = {};
    (window.SB_BUILDS || []).forEach(function (b) { buildsById[b.id] = b; });
    var slotLabels = window.SB_SLOTS || {};

    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
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
                window.location.href = 'index.php#ep-tech-match';
            })
            .catch(function () {
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not open this build.', 'error');
            });
    }

    document.querySelectorAll('.sb-view').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = Number(btn.getAttribute('data-id'));
            var local = buildsById[id];
            if (local) {
                openViewModal(local);
                return;
            }
            fetch('saved_builds_api.php?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
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
        });
    });

    document.querySelectorAll('.sb-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            loadIntoTechMatch(btn.getAttribute('data-id'), 'edit');
        });
    });

    document.querySelectorAll('.sb-cart').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            btn.disabled = true;
            fetch('saved_builds_api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ action: 'add_to_cart', id: Number(id), csrf_token: window.EP_CSRF || '' })
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    btn.disabled = false;
                    if (!data || !data.ok) {
                        if (typeof IAS_UI !== 'undefined') {
                            IAS_UI.alert((data && data.message) || 'Could not add to cart.', 'error');
                        }
                        return;
                    }
                    if (typeof IAS_UI !== 'undefined') IAS_UI.alert(data.message || 'Added to cart!', 'success');
                })
                .catch(function () {
                    btn.disabled = false;
                    if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Could not add to cart.', 'error');
                });
        });
    });
});
</script>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
