<?php
/**
 * AJAX endpoint for staff messaging (widget + full Messages pages).
 * GET:  get_staff | get_history | poll | file | search | media
 * POST: send | react | unsend | forward | pin | unpin   (all require csrf_token)
 *
 * Every message-level action re-checks that the current user is one of the two
 * people in that message's conversation.
 */
session_start();
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/staff_layout.php';
require_once __DIR__ . '/staff_chat_lib.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$role = (string)($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !staff_chat_role_ok($role)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDbConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$userId = (int)$_SESSION['user_id'];
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$roleList = staff_chat_allowed_roles();
$roleIn = implode(',', array_fill(0, count($roleList), '?'));

const STAFF_CHAT_MAX_TEXT = 4000;
const STAFF_CHAT_HISTORY_LIMIT = 100;

function staff_chat_json(array $payload): never
{
    echo json_encode($payload);
    exit;
}

function staff_chat_peer_id(): int
{
    return (int)($_GET['staff_id'] ?? $_POST['staff_id'] ?? $_GET['seller_id'] ?? $_POST['seller_id'] ?? 0);
}

/** A chat-enabled, unlocked staff account other than me. */
function staff_chat_valid_peer(PDO $db, int $peerId, int $userId, array $roleList, string $roleIn): bool
{
    if ($peerId <= 0 || $peerId === $userId) {
        return false;
    }
    $check = $db->prepare("SELECT 1 FROM users WHERE id = ? AND role IN ($roleIn) AND COALESCE(is_locked, 0) = 0");
    $check->execute(array_merge([$peerId], $roleList));
    return (bool)$check->fetchColumn();
}

/** Load a message only if the current user sent or received it. */
function staff_chat_own_message(PDO $db, int $messageId, int $userId): ?array
{
    $q = $db->prepare('SELECT * FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?)');
    $q->execute([$messageId, $userId, $userId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function staff_chat_snippet(?string $text, int $len = 80): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text) ?? '');
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '…' : $text;
}

/**
 * Messages of one conversation that the user hasn't removed for themselves (oldest first).
 * $afterId: only newer messages (poll). $beforeId: the page just before that message ("load earlier").
 * $hasMore is set to true when older messages exist beyond the returned page.
 */
function staff_chat_fetch(PDO $db, int $userId, int $peerId, int $afterId = 0, int $beforeId = 0, ?bool &$hasMore = null): array
{
    $sql = "SELECT m.id, m.sender_id, m.message, m.created_at, m.reply_to_id, m.is_forwarded, m.deleted_for_all,
                   m.attachment_path, m.attachment_name, m.attachment_mime, m.attachment_size,
                   r.sender_id AS reply_sender_id, r.message AS reply_message,
                   r.deleted_for_all AS reply_deleted, r.attachment_name AS reply_attachment
            FROM messages m
            LEFT JOIN messages r ON r.id = m.reply_to_id
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
              AND m.id > ?
              AND (? = 0 OR m.id < ?)
              AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
            ORDER BY m.id DESC
            LIMIT " . (STAFF_CHAT_HISTORY_LIMIT + 1);
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $peerId, $peerId, $userId, $afterId, $beforeId, $beforeId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > STAFF_CHAT_HISTORY_LIMIT;
    $rows = array_reverse(array_slice($rows, 0, STAFF_CHAT_HISTORY_LIMIT));

    $reactions = staff_chat_reactions($db, $userId, array_map(fn($r) => (int)$r['id'], $rows));
    return array_map(fn($r) => staff_chat_format($r, $userId, $reactions[(int)$r['id']] ?? []), $rows);
}

/** @return array<int, list<array{emoji:string,count:int,mine:bool}>> */
function staff_chat_reactions(PDO $db, int $userId, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT message_id, user_id, emoji FROM message_reactions WHERE message_id IN ($in) ORDER BY created_at");
    $stmt->execute($ids);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$row['message_id'];
        $e = (string)$row['emoji'];
        $out[$mid][$e] ??= ['emoji' => $e, 'count' => 0, 'mine' => false];
        $out[$mid][$e]['count']++;
        if ((int)$row['user_id'] === $userId) {
            $out[$mid][$e]['mine'] = true;
        }
    }
    return array_map('array_values', $out);
}

