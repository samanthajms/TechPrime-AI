<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/order_reviews.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');
ep_review_ensure_schema($db);

$uid = (int) $_SESSION['user_id'];
$notice = '';
$noticeErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply_review') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $noticeErr = 'Your session expired. Refresh the page and try again.';
    } else {
        $reviewId = (int) ($_POST['review_id'] ?? 0);
        $text = trim((string) ($_POST['reply_text'] ?? ''));
        $tags = ep_review_tags_from_request((array) ($_POST['reply_tags'] ?? []), ep_review_staff_tags());
        $code = ep_review_reply($db, $uid, $reviewId, $text, $tags);
        if ($code === '') {
            $keepPage = max(1, (int) ($_POST['page'] ?? 1));
            $keepStars = (int) ($_POST['stars'] ?? 0);
            $keepImages = (($_POST['images'] ?? '') === '1');
            $to = 'inventory_reviews.php?reply=sent';
            if ($keepStars >= 1 && $keepStars <= 5) {
                $to .= '&stars=' . $keepStars;
            }
            if ($keepImages) {
                $to .= '&images=1';
            }
            if ($keepPage > 1) {
                $to .= '&page=' . $keepPage;
            }
            header('Location: ' . $to);
            exit;
        }
        $noticeErr = $code === 'empty_reply'
            ? 'Choose a quick reply or write a message.'
            : 'That review could not be updated.';
    }
}

if (($_GET['reply'] ?? '') === 'sent') {
    $notice = 'Reply saved.';
}

ep_review_remove_videos($db);

$stars = (int) ($_GET['stars'] ?? 0);
if ($stars < 1 || $stars > 5) {
    $stars = 0;
}
$imagesOnly = (($_GET['images'] ?? '') === '1');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$list = ep_review_list($db, '', $page, $perPage, $stars, $imagesOnly);

$filterQuery = static function (int $targetPage) use ($stars, $imagesOnly): string {
    $query = [];
    if ($stars >= 1) {
        $query['stars'] = $stars;
    }
    if ($imagesOnly) {
        $query['images'] = '1';
    }
    if ($targetPage > 1) {
        $query['page'] = $targetPage;
    }
    return $query === [] ? 'inventory_reviews.php' : 'inventory_reviews.php?' . http_build_query($query);
};
$rows = $list['rows'];
$page = (int) $list['page'];
$pages = (int) $list['pages'];
$total = (int) $list['total'];

$staffTags = ep_review_staff_tags();

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Reviews',
    'active' => 'reviews',
    'heading' => 'Reviews',
    'subtitle' => 'Reviews from every client account',
    'extra_head' => <<<'CSS'
