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

$q = $db->prepare('SELECT name, surname, age, address, email, password FROM users WHERE id = ? LIMIT 1');
$q->execute([$admin_id]);
$admin = $q->fetch(PDO::FETCH_ASSOC);
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
        } else {
            $upd = $db->prepare('UPDATE users SET name = ?, surname = ?, age = ?, address = ? WHERE id = ?');
            if ($upd->execute([$name, $surname, $age, $address, $admin_id])) {
                $_SESSION['name'] = $name; $_SESSION['surname'] = $surname;
                $admin['name'] = $name; $admin['surname'] = $surname;
                $admin['age'] = $age; $admin['address'] = $address;
                logActivity($db, $admin_id, 'profile_update', 'Admin updated their profile info');
                $success = 'Profile updated successfully.';
            } else {
                $error = 'Failed to update profile. Please try again.';
            }
        }
    }

    if ($form === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password']     ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            $error = 'All password fields are required.';
        } elseif (!password_verify($current, $admin['password'])) {
            $error = 'Current password is incorrect.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (!isPasswordComplex($new)) {
            $error = 'New password does not meet complexity requirements.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd  = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
            if ($upd->execute([$hash, $admin_id])) {
                $admin['password'] = $hash;
                logActivity($db, $admin_id, 'password_change', 'Admin changed their password');
                $success = 'Password changed successfully.';
            } else {
                $error = 'Failed to update password. Please try again.';
            }
        }
    }
}

$adminInitials = strtoupper(substr($admin['name'] ?? 'A', 0, 1));

staff_page_start([
    'role' => 'admin',
    'title' => 'My Profile',
    'active' => 'profile',
    'heading' => 'My Profile',
    'subtitle' => 'Manage your admin account details',
    'extra_head' => '<style>
        .profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media(max-width:768px){ .profile-grid { grid-template-columns: 1fr; } }
        .profile-avatar {
            width: 72px; height: 72px;
            background: var(--ep-green);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: 800; color: #fff;
            margin-bottom: 16px;
        }
    </style>',
]);
?>

        <?php if ($success): ?>
            <div class="alert alert-success">✔ <?php echo h($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">✘ <?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="profile-grid">

            <!-- Profile Info -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-user"></i></span> Profile Information</h3>
                        <div class="card-subtitle">Update your name and contact info</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="profile-avatar"><?php echo $adminInitials; ?></div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="form" value="profile">

                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="name" class="form-control" value="<?php echo h($admin['name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Surname</label>
                            <input type="text" name="surname" class="form-control" value="<?php echo h($admin['surname']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Age</label>
                            <input type="number" name="age" class="form-control" min="13" value="<?php echo (int)$admin['age']; ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" value="<?php echo h($admin['address']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?php echo h($admin['email']); ?>" disabled style="background:var(--slate-50);color:var(--text-muted);">
                            <div class="form-hint">Email cannot be changed here.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Profile</button>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-key"></i></span> Change Password</h3>
                        <div class="card-subtitle">Must meet current complexity requirements</div>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="form" value="password">

                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" required autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>
            </div>

        </div>

<?php staff_page_end(); ?>
