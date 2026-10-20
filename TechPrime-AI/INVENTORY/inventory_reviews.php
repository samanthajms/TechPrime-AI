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
]);

$starsHtml = static function (int $rating): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<i class="ph-fill ph-star' . ($i <= $rating ? ' is-on' : '') . '" aria-hidden="true"></i>';
    }
    return $out;
};
?>
<div class="inv-stack">
    <?php if ($notice !== ''): ?>
        <div class="alert alert-success" role="status"><i class="ph-fill ph-check-circle" aria-hidden="true"></i> <?php echo h($notice); ?></div>
    <?php endif; ?>
    <?php if ($noticeErr !== ''): ?>
        <div class="alert alert-error" role="alert"><i class="ph-fill ph-x-circle" aria-hidden="true"></i> <?php echo h($noticeErr); ?></div>
    <?php endif; ?>

    <div class="rv-layout">
    <section class="cx-card rv-main" aria-labelledby="rvTitle">
        <div class="cx-card-head">
            <div>
                <h2 id="rvTitle"><i class="ph ph-star" aria-hidden="true"></i> Client reviews</h2>
                <p><span class="cx-num"><?php echo (int) $total; ?></span> review<?php echo $total === 1 ? '' : 's'; ?><?php echo ($stars >= 1 || $imagesOnly) ? ' match this filter' : ', newest first'; ?></p>
            </div>
        </div>
        <?php if ($rows === []): ?>
            <div class="cx-empty">
                <span class="cx-empty-icon" aria-hidden="true"><i class="ph ph-star"></i></span>
                <strong><?php echo ($stars >= 1 || $imagesOnly) ? 'No reviews match this filter' : 'No client reviews yet'; ?></strong>
                <span><?php echo ($stars >= 1 || $imagesOnly) ? 'Try another star rating, or show reviews without photos too.' : 'Completed orders can be reviewed from the client Order History.'; ?></span>
                <?php if ($stars >= 1 || $imagesOnly): ?><a class="cx-btn cx-btn-outline" href="inventory_reviews.php">Show all reviews</a><?php endif; ?>
            </div>
        <?php else: ?>
        <div class="rv-list">
        <?php
        $replyPayload = [];
        foreach ($rows as $row):
            $rating = (int) $row['rating'];
            $when = format_utc_datetime((string) ($row['created_at'] ?? ''), 'M d, Y');
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
            <article class="rv-card<?php echo $replyText === '' ? ' is-pending' : ''; ?>">
                <header class="rv-head">
                    <span class="rv-avatar<?php echo $isAnon ? ' is-anon' : ''; ?>" aria-hidden="true"><?php echo $isAnon ? '<i class="ph ph-user"></i>' : h(mb_strtoupper(mb_substr($clientName, 0, 1))); ?></span>
                    <div class="rv-who">
                        <strong><?php echo h($clientName); ?></strong>
                        <span class="cx-num">#ORD-<?php echo (int) $row['order_id']; ?><?php echo $when !== '' ? ' · ' . h($when) : ''; ?></span>
                    </div>
                    <span class="rv-stars" role="img" aria-label="<?php echo $rating; ?> out of 5 stars"><?php echo $starsHtml($rating); ?></span>
                </header>
                <div class="rv-product"><i class="ph ph-package" aria-hidden="true"></i><span><?php echo h((string) ($row['product_names'] ?? 'Product')); ?></span></div>
                <?php if ($commentText !== ''): ?>
                    <p class="rv-comment"><?php echo nl2br(h($commentText)); ?></p>
                <?php elseif ($tagList === []): ?>
                    <p class="rv-comment is-empty">No written comment</p>
                <?php endif; ?>
                <?php if ($tagList !== []): ?>
                    <div class="rv-tags"><?php foreach ($tagList as $tag): ?><span><?php echo h($tag); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ($images !== []): ?>
                    <div class="rv-media">
                        <?php foreach ($images as $media):
                            $src = '../backend/api/review_media.php?id=' . (int) $media['id'];
                            ?>
                            <a href="<?php echo h($src); ?>" target="_blank" rel="noopener"><img src="<?php echo h($src); ?>" alt="Photo from <?php echo h($clientName); ?>'s review" loading="lazy" width="96" height="72"></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($replyText !== ''): ?>
                    <div class="rv-reply">
                        <div class="rv-reply-head">
                            <span><i class="ph ph-arrow-bend-up-left" aria-hidden="true"></i> Your reply</span>
                            <button type="button" class="cx-btn cx-btn-ghost cx-btn-sm" data-send-reply="<?php echo (int) $row['id']; ?>"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Edit</button>
                        </div>
                        <p><?php echo nl2br(h($replyText)); ?></p>
                    </div>
                <?php else: ?>
                    <div class="rv-actions">
                        <span class="rv-awaiting"><i class="ph-fill ph-chat-circle-dots" aria-hidden="true"></i> Not replied yet</span>
                        <button type="button" class="cx-btn cx-btn-primary cx-btn-sm" data-send-reply="<?php echo (int) $row['id']; ?>"><i class="ph ph-arrow-bend-up-left" aria-hidden="true"></i> Reply</button>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php staff_pagination($page, max(1, $pages), $total, $perPage, $filterQuery, 'Reviews pages'); ?>
    </section>
    <aside class="cx-card inv-filter-panel" aria-labelledby="rvFilterTitle">
        <div class="cx-card-head">
            <h2 id="rvFilterTitle"><i class="ph ph-funnel" aria-hidden="true"></i> Filter</h2>
        </div>
        <form method="get" action="inventory_reviews.php" class="rv-filter-form">
            <fieldset class="rv-filter-group">
                <legend>Stars</legend>
                <label class="rv-filter-opt"><input type="radio" name="stars" value="0" <?php echo $stars === 0 ? 'checked' : ''; ?>> <span>All ratings</span></label>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <label class="rv-filter-opt">
                        <input type="radio" name="stars" value="<?php echo $star; ?>" <?php echo $stars === $star ? 'checked' : ''; ?>>
                        <span class="rv-stars" aria-hidden="true"><?php echo $starsHtml($star); ?></span>
                        <span class="cx-sr"><?php echo $star; ?> star<?php echo $star === 1 ? '' : 's'; ?></span>
                    </label>
                <?php endfor; ?>
            </fieldset>
            <label class="rv-filter-opt rv-filter-images">
                <input type="checkbox" name="images" value="1" <?php echo $imagesOnly ? 'checked' : ''; ?>>
                <span>With photos only</span>
            </label>
            <button type="submit" class="cx-btn cx-btn-primary">Apply</button>
        </form>
    </aside>
    </div>
