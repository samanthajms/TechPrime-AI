(function () {
    var modal = document.getElementById('epReviewModal');
    var backdrop = document.getElementById('epReviewBackdrop');
    var dataEl = document.getElementById('epReviewData');
    if (!modal || !dataEl) return;

    var reviews = {};
    try { reviews = JSON.parse(dataEl.textContent || '{}') || {}; } catch (e) { reviews = {}; }

    var form = document.getElementById('epReviewForm');
    var orderId = document.getElementById('epReviewOrderId');
    var ratingInput = document.getElementById('epReviewRating');
    var label = document.getElementById('epReviewOrderLabel');
    var comment = document.getElementById('epReviewComment');
    var files = document.getElementById('epReviewFiles');
    var existing = document.getElementById('epReviewExisting');
    var replyBox = document.getElementById('epReviewReply');
    var submit = document.getElementById('epReviewSubmit');
    var stars = modal.querySelectorAll('[data-star]');

    function paintStars(n) {
        stars.forEach(function (btn) {
            btn.classList.toggle('is-on', Number(btn.getAttribute('data-star')) <= n);
        });
        ratingInput.value = String(n);
    }

    function closeModal() {
        modal.hidden = true;
        if (backdrop) backdrop.hidden = true;
    }

    function textWithTags(text, tags) {
        text = String(text || '').replace(/\s+$/, '');
        (tags || []).forEach(function (tag) {
            tag = String(tag || '').trim();
            if (!tag) return;
            if (text.toLowerCase().indexOf(tag.toLowerCase()) === -1) {
                text = text === '' ? tag : text + '\n' + tag;
            }
        });
        return text;
    }

    function setLine(text, phrase, on) {
        phrase = String(phrase || '').trim();
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

    function openModal(id) {
        var saved = reviews[String(id)] || null;
        orderId.value = String(id);
        label.textContent = 'Order #ORD-' + id;
        form.reset();
        orderId.value = String(id);
        paintStars(saved ? Number(saved.rating) || 5 : 5);
        comment.value = saved ? textWithTags(saved.comment || '', saved.tags || []) : '';
        var chipBlock = document.getElementById('epReviewChips');
        var chipLabel = document.getElementById('epReviewChipLabel');
        if (chipBlock) chipBlock.hidden = !!saved;
        if (chipLabel) chipLabel.hidden = !!saved;
        modal.querySelectorAll('input[name="tags[]"]').forEach(function (box) {
            box.checked = false;
            box.disabled = !!saved;
        });
        comment.disabled = !!saved;
        var anon = document.getElementById('epReviewAnon');
        if (anon) {
            anon.checked = !!(saved && saved.anonymous);
            anon.disabled = !!saved;
        }
        var fileBlock = document.getElementById('epReviewFileBlock');
        if (fileBlock) fileBlock.hidden = !!saved;
        files.disabled = !!saved;
        stars.forEach(function (btn) { btn.disabled = !!saved; });
        submit.hidden = !!saved;
        existing.hidden = true;
        existing.innerHTML = '';
        if (saved && saved.media && saved.media.length) {
            existing.hidden = false;
            saved.media.forEach(function (file) {
                var url = '../backend/api/review_media.php?id=' + encodeURIComponent(file.id);
                if (file.kind === 'video') {
                    existing.insertAdjacentHTML('beforeend', '<video controls src="' + url + '"></video>');
                } else {
                    existing.insertAdjacentHTML('beforeend', '<img src="' + url + '" alt="">');
                }
            });
        }
        replyBox.hidden = true;
        replyBox.innerHTML = '';
        if (saved && ((saved.reply && saved.reply !== '') || (saved.reply_tags && saved.reply_tags.length))) {
            replyBox.hidden = false;
            var text = textWithTags(saved.reply || '', saved.reply_tags || []).replace(/[&<>"]/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
            });
            replyBox.innerHTML = '<strong>Store reply</strong>' + (text ? '<p>' + text.replace(/\n/g, '<br>') + '</p>' : '');
        }
        modal.hidden = false;
        if (backdrop) backdrop.hidden = false;
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-open-review]');
        if (opener) {
            openModal(opener.getAttribute('data-open-review'));
            return;
        }
        var star = e.target.closest('[data-star]');
        if (star && modal.contains(star) && !star.disabled) {
            paintStars(Number(star.getAttribute('data-star')));
        }
    });

    modal.querySelectorAll('input[name="tags[]"]').forEach(function (box) {
        box.addEventListener('change', function () {
            if (box.disabled) return;
            comment.value = setLine(comment.value, box.value, box.checked);
        });
    });

    var closeBtn = document.getElementById('epReviewClose');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });
})();
