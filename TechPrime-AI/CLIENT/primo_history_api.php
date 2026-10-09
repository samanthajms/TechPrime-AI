<?php
/**
 * Primo chat history API — list / get / append / delete / reset context.
 * Conversations belong to the authenticated client only; 7-day retention.
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const PRIMO_HISTORY_DAYS = 7;

function ph_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ph_ensure_tables(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db->exec(
        "CREATE TABLE IF NOT EXISTS primo_conversations (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            title VARCHAR(160) NOT NULL DEFAULT 'Chat',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $db->exec("CREATE INDEX IF NOT EXISTS idx_primo_conv_user_updated ON primo_conversations (user_id, updated_at)");
    $db->exec(
        "CREATE TABLE IF NOT EXISTS primo_messages (
            id SERIAL PRIMARY KEY,
            conversation_id INTEGER NOT NULL,
            role VARCHAR(10) NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $db->exec("CREATE INDEX IF NOT EXISTS idx_primo_msg_conv ON primo_messages (conversation_id, id)");
}

/** Remove expired conversations for this user (and orphans older than retention). */
function ph_cleanup(PDO $db, int $uid): void
{
    $days = PRIMO_HISTORY_DAYS;
    $stmt = $db->prepare(
        "DELETE FROM primo_conversations
         WHERE user_id = ?
           AND updated_at < (NOW() - make_interval(days => {$days}))"
    );
    if ($stmt) {
        $stmt->execute([$uid]);
    }
    /* Drop orphan messages whose parent was deleted (no FK in runtime CREATE). */
    $db->exec(
        'DELETE FROM primo_messages
         WHERE NOT EXISTS (
            SELECT 1 FROM primo_conversations c WHERE c.id = primo_messages.conversation_id
         )'
    );
}

function ph_day_label(string $datetime): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return 'Earlier';
    }
    $today = strtotime('today');
    $yesterday = strtotime('yesterday');
    if ($ts >= $today) {
        return 'Today';
    }
    if ($ts >= $yesterday) {
        return 'Yesterday';
    }
    return date('M j, Y', $ts);
}

/**
 * Product names from a stored Primo reply. primo_product_intent() (backend/api/primo_chat.php)
 * writes one line per product: "• {name} — ₱{price} — {category} — {availability}".
 * @return list<string>
 */
function ph_bullet_product_names(string $content): array
{
    $names = [];
    foreach (preg_split('/\R/u', $content) ?: [] as $line) {
        if (preg_match('/^\s*•\s+(.+?)\s+—\s+₱[\d,]+(?:\.\d+)?\s+—\s/u', $line, $m)) {
            $names[] = trim($m[1]);
        }
    }
    return $names;
}

/**
 * Re-attach product cards to stored bot replies (same shape as primo_chat.php's "products"),
 * with current price/stock. A reply only gets cards when every listed product still resolves,
 * otherwise it stays as text so no line silently disappears.
 * @param list<array{role:string,content:string}> $messages
 * @return list<array>
 */
function ph_attach_products(PDO $db, array $messages): array
{
    $perMessage = [];
    $all = [];
    foreach ($messages as $i => $m) {
        if ($m['role'] !== 'bot') {
            continue;
        }
        $names = ph_bullet_product_names($m['content']);
        if ($names) {
            $perMessage[$i] = $names;
            foreach ($names as $n) {
                $all[$n] = true;
            }
        }
    }
    if (!$all) {
        return $messages;
    }

    $names = array_slice(array_keys($all), 0, 200);
    $place = implode(',', array_fill(0, count($names), '?'));
    $stmt = $db->prepare(
        "SELECT id, name, price, stock, category, image, image_url
         FROM products WHERE name IN ({$place}) ORDER BY id DESC"
    );
    $stmt->execute($names);
    $byName = [];
    while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = (string)$p['name'];
        $img = ias_client_product_image_url($p);
        if (isset($byName[$key]) || $img === '') {
            continue;
        }
        $byName[$key] = [
            'id' => (int)$p['id'],
            'name' => $key,
            'price' => (float)$p['price'],
            'stock' => (int)($p['stock'] ?? 0),
            'category' => (string)($p['category'] ?? ''),
            'image' => $img,
        ];
    }

    foreach ($perMessage as $i => $list) {
        $cards = [];
        foreach ($list as $n) {
            if (!isset($byName[$n])) {
                $cards = [];
                break;
            }
            $cards[] = $byName[$n];
        }
        if ($cards) {
            $messages[$i]['products'] = $cards;
        }
    }
    return $messages;
}

/** One-line preview of a stored message for the Recent chats list. */
function ph_preview(string $content): string
{
    $line = '';
    foreach (preg_split('/\R/u', $content) ?: [] as $l) {
        $l = trim($l);
        if ($l !== '') {
            $line = $l;
            break;
        }
    }
    $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
    return mb_strlen($line) > 90 ? mb_substr($line, 0, 87) . '…' : $line;
}

