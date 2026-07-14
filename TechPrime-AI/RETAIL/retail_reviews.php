<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/staff_layout.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header('Location: ../login.php'); exit;
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

staff_page_start([
    'role' => 'retail_officer',
    'title' => 'Reviews',
    'active' => 'reviews',
    'heading' => 'Customer Feedback',
    'subtitle' => 'Reviews for your products',
    'extra_head' => <<<'EXTRA'
<style>
.review-list { display: flex; flex-direction: column; gap: 18px; }
.review-card .review-header {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 14px;
}
.customer-info { display: flex; gap: 12px; align-items: center; }
.review-avatar {
    width: 40px; height: 40px; background: var(--ep-green); border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; color: #fff; flex-shrink: 0;
}
.stars { color: #f3c400; font-size: 16px; letter-spacing: 1px; }
.product-tag {
    font-size: 11px; background: var(--ep-green-light); color: var(--ep-green-dark);
    padding: 4px 10px; border-radius: 10px; font-weight: 700;
}
.comment-text {
    font-size: 14px; color: #444; line-height: 1.6; font-style: italic;
    background: #fafafa; padding: 12px; border-radius: 8px; margin-bottom: 14px;
}
.reply-section {
    background: var(--ep-green-light); padding: 14px 16px; border-radius: 10px;
    border-left: 4px solid var(--ep-green);
}
.reply-label {
    font-size: 12px; font-weight: 800; color: var(--ep-green-dark);
    text-transform: uppercase; margin-bottom: 6px; display: block;
}
</style>
EXTRA
]);
?>

        <?php if($reviews->num_rows > 0): ?>
            <div class="review-list">
            <?php while($rev = $reviews->fetch_assoc()): ?>
                <div class="card review-card">
                    <div class="card-body">
                        <div class="review-header">
                            <div class="customer-info">
                                <div class="review-avatar"><?php echo strtoupper(substr($rev['customer_name'], 0, 1)); ?></div>
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
                                <div class="form-group" style="margin-bottom:10px;">
                                    <textarea name="reply_text" class="form-control" rows="2" placeholder="Thank the customer or address their concerns..." required></textarea>
                                </div>
                                <button type="submit" name="submit_reply" class="btn btn-primary btn-sm">Send Reply</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty-state">
                        <i class="fas fa-star" style="font-size:40px;opacity:.35;margin-bottom:10px;color:var(--ep-yellow,#f3c400);"></i>
                        <p>No reviews yet for your products.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

<?php staff_page_end(); ?>
