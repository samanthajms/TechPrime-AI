<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];
$success  = '';
$error    = '';

// Ensure settings table & default rows exist (idempotent)
$db->query("
    CREATE TABLE IF NOT EXISTS `site_settings` (
        `id`            INT(11)      NOT NULL AUTO_INCREMENT,
        `setting_key`   VARCHAR(100) NOT NULL,
        `setting_value` TEXT         NOT NULL,
        `updated_by`    INT(11)      DEFAULT NULL,
        `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$defaults = [
    'pw_min_length'      => '8',
    'pw_require_upper'   => '1',
    'pw_require_lower'   => '1',
    'pw_require_number'  => '1',
    'pw_require_special' => '1',
    'max_failed_attempts'=> '3',
];
foreach ($defaults as $k => $v) {
    $ins = $db->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    $ins->bind_param('ss', $k, $v);
    $ins->execute();
    $ins->close();
}

function getSettings($db) {
    $res = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    $minLen      = max(6, min(32, (int)($_POST['pw_min_length']       ?? 8)));
    $reqUpper    = isset($_POST['pw_require_upper'])   ? '1' : '0';
    $reqLower    = isset($_POST['pw_require_lower'])   ? '1' : '0';
    $reqNumber   = isset($_POST['pw_require_number'])  ? '1' : '0';
    $reqSpecial  = isset($_POST['pw_require_special']) ? '1' : '0';
    $maxAttempts = max(1, min(10, (int)($_POST['max_failed_attempts'] ?? 3)));

    $updates = [
        'pw_min_length'       => (string)$minLen,
        'pw_require_upper'    => $reqUpper,
        'pw_require_lower'    => $reqLower,
        'pw_require_number'   => $reqNumber,
        'pw_require_special'  => $reqSpecial,
        'max_failed_attempts' => (string)$maxAttempts,
    ];

    $ok = true;
    foreach ($updates as $k => $v) {
        $upd = $db->prepare("UPDATE site_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
        $upd->bind_param('sis', $v, $admin_id, $k);
        if (!$upd->execute()) { $ok = false; }
        $upd->close();
    }

    if ($ok) {
        logActivity($db, $admin_id, 'settings_update', 'Admin updated security settings (max_attempts=' . $maxAttempts . ')');
        $success = 'Settings saved successfully.';
    } else {
        $error = 'Failed to save some settings. Please try again.';
    }
}

$cfg = getSettings($db);
$adminInitials = strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — IAS Admin</title>
    <link rel="stylesheet" href="admin_shared.css">
    <style>
        .rule-row {
            display: flex; align-items: flex-start;
            gap: 16px; padding: 18px 0;
            border-bottom: 1px solid var(--border);
        }
        .rule-row:last-child { border-bottom: none; padding-bottom: 0; }
        .rule-info { flex: 1; }
        .rule-info strong { display: block; font-size: 14px; font-weight: 600; margin-bottom: 3px; }
        .rule-info .rule-desc { font-size: 12.5px; color: var(--text-muted); }
        .rule-ctrl { flex-shrink: 0; display: flex; align-items: center; }

        .num-stepper {
            display: flex; align-items: center; gap: 8px;
        }
        .stepper-btn {
            width: 34px; height: 34px;
            border: 1.5px solid var(--border);
            background: var(--slate-50);
            border-radius: 8px; cursor: pointer;
            font-size: 18px; font-weight: 700; line-height: 1;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-main);
            transition: all .15s;
        }
        .stepper-btn:hover { background: var(--teal-pale); border-color: var(--teal); color: var(--teal-deeper); }
        .stepper-input {
            width: 68px; padding: 7px 10px;
            border: 1.5px solid var(--border);
            border-radius: 8px; font-size: 16px;
            font-weight: 700; text-align: center;
            outline: none; transition: border-color .18s;
            font-family: var(--font-mono);
            color: var(--text-main);
        }
        .stepper-input:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(9,152,167,.1); }
        .stepper-range { font-size: 11px; color: var(--text-muted); margin-top: 4px; }

        .preview-box {
            background: var(--slate-50);
            border: 1.5px solid var(--border);
            border-radius: 10px; padding: 16px 20px;
        }
        .preview-box h4 { margin: 0 0 12px; font-size: 12px; text-transform: uppercase; letter-spacing: .8px; color: var(--text-muted); font-weight: 700; }
        .preview-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 7px; }
        .preview-list li { font-size: 13px; display: flex; align-items: center; gap: 9px; transition: opacity .2s; }
        .pdot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; transition: background .2s; }
        .pdot-on  { background: var(--teal); }
        .pdot-off { background: var(--slate-300); }

        .attempts-banner {
            background: var(--teal-pale);
            border: 1.5px solid var(--teal-light);
            border-radius: 10px;
            padding: 16px 20px;
            display: flex; gap: 14px; align-items: flex-start;
            margin-bottom: 0;
        }
        .attempts-icon { font-size: 28px; flex-shrink: 0; }
        .attempts-desc strong { display: block; font-size: 14px; color: var(--teal-deeper); margin-bottom: 4px; }
        .attempts-desc span { font-size: 12.5px; color: var(--slate-500); }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <div>
            <div class="brand-text">IAS Admin</div>
            <div class="brand-sub">Control Panel</div>
        </div>
    </div>
    <nav>
        <a href="admin_dashboard.php">Dashboard</a>
        <a href="manage_users.php">Manage Users</a>
        <a href="view_logs.php">Activity Logs</a>
        <a href="admin_profile.php">My Profile</a>
        <a href="admin_settings.php" class="active">Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h2>Security Settings</h2>
            <div class="breadcrumb">Configure authentication &amp; password policies</div>
        </div>
        <div class="topbar-right">
            <div class="admin-badge">
                <div class="avatar"><?php echo $adminInitials; ?></div>
                <?php echo h($_SESSION['name']); ?>
            </div>
        </div>
    </div>

    <div class="page-content">

        <?php if ($success): ?>
            <div class="alert alert-success">✔ <?php echo h($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">✘ <?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="alert alert-info">
            ℹ️ Password complexity changes apply to <strong>new registrations only</strong>. The failed login attempts limit applies <strong>immediately</strong> for all users.
        </div>

        <form method="post" id="settingsForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

            <!-- ── Failed Login Attempts ── -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon">🔒</span> Login Security</h3>
                        <div class="card-subtitle">Controls how many failed attempts are allowed before an account is locked</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="attempts-banner">
                        <div class="attempts-icon">🚫</div>
                        <div class="attempts-desc">
                            <strong>Maximum Failed Login Attempts</strong>
                            <span>After this many consecutive wrong passwords, the user's account will be automatically locked. An admin must unlock it. Default is 3.</span>
                        </div>
                    </div>
                    <div style="margin-top:20px;">
                        <div class="rule-row" style="border:none;padding:0">
                            <div class="rule-info">
                                <strong>Failed Attempts Before Lockout</strong>
                                <div class="rule-desc">Range: 1 (strictest) to 10 (most lenient). Recommended: 3–5.</div>
                                <div class="num-stepper" style="margin-top:12px">
                                    <button type="button" class="stepper-btn" onclick="adjustVal('maxAttempts',-1,1,10)">−</button>
                                    <input type="number" name="max_failed_attempts" id="maxAttempts"
                                           class="stepper-input" min="1" max="10"
                                           value="<?php echo (int)$cfg['max_failed_attempts']; ?>">
                                    <button type="button" class="stepper-btn" onclick="adjustVal('maxAttempts',1,1,10)">+</button>
                                </div>
                                <div class="stepper-range">min 1 · max 10</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Password Complexity ── -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon">🔑</span> Password Complexity</h3>
                        <div class="card-subtitle">Rules new users must meet when registering or changing passwords</div>
                    </div>
                </div>
                <div class="card-body">

                    <!-- Minimum Length -->
                    <div class="rule-row">
                        <div class="rule-info">
                            <strong>Minimum Password Length</strong>
                            <div class="rule-desc">How many characters a password must have at minimum (6–32).</div>
                            <div class="num-stepper" style="margin-top:12px">
                                <button type="button" class="stepper-btn" onclick="adjustVal('pwMinLen',-1,6,32)">−</button>
                                <input type="number" name="pw_min_length" id="pwMinLen"
                                       class="stepper-input" min="6" max="32"
                                       value="<?php echo (int)$cfg['pw_min_length']; ?>">
                                <button type="button" class="stepper-btn" onclick="adjustVal('pwMinLen',1,6,32)">+</button>
                            </div>
                            <div class="stepper-range">min 6 · max 32</div>
                        </div>
                    </div>

                    <!-- Uppercase -->
                    <div class="rule-row">
                        <div class="rule-info">
                            <strong>Require Uppercase Letter</strong>
                            <div class="rule-desc">Password must contain at least one uppercase letter (A–Z).</div>
                        </div>
                        <div class="rule-ctrl">
                            <label class="toggle">
                                <input type="checkbox" name="pw_require_upper" id="toggleUpper"
                                       <?php echo $cfg['pw_require_upper'] === '1' ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Lowercase -->
                    <div class="rule-row">
                        <div class="rule-info">
                            <strong>Require Lowercase Letter</strong>
                            <div class="rule-desc">Password must contain at least one lowercase letter (a–z).</div>
                        </div>
                        <div class="rule-ctrl">
                            <label class="toggle">
                                <input type="checkbox" name="pw_require_lower" id="toggleLower"
                                       <?php echo $cfg['pw_require_lower'] === '1' ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Number -->
                    <div class="rule-row">
                        <div class="rule-info">
                            <strong>Require Number</strong>
                            <div class="rule-desc">Password must contain at least one digit (0–9).</div>
                        </div>
                        <div class="rule-ctrl">
                            <label class="toggle">
                                <input type="checkbox" name="pw_require_number" id="toggleNum"
                                       <?php echo $cfg['pw_require_number'] === '1' ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Special Character -->
                    <div class="rule-row">
                        <div class="rule-info">
                            <strong>Require Special Character</strong>
                            <div class="rule-desc">Password must contain at least one special character (e.g. !@#$%^&*).</div>
                        </div>
                        <div class="rule-ctrl">
                            <label class="toggle">
                                <input type="checkbox" name="pw_require_special" id="toggleSpec"
                                       <?php echo $cfg['pw_require_special'] === '1' ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Live Preview ── -->
            <div class="card">
                <div class="card-header">
                    <h3><span class="card-icon">👁️</span> Live Preview</h3>
                </div>
                <div class="card-body">
                    <div class="preview-box">
                        <h4>Password must satisfy:</h4>
                        <ul class="preview-list" id="previewList">
                            <li id="prev-len">
                                <span class="pdot pdot-on" id="dot-len"></span>
                                <span id="label-len">At least <?php echo (int)$cfg['pw_min_length']; ?> characters</span>
                            </li>
                            <li id="prev-upper" style="<?php echo $cfg['pw_require_upper'] !== '1' ? 'opacity:.4' : ''; ?>">
                                <span class="pdot <?php echo $cfg['pw_require_upper'] === '1' ? 'pdot-on' : 'pdot-off'; ?>" id="dot-upper"></span>
                                <span>Contains an uppercase letter (A–Z)</span>
                            </li>
                            <li id="prev-lower" style="<?php echo $cfg['pw_require_lower'] !== '1' ? 'opacity:.4' : ''; ?>">
                                <span class="pdot <?php echo $cfg['pw_require_lower'] === '1' ? 'pdot-on' : 'pdot-off'; ?>" id="dot-lower"></span>
                                <span>Contains a lowercase letter (a–z)</span>
                            </li>
                            <li id="prev-num" style="<?php echo $cfg['pw_require_number'] !== '1' ? 'opacity:.4' : ''; ?>">
                                <span class="pdot <?php echo $cfg['pw_require_number'] === '1' ? 'pdot-on' : 'pdot-off'; ?>" id="dot-num"></span>
                                <span>Contains a number (0–9)</span>
                            </li>
                            <li id="prev-spec" style="<?php echo $cfg['pw_require_special'] !== '1' ? 'opacity:.4' : ''; ?>">
                                <span class="pdot <?php echo $cfg['pw_require_special'] === '1' ? 'pdot-on' : 'pdot-off'; ?>" id="dot-spec"></span>
                                <span>Contains a special character (!@#$%^&* …)</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:12px; align-items:center;">
                <button type="submit" class="btn btn-primary">💾 Save Settings</button>
                <span class="text-muted text-small">Changes to login attempts take effect immediately.</span>
            </div>
        </form>
    </div>
</div>

<script src="../includes/ui_alerts.js"></script>
<script>
function adjustVal(id, delta, min, max) {
    const inp = document.getElementById(id);
    let v = parseInt(inp.value) + delta;
    v = Math.max(min, Math.min(max, v));
    inp.value = v;
    if (id === 'pwMinLen') updatePreview();
}

function updatePreview() {
    const len   = parseInt(document.getElementById('pwMinLen').value) || 8;
    const upper = document.getElementById('toggleUpper').checked;
    const lower = document.getElementById('toggleLower').checked;
    const num   = document.getElementById('toggleNum').checked;
    const spec  = document.getElementById('toggleSpec').checked;

    document.getElementById('label-len').textContent = 'At least ' + len + ' characters';
    setPrev('upper', upper);
    setPrev('lower', lower);
    setPrev('num',   num);
    setPrev('spec',  spec);
}

function setPrev(key, active) {
    const dot = document.getElementById('dot-' + key);
    const row = document.getElementById('prev-' + key);
    dot.className = active ? 'pdot pdot-on' : 'pdot pdot-off';
    row.style.opacity = active ? '1' : '0.4';
}

document.getElementById('pwMinLen').addEventListener('input', updatePreview);
document.getElementById('toggleUpper').addEventListener('change', updatePreview);
document.getElementById('toggleLower').addEventListener('change', updatePreview);
document.getElementById('toggleNum').addEventListener('change', updatePreview);
document.getElementById('toggleSpec').addEventListener('change', updatePreview);
</script>
</body>
</html>
