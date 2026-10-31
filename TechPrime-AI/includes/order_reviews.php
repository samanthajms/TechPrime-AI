<?php
/**
 * Order reviews: one review per completed order, visible to every inventory custodian.
 */

function ep_review_client_tags(): array
{
    return [
        'Good quality',
        'Fast delivery',
        'Well packed',
        'Item as described',
        'Worth the price',
        'Excellent service',
        'Average quality',
        'Poor quality',
        'Slow delivery',
        'Not as described',
        'Damaged packaging',
    ];
}

function ep_review_staff_tags(): array
{
    return [
        'Thank you for your review',
        'Glad you liked it',
        'We appreciate your feedback',
        'Sorry for the inconvenience',
        'We will look into this',
        'Thanks for shopping at EasyPC',
    ];
}

function ep_review_ensure_schema(PDO $db): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $check = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'order_reviews'");
    if (!$check || !$check->fetchColumn()) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS public.order_reviews (
            id SERIAL PRIMARY KEY,
            order_id INTEGER NOT NULL UNIQUE REFERENCES public.orders(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
            rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
            comment TEXT NOT NULL DEFAULT '',
            tags TEXT NOT NULL DEFAULT '[]',
            created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reply_text TEXT NOT NULL DEFAULT '',
            reply_tags TEXT NOT NULL DEFAULT '[]',
            reply_user_id INTEGER NULL REFERENCES public.users(id) ON DELETE SET NULL,
            replied_at TIMESTAMP WITHOUT TIME ZONE NULL,
            is_anonymous SMALLINT NOT NULL DEFAULT 0
        )"
    );
    $db->exec('CREATE INDEX IF NOT EXISTS order_reviews_user_idx ON public.order_reviews (user_id, id DESC)');
    $db->exec(
        "CREATE TABLE IF NOT EXISTS public.order_review_media (
            id SERIAL PRIMARY KEY,
            review_id INTEGER NOT NULL REFERENCES public.order_reviews(id) ON DELETE CASCADE,
            stored_name VARCHAR(80) NOT NULL,
            media_kind VARCHAR(10) NOT NULL CHECK (media_kind IN ('image', 'video')),
            created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $db->exec('CREATE INDEX IF NOT EXISTS order_review_media_review_idx ON public.order_review_media (review_id, id)');
    }
    $col = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'order_reviews' AND column_name = 'is_anonymous'");
    if (!$col || !$col->fetchColumn()) {
        $db->exec('ALTER TABLE public.order_reviews ADD COLUMN is_anonymous SMALLINT NOT NULL DEFAULT 0');
    }
    $ready = true;
}

function ep_review_tags_from_request(array $posted, array $allowed): array
{
    $picked = [];
    foreach ($posted as $tag) {
        $tag = trim((string) $tag);
        if ($tag !== '' && in_array($tag, $allowed, true) && !in_array($tag, $picked, true)) {
            $picked[] = $tag;
        }
        if (count($picked) >= 8) {
            break;
        }
    }
    return $picked;
}

function ep_review_text_with_tags(string $text, array $tags): string
{
    $text = trim($text);
    foreach ($tags as $tag) {
        $tag = trim((string) $tag);
        if ($tag === '') {
            continue;
        }
        if ($text === '') {
            $text = $tag;
            continue;
        }
        if (mb_stripos($text, $tag) === false) {
            $text .= "\n" . $tag;
        }
    }
    return $text;
}

function ep_review_decode_tags(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $tag) {
        if (is_string($tag) && $tag !== '') {
            $out[] = $tag;
        }
    }
    return $out;
}

/**
 * @param list<int> $orderIds
 * @return array<int, array<string, mixed>>
 */
function ep_review_map_for_user(PDO $db, int $userId, array $orderIds): array
{
    if ($orderIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $db->prepare(
        "SELECT * FROM order_reviews WHERE user_id = ? AND order_id IN ($marks)"
    );
    $stmt->execute(array_merge([$userId], $orderIds));
    $map = [];
    $reviewIds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['tag_list'] = ep_review_decode_tags((string) $row['tags']);
        $row['reply_tag_list'] = ep_review_decode_tags((string) $row['reply_tags']);
        $row['media'] = [];
        $map[(int) $row['order_id']] = $row;
        $reviewIds[] = (int) $row['id'];
    }
    ep_review_attach_media($db, $map, $reviewIds, 'order_id');
    return $map;
}

/**
 * @param array<int, array<string, mixed>> $map
 * @param list<int> $reviewIds
 */
