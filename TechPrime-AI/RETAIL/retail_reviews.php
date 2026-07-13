<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header("Location: ../login.html"); exit;
}

$db = getDbConnection();
$retailId = (int)$_SESSION['user_id'];

// --- HANDLE RETAIL REPLY ---
if (isset($_POST['submit_reply'])) {
    $reviewId = (int)$_POST['review_id'];
    $replyText = $db->real_escape_string($_POST['reply_text']);
    
    $update = $db->prepare("UPDATE reviews r 
                            JOIN products p ON r.product_id = p.id 
                            SET r.seller_reply = ?, r.replied_at = NOW() 
                            WHERE r.id = ? AND p.seller_id = ?");
    $update->bind_param("sii", $replyText, $reviewId, $retailId);
    $update->execute();
    header("Location: retail_reviews.php?success=1"); exit;
}

// --- FETCH REVIEWS FOR THIS RETAILER'S PRODUCTS ---
$query = "SELECT r.*, u.name as customer_name, p.name as product_name 
          FROM reviews r
          JOIN products p ON r.product_id = p.id
          JOIN users u ON r.user_id = u.id
          WHERE p.seller_id = ?
          ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $retailId);
$stmt->execute();
$reviews = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Reviews | Easy PC Retail</title>
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
        
        .review-card { 
            background: white; 
            border-radius: 12px; 
            padding: 25px; 
            margin-bottom: 20px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px dashed var(--ias-teal);
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .review-header { display: flex; justify-content: space-between; align-items: flex-start; }
        .customer-info { display: flex; gap: 12px; align-items: center; }
        .avatar { width: 40px; height: 40px; background: var(--ias-teal); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; }
        
        .stars { color: #ffc107; font-size: 18px; }
        .product-tag { font-size: 11px; background: #f0fbfc; color: var(--ias-teal); padding: 4px 10px; border-radius: 10px; font-weight: 700; }
        
        .comment-text { font-size: 14px; color: #444; line-height: 1.6; font-style: italic; background: #fafafa; padding: 10px; border-radius: 8px; }
        
        /* RETAIL REPLY BOX */
        .reply-section { 
            background: #f8f9fa; 
            padding: 15px; 
            border-radius: 12px; 
            border-left: 4px solid var(--ias-teal);
        }
        .reply-label { font-size: 12px; font-weight: 800; color: var(--ias-teal); text-transform: uppercase; margin-bottom: 5px; display: block; }
        
        textarea { width: 100%; border: 1px solid #ddd; border-radius: 8px; padding: 10px; font-family: inherit; resize: none; box-sizing: border-box; }
        .btn-reply { background: var(--ias-teal); color: white; border: none; padding: 8px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 10px; transition: 0.3s; }
        .btn-reply:hover { opacity: 0.9; }

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
        <button class="sidebar-item active">⭐ Reviews</button>
        <button class="sidebar-item" onclick="location.href='retail_settings.php'">⚙️ Settings</button>
        <button class="sidebar-item logout-btn" onclick="location.href='../logout.php'">🚪 Logout</button>
    </aside>

    <main class="retail-main">
        <h2 style="margin-bottom: 25px; color: #333;">Customer Feedback</h2>

        <?php if($reviews->num_rows > 0): ?>
            <?php while($rev = $reviews->fetch_assoc()): ?>
                <div class="review-card">
                    <div class="review-header">
                        <div class="customer-info">
                            <div class="avatar"><?php echo strtoupper(substr($rev['customer_name'], 0, 1)); ?></div>
                            <div>
                                <div style="font-weight: 700;"><?php echo h($rev['customer_name']); ?></div>
                                <div class="stars"><?php echo str_repeat('★', $rev['rating']) . str_repeat('☆', 5 - $rev['rating']); ?></div>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <span class="product-tag"><?php echo h($rev['product_name']); ?></span>
                            <div style="font-size: 11px; color: #bbb; margin-top: 5px;"><?php echo date('M d, Y', strtotime($rev['created_at'])); ?></div>
                        </div>
                    </div>

                    <div class="comment-text">
                        "<?php echo h($rev['comment']); ?>"
                    </div>

                    <?php if($rev['seller_reply']): ?>
                        <div class="reply-section">
                            <span class="reply-label">Your Response</span>
                            <div style="font-size: 13px; color: #555;">
                                <?php echo h($rev['seller_reply']); ?>
                            </div>
                            <div style="font-size: 10px; color: #bbb; margin-top: 5px;">Replied on <?php echo date('M d, Y', strtotime($rev['replied_at'])); ?></div>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="reply-section">
                            <span class="reply-label">Write a Response</span>
                            <input type="hidden" name="review_id" value="<?php echo $rev['id']; ?>">
                            <textarea name="reply_text" rows="2" placeholder="Thank the customer or address their concerns..." required></textarea>
                            <button type="submit" name="submit_reply" class="btn-reply">Send Reply</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 100px; color: #ccc;">
                <span style="font-size: 50px;">⭐</span>
                <p>No reviews yet for your products.</p>
            </div>
        <?php endif; ?>
    </main>
</div>

<footer class="ias-footer">
    © 2026 Easy PC Retail Center. All Rights Reserved.
</footer>

</body>
</html>
