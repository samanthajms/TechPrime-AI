<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/staff_layout.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header("Location: ../login.php"); exit;
}

$db = getDbConnection();
$retailId = (int)$_SESSION['user_id'];
$message = "";
$messageType = "success";

// --- HANDLE UPDATES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $newName = trim($_POST['shop_name'] ?? '');

        if ($newName === '') {
            $message = "Display name is required.";
            $messageType = "error";
        } else {
            $stmt = $db->prepare("UPDATE users SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $newName, $retailId);
            if ($stmt->execute()) {
            $_SESSION['name'] = $newName;
            $message = "Profile updated successfully!";
            } else {
            $message = "Could not update profile.";
            $messageType = "error";
            }
            $stmt->close();
        }
    }

    if (isset($_POST['update_password'])) {
        $newPassword = (string)($_POST['new_password'] ?? '');

        if ($newPassword === '') {
            $message = "Please enter a new password.";
            $messageType = "error";
        } elseif (!isPasswordComplex($newPassword, $db)) {
            $message = "Password does not meet the site's complexity requirements.";
            $messageType = "error";
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $passwordHash, $retailId);
            if ($stmt->execute()) {
                $message = "Password updated successfully!";
            } else {
                $message = "Could not update password.";
                $messageType = "error";
            }
            $stmt->close();
        }
    }
}

// --- FETCH CURRENT DATA ---
$stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt->bind_param("i", $retailId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Settings',
    'active' => 'settings',
    'heading' => 'Shop Settings',
    'subtitle' => 'Manage your shop profile and security',
    'extra_head' => <<<'EXTRA'
<style>
.settings-wrap { max-width: 720px; display: flex; flex-direction: column; gap: 20px; }
</style>
EXTRA
]);
?>

        <div class="settings-wrap">
            <?php if($message): ?>
                <div class="alert <?php echo $messageType === 'error' ? 'alert-error' : 'alert-success'; ?>"><?php echo h($message); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-header">
                        <div>
                            <h3><span class="card-icon"><i class="fas fa-store"></i></span> Shop Profile</h3>
                            <div class="card-subtitle">Your public shop display name</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Email Address (Cannot change)</label>
                            <input type="text" class="form-control" value="<?php echo h($user['email']); ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Shop Display Name</label>
                            <input type="text" name="shop_name" class="form-control" value="<?php echo h($user['name']); ?>" required>
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3><span class="card-icon"><i class="fas fa-lock"></i></span> Security</h3>
                            <div class="card-subtitle">Update your account password</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current password">
                        </div>
                        <button type="submit" name="update_password" class="btn btn-primary">Update Password</button>
                    </div>
                </div>
            </form>
        </div>

<?php staff_page_end(); ?>