<style>
.inv-reviews { display: flex; flex-direction: column; gap: 12px; }
.inv-reviews-note { margin: 0; }
.inv-reviews-layout { display: grid; grid-template-columns: minmax(0, 1fr) 228px; gap: 12px; align-items: start; }
.inv-reviews-main { min-width: 0; padding: 10px 12px 12px; }
.inv-reviews-count { margin: 0 0 8px; font-size: 12.5px; color: var(--text-muted, #667085); font-weight: 700; }
.inv-filter-panel {
    position: sticky; top: 12px;
    border: 1px solid #d7e8c4; border-radius: 16px; background: #fff;
    box-shadow: 0 8px 22px rgba(75, 139, 42, 0.08); padding: 16px 14px;
}
.inv-filter-panel h3 { margin: 0 0 10px; font-size: 14px; color: var(--ep-green-dark, #2f6b16); }
.inv-filter-panel p { margin: 0 0 6px; font-size: 11px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #667085; }
.inv-filter-list { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.inv-filter-list label, .inv-filter-images {
    display: flex; align-items: center; gap: 8px; min-height: 28px; padding: 0 8px; border-radius: 8px;
    font-size: 13px; cursor: pointer;
}
.inv-filter-list label:hover, .inv-filter-images:hover { background: #f4faef; }
.inv-filter-stars { color: #e3a008; letter-spacing: 1px; }
.inv-filter-panel .btn { width: 100%; justify-content: center; margin-top: 4px; padding: 7px 10px; font-size: 12.5px; }
.inv-reviews-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.inv-reviews-table th, .inv-reviews-table td {
    text-align: left; vertical-align: middle; padding: 6px 8px; border-bottom: 1px solid rgba(75, 139, 42, 0.12);
    font-size: 13px;
}
.inv-reviews-table .inv-col-order { width: 132px; }
.inv-reviews-table .inv-col-date { width: 108px; }
.inv-reviews-table .inv-col-rating { width: 96px; }
.inv-reviews-table td.inv-order,
.inv-reviews-table td.inv-date,
.inv-reviews-table td.inv-stars { white-space: nowrap; overflow-wrap: normal; }
.inv-reviews-table td { overflow-wrap: break-word; }
.inv-reviews-table th {
    font-size: 12px; letter-spacing: .03em; text-transform: uppercase; color: var(--text-muted, #667085); background: #f6fbf3;
}
.inv-review-block td.inv-media-cell { background: #fff; }
.inv-review-gap td { height: 6px; padding: 0; border: 0; background: transparent; }
.inv-stars { color: #e3a008; letter-spacing: 1px; font-size: 14px; white-space: nowrap; }
.inv-stars span { color: #d1d5db; }
.inv-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.inv-tags span, .inv-reply-chip span {
    display: inline-flex; align-items: center; min-height: 26px; padding: 0 8px; border-radius: 999px;
    background: #f4faef; border: 1px solid #d7e8c4; color: var(--ep-green-dark, #2f6b16); font-size: 12px; font-weight: 700;
}
.inv-reply-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.inv-reply-chip input { position: absolute; opacity: 0; pointer-events: none; }
.inv-reply-chip { cursor: pointer; }
.inv-reply-chip input:checked + span { background: var(--ep-green, #4b8b2a); color: #fff; border-color: var(--ep-green, #4b8b2a); }
.inv-media { display: flex; flex-wrap: wrap; gap: 6px; }
.inv-media img { width: 84px; height: 64px; object-fit: cover; border-radius: 8px; background: #f3f4f6; border: 1px solid #e5e7eb; }
.inv-review-box { display: flex; flex-direction: column; align-items: stretch; gap: 8px; }
.inv-review-copy { min-width: 0; }
.inv-reply-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: flex-end; }
.inv-reply-actions .btn { width: auto; padding: 5px 12px; font-size: 12px; }
.inv-reply-state { font-size: 12px; font-weight: 700; color: var(--ep-green-dark, #2f6b16); }
.inv-reply-pop[hidden] { display: none !important; }
.inv-reply-pop {
    position: fixed; inset: 0; z-index: 80; display: flex; align-items: center; justify-content: center;
    background: rgba(17, 24, 39, 0.28); padding: 16px;
}
.inv-reply-pop-card {
    position: relative; width: min(380px, 100%); max-height: min(70vh, 420px); overflow: auto;
    background: #fff; border: 1px solid #d7e8c4; border-radius: 14px;
    box-shadow: 0 16px 40px rgba(17, 24, 39, 0.16); padding: 14px 14px 12px;
}
.inv-reply-pop-card h3 { margin: 0 28px 2px 0; font-size: 15px; color: var(--ep-green-dark, #2f6b16); }
.inv-reply-pop-order { margin: 0 0 10px; font-size: 12px; color: #667085; }
.inv-reply-pop-x {
    position: absolute; top: 8px; right: 8px; width: 28px; height: 28px; border: 0; border-radius: 8px;
    background: #f4faef; color: #3f6212; font-size: 18px; line-height: 1; cursor: pointer;
}
.inv-reply-pop-view {
    margin: 0; max-height: 120px; overflow-y: auto; white-space: pre-wrap; word-break: break-word;
    padding: 8px 10px; border-radius: 10px; background: #f6fbf3; border: 1px solid #d7e8c4;
    font-size: 13px; line-height: 1.45;
}
.inv-reply-pop-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
.inv-reply-pop-actions .btn { width: auto; padding: 5px 12px; font-size: 12px; }
#invReplyForm[hidden], #invReplyView[hidden], #invReplyViewActions[hidden] { display: none !important; }
#invReplyForm textarea {
    width: 100%; height: 96px; min-height: 96px; max-height: 96px; resize: none; overflow-y: auto;
    border-radius: 10px; border: 1px solid #d9e8cf; padding: 8px 10px; font: inherit; box-sizing: border-box;
}
.inv-reviews-empty { padding: 16px 8px; color: var(--text-muted, #667085); }
.orders-pagination {
    display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap;
    padding: 18px 8px 4px; border-top: 1px solid var(--ep-border, #e5e7eb); margin-top: 8px;
}
.orders-page-link {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    min-width: 36px; height: 36px; padding: 0 12px; border-radius: 10px;
    border: 1px solid var(--ep-border, #e5e7eb); background: #fff; color: var(--ep-text, #1f2937);
    font-size: 13px; font-weight: 600; text-decoration: none; font-variant-numeric: tabular-nums;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease, transform 160ms var(--ease-out);
}
.orders-page-link:active:not(.disabled) { transform: scale(0.95); }
@media (prefers-reduced-motion: reduce) { .orders-page-link:active:not(.disabled) { transform: none; } }
.orders-page-link:hover:not(.disabled):not(.active) {
    border-color: var(--ep-green); color: var(--ep-green-dark); background: var(--ep-green-light);
}
.orders-page-link.active {
    background: var(--ep-green); border-color: var(--ep-green); color: #fff;
    box-shadow: 0 4px 12px rgba(75, 139, 42, 0.28);
}
.orders-page-link.disabled { opacity: 0.45; pointer-events: none; }
.orders-page-summary {
    width: 100%; text-align: center; font-size: 12px; color: var(--ep-muted, #667085);
    font-weight: 500; margin-top: 6px;
}
@media (max-width: 900px) {
    .inv-reviews-layout { grid-template-columns: 1fr; }
    .inv-filter-panel { position: static; }
}
</style>
CSS
]);
?>
<div class="inv-reviews">
    <?php if ($notice !== ''): ?>
        <div class="alert alert-success inv-reviews-note"><?php echo h($notice); ?></div>
    <?php endif; ?>
    <?php if ($noticeErr !== ''): ?>
        <div class="alert alert-error inv-reviews-note"><?php echo h($noticeErr); ?></div>
    <?php endif; ?>

    <div class="inv-reviews-layout">
    <section class="card inv-reviews-main">
        <p class="inv-reviews-count"><?php echo (int) $total; ?> review<?php echo $total === 1 ? '' : 's'; ?></p>
        <div class="table-wrap">
            <table class="inv-reviews-table">
                <colgroup>
                    <col>
                    <col class="inv-col-order">
                    <col class="inv-col-date">
                    <col class="inv-col-rating">
                </colgroup>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td class="inv-reviews-empty"><?php echo ($stars >= 1 || $imagesOnly) ? 'No reviews match this filter.' : 'No client reviews yet. Completed orders can be reviewed from the client Order History.'; ?></td></tr>
                <?php endif; ?>
                <?php
                $replyPayload = [];
                foreach ($rows as $row):
                    $rating = (int) $row['rating'];
                    $when = !empty($row['created_at']) ? date('M d, Y', strtotime((string) $row['created_at'])) : '';
                    ?>
                    <tr class="inv-review-block">
                        <th colspan="4">Client</th>
                    </tr>
                    <tr>
                        <td colspan="4"><?php echo !empty($row['is_anonymous']) ? 'Anonymous' : h((string) $row['client_name']); ?></td>
                    </tr>
                    <tr>
                        <th colspan="4">Image</th>
                    </tr>
                    <tr>
                        <td colspan="4" class="inv-media-cell">
                            <?php
                            $images = array_values(array_filter($row['media'], static fn($media) => ($media['kind'] ?? '') === 'image'));
                            ?>
                            <?php if ($images === []): ?>
                                <span>No image</span>
                            <?php else: ?>
                                <div class="inv-media">
                                    <?php foreach ($images as $media):
                                        $src = '../backend/api/review_media.php?id=' . (int) $media['id'];
                                        ?>
                                        <a href="<?php echo h($src); ?>" target="_blank" rel="noopener"><img src="<?php echo h($src); ?>" alt=""></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Product</th>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Rating</th>
                    </tr>
                    <tr>
                        <td><?php echo h((string) ($row['product_names'] ?? 'Product')); ?></td>
                        <td class="inv-order"><strong>#ORD-<?php echo (int) $row['order_id']; ?></strong></td>
                        <td class="inv-date"><?php echo h($when); ?></td>
                        <td class="inv-stars" aria-label="<?php echo $rating; ?> out of 5">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <?php if ($i <= $rating): ?>★<?php else: ?><span>★</span><?php endif; ?>
                            <?php endfor; ?>
                        </td>
                    </tr>
                    <tr>
                        <th colspan="4">Review</th>
                    </tr>
                    <tr>
                        <td colspan="4">
                            <?php
                            $reviewText = ep_review_text_with_tags((string) $row['comment'], $row['tag_list']);
                            $replyText = ep_review_text_with_tags((string) $row['reply_text'], $row['reply_tag_list']);
                            $replyPayload[(string) $row['id']] = [
                                'order' => (int) $row['order_id'],
                                'reply' => $replyText,
                            ];
                            ?>
                            <div class="inv-review-box">
                                <div class="inv-review-copy"><?php echo $reviewText !== '' ? nl2br(h($reviewText)) : 'No written comment'; ?></div>
                                <div class="inv-reply-actions">
                                    <?php if ($replyText !== ''): ?><span class="inv-reply-state">Replied</span><?php endif; ?>
                                    <button type="button" class="btn btn-primary" data-send-reply="<?php echo (int) $row['id']; ?>">Send reply</button>
                                    <button type="button" class="btn btn-outline" data-view-reply="<?php echo (int) $row['id']; ?>">View reply</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr class="inv-review-gap"><td colspan="4"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total > 0): ?>
            <nav class="orders-pagination" aria-label="Reviews pagination">
                <a href="<?php echo h($filterQuery(max(1, $page - 1))); ?>"
                   class="orders-page-link<?php echo $page <= 1 ? ' disabled' : ''; ?>"
                   aria-disabled="<?php echo $page <= 1 ? 'true' : 'false'; ?>">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a href="<?php echo h($filterQuery($p)); ?>"
                       class="orders-page-link<?php echo $p === $page ? ' active' : ''; ?>"
                       <?php echo $p === $page ? 'aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                <?php endfor; ?>
                <a href="<?php echo h($filterQuery(min($pages, $page + 1))); ?>"
                   class="orders-page-link<?php echo $page >= $pages ? ' disabled' : ''; ?>"
                   aria-disabled="<?php echo $page >= $pages ? 'true' : 'false'; ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
                <div class="orders-page-summary">
                    Showing <?php echo (($page - 1) * $perPage + 1); ?>–<?php echo min($page * $perPage, $total); ?> of <?php echo (int) $total; ?> reviews
                    (<?php echo (int) $perPage; ?> per page)
                </div>
            </nav>
        <?php endif; ?>
    </section>
    <aside class="inv-filter-panel" aria-label="Filtering">
        <h3>Filtering</h3>
        <form method="get" action="inventory_reviews.php">
            <p>Stars</p>
            <div class="inv-filter-list">
                <label><input type="radio" name="stars" value="0" <?php echo $stars === 0 ? 'checked' : ''; ?>> All</label>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <label>
                        <input type="radio" name="stars" value="<?php echo $star; ?>" <?php echo $stars === $star ? 'checked' : ''; ?>>
                        <span class="inv-filter-stars"><?php echo str_repeat('★', $star); ?><span style="color:#d1d5db"><?php echo str_repeat('★', 5 - $star); ?></span></span>
                    </label>
                <?php endfor; ?>
            </div>
            <label class="inv-filter-images">
                <input type="checkbox" name="images" value="1" <?php echo $imagesOnly ? 'checked' : ''; ?>>
                Images only
            </label>
            <button type="submit" class="btn btn-primary">Apply</button>
        </form>
    </aside>
    </div>
</div>
<div class="inv-reply-pop" id="invReplyPop" hidden>
    <div class="inv-reply-pop-card" role="dialog" aria-modal="true" aria-labelledby="invReplyPopTitle">
        <button type="button" class="inv-reply-pop-x" id="invReplyClose" aria-label="Close">&times;</button>
        <h3 id="invReplyPopTitle">Reply</h3>
        <p class="inv-reply-pop-order" id="invReplyOrder"></p>
        <div class="inv-reply-pop-view" id="invReplyView"></div>
        <div class="inv-reply-pop-actions" id="invReplyViewActions">
            <button type="button" class="btn btn-primary" id="invReplyEdit">Edit reply</button>
        </div>
        <form method="post" id="invReplyForm" hidden>
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="reply_review">
            <input type="hidden" name="review_id" id="invReplyId" value="">
            <input type="hidden" name="page" value="<?php echo (int) $page; ?>">
            <input type="hidden" name="stars" value="<?php echo (int) $stars; ?>">
            <?php if ($imagesOnly): ?><input type="hidden" name="images" value="1"><?php endif; ?>
            <div class="inv-reply-chips">
                <?php foreach ($staffTags as $tag): ?>
                    <label class="inv-reply-chip">
                        <input type="checkbox" name="reply_tags[]" value="<?php echo h($tag); ?>">
                        <span><?php echo h($tag); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <textarea name="reply_text" id="invReplyText" maxlength="1000" placeholder="Write a reply"></textarea>
            <div class="inv-reply-pop-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-outline" id="invReplyCancel">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script type="application/json" id="invReplyData"><?php echo json_encode($replyPayload ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script>
(function () {
    var pop = document.getElementById('invReplyPop');
    var dataEl = document.getElementById('invReplyData');
    var form = document.getElementById('invReplyForm');
    var view = document.getElementById('invReplyView');
    var viewActions = document.getElementById('invReplyViewActions');
    var orderLabel = document.getElementById('invReplyOrder');
    var reviewId = document.getElementById('invReplyId');
    var area = document.getElementById('invReplyText');
    if (!pop || !dataEl || !form || !area) return;
    var replies = {};
    try { replies = JSON.parse(dataEl.textContent || '{}') || {}; } catch (e) { replies = {}; }
    var currentId = '';
    var savedText = '';

    function setLine(text, phrase, on) {
        var lines = String(text || '').split('\n');
        var idx = -1;
        lines.forEach(function (line, i) {
            if (idx === -1 && line.trim().toLowerCase() === phrase.toLowerCase()) idx = i;
        });
        if (on) {
            if (idx !== -1) return text;
            var base = String(text || '').replace(/\s+$/, '');
            return base === '' ? phrase : base + '\n' + phrase;
        }
        if (idx === -1) return text;
        lines.splice(idx, 1);
        return lines.join('\n').replace(/^\n+|\n+$/g, '');
    }

    function showView() {
        form.hidden = true;
        view.hidden = false;
        viewActions.hidden = false;
        view.textContent = savedText !== '' ? savedText : 'No reply yet.';
    }

    function showEdit() {
        view.hidden = true;
        viewActions.hidden = true;
        form.hidden = false;
        area.value = savedText;
        form.querySelectorAll('input[name="reply_tags[]"]').forEach(function (box) { box.checked = false; });
    }

    function openReply(id, editing) {
        currentId = String(id);
        var row = replies[currentId] || { order: '', reply: '' };
        savedText = row.reply || '';
        reviewId.value = currentId;
        orderLabel.textContent = row.order ? 'Order #ORD-' + row.order : '';
        if (editing) showEdit();
        else showView();
        pop.hidden = false;
    }

    function closeReply() {
        pop.hidden = true;
    }

    document.addEventListener('click', function (e) {
        var viewBtn = e.target.closest('[data-view-reply]');
        if (viewBtn) {
            openReply(viewBtn.getAttribute('data-view-reply'), false);
            return;
        }
        var sendBtn = e.target.closest('[data-send-reply]');
        if (sendBtn) {
            openReply(sendBtn.getAttribute('data-send-reply'), true);
            return;
        }
        if (e.target.id === 'invReplyClose' || e.target === pop) closeReply();
    });
    document.getElementById('invReplyEdit').addEventListener('click', showEdit);
    document.getElementById('invReplyCancel').addEventListener('click', function () {
        if (savedText === '') closeReply();
        else showView();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !pop.hidden) closeReply();
    });
    form.querySelectorAll('input[name="reply_tags[]"]').forEach(function (box) {
        box.addEventListener('change', function () {
            area.value = setLine(area.value, box.value.trim(), box.checked);
        });
    });
})();
</script>
<?php
staff_page_end();
