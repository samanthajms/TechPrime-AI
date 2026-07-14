<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header("Location: ../login.html");
    exit();
}

$db = getDbConnection();
$retailId = (int)$_SESSION['user_id'];

// --- 1. FETCH CUSTOMERS / CONVERSATIONS ---
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Messages | TechPrime AI</title>
    <link rel="stylesheet" href="../retail.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .chat-container { display: grid; grid-template-columns: 320px 1fr; gap: 24px; }
        .chat-card { display: flex; gap: 24px; min-height: 640px; }
        .contacts-column { display: flex; flex-direction: column; gap: 0; }
        .column-head { padding: 20px; background: #f7fafb; border-bottom: 1px solid var(--ias-border); font-weight: 800; color: var(--ias-teal); }
        .contact-link { display: block; padding: 16px 20px; border-bottom: 1px solid #f1f4f6; color: var(--ias-ink); transition: background 0.2s ease, color 0.2s ease; }
        .contact-link:hover { background: #f0fbfc; }
        .contact-link.active { background: rgba(9, 152, 168, 0.12); border-left: 4px solid var(--ias-teal); color: var(--ias-teal); font-weight: 700; }
        .chat-column { display: flex; flex-direction: column; min-height: 640px; }
        .chat-header { padding: 20px 24px; background: var(--ias-surface); border-bottom: 1px solid var(--ias-border); display: flex; justify-content: space-between; align-items: center; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 16px; background: #f9fbfc; }
        .bubble { max-width: 72%; padding: 16px 18px; border-radius: 22px; line-height: 1.6; box-shadow: 0 12px 28px rgba(22, 52, 73, 0.06); }
        .sent { align-self: flex-end; background: var(--ias-teal); color: white; border-bottom-right-radius: 4px; }
        .received { align-self: flex-start; background: white; color: var(--ias-ink); border-bottom-left-radius: 4px; }
        .chat-footer { padding: 18px 24px; background: var(--ias-surface); border-top: 1px solid var(--ias-border); }
        .input-box { display: flex; gap: 12px; align-items: center; background: #f4f7f6; padding: 14px 18px; border-radius: 999px; border: 1px solid var(--ias-border); }
        .input-box input { flex: 1; border: none; background: transparent; outline: none; padding: 8px 0; }
        .send-btn { min-width: 120px; }
        @media (max-width: 1100px) { .chat-container { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php $active = 'chatbot'; include __DIR__ . '/../includes/retail_shell.php'; ?>
    <main class="retail-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">Customer Messages</h1>
                <p class="page-subtitle">Respond to customers fast with a consistent retail chat workspace.</p>
            </div>
        </div>

        <div class="chat-container">
            <aside class="card contacts-column">
                <div class="column-head">Active Conversations</div>
                <div style="overflow-y:auto; flex:1;">
                    <?php while($c = $contacts->fetch_assoc()): ?>
                        <a href="retail_messages.php?client_id=<?php echo $c['id']; ?>" class="contact-link <?php echo ($activeClientId == $c['id']) ? 'active' : ''; ?>">
                            <?php echo h($c['name']); ?>
                        </a>
                    <?php endwhile; ?>
                    <?php if($contacts->num_rows == 0): ?>
                        <div style="padding: 24px; color: var(--ias-slate); text-align:center;">No conversations yet.</div>
                    <?php endif; ?>
                </div>
            </aside>

            <section class="card chat-column">
                <div class="chat-header">
                    <div>
                        <div style="font-weight:900; color: var(--ias-ink);"><?php echo $activeClientId ? h($clientName) : 'Select a Customer'; ?></div>
                        <?php if($activeClientId): ?><div style="font-size:13px; color: var(--ias-slate);">Active Conversation</div><?php endif; ?>
                    </div>
                </div>

                <div class="chat-messages" id="chatWindow">
                    <?php if($activeClientId): ?>
                        <?php while($m = $messages->fetch_assoc()): ?>
                            <div class="bubble <?php echo ($m['sender_id'] == $retailId) ? 'sent' : 'received'; ?>">
                                <?php echo h($m['message']); ?>
                                <div style="font-size: 11px; margin-top: 10px; opacity: 0.72; text-align: right;">
                                    <?php echo date('h:i A', strtotime($m['created_at'])); ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="margin:auto; text-align:center; color: var(--ias-slate);">
                            <div style="font-size: 56px;">📩</div>
                            <p style="margin-top:14px; font-weight:700;">Choose a customer to load the chat.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if($activeClientId): ?>
                    <div class="chat-footer">
                        <form class="input-box" action="../backend/send_message.php" method="POST">
                            <input type="hidden" name="receiver_id" value="<?php echo $activeClientId; ?>">
                            <input type="text" name="message" placeholder="Type your reply here..." required autocomplete="off">
                            <button type="submit" class="btn btn-primary send-btn">Send</button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<footer class="ias-footer">© 2026 TechPrime AI Retail Center. All Rights Reserved.</footer>

<script>
    const chatWindow = document.getElementById('chatWindow');
    if (chatWindow) {
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }
</script>
</body>
</html>
