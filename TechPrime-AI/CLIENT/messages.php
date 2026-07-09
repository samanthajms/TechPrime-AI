<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$db     = getDbConnection();
$userId = (int)$_SESSION['user_id'];

$isLoggedIn  = true;
$activePage  = 'easyfix';
$pageTitle   = 'EasyFix Support';
$searchQuery = '';
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];

$convQuery = "SELECT DISTINCT u.id, u.name
              FROM users u
              INNER JOIN messages m ON (u.id = m.sender_id OR u.id = m.receiver_id)
              WHERE (m.sender_id = ? OR m.receiver_id = ?)
              AND u.id != ?
              ORDER BY m.created_at DESC";
$stmt = $db->prepare($convQuery);
$stmt->bind_param('iii', $userId, $userId, $userId);
$stmt->execute();
$contacts = $stmt->get_result();

$activeContactId = isset($_GET['contact_id']) ? (int)$_GET['contact_id'] : null;
$chatPartnerName = 'Select a Chat';
$messages        = [];

if ($activeContactId) {
    $nameStmt = $db->prepare('SELECT name FROM users WHERE id = ?');
    $nameStmt->bind_param('i', $activeContactId);
    $nameStmt->execute();
    if ($row = $nameStmt->get_result()->fetch_assoc()) {
        $chatPartnerName = $row['name'];
    }

    $mStmt = $db->prepare(
        "SELECT * FROM messages
         WHERE (sender_id = ? AND receiver_id = ?)
            OR (sender_id = ? AND receiver_id = ?)
         ORDER BY created_at ASC"
    );
    $mStmt->bind_param('iiii', $userId, $activeContactId, $activeContactId, $userId);
    $mStmt->execute();
    $messages = $mStmt->get_result();
}
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner">

        <div class="ep-page-header-row">
            <button class="ep-back-btn" onclick="location.href='index.php'">
                <i class="fas fa-arrow-left"></i> Back to Home
            </button>
            <h2 class="ep-page-title">EasyFix Support</h2>
        </div>

        <div class="ep-messaging-layout">
            <!-- Contacts sidebar -->
            <aside class="ep-contacts-list">
                <div class="ep-contacts-title"><i class="fas fa-comments"></i> Chats</div>
                <?php if ($contacts && $contacts->num_rows > 0): ?>
                    <?php while ($c = $contacts->fetch_assoc()): ?>
                        <a href="messages.php?contact_id=<?php echo (int)$c['id']; ?>"
                           class="ep-contact-item <?php echo ($activeContactId == $c['id']) ? 'active' : ''; ?>">
                            <div class="ep-contact-avatar"><?php echo strtoupper(substr(h($c['name']), 0, 1)); ?></div>
                            <strong><?php echo h($c['name']); ?></strong>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="ep-contacts-empty">No messages yet.</div>
                <?php endif; ?>
            </aside>

            <!-- Chat window -->
            <section class="ep-chat-window">
                <div class="ep-chat-header">
                    <div class="ep-chat-partner-avatar">
                        <?php echo strtoupper(substr(h($chatPartnerName), 0, 1)); ?>
                    </div>
                    <span><?php echo h($chatPartnerName); ?></span>
                </div>

                <div class="ep-message-stream" id="epMsgStream">
                    <?php if ($activeContactId): ?>
                        <?php while ($m = $messages->fetch_assoc()): ?>
                            <div class="ep-msg-bubble <?php echo ($m['sender_id'] == $userId) ? 'ep-sent' : 'ep-received'; ?>">
                                <?php echo h($m['message']); ?>
                                <span class="ep-msg-time"><?php echo date('g:i a', strtotime($m['created_at'])); ?></span>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="ep-chat-placeholder">
                            <i class="fas fa-comment-dots" style="font-size:48px;color:#ccc;"></i>
                            <p>Select a contact to start chatting</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($activeContactId): ?>
                    <form class="ep-chat-input-form" action="../backend/send_message.php" method="POST">
                        <input type="hidden" name="receiver_id" value="<?php echo (int)$activeContactId; ?>">
                        <input type="text" name="message" placeholder="Type a message..." required autocomplete="off">
                        <button type="submit" class="ep-send-btn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                <?php endif; ?>
            </section>
        </div>

    </div>
</main>

<style>
/* ── Messages-specific overrides ─────────────────────────────────────── */
.ep-messaging-layout {
    display: flex;
    gap: 0;
    border: 1.5px solid #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
    background: #fff;
    min-height: 560px;
    margin-top: 20px;
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
}
.ep-contacts-list {
    width: 240px;
    flex-shrink: 0;
    border-right: 1.5px solid #f0f0f0;
    display: flex;
    flex-direction: column;
}
.ep-contacts-title {
    padding: 18px 18px 12px;
    font-weight: 800;
    font-size: 14px;
    color: #1a1a1a;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ep-contact-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 16px;
    text-decoration: none;
    color: #333;
    font-size: 14px;
    border-bottom: 1px solid #f7f7f7;
    transition: background .15s;
}
.ep-contact-item:hover { background: #f4fbfc; }
.ep-contact-item.active { background: #e8f8f9; color: #0998a8; }
.ep-contact-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: #0998a8;
    color: #eaf41f;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 13px;
    flex-shrink: 0;
}
.ep-contacts-empty { padding: 24px 16px; color: #aaa; font-size: 13px; }
.ep-chat-window {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.ep-chat-header {
    padding: 16px 20px;
    border-bottom: 1.5px solid #f0f0f0;
    font-weight: 700;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fafafa;
}
.ep-chat-partner-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: #0998a8;
    color: #eaf41f;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 14px;
}
.ep-message-stream {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: #f9fafb;
}
.ep-msg-bubble {
    max-width: 68%;
    padding: 10px 14px;
    border-radius: 16px;
    font-size: 14px;
    line-height: 1.5;
    position: relative;
}
.ep-sent {
    background: #0998a8;
    color: #fff;
    align-self: flex-end;
    border-bottom-right-radius: 4px;
}
.ep-received {
    background: #fff;
    color: #333;
    align-self: flex-start;
    border: 1px solid #e5e7eb;
    border-bottom-left-radius: 4px;
}
.ep-msg-time {
    display: block;
    font-size: 10px;
    opacity: .6;
    margin-top: 4px;
    text-align: right;
}
.ep-chat-placeholder {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: #aaa;
    font-size: 14px;
}
.ep-chat-input-form {
    padding: 14px 16px;
    border-top: 1.5px solid #f0f0f0;
    display: flex;
    gap: 10px;
    background: #fff;
}
.ep-chat-input-form input {
    flex: 1;
    padding: 11px 16px;
    border: 1.5px solid #e5e7eb;
    border-radius: 100px;
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: border-color .15s;
}
.ep-chat-input-form input:focus { border-color: #0998a8; }
.ep-send-btn {
    width: 44px; height: 44px;
    background: #0998a8;
    color: #eaf41f;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.ep-send-btn:hover { background: #077a87; }
</style>

<script>
    // Auto-scroll to bottom of messages
    document.addEventListener('DOMContentLoaded', function() {
        const stream = document.getElementById('epMsgStream');
        if (stream) stream.scrollTop = stream.scrollHeight;
    });
</script>

<?php include __DIR__ . '/ep_footer.php'; ?>