</div>
<div class="inv-reply-pop" id="invReplyPop" hidden>
    <div class="inv-reply-pop-card" role="dialog" aria-modal="true" aria-labelledby="invReplyPopTitle">
        <button type="button" class="inv-reply-pop-x" id="invReplyClose" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        <h3 id="invReplyPopTitle"><i class="ph ph-arrow-bend-up-left" aria-hidden="true"></i> Reply</h3>
        <p class="inv-reply-pop-order cx-num" id="invReplyOrder"></p>
        <div class="inv-reply-pop-view" id="invReplyView"></div>
        <div class="inv-reply-pop-actions" id="invReplyViewActions">
            <button type="button" class="cx-btn cx-btn-primary" id="invReplyEdit"><i class="ph ph-pencil-simple" aria-hidden="true"></i> Edit reply</button>
        </div>
        <form method="post" id="invReplyForm" hidden>
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="reply_review">
            <input type="hidden" name="review_id" id="invReplyId" value="">
            <input type="hidden" name="page" value="<?php echo (int) $page; ?>">
            <input type="hidden" name="stars" value="<?php echo (int) $stars; ?>">
            <?php if ($imagesOnly): ?><input type="hidden" name="images" value="1"><?php endif; ?>
            <p class="form-label inv-reply-chips-label" id="invReplyChipsLabel">Quick replies</p>
            <div class="inv-reply-chips" role="group" aria-labelledby="invReplyChipsLabel">
                <?php foreach ($staffTags as $tag): ?>
                    <label class="inv-reply-chip">
                        <input type="checkbox" name="reply_tags[]" value="<?php echo h($tag); ?>">
                        <span><?php echo h($tag); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <label class="cx-sr" for="invReplyText">Reply</label>
            <textarea name="reply_text" id="invReplyText" class="form-control" maxlength="1000" placeholder="Write a reply"></textarea>
            <div class="inv-reply-pop-actions">
                <button type="button" class="cx-btn cx-btn-ghost" id="invReplyCancel">Cancel</button>
                <button type="submit" class="cx-btn cx-btn-primary"><i class="ph ph-paper-plane-tilt" aria-hidden="true"></i> Save reply</button>
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
