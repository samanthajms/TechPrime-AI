<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];
$success  = '';
$error    = '';

// Ensure settings table & default rows exist (idempotent)
$db->query("
    CREATE TABLE IF NOT EXISTS site_settings (
        id SERIAL PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        updated_by INTEGER DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
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
    $ins = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO NOTHING");
    $ins->execute([$k, $v]);
}

function getSettings($db) {
    $res = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $out = [];
    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
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
        if (!$upd->execute([$v, $admin_id, $k])) { $ok = false; }
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

$pwToggles = [
    ['key' => 'upper', 'id' => 'toggleUpper', 'name' => 'pw_require_upper', 'title' => 'Require uppercase letter', 'desc' => 'At least one uppercase letter (A–Z).', 'preview' => 'Contains an uppercase letter (A–Z)'],
    ['key' => 'lower', 'id' => 'toggleLower', 'name' => 'pw_require_lower', 'title' => 'Require lowercase letter', 'desc' => 'At least one lowercase letter (a–z).', 'preview' => 'Contains a lowercase letter (a–z)'],
    ['key' => 'num', 'id' => 'toggleNum', 'name' => 'pw_require_number', 'title' => 'Require number', 'desc' => 'At least one digit (0–9).', 'preview' => 'Contains a number (0–9)'],
    ['key' => 'spec', 'id' => 'toggleSpec', 'name' => 'pw_require_special', 'title' => 'Require special character', 'desc' => 'At least one special character (e.g. !@#$%^&*).', 'preview' => 'Contains a special character (!@#$%^&* …)'],
];

staff_page_start([
    'role' => 'admin',
    'title' => 'Settings',
    'active' => 'settings',
    'heading' => 'Security Settings',
    'subtitle' => 'Configure authentication and password policies',
]);
?>

        <?php if ($success): ?>
            <div class="alert alert-success" role="status"><i class="ph-fill ph-check-circle" aria-hidden="true"></i><span><?php echo h($success); ?></span></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><i class="ph-fill ph-warning-circle" aria-hidden="true"></i><span><?php echo h($error); ?></span></div>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="ph ph-info" aria-hidden="true"></i>
            <span>Password complexity changes apply to <strong>new registrations only</strong>. The failed login attempts limit applies <strong>immediately</strong> for all users.</span>
        </div>

        <form method="post" id="settingsForm" class="settings-layout">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

            <div class="settings-main">
                <!-- Failed login attempts -->
                <section class="cx-card" aria-labelledby="loginSecTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="loginSecTitle"><i class="ph ph-shield-check" aria-hidden="true"></i> Login security</h2>
                            <p>How many failed attempts are allowed before an account is locked</p>
                        </div>
                    </div>
                    <div class="rule-list">
                        <div class="rule-row">
                            <div class="rule-info">
                                <label for="maxAttempts">Failed attempts before lockout</label>
                                <span class="rule-desc" id="maxAttemptsHint">After this many consecutive wrong passwords the account is locked until an admin unlocks it. 1 is strictest, 10 most lenient; 3–5 is recommended.</span>
                            </div>
                            <div class="rule-ctrl">
                                <div class="num-stepper">
                                    <button type="button" class="stepper-btn" onclick="adjustVal('maxAttempts',-1,1,10)" aria-label="Fewer attempts"><i class="ph ph-minus" aria-hidden="true"></i></button>
                                    <input type="number" name="max_failed_attempts" id="maxAttempts"
                                           class="stepper-input" min="1" max="10" inputmode="numeric" aria-describedby="maxAttemptsHint"
                                           value="<?php echo (int)$cfg['max_failed_attempts']; ?>">
                                    <button type="button" class="stepper-btn" onclick="adjustVal('maxAttempts',1,1,10)" aria-label="More attempts"><i class="ph ph-plus" aria-hidden="true"></i></button>
                                </div>
                                <span class="stepper-range">1 – 10</span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Password complexity -->
                <section class="cx-card" aria-labelledby="pwTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="pwTitle"><i class="ph ph-key" aria-hidden="true"></i> Password complexity</h2>
                            <p>Rules new users must meet when registering or changing passwords</p>
                        </div>
                    </div>
                    <div class="rule-list">
                        <div class="rule-row">
                            <div class="rule-info">
                                <label for="pwMinLen">Minimum password length</label>
                                <span class="rule-desc" id="pwMinLenHint">How many characters a password must have at minimum.</span>
                            </div>
                            <div class="rule-ctrl">
                                <div class="num-stepper">
                                    <button type="button" class="stepper-btn" onclick="adjustVal('pwMinLen',-1,6,32)" aria-label="Shorter minimum"><i class="ph ph-minus" aria-hidden="true"></i></button>
                                    <input type="number" name="pw_min_length" id="pwMinLen"
                                           class="stepper-input" min="6" max="32" inputmode="numeric" aria-describedby="pwMinLenHint"
                                           value="<?php echo (int)$cfg['pw_min_length']; ?>">
                                    <button type="button" class="stepper-btn" onclick="adjustVal('pwMinLen',1,6,32)" aria-label="Longer minimum"><i class="ph ph-plus" aria-hidden="true"></i></button>
                                </div>
                                <span class="stepper-range">6 – 32</span>
                            </div>
                        </div>
                        <?php foreach ($pwToggles as $t): ?>
                        <div class="rule-row">
                            <div class="rule-info">
                                <label for="<?php echo h($t['id']); ?>"><?php echo h($t['title']); ?></label>
                                <span class="rule-desc"><?php echo h($t['desc']); ?></span>
                            </div>
                            <div class="rule-ctrl">
                                <label class="toggle">
                                    <input type="checkbox" name="<?php echo h($t['name']); ?>" id="<?php echo h($t['id']); ?>"
                                           <?php echo $cfg[$t['name']] === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider" aria-hidden="true"></span>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <aside class="settings-side">
                <!-- Live preview -->
                <section class="cx-card preview-card" aria-labelledby="previewTitle">
                    <div class="cx-card-head">
                        <div>
                            <h2 id="previewTitle"><i class="ph ph-eye" aria-hidden="true"></i> Live preview</h2>
                            <p>What a new password must satisfy</p>
                        </div>
                    </div>
                    <ul class="preview-list" id="previewList">
                        <li id="prev-len" class="is-on">
                            <i class="ph-fill ph-check-circle" id="dot-len" aria-hidden="true"></i>
                            <span id="label-len">At least <?php echo (int)$cfg['pw_min_length']; ?> characters</span>
                            <span class="preview-state">Required</span>
                        </li>
                        <?php foreach ($pwToggles as $t): $on = $cfg[$t['name']] === '1'; ?>
                        <li id="prev-<?php echo h($t['key']); ?>" class="<?php echo $on ? 'is-on' : 'is-off'; ?>">
                            <i class="<?php echo $on ? 'ph-fill ph-check-circle' : 'ph ph-minus-circle'; ?>" id="dot-<?php echo h($t['key']); ?>" aria-hidden="true"></i>
                            <span><?php echo h($t['preview']); ?></span>
                            <span class="preview-state"><?php echo $on ? 'Required' : 'Not required'; ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="settings-save">
                        <button type="submit" class="cx-btn cx-btn-primary cx-btn-lg"><i class="ph ph-floppy-disk" aria-hidden="true"></i> Save settings</button>
                        <span class="settings-save-hint">Changes to login attempts take effect immediately.</span>
                    </div>
                </section>
            </aside>
        </form>

<?php
staff_page_end(<<<'SCRIPTS'
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

// Required / not required is shown by icon + text, not by colour alone
function setPrev(key, active) {
    const dot = document.getElementById('dot-' + key);
    const row = document.getElementById('prev-' + key);
    dot.className = active ? 'ph-fill ph-check-circle' : 'ph ph-minus-circle';
    row.className = active ? 'is-on' : 'is-off';
    row.querySelector('.preview-state').textContent = active ? 'Required' : 'Not required';
}

document.getElementById('pwMinLen').addEventListener('input', updatePreview);
document.getElementById('toggleUpper').addEventListener('change', updatePreview);
document.getElementById('toggleLower').addEventListener('change', updatePreview);
document.getElementById('toggleNum').addEventListener('change', updatePreview);
document.getElementById('toggleSpec').addEventListener('change', updatePreview);
</script>
SCRIPTS);
