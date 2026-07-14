<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

$admin_id = (int)$_SESSION['user_id'];
$success  = '';
$error    = '';

$q = $db->prepare('SELECT name, surname, age, address, email, password FROM users WHERE id = ? LIMIT 1');
$q->bind_param('i', $admin_id);
$q->execute();
$admin = $q->get_result()->fetch_assoc();
$q->close();

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
            $upd->bind_param('ssisi', $name, $surname, $age, $address, $admin_id);
            if ($upd->execute()) {
                $_SESSION['name'] = $name; $_SESSION['surname'] = $surname;
                $admin['name'] = $name; $admin['surname'] = $surname;
                $admin['age'] = $age; $admin['address'] = $address;
                logActivity($db, $admin_id, 'profile_update', 'Admin updated their profile info');
                $success = 'Profile updated successfully.';
            } else {
                $error = 'Failed to update profile. Please try again.';
            }
            $upd->close();
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
            $upd->bind_param('si', $hash, $admin_id);
            if ($upd->execute()) {
                $admin['password'] = $hash;
                logActivity($db, $admin_id, 'password_change', 'Admin changed their password');
                $success = 'Password changed successfully.';
            } else {
                $error = 'Failed to update password. Please try again.';
            }
            $upd->close();
        }
    }
}

$adminInitials = strtoupper(substr($admin['name'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — EasyPC Admin</title>
    <link rel="stylesheet" href="admin_shared.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
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
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/easypc-logo-transparent.png" alt="EasyPC" class="ep-logo-img brand-logo">
        <div>
            <div class="brand-text">EasyPC</div>
            <div class="brand-sub">Admin</div>
        </div>
    </div>
    <nav>
        <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
        <a href="manage_users.php"><i class="fas fa-users"></i><span>Manage Users</span></a>
        <a href="view_logs.php"><i class="fas fa-clipboard-list"></i><span>Activity Logs</span></a>
        <a href="admin_profile.php" class="active"><i class="fas fa-user"></i><span>My Profile</span></a>
        <a href="admin_settings.php"><i class="fas fa-cog"></i><span>Settings</span></a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h2>My Profile</h2>
            <div class="breadcrumb">Manage your admin account details</div>
        </div>
        <div class="topbar-right">
            <div class="admin-badge">
                <div class="avatar"><?php echo $adminInitials; ?></div>
                <?php echo h($admin['name']); ?>
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
    </div>
</div>

<script src="../includes/ui_alerts.js"></script>
</body>
</html>
