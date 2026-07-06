<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}

$db     = getDbConnection();
$userId = (int)$_SESSION['user_id'];

$convQuery = "SELECT DISTINCT u.id, u.name
              FROM users u
              INNER JOIN messages m ON (u.id = m.sender_id OR u.id = m.receiver_id)
              WHERE (m.sender_id = ? OR m.receiver_id = ?)
              AND u.id != ?
              ORDER BY m.created_at DESC";

$stmt = $db->prepare($convQuery);
$stmt->bind_param("iii", $userId, $userId, $userId);
$stmt->execute();
$contacts = $stmt->get_result();

// FIX: Cast contact_id to int from URL
$activeContactId = isset($_GET['contact_id']) ? (int)$_GET['contact_id'] : null;
$chatPartnerName = "Select a Chat";
$messages        = [];

if ($activeContactId) {
    $nameStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $nameStmt->bind_param("i", $activeContactId);
    $nameStmt->execute();
    if ($row = $nameStmt->get_result()->fetch_assoc()) {
        // FIX: Escape name from DB before storing
        $chatPartnerName = $row['name'];
    }

    $msgQuery = "SELECT * FROM messages
                 WHERE (sender_id = ? AND receiver_id = ?)
                 OR (sender_id = ? AND receiver_id = ?)
                 ORDER BY created_at ASC";
    $mStmt = $db->prepare($msgQuery);
    $mStmt->bind_param("iiii", $userId, $activeContactId, $activeContactId, $userId);
    $mStmt->execute();
    $messages = $mStmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - IAS</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body>

    <header class="top-header">
        <a href="index.php" class="logo">IAS</a>
        <div class="search-wrap">
            <input type="text" placeholder="Search for messages...">
        </div>
        <div class="header-icons">
            <button class="icon-btn" onclick="location.href='index.php'">🏠</button>
            <button class="icon-btn" onclick="location.href='user_dashboard.php'">👤</button>
        </div>
    </header>

    <main class="main-container">
        <div class="back-nav-container">
            <a href="index.php" class="back-btn">
                <span>←</span> Back to Dashboard
            </a>
        </div>

        <div class="messaging-layout">
            <aside class="contacts-list">
                <div class="sidebar-title">Chats</div>
                <?php if($contacts && $contacts->num_rows > 0): ?>
                    <?php while($c = $contacts->fetch_assoc()): ?>
                        <!-- FIX: Cast contact id to int in URL, name wrapped with h() -->
                        <a href="messages.php?contact_id=<?php echo (int)$c['id']; ?>"
                           class="contact-item <?php echo ($activeContactId == $c['id']) ? 'active' : ''; ?>">
                            <strong><?php echo h($c['name']); ?></strong>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="empty-state">No messages yet.</p>
                <?php endif; ?>
            </aside>

            <section class="chat-window">
                <!-- FIX: Wrap chat partner name with h() -->
                <div class="chat-header"><?php echo h($chatPartnerName); ?></div>
                <div class="message-stream">
                    <?php if($activeContactId): ?>
                        <?php while($m = $messages->fetch_assoc()): ?>
                            <div class="msg-bubble <?php echo ($m['sender_id'] == $userId) ? 'sent' : 'received'; ?>">
                                <!-- FIX: Wrap message content with h() — critical, user-supplied text -->
                                <?php echo h($m['message']); ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">Select a contact to chat</div>
                    <?php endif; ?>
                </div>

                <?php if($activeContactId): ?>
                    <form class="chat-input-form" action="../backend/send_message.php" method="POST">
                        <!-- FIX: Cast receiver_id to int -->
                        <input type="hidden" name="receiver_id" value="<?php echo (int)$activeContactId; ?>">
                        <input type="text" name="message" placeholder="Type a message..." required>
                        <button type="submit" class="send-btn">Send</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <footer class="site-footer">
        © 2026 IAS E-Commerce Client Center. All Rights Reserved.
    </footer>
</body>
</html>