checkSessionTimeout();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = [];
$action = '';

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? 'list');
} else {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $action = (string)($payload['action'] ?? '');
    $token = (string)($payload['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        ph_json(['ok' => false, 'error' => 'csrf', 'message' => 'Invalid security token. Refresh and try again.'], 400);
    }
}

/* New chat clears Primo's context, which lives only in the session — guests need it too. */
if ($action === 'reset_context' && $method === 'POST') {
    unset($_SESSION['primo_ctx']);
    ph_json(['ok' => true]);
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    ph_json(['ok' => false, 'error' => 'auth_required', 'message' => 'Please log in to use chat history.'], 401);
}

$db = getDbConnection();
ph_ensure_tables($db);
$uid = (int)$_SESSION['user_id'];
ph_cleanup($db, $uid);

if ($action === 'list') {
    $days = PRIMO_HISTORY_DAYS;
    $stmt = $db->prepare(
        "SELECT c.id, c.title, c.created_at, c.updated_at,
                (SELECT COUNT(*) FROM primo_messages m WHERE m.conversation_id = c.id) AS message_count,
                lm.role AS last_role, lm.content AS last_content
         FROM primo_conversations c
         LEFT JOIN LATERAL (
             SELECT m.role, m.content FROM primo_messages m
             WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1
         ) lm ON TRUE
         WHERE c.user_id = ?
           AND c.updated_at >= (NOW() - make_interval(days => {$days}))
         ORDER BY c.updated_at DESC, c.id DESC
         LIMIT 50"
    );
    $stmt->execute([$uid]);
    $res = $stmt;
    $groups = [];
    $conversations = [];
    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        $label = ph_day_label((string)$row['updated_at']);
        $item = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
            'day_label' => $label,
            'message_count' => (int)$row['message_count'],
            'last_role' => (string)($row['last_role'] ?? ''),
            'preview' => ph_preview((string)($row['last_content'] ?? '')),
        ];
        $conversations[] = $item;
        if (!isset($groups[$label])) {
            $groups[$label] = [];
        }
        $groups[$label][] = $item;
    }
    $grouped = [];
    foreach ($groups as $label => $items) {
        $grouped[] = ['label' => $label, 'items' => $items];
    }
    ph_json(['ok' => true, 'conversations' => $conversations, 'grouped' => $grouped]);
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? ($payload['id'] ?? 0));
    if ($id <= 0) {
        ph_json(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $stmt = $db->prepare(
        'SELECT id, title, created_at, updated_at
         FROM primo_conversations WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$id, $uid]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conv) {
        ph_json(['ok' => false, 'error' => 'not_found'], 404);
    }

    $mstmt = $db->prepare(
        'SELECT id, role, content, created_at
         FROM primo_messages WHERE conversation_id = ? ORDER BY id ASC'
    );
    $mstmt->execute([$id]);
    $mres = $mstmt;
    $messages = [];
    while ($m = $mres->fetch(PDO::FETCH_ASSOC)) {
        $messages[] = [
            'id' => (int)$m['id'],
            'role' => (string)$m['role'],
            'content' => (string)$m['content'],
            'created_at' => (string)$m['created_at'],
        ];
    }
    $messages = ph_attach_products($db, $messages);
    ph_json([
        'ok' => true,
        'conversation' => [
            'id' => (int)$conv['id'],
            'title' => (string)$conv['title'],
            'created_at' => (string)$conv['created_at'],
            'updated_at' => (string)$conv['updated_at'],
            'messages' => $messages,
        ],
    ]);
}

if ($action === 'append' && $method === 'POST') {
    $convId = (int)($payload['conversation_id'] ?? 0);
    $userMsg = trim((string)($payload['user_message'] ?? ''));
    $botMsg = trim((string)($payload['bot_message'] ?? ''));
    if ($userMsg === '' && $botMsg === '') {
        ph_json(['ok' => false, 'error' => 'empty'], 400);
    }
    if (mb_strlen($userMsg) > 2000) {
        $userMsg = mb_substr($userMsg, 0, 2000);
    }
    if (mb_strlen($botMsg) > 8000) {
        $botMsg = mb_substr($botMsg, 0, 8000);
    }

    if ($convId > 0) {
        $chk = $db->prepare('SELECT id FROM primo_conversations WHERE id = ? AND user_id = ? LIMIT 1');
        $chk->execute([$convId, $uid]);
        $owned = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$owned) {
            ph_json(['ok' => false, 'error' => 'not_found'], 404);
        }
    } else {
        $title = $userMsg !== '' ? $userMsg : 'Chat';
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        if (mb_strlen($title) > 60) {
            $title = mb_substr($title, 0, 57) . '…';
        }
        if ($title === '') {
            $title = 'Chat';
        }
        $ins = $db->prepare('INSERT INTO primo_conversations (user_id, title) VALUES (?, ?)');
        if (!$ins->execute([$uid, $title])) {
            ph_json(['ok' => false, 'error' => 'create_failed'], 500);
        }
        $convId = (int)$db->lastInsertId();
    }

    $msgIns = $db->prepare(
        'INSERT INTO primo_messages (conversation_id, role, content) VALUES (?, ?, ?)'
    );
    if ($userMsg !== '') {
        $role = 'user';
        $msgIns->execute([$convId, $role, $userMsg]);
    }
    if ($botMsg !== '') {
        $role = 'bot';
        $msgIns->execute([$convId, $role, $botMsg]);
    }
    $touch = $db->prepare('UPDATE primo_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
    $touch->execute([$convId, $uid]);
    ph_json(['ok' => true, 'conversation_id' => $convId]);
}

if ($action === 'delete' && $method === 'POST') {
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        ph_json(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $delMsg = $db->prepare(
        'DELETE FROM primo_messages
         WHERE conversation_id = ?
           AND EXISTS (
                SELECT 1 FROM primo_conversations c
                WHERE c.id = primo_messages.conversation_id AND c.user_id = ?
           )'
    );
    $delMsg->execute([$id, $uid]);
    $del = $db->prepare('DELETE FROM primo_conversations WHERE id = ? AND user_id = ?');
    $del->execute([$id, $uid]);
    $affected = $del->rowCount();
    if ($affected <= 0) {
        ph_json(['ok' => false, 'error' => 'not_found'], 404);
    }
    ph_json(['ok' => true]);
}

ph_json(['ok' => false, 'error' => 'unknown_action'], 400);