function staff_chat_format(array $r, int $userId, array $reactions): array
{
    $deleted = (int)$r['deleted_for_all'] === 1;
    $mine = (int)$r['sender_id'] === $userId;
    $text = $deleted ? '' : (string)$r['message'];
    $attachment = null;
    if (!$deleted && !empty($r['attachment_path'])) {
        $attachment = [
            'name' => (string)$r['attachment_name'],
            'mime' => (string)$r['attachment_mime'],
            'size' => (int)$r['attachment_size'],
            'is_image' => staff_chat_is_image_mime($r['attachment_mime']),
        ];
    }
    $reply = null;
    if (!$deleted && !empty($r['reply_to_id'])) {
        $replyDeleted = (int)($r['reply_deleted'] ?? 0) === 1;
        $replyText = $replyDeleted ? 'Message unsent' : staff_chat_snippet((string)$r['reply_message']);
        if (!$replyDeleted && $replyText === '' && !empty($r['reply_attachment'])) {
            $replyText = '📎 ' . $r['reply_attachment'];
        }
        $reply = [
            'id' => (int)$r['reply_to_id'],
            'mine' => (int)$r['reply_sender_id'] === $userId,
            'text' => $replyText,
            'deleted' => $replyDeleted,
        ];
    }
    // `message` stays a plain display string for the floating widget, which only renders text.
    $display = $text;
    if ($deleted) {
        $display = $mine ? 'You unsent a message' : 'Message unsent';
    } elseif ($display === '' && $attachment) {
        $display = '📎 ' . $attachment['name'];
    }
    return [
        'id' => (int)$r['id'],
        'sender_id' => (int)$r['sender_id'],
        'mine' => $mine,
        'message' => $display,
        'text' => $text,
        'created_at' => $r['created_at'],
        'deleted' => $deleted,
        'forwarded' => (int)$r['is_forwarded'] === 1,
        'reply' => $reply,
        'attachment' => $attachment,
        'reactions' => $deleted ? [] : $reactions,
    ];
}

/** Pinned messages of a conversation, newest pin first. */
function staff_chat_pins(PDO $db, int $userId, int $peerId): array
{
    $stmt = $db->prepare(
        "SELECT m.id, m.sender_id, m.message, m.created_at, m.attachment_name, m.attachment_mime, m.attachment_size, p.pinned_by
         FROM message_pins p
         JOIN messages m ON m.id = p.message_id
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
           AND m.deleted_for_all = 0
           AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
         ORDER BY p.created_at DESC"
    );
    $stmt->execute([$userId, $peerId, $peerId, $userId, $userId]);
    $pins = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $text = staff_chat_snippet((string)$row['message'], 120);
        $pins[] = [
            'id' => (int)$row['id'],
            'mine' => (int)$row['sender_id'] === $userId,
            'text' => $text !== '' ? $text : '📎 ' . (string)$row['attachment_name'],
            'full_text' => (string)$row['message'],
            'created_at' => $row['created_at'],
            'attachment' => $row['attachment_name'] !== null ? [
                'name' => (string)$row['attachment_name'],
                'mime' => (string)$row['attachment_mime'],
                'size' => (int)$row['attachment_size'],
                'is_image' => staff_chat_is_image_mime($row['attachment_mime']),
            ] : null,
            'pinned_by_me' => (int)$row['pinned_by'] === $userId,
        ];
    }
    return $pins;
}

/**
 * Current reactions / unsend state / pins for the loaded window of a conversation.
 * Returned with a version hash so polls only ship it when something changed.
 */
