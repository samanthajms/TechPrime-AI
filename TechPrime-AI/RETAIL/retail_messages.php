<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header('Location: ../login.php');
    exit();
}

$db = getDbConnection();
$retailId = (int)$_SESSION['user_id'];

// --- 1. FETCH CUSTOMERS ---
$convQuery = "SELECT DISTINCT u.id, u.name 
              FROM users u
              INNER JOIN messages m ON (u.id = m.sender_id OR u.id = m.receiver_id)
              WHERE (m.sender_id = ? OR m.receiver_id = ?) 
              AND u.id != ?
              ORDER BY m.created_at DESC";

$stmt = $db->prepare($convQuery);
$stmt->bind_param("iii", $retailId, $retailId, $retailId);
$stmt->execute();
$contacts = $stmt->get_result();

$activeClientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$clientName = "Select a Customer";
$messages = [];

if ($activeClientId) {
    $nameStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $nameStmt->bind_param("i", $activeClientId);
    $nameStmt->execute();
    if($row = $nameStmt->get_result()->fetch_assoc()) $clientName = $row['name'];

    $msgQuery = "SELECT * FROM messages 
                 WHERE (sender_id = ? AND receiver_id = ?) 
                 OR (sender_id = ? AND receiver_id = ?) 
                 ORDER BY created_at ASC";
    $mStmt = $db->prepare($msgQuery);
    $mStmt->bind_param("iiii", $retailId, $activeClientId, $activeClientId, $retailId);
    $mStmt->execute();
    $messages = $mStmt->get_result();
}

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Messages',
    'active' => 'messages',
    'heading' => 'Customer Messages',
    'subtitle' => 'Reply to customer inquiries',
    'extra_head' => <<<'EXTRA'
<style>
.chat-container { display: flex; height: calc(100vh - 180px); min-height: 420px; }
.chat-card {
    background: #fff; border-radius: 12px; border: 1px solid var(--border);
    box-shadow: var(--shadow-sm, 0 4px 12px rgba(0,0,0,0.05));
    display: flex; width: 100%; overflow: hidden;
}
.contacts-column { width: 300px; border-right: 1px solid #eee; display: flex; flex-direction: column; flex-shrink: 0; }
.column-head {
    padding: 18px 20px; font-weight: 800; border-bottom: 1px solid #eee;
    font-size: 13px; background: var(--ep-green-light); color: var(--ep-green-dark);
    text-transform: uppercase; letter-spacing: 0.04em;
}
.contact-link {
    padding: 14px 20px; border-bottom: 1px solid #f5f5f5;
    text-decoration: none; color: #555; font-size: 14px; display: block; transition: 0.15s;
}
.contact-link:hover { background: var(--ep-green-light); }
.contact-link.active {
    background: var(--ep-green-light); border-left: 4px solid var(--ep-green);
    font-weight: 700; color: var(--ep-green-dark);
}
.chat-column { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat-header {
    padding: 15px 22px; border-bottom: 1px solid #eee;
    display: flex; align-items: center; justify-content: space-between; background: #fff;
}
.chat-messages {
    flex: 1; padding: 22px; overflow-y: auto; background: #fafafa;
    display: flex; flex-direction: column; gap: 12px;
}
.bubble {
    max-width: 70%; padding: 12px 16px; border-radius: 18px;
    font-size: 14px; line-height: 1.45; box-shadow: 0 2px 5px rgba(0,0,0,0.04);
}
.sent {
    align-self: flex-end; background: var(--ep-green); color: #fff;
    border-bottom-right-radius: 4px;
}
.received {
    align-self: flex-start; background: #f1f1f1; color: #444;
    border-bottom-left-radius: 4px;
}
.chat-footer { padding: 16px 20px; border-top: 1px solid #eee; background: #fff; }
.input-box {
    display: flex; gap: 12px; background: #f4f7f6; padding: 8px 14px;
    border-radius: 30px; border: 1px solid #eee;
}
.input-box input {
    flex: 1; border: none; background: transparent; outline: none;
    padding: 8px; font-family: inherit; font-size: 14px;
}
.send-btn {
    background: var(--ep-green); color: #fff; border: none;
    padding: 8px 22px; border-radius: 20px; cursor: pointer; font-weight: 700;
}
.send-btn:hover { background: var(--ep-green-dark); }
.chat-empty { margin: auto; text-align: center; color: #bbb; }
.chat-empty i { font-size: 48px; opacity: 0.4; margin-bottom: 10px; display: block; }
@media (max-width: 900px) {
    .chat-container { flex-direction: column; height: auto; }
    .contacts-column { width: 100%; max-height: 200px; border-right: none; border-bottom: 1px solid #eee; }
}
</style>
EXTRA
]);
?>

        <div class="chat-container">
            <div class="chat-card">
                <aside class="contacts-column">
                    <div class="column-head"><i class="fas fa-comments"></i> Customer Inquiries</div>
                    <div style="overflow-y: auto; flex: 1;">
                        <?php while($c = $contacts->fetch_assoc()): ?>
                            <a href="retail_messages.php?client_id=<?php echo $c['id']; ?>"
                               class="contact-link <?php echo ($activeClientId == $c['id']) ? 'active' : ''; ?>">
                                <?php echo h($c['name']); ?>
                            </a>
                        <?php endwhile; ?>
                        <?php if($contacts->num_rows == 0): ?>
                            <p style="padding: 20px; color: #bbb; font-size: 13px; text-align: center;">No conversations yet.</p>
                        <?php endif; ?>
                    </div>
                </aside>

                <section class="chat-column">
                    <div class="chat-header">
                        <span style="font-weight: 800; color: #333;"><?php echo $activeClientId ? h($clientName) : 'Select a Chat'; ?></span>
                        <?php if($activeClientId): ?>
                            <span style="font-size: 12px; color: var(--ep-green); font-weight: 700;">● Active Conversation</span>
                        <?php endif; ?>
                    </div>

                    <div class="chat-messages" id="chatWindow">
                        <?php if($activeClientId): ?>
                            <?php while($m = $messages->fetch_assoc()): ?>
                                <div class="bubble <?php echo ($m['sender_id'] == $retailId) ? 'sent' : 'received'; ?>">
                                    <?php echo h($m['message']); ?>
                                    <div style="font-size: 9px; margin-top: 5px; opacity: 0.7; text-align: right;">
                                        <?php echo date('h:i A', strtotime($m['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="chat-empty">
                                <i class="fas fa-envelope-open-text"></i>
                                <p style="font-weight: 600;">Pick a customer to start chatting</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if($activeClientId): ?>
                    <div class="chat-footer">
                        <form class="input-box" action="../backend/send_message.php" method="POST">
                            <input type="hidden" name="receiver_id" value="<?php echo $activeClientId; ?>">
                            <input type="text" name="message" placeholder="Type your reply here..." required autocomplete="off">
                            <button type="submit" class="send-btn">Send</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>

<?php staff_page_end(<<<'SCRIPTS'
<script>
const chatWindow = document.getElementById('chatWindow');
if (chatWindow) {
    chatWindow.scrollTop = chatWindow.scrollHeight;
}
</script>
SCRIPTS); ?>
