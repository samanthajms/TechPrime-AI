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
@media (hover: hover) and (pointer: fine) {
    .inv-filter-list label:hover, .inv-filter-images:hover { background: #f4faef; }
}
.inv-filter-list label:has(input:checked) { background: var(--ep-green-light); font-weight: 600; }
.inv-filter-stars { color: #e3a008; letter-spacing: 1px; }
.inv-filter-panel .btn { width: 100%; justify-content: center; margin-top: 4px; padding: 7px 10px; font-size: 12.5px; }
.inv-filter-panel .btn[hidden] { display: none; }
/* ── Review cards ── */
.rv-list { display: flex; flex-direction: column; gap: 12px; }
.rv-card {
    display: flex; flex-direction: column; gap: 10px;
    padding: 16px 18px;
    border: 1px solid var(--border); border-radius: 12px; background: #fff;
}
.rv-head { display: flex; align-items: center; gap: 11px; }
.rv-avatar {
    width: 36px; height: 36px; flex-shrink: 0;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--ep-green-light); color: var(--ep-green-dark);
    font-size: 14px; font-weight: 700;
}
.rv-avatar.is-anon { background: var(--slate-100); color: var(--slate-500); }
.rv-who { display: flex; flex-direction: column; gap: 1px; min-width: 0; flex: 1; }
.rv-who strong { font-size: 14px; color: var(--text-main); }
.rv-who span { font-size: 12px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
.rv-stars { font-size: 16px; }
.rv-product { display: flex; align-items: baseline; gap: 8px; font-size: 13px; font-weight: 600; color: var(--text-main); }
.rv-product i { font-size: 11px; color: var(--slate-400); }
.rv-comment { margin: 0; font-size: 13.5px; line-height: 1.55; color: var(--text-main); overflow-wrap: anywhere; }
.rv-comment.is-empty { color: var(--text-muted); font-style: italic; }
.rv-card .inv-tags { margin-bottom: 0; }
.rv-reply {
    padding: 10px 12px;
    border-left: 3px solid var(--ep-green);
    border-radius: 0 10px 10px 0;
    background: var(--slate-50);
}
.rv-reply-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px; }
.rv-reply-head span { font-size: 12px; font-weight: 700; color: var(--ep-green-dark); }
.rv-reply p { margin: 0; font-size: 13px; line-height: 1.5; color: var(--text-main); overflow-wrap: anywhere; }
.rv-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding-top: 10px; border-top: 1px solid var(--border); }
.rv-awaiting { margin-right: auto; font-size: 12px; font-weight: 600; color: #b45309; }
.rv-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 40px 16px; text-align: center; color: var(--text-muted); }
.rv-empty p { margin: 0; font-size: 13.5px; }
.rv-empty-icon {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: var(--slate-100); color: var(--slate-400); font-size: 17px;
}
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
    position: relative; width: min(420px, 100%); max-height: min(70vh, 460px); overflow: auto;
    background: #fff; border: 1px solid #d7e8c4; border-radius: 14px;
    box-shadow: 0 16px 40px rgba(17, 24, 39, 0.16); padding: 16px 16px 14px;
    transition: opacity 200ms var(--ease-out), transform 200ms var(--ease-out);
}
.inv-reply-pop { transition: opacity 200ms var(--ease-out); }
/* Enter: scrim fades, card scales up from 0.96 (centred: a dialog is not anchored to its trigger) */
@starting-style {
    .inv-reply-pop:not([hidden]) { opacity: 0; }
    .inv-reply-pop:not([hidden]) .inv-reply-pop-card { opacity: 0; transform: scale(0.96) translateY(6px); }
}
@media (prefers-reduced-motion: reduce) {
    @starting-style { .inv-reply-pop:not([hidden]) .inv-reply-pop-card { transform: none; } }
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
        <p class="inv-reviews-count"><?php echo (int) $total; ?> review<?php echo $total === 1 ? '' : 's'; ?><?php echo ($stars >= 1 || $imagesOnly) ? ' match this filter' : ''; ?></p>
        <?php if ($rows === []): ?>
            <div class="rv-empty">
                <span class="rv-empty-icon" aria-hidden="true"><i class="far fa-star"></i></span>
                <p><?php echo ($stars >= 1 || $imagesOnly) ? 'No reviews match this filter.' : 'No client reviews yet. Completed orders can be reviewed from the client Order History.'; ?></p>
            </div>
        <?php endif; ?>
        <div class="rv-list">
        <?php
        $replyPayload = [];
        foreach ($rows as $row):
            $rating = (int) $row['rating'];
            $when = !empty($row['created_at']) ? date('M d, Y', strtotime((string) $row['created_at'])) : '';
            $isAnon = !empty($row['is_anonymous']);
            $clientName = $isAnon ? 'Anonymous' : (string) $row['client_name'];
            // Tags show as chips, so drop the lines in the comment that only repeat a tag.
            $tagList = array_values(array_filter(array_map('trim', array_map('strval', (array) $row['tag_list'])), 'strlen'));
            $tagKeys = array_map('mb_strtolower', $tagList);
            $commentLines = array_filter(
                preg_split('/\R/u', trim((string) $row['comment'])) ?: [],
                static fn($line) => trim($line) !== '' && !in_array(mb_strtolower(trim($line)), $tagKeys, true)
            );
            $commentText = implode("\n", $commentLines);
            $replyText = ep_review_text_with_tags((string) $row['reply_text'], $row['reply_tag_list']);
            $replyPayload[(string) $row['id']] = [
                'order' => (int) $row['order_id'],
                'reply' => $replyText,
            ];
            $images = array_values(array_filter($row['media'], static fn($media) => ($media['kind'] ?? '') === 'image'));
            ?>
            <article class="rv-card">
                <header class="rv-head">
                    <span class="rv-avatar<?php echo $isAnon ? ' is-anon' : ''; ?>" aria-hidden="true"><?php echo $isAnon ? '<i class="fas fa-user-secret"></i>' : h(mb_strtoupper(mb_substr($clientName, 0, 1))); ?></span>
                    <div class="rv-who">
                        <strong><?php echo h($clientName); ?></strong>
                        <span>#ORD-<?php echo (int) $row['order_id']; ?><?php echo $when !== '' ? ' · ' . h($when) : ''; ?></span>
                    </div>
                    <span class="inv-stars rv-stars" role="img" aria-label="<?php echo $rating; ?> out of 5 stars"><?php for ($i = 1; $i <= 5; $i++): ?><?php if ($i <= $rating): ?>★<?php else: ?><span>★</span><?php endif; ?><?php endfor; ?></span>
                </header>
                <div class="rv-product"><i class="fas fa-box" aria-hidden="true"></i><span><?php echo h((string) ($row['product_names'] ?? 'Product')); ?></span></div>
                <?php if ($commentText !== ''): ?>
                    <p class="rv-comment"><?php echo nl2br(h($commentText)); ?></p>
                <?php elseif ($tagList === []): ?>
                    <p class="rv-comment is-empty">No written comment</p>
                <?php endif; ?>
                <?php if ($tagList !== []): ?>
                    <div class="inv-tags"><?php foreach ($tagList as $tag): ?><span><?php echo h($tag); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ($images !== []): ?>
                    <div class="inv-media">
                        <?php foreach ($images as $media):
                            $src = '../backend/api/review_media.php?id=' . (int) $media['id'];
                            ?>
                            <a href="<?php echo h($src); ?>" target="_blank" rel="noopener"><img src="<?php echo h($src); ?>" alt="Photo from <?php echo h($clientName); ?>'s review" loading="lazy"></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($replyText !== ''): ?>
                    <div class="rv-reply">
                        <div class="rv-reply-head">
                            <span><i class="fas fa-reply" aria-hidden="true"></i> Your reply</span>
                            <button type="button" class="btn btn-outline btn-xs" data-send-reply="<?php echo (int) $row['id']; ?>"><i class="fas fa-pen"></i> Edit</button>
                        </div>
                        <p><?php echo nl2br(h($replyText)); ?></p>
                    </div>
                <?php else: ?>
                    <div class="rv-actions">
                        <span class="rv-awaiting">Not replied yet</span>
                        <button type="button" class="btn btn-primary btn-sm" data-send-reply="<?php echo (int) $row['id']; ?>"><i class="fas fa-reply"></i> Reply</button>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
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
    var lastFocus = null;
    var pressedOnScrim = false;

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
        lastFocus = document.activeElement;
        pop.hidden = false;
        requestAnimationFrame(function () {
            var target = editing ? area : document.getElementById('invReplyEdit');
            if (target) target.focus({ preventScroll: true });
            if (editing) area.setSelectionRange(area.value.length, area.value.length);
        });
    }

    function closeReply() {
        pop.hidden = true;
        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus({ preventScroll: true });
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
        // Scrim closes only when the press also started on it, so a text selection
        // dragged out of the textarea never throws the draft away.
        if (e.target.id === 'invReplyClose' || (e.target === pop && pressedOnScrim)) closeReply();
    });
    pop.addEventListener('mousedown', function (e) { pressedOnScrim = e.target === pop; });

    // Filters apply as soon as they change; Apply stays only as a no-JS fallback.
    var filterForm = document.querySelector('.inv-filter-panel form');
    if (filterForm) {
        var applyBtn = filterForm.querySelector('button[type="submit"]');
        if (applyBtn) applyBtn.hidden = true;
        filterForm.addEventListener('change', function () {
            if (typeof filterForm.requestSubmit === 'function') filterForm.requestSubmit();
            else filterForm.submit();
        });
    }
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
