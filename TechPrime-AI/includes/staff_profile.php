<?php
/**
 * Shared "My Profile" page for staff roles (admin / retail_officer / inventory_custodian / cashier).
 *
 * The calling page does the guards first (checkSessionTimeout() + checkRole()),
 * then calls staff_profile_page($db, $role), which handles the POST and renders the page.
 * Requires security.php, database.php and staff_layout.php to be loaded.
 */

if (!function_exists('staff_profile_format_ts')) {
    /** users/logs timestamps hold UTC; display them in Manila time. */
    function staff_profile_format_ts(?string $ts, string $format): string
    {
        if ($ts === null || trim($ts) === '') {
            return '';
        }
        try {
            $dt = new DateTimeImmutable($ts, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('staff_profile_save_avatar')) {
    /**
     * Save an uploaded profile picture as assets/profiles/u{id}.{ext} (same rules as the client avatar,
     * plus the chat attachment content checks). Returns an error message, or '' on success.
     */
    function staff_profile_save_avatar(PDO $db, int $userId, array $file): string
    {
        require_once __DIR__ . '/staff_chat_lib.php';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($err === UPLOAD_ERR_OK && filesize($tmp) > 2 * 1024 * 1024)) {
            return 'Image must be 2 MB or smaller.';
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            return 'Upload failed. Please try again.';
        }
        $mime = (string)((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '');
        if (!isset($allowed[$mime])) {
            return 'Only JPG, PNG, WEBP, or GIF images are allowed.';
        }
        $ext = $allowed[$mime];
        $problem = staff_chat_inspect_file($tmp, $ext);
        if ($problem !== '') {
            return $problem;
        }
        $dir = dirname(__DIR__) . '/assets/profiles';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return 'Could not save the image.';
        }
        $rel = 'profiles/u' . $userId . '.' . $ext;
        foreach ($allowed as $oldExt) {
            $old = $dir . '/u' . $userId . '.' . $oldExt;
            if ($oldExt !== $ext && is_file($old)) {
                @unlink($old);
            }
        }
        if (!move_uploaded_file($tmp, dirname(__DIR__) . '/assets/' . $rel)) {
            return 'Could not save the image.';
        }
        $db->prepare('UPDATE users SET profile_image = ? WHERE id = ?')->execute([$rel, $userId]);
        return '';
    }
}

if (!function_exists('staff_profile_page')) {
    function staff_profile_page(PDO $db, string $role): void
    {
        $userId    = (int)$_SESSION['user_id'];
        $roleLabel = staff_role_label($role);
        $success   = '';
        $error     = '';
        $errorForm = '';

        $q = $db->prepare('SELECT name, surname, age, address, email, password, created_at, profile_image FROM users WHERE id = ? LIMIT 1');
        $q->execute([$userId]);
        $user = $q->fetch(PDO::FETCH_ASSOC);
        $formValues = $user;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                die('Invalid CSRF token.');
            }

            $form = $_POST['form'] ?? '';

            if ($form === 'profile') {
                $name    = trim($_POST['name']    ?? '');
                $surname = trim($_POST['surname'] ?? '');
                $age     = (int)($_POST['age']    ?? 0);
                $address = trim($_POST['address'] ?? '');

                if ($name === '' || $surname === '' || $age < 13 || $address === '') {
                    $error = 'Please fill in all required fields. Age must be 13 or older.';
                    $errorForm = 'profile';
                    /* keep what the user typed so they can fix it */
                    $formValues = array_merge($formValues, [
                        'name' => $name, 'surname' => $surname,
                        'age' => $age > 0 ? $age : '', 'address' => $address,
                    ]);
                } else {
                    $upd = $db->prepare('UPDATE users SET name = ?, surname = ?, age = ?, address = ? WHERE id = ?');
                    if ($upd->execute([$name, $surname, $age, $address, $userId])) {
                        $_SESSION['name'] = $name; $_SESSION['surname'] = $surname;
                        $user['name'] = $name; $user['surname'] = $surname;
                        $user['age'] = $age; $user['address'] = $address;
                        $formValues = $user;
                        logActivity($db, $userId, 'profile_update', $roleLabel . ' updated their profile info');
                        $success = 'Profile updated successfully.';
                    } else {
                        $error = 'Failed to update profile. Please try again.';
                        $errorForm = 'profile';
                    }
                }
            }

            if ($form === 'avatar') {
                $avatarError = staff_profile_save_avatar($db, $userId, $_FILES['avatar'] ?? []);
                if ($avatarError === '') {
                    $q->execute([$userId]);
                    $user = $q->fetch(PDO::FETCH_ASSOC);
                    logActivity($db, $userId, 'profile_update', $roleLabel . ' updated their profile picture');
                    $success = 'Profile picture updated.';
                } else {
                    $error = $avatarError;
                }
            }

            if ($form === 'avatar_remove') {
                foreach (['jpg', 'png', 'webp', 'gif'] as $oldExt) {
                    $old = dirname(__DIR__) . '/assets/profiles/u' . $userId . '.' . $oldExt;
                    if (is_file($old)) {
                        @unlink($old);
                    }
                }
                $db->prepare('UPDATE users SET profile_image = NULL WHERE id = ?')->execute([$userId]);
                $user['profile_image'] = null;
                logActivity($db, $userId, 'profile_update', $roleLabel . ' removed their profile picture');
                $success = 'Profile picture removed.';
            }

            if ($form === 'password') {
                $current = (string)($_POST['current_password'] ?? '');
                $new     = (string)($_POST['new_password']     ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');
                $errorForm = 'password';

                if ($current === '' || $new === '' || $confirm === '') {
                    $error = 'All password fields are required.';
                } elseif (!password_verify($current, $user['password'])) {
                    $error = 'Current password is incorrect.';
                } elseif ($new !== $confirm) {
                    $error = 'New passwords do not match.';
                } elseif (!isPasswordComplex($new, $db)) {
                    $error = 'New password does not meet complexity requirements.';
                } else {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $upd  = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
                    if ($upd->execute([$hash, $userId])) {
                        $user['password'] = $hash;
                        $errorForm = '';
                        logActivity($db, $userId, 'password_change', $roleLabel . ' changed their password');
                        $success = 'Password changed successfully.';
                    } else {
                        $error = 'Failed to update password. Please try again.';
                    }
                }
            }
        }

        /* Previous sign-in = the login before the current session */
        $prevLogin = '';
        try {
            $lq = $db->prepare("SELECT created_at FROM logs WHERE user_id = ? AND action = 'login_success' ORDER BY created_at DESC LIMIT 1 OFFSET 1");
            $lq->execute([$userId]);
            $prevLogin = (string)($lq->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $prevLogin = '';
        }

        $rules = getPasswordRules($db);
        $ruleItems = [
            'len'     => 'At least ' . (int)$rules['min_length'] . ' characters',
        ];
        if ($rules['require_upper'])   { $ruleItems['upper']   = 'One uppercase letter'; }
        if ($rules['require_lower'])   { $ruleItems['lower']   = 'One lowercase letter'; }
        if ($rules['require_number'])  { $ruleItems['number']  = 'One number'; }
        if ($rules['require_special']) { $ruleItems['special'] = 'One special character'; }

        $fullName     = trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''));
        $initials     = staff_user_initials((string)($user['name'] ?? ''), (string)($user['surname'] ?? ''));
        $memberSince  = staff_profile_format_ts($user['created_at'] ?? null, 'M j, Y');
        $prevLoginFmt = staff_profile_format_ts($prevLogin, 'M j, Y · g:i A');
        $csrf         = generateCsrfToken();
        require_once __DIR__ . '/staff_chat_lib.php';
        $avatarUrl    = staff_chat_avatar_url($user['profile_image'] ?? null);

        staff_page_start([
            'role' => $role,
            'title' => 'My Profile',
            'active' => 'profile',
            'heading' => 'My Profile',
            'subtitle' => 'Manage your account details and password',
            'extra_head' => <<<'EXTRA'
<style>
.sp-hero {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px 28px;
    padding: 24px 26px 24px 30px;
    overflow: hidden;
}
.sp-hero::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 5px;
    background: linear-gradient(180deg, var(--ep-green), var(--ep-green-dark));
}
.sp-hero-main { display: flex; align-items: center; gap: 18px; min-width: 0; }
.sp-hero-avatar {
    width: 76px; height: 76px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--ep-green) 0%, var(--ep-green-dark) 100%);
    color: #fff;
    font-size: 26px; font-weight: 800; letter-spacing: .02em;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 0 0 4px var(--ep-green-light), 0 8px 20px rgba(75, 139, 42, .28);
}
.sp-hero-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; display: block; }
.sp-avatar-wrap { position: relative; flex-shrink: 0; }
.sp-avatar-edit {
    position: absolute; right: -2px; bottom: -2px;
    width: 30px; height: 30px; border-radius: 50%;
    border: 2px solid #fff; background: var(--ep-black); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; cursor: pointer; transition: background .15s;
}
.sp-avatar-edit:hover, .sp-avatar-edit:focus-visible { background: var(--ep-green-dark); outline: none; }
.sp-avatar-remove {
    border: 0; background: none; padding: 0; margin-top: 8px; cursor: pointer;
    font-family: inherit; font-size: 12px; font-weight: 600; color: var(--text-muted); text-decoration: underline;
}
.sp-avatar-remove:hover { color: #b42318; }
.sp-hero-id { min-width: 0; }
.sp-hero-name {
    margin: 0;
    font-size: 22px; font-weight: 800; line-height: 1.2;
    color: var(--text-main);
    overflow-wrap: anywhere;
}
.sp-hero-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; margin-top: 8px; }
.sp-role-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 11px;
    border-radius: 999px;
    background: var(--ep-green-light);
    border: 1px solid var(--teal-light);
    color: var(--ep-green-dark);
    font-size: 12px; font-weight: 700;
}
.sp-hero-email {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--text-muted);
    overflow-wrap: anywhere;
}
.sp-hero-meta { display: flex; flex-wrap: wrap; gap: 10px; margin: 0; }
.sp-hero-meta > div {
    min-width: 150px;
    padding: 10px 14px;
    background: var(--slate-50);
    border: 1px solid var(--border);
    border-radius: 10px;
}
.sp-hero-meta dt {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
    color: var(--text-muted);
}
.sp-hero-meta dt i { color: var(--ep-green-dark); }
.sp-hero-meta dd { margin: 4px 0 0; font-size: 13.5px; font-weight: 700; color: var(--text-main); }