function staff_chat_state(PDO $db, int $userId, int $peerId): array
{
    $stmt = $db->prepare(
        "SELECT m.id, m.deleted_for_all FROM messages m
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
           AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
         ORDER BY m.id DESC LIMIT " . STAFF_CHAT_HISTORY_LIMIT
    );
    $stmt->execute([$userId, $peerId, $peerId, $userId, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $reactions = staff_chat_reactions($db, $userId, array_map(fn($r) => (int)$r['id'], $rows));
    $items = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $items[$id] = ['deleted' => (int)$r['deleted_for_all'] === 1, 'reactions' => $reactions[$id] ?? []];
    }
    $state = ['items' => $items, 'pins' => staff_chat_pins($db, $userId, $peerId)];
    $state['v'] = md5(json_encode($state));
    return $state;
}

function staff_chat_mark_read(PDO $db, int $peerId, int $userId): void
{
    $mark = $db->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0');
    $mark->execute([$peerId, $userId]);
}

// ── GET STAFF ────────────────────────────────────────────────────────────────
if ($action === 'get_staff' || $action === 'get_sellers') {
    $sql = "SELECT u.id, u.name, u.surname, u.role, u.profile_image,
                   lm.message AS last_msg, lm.created_at AS last_time, lm.sender_id AS last_sender,
                   lm.deleted_for_all AS last_deleted, lm.attachment_name AS last_attachment,
                   (SELECT COUNT(*) FROM messages m
                    WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.is_read = 0 AND m.deleted_for_all = 0) AS unread
            FROM users u
            LEFT JOIN LATERAL (
                SELECT m.message, m.created_at, m.sender_id, m.deleted_for_all, m.attachment_name
                FROM messages m
                WHERE ((m.sender_id = u.id AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = u.id))
                  AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
                ORDER BY m.id DESC LIMIT 1
            ) lm ON TRUE
            WHERE u.role IN ($roleIn)
              AND COALESCE(u.is_locked, 0) = 0
              AND u.id != ?
            ORDER BY lm.created_at DESC NULLS LAST, u.name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$userId, $userId, $userId, $userId], $roleList, [$userId]));
    $staff = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lastMine = (int)($row['last_sender'] ?? 0) === $userId;
        $last = null;
        if ($row['last_time'] !== null) {
            if ((int)$row['last_deleted'] === 1) {
                $last = $lastMine ? 'You unsent a message' : 'Message unsent';
            } else {
                $last = staff_chat_snippet((string)$row['last_msg'], 50);
                if ($last === '' && $row['last_attachment'] !== null) {
                    $last = '📎 ' . staff_chat_snippet((string)$row['last_attachment'], 40);
                }
            }
        }
        $staff[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'surname' => $row['surname'],
            'role' => $row['role'],
            'role_label' => staff_role_label((string)$row['role']),
            'avatar' => staff_chat_avatar_url($row['profile_image'] ?? null),
            'last_msg' => $last,
            'last_time' => $row['last_time'],
            'last_mine' => $lastMine,
            'unread' => (int)$row['unread'],
        ];
    }
    $meStmt = $db->prepare('SELECT id, name, surname, role, profile_image FROM users WHERE id = ?');
    $meStmt->execute([$userId]);
    $meRow = $meStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $me = [
        'id' => $userId,
        'name' => $meRow['name'] ?? '',
        'surname' => $meRow['surname'] ?? '',
        'role' => $meRow['role'] ?? $role,
        'avatar' => staff_chat_avatar_url($meRow['profile_image'] ?? null),
    ];
    staff_chat_json(['staff' => $staff, 'sellers' => $staff, 'me' => $me]);
}

// ── GET MESSAGE HISTORY ───────────────────────────────────────────────────────
if ($action === 'get_history') {
    $peerId = staff_chat_peer_id();
    if (!$peerId) {
        staff_chat_json(['messages' => []]);
    }
    $beforeId = max(0, (int)($_GET['before_id'] ?? 0));
    if ($beforeId) {
        // "Load earlier messages": just the older page.
        $page = staff_chat_fetch($db, $userId, $peerId, 0, $beforeId, $hasMore);
        staff_chat_json(['messages' => $page, 'has_more' => $hasMore]);
    }
    staff_chat_mark_read($db, $peerId, $userId);
    $state = staff_chat_state($db, $userId, $peerId);
    $page = staff_chat_fetch($db, $userId, $peerId, 0, 0, $hasMore);
    staff_chat_json([
        'messages' => $page,
        'has_more' => $hasMore,
        'pins' => $state['pins'],
        'v' => $state['v'],
    ]);
}

