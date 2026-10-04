<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/client_helpers.php';
require_once __DIR__ . '/../includes/client_notifications.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('client');

$userId = (int)$_SESSION['user_id'];
inv_notifications_ensure_schema($db);

$tabs = ep_notif_tabs();
$tab = (string)($_GET['tab'] ?? 'all');
if (!isset($tabs[$tab])) {
    $tab = 'all';
}

/* Tab counts (unread per tab) in one pass. */
$countSql = ['COUNT(*) AS all_total', 'COUNT(*) FILTER (WHERE is_read = 0) AS unread_total'];
$countParams = [];
foreach ($tabs as $key => $def) {
    if ($def['types'] === null) {
        continue;
    }
    $ph = implode(',', array_fill(0, count($def['types']), '?'));
    $countSql[] = "COUNT(*) FILTER (WHERE is_read = 0 AND type IN ({$ph})) AS {$key}_unread";
    $countParams = array_merge($countParams, $def['types']);
}
$cst = $db->prepare('SELECT ' . implode(', ', $countSql) . ' FROM notifications WHERE user_id = ?');
$cst->execute(array_merge($countParams, [$userId]));
$counts = $cst->fetch(PDO::FETCH_ASSOC) ?: [];
$totalAll = (int)($counts['all_total'] ?? 0);
$unreadAll = (int)($counts['unread_total'] ?? 0);

/* Current tab, paginated. */
$where = 'user_id = ?';
$params = [$userId];
if ($tab === 'unread') {
    $where .= ' AND is_read = 0';
} elseif ($tabs[$tab]['types'] !== null) {
    $where .= ' AND type IN (' . implode(',', array_fill(0, count($tabs[$tab]['types']), '?')) . ')';
    $params = array_merge($params, $tabs[$tab]['types']);
}
$perPage = 15;
$tst = $db->prepare("SELECT COUNT(*) FROM notifications WHERE {$where}");
$tst->execute($params);
$tabTotal = (int)$tst->fetchColumn();
$pages = max(1, (int)ceil($tabTotal / $perPage));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$lst = $db->prepare(
    "SELECT id, message, is_read, type, link, created_at FROM notifications
     WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}"
);
$lst->execute($params);
$rows = $lst->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($rows as $row) {
    $groups[ep_notif_day_label(ep_notif_timestamp((string)$row['created_at']))][] = $row;
}

$tabUrl = static function (string $key, int $p = 1): string {
    $q = [];
    if ($key !== 'all') {
        $q['tab'] = $key;
    }
    if ($p > 1) {
        $q['page'] = $p;
    }
    return 'notifications.php' . ($q ? '?' . http_build_query($q) : '');
};

$emptyCopy = [
    'all'           => ['You have no notifications yet', 'Updates about your orders, payments and cancellations will show up here.'],
    'unread'        => ['You\'re all caught up', 'There are no unread notifications. Nice!'],
    'orders'        => ['No order updates', 'Place an order and we\'ll keep you posted on every step.'],
    'payments'      => ['No payment updates', 'Payment confirmations for your orders will appear here.'],
    'cancellations' => ['No cancellations', 'Cancellation requests and results will appear here.'],
];

$isLoggedIn = true;
$activePage = 'notifications';
$pageTitle = 'Notifications';
$searchQuery = '';
$bodyClass = 'ep-shop-page-body';
?>
<?php include __DIR__ . '/ep_header.php'; ?>

<main class="ep-main">
    <div class="ep-page-inner ep-notif-page">
        <a href="user_dashboard.php" class="ep-profile-back-arrow" aria-label="Back to Profile">&lt;</a>

        <header class="ep-wish-hero ep-notif-hero">
            <div class="ep-wish-hero-icon ep-notif-hero-icon" aria-hidden="true">
                <i class="fas fa-bell"></i>
            </div>
            <div class="ep-wish-hero-copy">
                <p class="ep-wish-kicker">Stay updated</p>
                <h1 class="ep-wish-title">Notifications</h1>
                <p class="ep-wish-subtitle">Order, payment and cancellation updates from EasyPC One Oasis.</p>
            </div>
            <div class="ep-notif-hero-side">
                <span class="ep-wish-count" id="epNotifPageUnread"><?php echo $unreadAll; ?> unread</span>
                <button type="button" class="ep-btn ep-btn-primary ep-notif-page-readall" data-notif-readall<?php echo $unreadAll > 0 ? '' : ' hidden'; ?>>
                    <i class="fas fa-check-double" aria-hidden="true"></i> Mark all as read
                </button>
            </div>
        </header>

        <nav class="ep-notif-tabs" aria-label="Filter notifications">
            <?php foreach ($tabs as $key => $def):
                $badge = $key === 'all' ? 0 : ($key === 'unread' ? $unreadAll : (int)($counts[$key . '_unread'] ?? 0));
                ?>
                <a href="<?php echo h($tabUrl($key)); ?>"
                   class="ep-notif-tab<?php echo $tab === $key ? ' is-active' : ''; ?>"
                   <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>>
                    <?php echo h($def['label']); ?>
                    <?php if ($badge > 0): ?><span class="ep-notif-tab-count"><?php echo $badge > 99 ? '99+' : $badge; ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (empty($rows)):
            [$emptyTitle, $emptyText] = $emptyCopy[$tab];
            ?>
            <div class="ep-wish-empty ep-notif-page-empty">
                <div class="ep-wish-empty-icon ep-notif-hero-icon" aria-hidden="true"><i class="far fa-bell"></i></div>
                <h3><?php echo h($emptyTitle); ?></h3>
                <p><?php echo h($emptyText); ?></p>
                <a href="shop.php" class="ep-btn ep-btn-primary">Continue shopping</a>
            </div>
        <?php else: ?>
            <div class="ep-notif-groups" id="epNotifGroups">
                <?php foreach ($groups as $label => $items): ?>
                    <section class="ep-notif-group">
                        <h2 class="ep-notif-group-label"><?php echo h($label); ?></h2>
                        <ul class="ep-notif-page-list">
                            <?php foreach ($items as $n) echo ep_notif_render_item($n, 'page'); ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="ep-notif-pager" aria-label="Notification pages">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo h($tabUrl($tab, $page - 1)); ?>" class="ep-notif-pager-btn"><i class="fas fa-chevron-left" aria-hidden="true"></i> Newer</a>
                    <?php else: ?>
                        <span class="ep-notif-pager-btn is-disabled"><i class="fas fa-chevron-left" aria-hidden="true"></i> Newer</span>
                    <?php endif; ?>
                    <span class="ep-notif-pager-info">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                    <?php if ($page < $pages): ?>
                        <a href="<?php echo h($tabUrl($tab, $page + 1)); ?>" class="ep-notif-pager-btn">Older <i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                    <?php else: ?>
                        <span class="ep-notif-pager-btn is-disabled">Older <i class="fas fa-chevron-right" aria-hidden="true"></i></span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<script>
(function () {
    var unreadLabel = document.getElementById('epNotifPageUnread');
    document.addEventListener('ep:notif-unread', function (e) {
        if (unreadLabel) unreadLabel.textContent = e.detail.unread + ' unread';
    });
    // A day group emptied by "Remove": drop its heading; reload when the page has nothing left.
    document.addEventListener('ep:notif-list-empty', function (e) {
        var group = e.detail.list.closest('.ep-notif-group');
        if (!group) return;
        group.remove();
        if (!document.querySelector('#epNotifGroups .ep-notif-item')) window.location.reload();
    });
})();
</script>

<?php include __DIR__ . '/ep_footer.php'; ?>
<?php ias_alert_footer(); ?>