.sp-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
    gap: 22px;
    align-items: start;
    margin-bottom: 56px; /* clear the floating Messages button */
}
.sp-card { padding: 0; margin-bottom: 0; overflow: hidden; }
.sp-card .card-header { padding: 18px 22px; }
.sp-card .card-subtitle { margin-top: 4px; }
.sp-card .card-body { padding: 22px 22px 0; }
.sp-row-age { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 16px; }
.sp-req { color: #dc2626; margin-left: 2px; }

.sp-input-wrap { position: relative; }
.sp-input-wrap > .sp-input-icon {
    position: absolute; left: 13px; top: 50%;
    transform: translateY(-50%);
    color: var(--slate-400); font-size: 13px;
    pointer-events: none;
}
.sp-input-wrap.has-icon .form-control { padding-left: 36px; }
.sp-input-wrap.has-eye .form-control { padding-right: 44px; }
.sp-readonly .form-control {
    background: var(--slate-50);
    color: var(--text-muted);
    cursor: not-allowed;
}
.sp-eye {
    position: absolute; right: 6px; top: 50%;
    transform: translateY(-50%);
    width: 32px; height: 32px;
    border: 0; border-radius: 6px;
    background: transparent;
    color: var(--slate-400);
    cursor: pointer;
    transition: background .15s, color .15s;
}
.sp-eye:hover, .sp-eye:focus-visible { background: var(--ep-green-light); color: var(--ep-green-dark); outline: none; }

.sp-rules {
    list-style: none;
    margin: -8px 0 18px;
    padding: 12px 14px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 7px 12px;
    background: var(--slate-50);
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 12px;
    color: var(--text-muted);
}
.sp-rules li { display: flex; align-items: center; gap: 7px; transition: color .15s; }
.sp-rules li i { font-size: 12px; color: var(--slate-300); transition: color .15s; }
.sp-rules li.ok { color: var(--ep-green-dark); font-weight: 600; }
.sp-rules li.ok i { color: var(--ep-green); }
.sp-match { min-height: 16px; margin-top: 6px; font-size: 12px; font-weight: 600; }
.sp-match.ok { color: var(--ep-green-dark); }
.sp-match.bad { color: #b42318; }

.sp-actions {
    display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px;
    margin: 4px -22px 0;
    padding: 14px 22px;
    background: var(--slate-50);
    border-top: 1px solid var(--border);
}
.sp-actions .btn:disabled { opacity: .55; cursor: not-allowed; transform: none; box-shadow: none; }

@media (max-width: 1100px) {
    .sp-grid { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 720px) {
    .sp-hero { padding: 20px 18px 20px 22px; }
    .sp-hero-avatar { width: 60px; height: 60px; font-size: 21px; }
    .sp-hero-name { font-size: 19px; }
    .sp-hero-meta { width: 100%; }
    .sp-hero-meta > div { flex: 1 1 140px; }
    .sp-row-age { grid-template-columns: minmax(0, 1fr); gap: 0; }
    .sp-rules { grid-template-columns: minmax(0, 1fr); }
}
</style>
EXTRA
        ]);
        ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo h($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-times-circle"></i> <?php echo h($error); ?></div>
        <?php endif; ?>

        <section class="card sp-hero" aria-label="Account summary">
            <div class="sp-hero-main">
                <div class="sp-avatar-wrap">
                    <div class="sp-hero-avatar" aria-hidden="true"><?php if ($avatarUrl !== ''): ?><img src="<?php echo h($avatarUrl); ?>" alt=""><?php else: ?><?php echo h($initials); ?><?php endif; ?></div>
                    <form method="post" enctype="multipart/form-data" id="spAvatarForm">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="form" value="avatar">
                        <input type="file" name="avatar" id="spAvatarInput" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                        <label for="spAvatarInput" class="sp-avatar-edit" tabindex="0" role="button" title="Change profile picture" aria-label="Change profile picture"><i class="fas fa-camera"></i></label>
                    </form>
                </div>
                <div class="sp-hero-id">
                    <h2 class="sp-hero-name"><?php echo h($fullName !== '' ? $fullName : 'User'); ?></h2>
                    <div class="sp-hero-tags">
                        <span class="sp-role-pill"><i class="fas fa-id-badge" aria-hidden="true"></i> <?php echo h($roleLabel); ?></span>
                        <span class="sp-hero-email"><i class="fas fa-envelope" aria-hidden="true"></i> <?php echo h((string)($user['email'] ?? '')); ?></span>
                    </div>
                    <?php if ($avatarUrl !== ''): ?>
                    <form method="post" id="spAvatarRemoveForm">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="form" value="avatar_remove">
                        <button type="submit" class="sp-avatar-remove">Remove photo</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <dl class="sp-hero-meta">
                <div>
                    <dt><i class="fas fa-calendar-alt" aria-hidden="true"></i> Member since</dt>
                    <dd><?php echo h($memberSince !== '' ? $memberSince : '—'); ?></dd>
                </div>
                <div>
                    <dt><i class="fas fa-history" aria-hidden="true"></i> Previous sign-in</dt>
                    <dd><?php echo h($prevLoginFmt !== '' ? $prevLoginFmt : 'First sign-in'); ?></dd>
                </div>
            </dl>
        </section>

        <div class="sp-grid">

            <section class="card sp-card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-user"></i></span> Personal Information</h3>
                        <div class="card-subtitle">Keep your name and contact details up to date</div>
                    </div>
                </div>
                <form method="post" class="card-body" id="spProfileForm"
                      data-force-dirty="<?php echo $errorForm === 'profile' ? '1' : '0'; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="form" value="profile">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="spName">First Name<span class="sp-req" aria-hidden="true">*</span></label>
                            <input type="text" id="spName" name="name" class="form-control" value="<?php echo h((string)$formValues['name']); ?>" required autocomplete="given-name">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="spSurname">Surname<span class="sp-req" aria-hidden="true">*</span></label>
                            <input type="text" id="spSurname" name="surname" class="form-control" value="<?php echo h((string)$formValues['surname']); ?>" required autocomplete="family-name">
                        </div>
                    </div>
                    <div class="sp-row-age">
                        <div class="form-group">
                            <label class="form-label" for="spAge">Age<span class="sp-req" aria-hidden="true">*</span></label>
                            <input type="number" id="spAge" name="age" class="form-control" min="13" value="<?php echo h((string)$formValues['age']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="spAddress">Address<span class="sp-req" aria-hidden="true">*</span></label>
                            <input type="text" id="spAddress" name="address" class="form-control" value="<?php echo h((string)$formValues['address']); ?>" required autocomplete="street-address">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="spEmail">Email</label>
                        <div class="sp-input-wrap has-icon sp-readonly">
                            <i class="fas fa-lock sp-input-icon" aria-hidden="true"></i>
                            <input type="email" id="spEmail" class="form-control" value="<?php echo h((string)($user['email'] ?? '')); ?>" disabled aria-describedby="spEmailHint">
                        </div>
                        <div class="form-hint" id="spEmailHint">Email cannot be changed here.</div>
                    </div>

                    <div class="sp-actions">
                        <button type="reset" class="btn btn-outline" id="spProfileReset"><i class="fas fa-undo"></i> Reset</button>
                        <button type="submit" class="btn btn-primary" id="spProfileSave"><i class="fas fa-save"></i> Save Changes</button>
                    </div>
                </form>
            </section>

            <section class="card sp-card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-key"></i></span> Change Password</h3>
                        <div class="card-subtitle">Use a strong password you don't use anywhere else</div>
                    </div>
                </div>
                <form method="post" class="card-body" id="spPasswordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="form" value="password">

                    <div class="form-group">
                        <label class="form-label" for="spCurrentPw">Current Password</label>
                        <div class="sp-input-wrap has-eye">
                            <input type="password" id="spCurrentPw" name="current_password" class="form-control" required autocomplete="current-password">
                            <button type="button" class="sp-eye" data-sp-toggle="spCurrentPw" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="spNewPw">New Password</label>
                        <div class="sp-input-wrap has-eye">
                            <input type="password" id="spNewPw" name="new_password" class="form-control" required autocomplete="new-password" aria-describedby="spRules">
                            <button type="button" class="sp-eye" data-sp-toggle="spNewPw" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <ul class="sp-rules" id="spRules" data-rules="<?php echo h(json_encode($rules)); ?>">
                        <?php foreach ($ruleItems as $key => $label): ?>
                        <li data-rule="<?php echo h($key); ?>"><i class="far fa-circle" aria-hidden="true"></i><span><?php echo h($label); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="form-group">
                        <label class="form-label" for="spConfirmPw">Confirm New Password</label>
                        <div class="sp-input-wrap has-eye">
                            <input type="password" id="spConfirmPw" name="confirm_password" class="form-control" required autocomplete="new-password" aria-describedby="spMatch">
                            <button type="button" class="sp-eye" data-sp-toggle="spConfirmPw" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye"></i></button>
                        </div>
                        <div class="sp-match" id="spMatch" aria-live="polite"></div>
                    </div>

                    <div class="sp-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-lock"></i> Update Password</button>
                    </div>
                </form>
            </section>

        </div>

        <?php
        $script = <<<'JS'
<script>
(function () {
    /* Profile picture: upload as soon as a file is picked (JPG/PNG/WEBP/GIF, max 2 MB) */
    var avInput = document.getElementById('spAvatarInput');
    var avForm = document.getElementById('spAvatarForm');
    if (avInput && avForm) {
        var avLabel = avForm.querySelector('.sp-avatar-edit');
        if (avLabel) {
            avLabel.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); avInput.click(); }
            });
        }
        avInput.addEventListener('change', function () {
            var f = avInput.files && avInput.files[0];
            if (!f) return;
            if (f.size > 2 * 1024 * 1024) {
                avInput.value = '';
                if (typeof IAS_UI !== 'undefined') IAS_UI.alert('Image must be 2 MB or smaller.', 'error');
                return;
            }
            avForm.submit();
        });
    }
    var avRemove = document.getElementById('spAvatarRemoveForm');
    if (avRemove) {
        avRemove.addEventListener('submit', function (e) {
            if (typeof IAS_UI === 'undefined') {
                if (!window.confirm('Remove your profile picture?')) e.preventDefault();
                return;
            }
            e.preventDefault();
            IAS_UI.confirm('Your initials will show instead.', {
                title: 'Remove your profile picture?', confirmLabel: 'Remove', type: 'danger'
            }).then(function (ok) { if (ok) avRemove.submit(); });
        });
    }

    document.querySelectorAll('[data-sp-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-sp-toggle'));
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            var icon = btn.querySelector('i');
            if (icon) icon.className = 'fas ' + (show ? 'fa-eye-slash' : 'fa-eye');
        });
    });

    /* Save/Reset only become active once something was edited */
    var pf = document.getElementById('spProfileForm');
    var save = document.getElementById('spProfileSave');
    var reset = document.getElementById('spProfileReset');
    if (pf && save && reset) {
        var forced = pf.getAttribute('data-force-dirty') === '1';
        var fields = pf.querySelectorAll('input[name]:not([type="hidden"])');
        var syncDirty = function () {
            var dirty = forced || Array.prototype.some.call(fields, function (f) {
                return f.value !== f.defaultValue;
            });
            save.disabled = !dirty;
            reset.disabled = !dirty;
        };
        pf.addEventListener('input', syncDirty);
        pf.addEventListener('reset', function () { forced = false; setTimeout(syncDirty, 0); });
        syncDirty();
    }

    /* Live password requirement checklist (same rules as isPasswordComplex) */
    var rulesEl = document.getElementById('spRules');
    var np = document.getElementById('spNewPw');
    var cp = document.getElementById('spConfirmPw');
    var match = document.getElementById('spMatch');
    if (rulesEl && np && cp && match) {
        var cfg = {};
        try { cfg = JSON.parse(rulesEl.getAttribute('data-rules') || '{}'); } catch (e) {}
        var tests = {
            len: function (v) { return v.length >= (parseInt(cfg.min_length, 10) || 8); },
            upper: function (v) { return /[A-Z]/.test(v); },
            lower: function (v) { return /[a-z]/.test(v); },
            number: function (v) { return /[0-9]/.test(v); },
            special: function (v) { return /[^A-Za-z0-9]/.test(v); }
        };
        var check = function () {
            var v = np.value;
            rulesEl.querySelectorAll('[data-rule]').forEach(function (li) {
                var fn = tests[li.getAttribute('data-rule')];
                var ok = fn ? fn(v) : false;
                li.classList.toggle('ok', ok);
                var icon = li.querySelector('i');
                if (icon) icon.className = ok ? 'fas fa-check-circle' : 'far fa-circle';
            });
            if (cp.value === '') {
                match.textContent = '';
                match.className = 'sp-match';
            } else if (cp.value === v) {
                match.textContent = 'Passwords match';
                match.className = 'sp-match ok';
            } else {
                match.textContent = 'Passwords do not match';
                match.className = 'sp-match bad';
            }
        };
        np.addEventListener('input', check);
        cp.addEventListener('input', check);
        check();
    }
})();
</script>
JS;
        staff_page_end($script);
    }
}