// ── POLL NEW MESSAGES (+ state changes) ──────────────────────────────────────
if ($action === 'poll') {
    $peerId = staff_chat_peer_id();
    $lastMsgId = (int)($_GET['last_id'] ?? 0);
    if (!$peerId) {
        staff_chat_json(['messages' => []]);
    }
    $msgs = staff_chat_fetch($db, $userId, $peerId, $lastMsgId);
    foreach ($msgs as $m) {
        if (!$m['mine']) {
            staff_chat_mark_read($db, $peerId, $userId);
            break;
        }
    }
    $out = ['messages' => $msgs];
    // Only the full page sends `v`; the widget never asks for state.
    if (isset($_GET['v'])) {
        $state = staff_chat_state($db, $userId, $peerId);
        if ($state['v'] !== (string)$_GET['v']) {
            $out['state'] = $state;
        }
    }
    staff_chat_json($out);
}

// ── SEARCH IN CONVERSATION ───────────────────────────────────────────────────
if ($action === 'search') {
    $peerId = staff_chat_peer_id();
    $q = trim((string)($_GET['q'] ?? ''));
    if (!$peerId || mb_strlen($q) < 2) {
        staff_chat_json(['results' => [], 'total' => 0]);
    }
    $q = mb_substr($q, 0, 100);
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $stmt = $db->prepare(
        "SELECT m.id, m.sender_id, m.message, m.attachment_name, m.created_at
         FROM messages m
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
           AND m.deleted_for_all = 0
           AND (m.message ILIKE ? OR m.attachment_name ILIKE ?)
           AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
         ORDER BY m.id DESC
         LIMIT 101"
    );
    $stmt->execute([$userId, $peerId, $peerId, $userId, $like, $like, $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results = [];
    foreach (array_slice($rows, 0, 100) as $row) {
        $text = (string)$row['message'];
        if (mb_stripos($text, $q) === false && $row['attachment_name'] !== null) {
            $text = '📎 ' . $row['attachment_name'];
        }
        // Short window of text around the first match.
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $pos = mb_stripos($text, $q);
        $start = $pos === false ? 0 : max(0, $pos - 30);
        $snippet = ($start > 0 ? '…' : '') . mb_substr($text, $start, 110) . (mb_strlen($text) > $start + 110 ? '…' : '');
        $results[] = [
            'id' => (int)$row['id'],
            'mine' => (int)$row['sender_id'] === $userId,
            'snippet' => $snippet,
            'created_at' => $row['created_at'],
        ];
    }
    staff_chat_json(['results' => $results, 'total' => count($rows)]);
}

// ── MEDIA AND FILES OF A CONVERSATION ────────────────────────────────────────
if ($action === 'media') {
    $peerId = staff_chat_peer_id();
    if (!$peerId) {
        staff_chat_json(['items' => [], 'has_more' => false]);
    }
    $images = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $not = ($_GET['kind'] ?? '') === 'files' ? 'NOT' : '';
    $beforeId = max(0, (int)($_GET['before_id'] ?? 0));
    $stmt = $db->prepare(
        "SELECT m.id, m.sender_id, m.attachment_name, m.attachment_mime, m.attachment_size, m.created_at
         FROM messages m
         WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
           AND m.attachment_path IS NOT NULL
           AND m.deleted_for_all = 0
           AND m.attachment_mime $not IN (?, ?, ?, ?)
           AND (? = 0 OR m.id < ?)
           AND NOT EXISTS (SELECT 1 FROM message_hidden h WHERE h.message_id = m.id AND h.user_id = ?)
         ORDER BY m.id DESC
         LIMIT 61"
    );
    $stmt->execute(array_merge([$userId, $peerId, $peerId, $userId], $images, [$beforeId, $beforeId, $userId]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = array_map(fn($r) => [
        'id' => (int)$r['id'],
        'mine' => (int)$r['sender_id'] === $userId,
        'name' => (string)$r['attachment_name'],
        'mime' => (string)$r['attachment_mime'],
        'size' => (int)$r['attachment_size'],
        'is_image' => staff_chat_is_image_mime($r['attachment_mime']),
        'created_at' => $r['created_at'],
    ], array_slice($rows, 0, 60));
    staff_chat_json(['items' => $items, 'has_more' => count($rows) > 60]);
}

// ── DOWNLOAD / VIEW AN ATTACHMENT ────────────────────────────────────────────
if ($action === 'file') {
    $msg = staff_chat_own_message($db, (int)($_GET['id'] ?? 0), $userId);
    $hidden = false;
    if ($msg) {
        $h = $db->prepare('SELECT 1 FROM message_hidden WHERE message_id = ? AND user_id = ?');
        $h->execute([(int)$msg['id'], $userId]);
        $hidden = (bool)$h->fetchColumn();
    }
    $abs = ($msg && !$hidden && (int)$msg['deleted_for_all'] === 0)
        ? staff_chat_stored_file($msg['attachment_path'] ?? null)
        : null;
    if ($abs === null) {
        http_response_code(404);
        staff_chat_json(['error' => 'File not found']);
    }

    $mime = (string)$msg['attachment_mime'];
    $name = (string)$msg['attachment_name'];
    // Only verified raster images may render inline; everything else is a forced download.
    $inline = isset($_GET['inline']) && staff_chat_is_image_mime($mime);
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'file';

    header_remove('Content-Type');
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . filesize($abs));
    header(sprintf(
        'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
        $inline ? 'inline' : 'attachment',
        $ascii,
        rawurlencode($name)
    ));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: private, max-age=3600');
    readfile($abs);
    exit;
}

// Everything below changes data.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    staff_chat_json(['error' => 'Unknown action']);
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    staff_chat_json(['error' => 'Invalid CSRF token']);
}

// ── SEND MESSAGE (text, reply, attachment) ───────────────────────────────────
if ($action === 'send') {
    $peerId = staff_chat_peer_id();
    $message = trim((string)($_POST['message'] ?? ''));
    $replyTo = (int)($_POST['reply_to_id'] ?? 0);
    $hasFile = isset($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!staff_chat_valid_peer($db, $peerId, $userId, $roleList, $roleIn)) {
        staff_chat_json(['error' => 'Staff member not found']);
    }
    if ($message === '' && !$hasFile) {
        staff_chat_json(['error' => 'Type a message or attach a file.']);
    }
    if (mb_strlen($message) > STAFF_CHAT_MAX_TEXT) {
        staff_chat_json(['error' => 'Messages can be at most ' . STAFF_CHAT_MAX_TEXT . ' characters.']);
    }
    if ($replyTo) {
        $orig = staff_chat_own_message($db, $replyTo, $userId);
        $sameChat = $orig && in_array((int)$orig['sender_id'], [$userId, $peerId], true)
            && in_array((int)$orig['receiver_id'], [$userId, $peerId], true);
        if (!$sameChat || (int)$orig['deleted_for_all'] === 1) {
            staff_chat_json(['error' => 'The message you replied to is no longer available.']);
        }
    }

    $file = null;
    if ($hasFile) {
        // Simple flood guard: at most 10 attachments per minute per user.
        $rate = $db->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND attachment_path IS NOT NULL
                              AND created_at > CURRENT_TIMESTAMP - INTERVAL '1 minute'");
        $rate->execute([$userId]);
        if ((int)$rate->fetchColumn() >= 10) {
            staff_chat_json(['error' => 'Too many files sent. Please wait a minute.']);
        }
        $file = staff_chat_store_upload($_FILES['attachment']);
        if (is_string($file)) {
            staff_chat_json(['error' => $file]);
        }
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO messages (sender_id, receiver_id, message, reply_to_id,
                                   attachment_path, attachment_name, attachment_mime, attachment_size)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id, created_at'
        );
        $stmt->execute([
            $userId, $peerId, $message, $replyTo ?: null,
            $file['path'] ?? null, $file['name'] ?? null, $file['mime'] ?? null, $file['size'] ?? null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($file && ($abs = staff_chat_stored_file($file['path']))) {
            @unlink($abs);
        }
        staff_chat_json(['error' => 'Could not send the message.']);
    }
    staff_chat_json(['ok' => true, 'id' => (int)$row['id'], 'mine' => true, 'message' => $message, 'created_at' => $row['created_at']]);
}

// All remaining actions act on one existing message in one of my conversations.
$msgId = (int)($_POST['message_id'] ?? 0);
$msg = staff_chat_own_message($db, $msgId, $userId);
if (!$msg) {
    staff_chat_json(['error' => 'Message not found.']);
}
$isMine = (int)$msg['sender_id'] === $userId;
if ((int)$msg['deleted_for_all'] === 1 && $action !== 'unsend') {
    staff_chat_json(['error' => 'This message was unsent.']);
}

// ── REACT (toggle; one reaction per person per message) ──────────────────────
if ($action === 'react') {
    $emoji = (string)($_POST['emoji'] ?? '');
    if (!staff_chat_emoji_allowed($emoji)) {
        staff_chat_json(['error' => 'That reaction is not available.']);
    }
    $cur = $db->prepare('SELECT emoji FROM message_reactions WHERE message_id = ? AND user_id = ?');
    $cur->execute([$msgId, $userId]);
    if ($cur->fetchColumn() === $emoji) {
        $db->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?')->execute([$msgId, $userId]);
    } else {
        $db->prepare(
            'INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)
             ON CONFLICT (message_id, user_id) DO UPDATE SET emoji = EXCLUDED.emoji, created_at = CURRENT_TIMESTAMP'
        )->execute([$msgId, $userId, $emoji]);
    }
    staff_chat_json(['ok' => true]);
}

// ── UNSEND (only the sender: for everyone, or just for themselves) ───────────
if ($action === 'unsend') {
    if (!$isMine) {
        staff_chat_json(['error' => 'You can only remove messages you sent.']);
    }
    $scope = (string)($_POST['scope'] ?? '');
    if ($scope === 'me') {
        $db->prepare('INSERT INTO message_hidden (message_id, user_id) VALUES (?, ?) ON CONFLICT DO NOTHING')
            ->execute([$msgId, $userId]);
        staff_chat_json(['ok' => true]);
    }
    if ($scope !== 'all') {
        staff_chat_json(['error' => 'Invalid option.']);
    }
    $db->beginTransaction();
    try {
        $db->prepare(
            "UPDATE messages SET deleted_for_all = 1, message = '',
                    attachment_path = NULL, attachment_name = NULL, attachment_mime = NULL, attachment_size = NULL
             WHERE id = ? AND sender_id = ?"
        )->execute([$msgId, $userId]);
        $db->prepare('DELETE FROM message_reactions WHERE message_id = ?')->execute([$msgId]);
        $db->prepare('DELETE FROM message_pins WHERE message_id = ?')->execute([$msgId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        staff_chat_json(['error' => 'Could not unsend the message.']);
    }
    if ($abs = staff_chat_stored_file($msg['attachment_path'] ?? null)) {
        @unlink($abs);
    }
    staff_chat_json(['ok' => true]);
}

// ── FORWARD to another staff member ──────────────────────────────────────────
if ($action === 'forward') {
    $toId = (int)($_POST['to_staff_id'] ?? 0);
    if (!staff_chat_valid_peer($db, $toId, $userId, $roleList, $roleIn)) {
        staff_chat_json(['error' => 'Staff member not found']);
    }
    $copy = null;
    if ($src = staff_chat_stored_file($msg['attachment_path'] ?? null)) {
        // Each message owns its own file, so unsending one never breaks the other.
        $ext = pathinfo($src, PATHINFO_EXTENSION);
        $copy = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!@copy($src, staff_chat_upload_dir() . '/' . $copy)) {
            staff_chat_json(['error' => 'Could not forward the attachment.']);
        }
    }
    try {
        $stmt = $db->prepare(
            'INSERT INTO messages (sender_id, receiver_id, message, is_forwarded,
                                   attachment_path, attachment_name, attachment_mime, attachment_size)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([
            $userId, $toId, (string)$msg['message'],
            $copy, $copy ? $msg['attachment_name'] : null, $copy ? $msg['attachment_mime'] : null, $copy ? $msg['attachment_size'] : null,
        ]);
    } catch (Throwable $e) {
        if ($copy && ($abs = staff_chat_stored_file($copy))) {
            @unlink($abs);
        }
        staff_chat_json(['error' => 'Could not forward the message.']);
    }
    staff_chat_json(['ok' => true, 'id' => (int)$stmt->fetchColumn()]);
}

// ── PIN / UNPIN (shared by both people in the conversation) ──────────────────
if ($action === 'pin') {
    $db->prepare('INSERT INTO message_pins (message_id, pinned_by) VALUES (?, ?) ON CONFLICT DO NOTHING')
        ->execute([$msgId, $userId]);
    staff_chat_json(['ok' => true]);
}
if ($action === 'unpin') {
    $db->prepare('DELETE FROM message_pins WHERE message_id = ?')->execute([$msgId]);
    staff_chat_json(['ok' => true]);
}

staff_chat_json(['error' => 'Unknown action']);
