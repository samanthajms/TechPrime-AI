<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_shop_taxonomy.php';

$db = getDbConnection();
checkSessionTimeout();
ep_ensure_session_wishlist($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_wishlist'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        if (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'csrf']);
            exit;
        }
        die('Invalid CSRF token.');
    }
    $pid = (int)($_POST['product_id'] ?? 0);
    $nowOn = ep_toggle_wishlist($db, $pid);
    if (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'in_wishlist' => $nowOn]);
        exit;
    }
    $returnTo = $_POST['return_to'] ?? 'wishlist.php';
    if (!preg_match('#^[a-zA-Z0-9_\-./?=&%+]+$#', $returnTo)) {
        $returnTo = 'wishlist.php';
    }
    header('Location: ' . $returnTo);
    exit;
}

$ids = ep_wishlist_ids();
$items = [];
if (!empty($ids)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare(
        "SELECT p.id, p.name, p.price, p.stock, p.category, p.image, p.image_url, u.name AS seller_name
         FROM products p
         INNER JOIN users u ON p.seller_id = u.id
         WHERE p.id IN ({$ph}) AND " . ias_client_product_list_sql_condition('p')
    );
    $st->execute($ids);
    $items = ep_shop_attach_taxonomy($st->fetchAll(PDO::FETCH_ASSOC));
}

$isLoggedIn = isset($_SESSION['user_id']);
$activePage = 'wishlist';
$pageTitle = 'My Wishlist';
$searchQuery = '';
$bodyClass = '';
$itemCount = count($items);
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<?php $wishCsrf = generateCsrfToken(); ?>
<main class="ep-main sf-main" id="main">
    <?php echo sf_page_hero([
        'back' => ['Back', 'user_dashboard.php'],
        'eyebrow' => 'Saved for later',
        'title' => 'My wishlist',
        'sub' => 'Products you saved stay here so you can come back to them anytime.',
        'icon' => 'fa-heart',
        'meta' => '<span class="sf-meta-chip"><i class="fas fa-heart" aria-hidden="true"></i><span><strong class="sf-num">'
            . $itemCount . '</strong> item' . ($itemCount === 1 ? '' : 's') . '</span></span>',
        'actions' => $items ? '<a href="shop.php" class="sf-btn sf-btn-outline"><i class="fas fa-store" aria-hidden="true"></i> Keep shopping</a>' : '',
    ]); ?>

    <div class="sf-wrap sf-body">
        <?php if (!empty($items)): ?>
            <ul class="sf-grid">
                <?php foreach ($items as $p): ?>
                    <li><?php echo sf_product_card($p, 'wishlist.php', $wishCsrf, [
                        'cat' => $p['tax_sub_label'] ?: $p['tax_parent_label'],
                        'wish' => true,
                    ]); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <?php echo sf_empty_state('fa-heart', 'Your wishlist is empty',
                'Browse the shop and tap the heart on a product to save it here.',
                '<a href="shop.php" class="sf-btn sf-btn-primary">Browse products</a>'); ?>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
