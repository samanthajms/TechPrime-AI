<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shop Settings | Easy PC Retail</title>
    <link rel="stylesheet" href="../retail.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --ias-teal: #0998a8; 
            --ias-gold: #f5f500; 
            --sidebar-gray: #6a969a; 
            --bg: #f4f7f6; 
        }

        html, body { height: 100%; margin: 0; }
        body { 
            display: flex; 
            flex-direction: column; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg); 
        }
        
        /* HEADER */
        .retail-header { 
            background: var(--ias-teal); 
            padding: 15px 30px; 
            border-bottom: 3px solid var(--ias-gold); 
        }
        .logo-text { color: var(--ias-gold); font-size: 24px; font-weight: 900; letter-spacing: 1px; }

        .retail-layout { display: flex; flex: 1; overflow: hidden; }

        /* SIDEBAR */
        .retail-sidebar { 
            background: var(--sidebar-gray); 
            width: 260px; 
            padding-top: 10px; 
            display: flex;
            flex-direction: column;
        }
        .sidebar-item { 
            background: transparent; 
            color: white; 
            border: none; 
            padding: 15px 25px; 
            width: 100%; 
            text-align: left; 
            font-size: 15px;
            font-weight: 600;
            cursor: pointer; 
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-item:hover, .sidebar-item.active { 
            background: rgba(0,0,0,0.1); 
            color: var(--ias-gold); 
        }
        .logout-btn { background: #b22222 !important; margin-top: auto; border-bottom: none; }

        /* MAIN CONTENT */
        .retail-main { padding: 30px; flex: 1; overflow-y: auto; }
        .settings-container { max-width: 800px; }
        
        .card { 
            background: white; 
            border-radius: 12px; 
            padding: 30px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
            margin-bottom: 25px; 
            border: 1px dashed var(--ias-teal);
        }
        .card h2 { font-size: 18px; margin-bottom: 20px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; font-weight: 800; }

        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 700; color: #666; margin-bottom: 8px; }
        input, textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-family: inherit; box-sizing: border-box; }
        input[readonly] { background: #f9f9f9; color: #aaa; cursor: not-allowed; }
        
        .btn-save { background: var(--ias-teal); color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.3s; }
        .btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
        
        .alert { padding: 15px; background: #e3faf3; color: #0ca678; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; border: 1px solid #0ca678; }
        .alert.error { background: #fff0f0; color: #c92a2a; border-color: #ff8787; }

        /* FOOTER */
        .ias-footer {
            background: var(--ias-teal);
            color: white;
            padding: 15px 30px;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>

<header class="retail-header">
    <div class="logo-text">EASY PC RETAIL</div>
</header>

<div class="retail-layout">
    <aside class="retail-sidebar">
        <button class="sidebar-item" onclick="location.href='retail_dashboard.php'">📊 Dashboard</button>
        <button class="sidebar-item" onclick="location.href='retail_products.php'">📦 My Products</button>
        <button class="sidebar-item" onclick="location.href='retail_orders.php'">📜 Orders</button>
        <button class="sidebar-item" onclick="location.href='retail_messages.php'">💬 Messages</button>
        <button class="sidebar-item" onclick="location.href='retail_reviews.php'">⭐ Reviews</button>
        <button class="sidebar-item active">⚙️ Settings</button>
        <button class="sidebar-item logout-btn" onclick="location.href='../logout.php'">🚪 Logout</button>
    </aside>

    <main class="retail-main">
        <div class="settings-container">
            <?php if($message): ?>
                <div class="alert <?php echo $messageType === 'error' ? 'error' : ''; ?>"><?php echo h($message); ?></div>
            <?php endif; ?>

            <form method="POST">
                <section class="card">
                    <h2>Shop Profile</h2>
                    <div class="form-group">
                        <label>Email Address (Cannot change)</label>
                        <input type="text" value="<?php echo h($user['email']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Shop Display Name</label>
                        <input type="text" name="shop_name" value="<?php echo h($user['name']); ?>" required>
                    </div>
                    <button type="submit" name="update_profile" class="btn-save">Save Changes</button>
                </section>

                <section class="card">
                    <h2>Security</h2>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" placeholder="Leave blank to keep current password">
                    </div>
                    <button type="submit" name="update_password" class="btn-save" style="background: #333;">Update Password</button>
                </section>
            </form>
        </div>
    </main>
</div>

<footer class="ias-footer">
    © 2026 Easy PC Retail Center. All Rights Reserved.
</footer>

</body>
</html>