function ep_review_attach_media(PDO $db, array &$map, array $reviewIds, string $keyField): void
{
    if ($reviewIds === []) {
        return;
    }
    $marks = implode(',', array_fill(0, count($reviewIds), '?'));
    $stmt = $db->prepare(
        "SELECT id, review_id, stored_name, media_kind FROM order_review_media WHERE review_id IN ($marks) ORDER BY id"
    );
    $stmt->execute($reviewIds);
    $byReview = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $media) {
        $byReview[(int) $media['review_id']][] = [
            'id' => (int) $media['id'],
            'kind' => (string) $media['media_kind'],
        ];
    }
    foreach ($map as $key => $row) {
        $rid = (int) ($row['id'] ?? 0);
        $map[$key]['media'] = $byReview[$rid] ?? [];
    }
}

/**
 * @return array{0:string,1:array<int,array{tmp:string,ext:string,kind:string}>}
 */
function ep_review_collect_uploads(array $files): array
{
    if ($files === [] || !isset($files['name']) || !is_array($files['name'])) {
        return ['', []];
    }
    $allowed = [
        'image/jpeg' => ['ext' => 'jpg', 'kind' => 'image', 'max' => 5 * 1024 * 1024],
        'image/png' => ['ext' => 'png', 'kind' => 'image', 'max' => 5 * 1024 * 1024],
    ];
    $count = count($files['name']);
    if ($count > 5) {
        return ['too_many', []];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $ready = [];
    for ($i = 0; $i < $count; $i++) {
        $err = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['upload_failed', []];
        }
        $tmp = (string) ($files['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['upload_failed', []];
        }
        $mime = $finfo->file($tmp) ?: '';
        if (!isset($allowed[$mime])) {
            return ['bad_type', []];
        }
        $rule = $allowed[$mime];
        if ((int) ($files['size'][$i] ?? 0) > $rule['max']) {
            return ['too_large', []];
        }
        $ready[] = ['tmp' => $tmp, 'ext' => $rule['ext'], 'kind' => $rule['kind']];
    }
    return ['', $ready];
}

function ep_review_media_dir(): string
{
    return dirname(__DIR__) . '/uploads/reviews';
}

/**
 * @param list<array{tmp:string,ext:string,kind:string}> $uploads
 */
function ep_review_submit(PDO $db, int $userId, int $orderId, int $rating, string $comment, array $tags, array $uploads, bool $anonymous = false): string
{
    if ($rating < 1 || $rating > 5) {
        return 'bad_rating';
    }
    $comment = ep_review_text_with_tags($comment, $tags);
    if (mb_strlen($comment) > 1000) {
        return 'bad_comment';
    }
    $own = $db->prepare(
        "SELECT id FROM orders
         WHERE id = ? AND user_id = ? AND LOWER(TRIM(status)) IN ('to_review', 'to review')
         LIMIT 1"
    );
    $own->execute([$orderId, $userId]);
    if (!$own->fetchColumn()) {
        return 'not_reviewable';
    }
    $exists = $db->prepare('SELECT id FROM order_reviews WHERE order_id = ? LIMIT 1');
    $exists->execute([$orderId]);
    if ($exists->fetchColumn()) {
        return 'already_reviewed';
    }

    $dir = ep_review_media_dir();
    if ($uploads !== [] && !is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return 'upload_failed';
    }

    $moved = [];
    $db->beginTransaction();
    try {
        $ins = $db->prepare(
            'INSERT INTO order_reviews (order_id, user_id, rating, comment, tags, is_anonymous)
             VALUES (?, ?, ?, ?, ?, ?)
             RETURNING id'
        );
        $ins->execute([$orderId, $userId, $rating, $comment, json_encode(array_values($tags)), $anonymous ? 1 : 0]);
        $reviewId = (int) $ins->fetchColumn();
        $mediaIns = $db->prepare(
            'INSERT INTO order_review_media (review_id, stored_name, media_kind) VALUES (?, ?, ?)'
        );
        foreach ($uploads as $file) {
            $name = bin2hex(random_bytes(16)) . '.' . $file['ext'];
            $dest = $dir . '/' . $name;
            if (!move_uploaded_file($file['tmp'], $dest)) {
                throw new RuntimeException('move_failed');
            }
            $moved[] = $dest;
            $mediaIns->execute([$reviewId, $name, $file['kind']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($moved as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        return 'upload_failed';
    }
    logActivity($db, $userId, 'order_review', 'order #' . $orderId . ' rating ' . $rating);
    return '';
}

function ep_review_reply(PDO $db, int $staffId, int $reviewId, string $text, array $tags): string
{
    $text = ep_review_text_with_tags($text, $tags);
    if (mb_strlen($text) > 1000) {
        return 'bad_comment';
    }
    if ($text === '') {
        return 'empty_reply';
    }
    $stmt = $db->prepare(
        'UPDATE order_reviews
         SET reply_text = ?, reply_tags = ?, reply_user_id = ?, replied_at = CURRENT_TIMESTAMP
         WHERE id = ?
         RETURNING id'
    );
    $stmt->execute([$text, json_encode(array_values($tags)), $staffId, $reviewId]);
    if (!$stmt->fetchColumn()) {
        return 'missing';
    }
    logActivity($db, $staffId, 'review_reply', 'review #' . $reviewId);
    return '';
}

/**
 * @return array{rows:list<array>, total:int}
 */
function ep_review_remove_videos(PDO $db): void
{
    $stmt = $db->query("SELECT id, stored_name FROM order_review_media WHERE media_kind = 'video'");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($rows === []) {
        return;
    }
    $dir = ep_review_media_dir();
    foreach ($rows as $row) {
        $path = $dir . '/' . basename((string) $row['stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $db->exec("DELETE FROM order_review_media WHERE media_kind = 'video'");
}

function ep_review_list(PDO $db, string $search, int $page, int $perPage, int $stars = 0, bool $imagesOnly = false): array
{
    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $clause = '(u.name ILIKE ? OR r.comment ILIKE ?';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        if (preg_match('/\d+/', $search, $digits)) {
            $clause .= ' OR CAST(r.order_id AS TEXT) = ?';
            $params[] = $digits[0];
        }
        $where[] = $clause . ')';
    }
    if ($stars >= 1 && $stars <= 5) {
        $where[] = 'r.rating = ?';
        $params[] = $stars;
    }
    if ($imagesOnly) {
        $where[] = "EXISTS (SELECT 1 FROM order_review_media m WHERE m.review_id = r.id AND m.media_kind = 'image')";
    }
    $where = implode(' AND ', $where);
    $count = $db->prepare(
        "SELECT COUNT(*) FROM order_reviews r JOIN users u ON u.id = r.user_id WHERE $where"
    );
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare(
        "SELECT r.*, u.name AS client_name
         FROM order_reviews r
         JOIN users u ON u.id = r.user_id
         WHERE $where
         ORDER BY r.created_at DESC, r.id DESC
         LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    $ids = [];
    foreach ($rows as $row) {
        $row['tag_list'] = ep_review_decode_tags((string) $row['tags']);
        $row['reply_tag_list'] = ep_review_decode_tags((string) $row['reply_tags']);
        $row['media'] = [];
        $map[(int) $row['id']] = $row;
        $ids[] = (int) $row['id'];
    }
    ep_review_attach_media($db, $map, $ids, 'id');
    $orderIds = [];
    foreach ($map as $row) {
        $orderIds[] = (int) $row['order_id'];
    }
    $orderIds = array_values(array_unique($orderIds));
    $productNames = [];
    if ($orderIds !== []) {
        $marks = implode(',', array_fill(0, count($orderIds), '?'));
        $items = $db->prepare(
            "SELECT oi.order_id, COALESCE(NULLIF(TRIM(p.name), ''), 'Product') AS name
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id IN ($marks)
             ORDER BY oi.id"
        );
        $items->execute($orderIds);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $productNames[(int) $item['order_id']][] = (string) $item['name'];
        }
    }
    foreach ($map as $id => $row) {
        $names = $productNames[(int) $row['order_id']] ?? [];
        $map[$id]['product_names'] = $names === [] ? 'Product' : implode(', ', $names);
    }
    return ['rows' => array_values($map), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function ep_review_stream(PDO $db, int $mediaId, int $userId, string $role): void
{
    $stmt = $db->prepare(
        'SELECT m.stored_name, m.media_kind, r.user_id
         FROM order_review_media m
         JOIN order_reviews r ON r.id = m.review_id
         WHERE m.id = ?
         LIMIT 1'
    );
    $stmt->execute([$mediaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    if ($role !== 'inventory_custodian' && (int) $row['user_id'] !== $userId) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }
    $name = basename((string) $row['stored_name']);
    $path = ep_review_media_dir() . '/' . $name;
    if (!is_file($path)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    $kind = (string) $row['media_kind'];
    $type = $kind === 'video' ? 'video/mp4' : (str_ends_with(strtolower($name), '.png') ? 'image/png' : 'image/jpeg');
    header('Content-Type: ' . $type);
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . $name . '"');
    readfile($path);
}
