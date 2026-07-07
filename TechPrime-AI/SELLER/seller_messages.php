<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header("Location: ../login.html");
    exit();
}

$db = getDbConnection();
$sellerId = (int)$_SESSION['user_id'];

// --- 1. FETCH CUSTOMERS ---
$convQuery = "SELECT DISTINCT u.id, u.name 
              FROM users u
              INNER JOIN messages m ON (u.id = m.sender_id OR u.id = m.receiver_id)
              WHERE (m.sender_id = ? OR m.receiver_id = ?) 
              AND u.id != ?
              ORDER BY m.created_at DESC";

$stmt = $db->prepare($convQuery);
$stmt->bind_param("iii", $sellerId, $sellerId, $sellerId);
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
    $mStmt->bind_param("iiii", $sellerId, $activeClientId, $activeClientId, $sellerId);
    $mStmt->execute();
    $messages = $mStmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Messages | IAS Seller</title>
    <link rel="stylesheet" href="../seller.css">
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
            overflow: hidden; /* Keeps chat contained */
        }
        
        /* HEADER */
        .seller-header { 
            background: var(--ias-teal); 
            padding: 15px 30px; 
            border-bottom: 3px solid var(--ias-gold); 
            flex-shrink: 0;
        }
        .logo-text { color: var(--ias-gold); font-size: 24px; font-weight: 900; letter-spacing: 1px; }

        .seller-layout { display: flex; flex: 1; overflow: hidden; }

        /* SIDEBAR */
        .seller-sidebar { 
            background: var(--sidebar-gray); 
            width: 260px; 
            padding-top: 10px; 
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
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

        /* CHAT INTERFACE */
        .chat-container { 
            flex: 1; 
            display: flex; 
            padding: 20px; 
            gap: 20px; 
            overflow: hidden;
        }
        .chat-card { 
            background: white; 
            border-radius: 12px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.08); 
            display: flex; 
            width: 100%;
            overflow: hidden;
            border: 1px dashed var(--ias-teal);
        }

        /* CONTACTS COLUMN */
        .contacts-column { width: 300px; border-right: 1px solid #eee; display: flex; flex-direction: column; }
        .column-head { padding: 20px; font-weight: 800; border-bottom: 1px solid #eee; font-size: 14px; background: #fafafa; color: var(--ias-teal); }
        .contact-link { padding: 15px 20px; border-bottom: 1px solid #f9f9f9; text-decoration: none; color: #555; font-size: 14px; transition: 0.2s; }
        .contact-link:hover { background: #f0fbfc; }
        .contact-link.active { background: #e0f7f9; border-left: 5px solid var(--ias-teal); font-weight: 700; color: var(--ias-teal); }

        /* CHAT COLUMN */
        .chat-column { flex: 1; display: flex; flex-direction: column; background: #fff; }
        .chat-header { padding: 15px 25px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; background: #fff; }
        .chat-messages { flex: 1; padding: 25px; overflow-y: auto; background: #fdfdfd; display: flex; flex-direction: column; gap: 12px; }

        /* BUBBLES */
        .bubble { max-width: 70%; padding: 12px 16px; border-radius: 18px; font-size: 14px; line-height: 1.4; position: relative; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
        .sent { align-self: flex-end; background: var(--ias-teal); color: white; border-bottom-right-radius: 4px; }
        .received { align-self: flex-start; background: #f1f1f1; color: #444; border-bottom-left-radius: 4px; }

        /* INPUT AREA */
        .chat-footer { padding: 20px; border-top: 1px solid #eee; background: white; }
        .input-box { display: flex; gap: 12px; background: #f4f7f6; padding: 8px 15px; border-radius: 30px; border: 1px solid #eee; }
        .input-box input { flex: 1; border: none; background: transparent; outline: none; padding: 8px; font-family: inherit; }
        .send-btn { background: var(--ias-teal); color: white; border: none; padding: 8px 25px; border-radius: 20px; cursor: pointer; font-weight: 700; transition: 0.3s; }
        .send-btn:hover { background: #077a87; }

        /* FOOTER */
        .ias-footer {
            background: var(--ias-teal);
            color: white;
            padding: 15px 30px;
            font-size: 14px;
            font-weight: 500;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

<header class="seller-header">
    <div class="logo-text">IAS SELLER</div>
</header>

<div class="seller-layout">
    <aside class="seller-sidebar">
        <button class="sidebar-item" onclick="location.href='seller_dashboard.php'">📊 Dashboard</button>
        <button class="sidebar-item" onclick="location.href='seller_products.php'">📦 My Products</button>
        <button class="sidebar-item" onclick="location.href='seller_orders.php'">📜 Orders</button>
        <button class="sidebar-item active">💬 Messages</button>
        <button class="sidebar-item" onclick="location.href='seller_settings.php'">⚙️ Settings</button>
        <button class="sidebar-item logout-btn" onclick="location.href='../logout.php'">🚪 Logout</button>
    </aside>

    <main class="chat-container">
        <div class="chat-card">
            <aside class="contacts-column">
                <div class="column-head">CUSTOMER INQUIRIES</div>
                <div style="overflow-y: auto; flex: 1;">
                    <?php while($c = $contacts->fetch_assoc()): ?>
                        <a href="seller_messages.php?client_id=<?php echo $c['id']; ?>" 
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
                    <span style="font-weight: 800; color: #333;"><?php echo $activeClientId ? h($clientName) : "Select a Chat"; ?></span>
                    <?php if($activeClientId): ?>
                        <span style="font-size: 12px; color: #2ecc71; font-weight: 700;">● Active Conversation</span>
                    <?php endif; ?>
                </div>

                <div class="chat-messages" id="chatWindow">
                    <?php if($activeClientId): ?>
                        <?php while($m = $messages->fetch_assoc()): ?>
                            <div class="bubble <?php echo ($m['sender_id'] == $sellerId) ? 'sent' : 'received'; ?>">
                                <?php echo h($m['message']); ?>
                                <div style="font-size: 9px; margin-top: 5px; opacity: 0.7; text-align: right;">
                                    <?php echo date('h:i A', strtotime($m['created_at'])); ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="margin: auto; text-align: center; color: #ccc;">
                            <span style="font-size: 60px;">📩</span>
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
    </main>
</div>

<footer class="ias-footer">
    © 2026 IAS E-Commerce Seller Center. All Rights Reserved.
</footer>

<script>
    // Auto-scroll to bottom of chat
    const chatWindow = document.getElementById('chatWindow');
    if(chatWindow) {
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }
</script>

</body>
</html>