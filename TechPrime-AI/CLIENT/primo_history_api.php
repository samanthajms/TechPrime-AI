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

function ph_ensure_tables(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db->query(
        "CREATE TABLE IF NOT EXISTS primo_conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(160) NOT NULL DEFAULT 'Chat',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_primo_conv_user_updated (user_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->query(
        "CREATE TABLE IF NOT EXISTS primo_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            role ENUM('user','bot') NOT NULL,
            content MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_primo_msg_conv (conversation_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Remove expired conversations for this user (and orphans older than retention). */
function ph_cleanup(mysqli $db, int $uid): void
{
    $days = PRIMO_HISTORY_DAYS;
    $stmt = $db->prepare(
        "DELETE FROM primo_conversations
         WHERE user_id = ?
           AND updated_at < (NOW() - INTERVAL {$days} DAY)"
    );
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->close();
    }
    /* Drop orphan messages whose parent was deleted (no FK in runtime CREATE). */
    $db->query(
        'DELETE m FROM primo_messages m
         LEFT JOIN primo_conversations c ON c.id = m.conversation_id
         WHERE c.id IS NULL'
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

checkSessionTimeout();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    ph_json(['ok' => false, 'error' => 'auth_required', 'message' => 'Please log in to use chat history.'], 401);
}

$db = getDbConnection();
ph_ensure_tables($db);
$uid = (int)$_SESSION['user_id'];
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

ph_cleanup($db, $uid);

if ($action === 'reset_context' && $method === 'POST') {
    unset($_SESSION['primo_ctx']);
    ph_json(['ok' => true]);
}

if ($action === 'list') {
    $days = PRIMO_HISTORY_DAYS;
    $stmt = $db->prepare(
        "SELECT id, title, created_at, updated_at
         FROM primo_conversations
         WHERE user_id = ?
           AND updated_at >= (NOW() - INTERVAL {$days} DAY)
         ORDER BY updated_at DESC, id DESC
         LIMIT 50"
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $groups = [];
    $conversations = [];
    while ($row = $res->fetch_assoc()) {
        $label = ph_day_label((string)$row['updated_at']);
        $item = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
            'day_label' => $label,
        ];
        $conversations[] = $item;
        if (!isset($groups[$label])) {
            $groups[$label] = [];
        }
        $groups[$label][] = $item;
    }
    $stmt->close();
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
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $conv = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$conv) {
        ph_json(['ok' => false, 'error' => 'not_found'], 404);
    }

    $mstmt = $db->prepare(
        'SELECT id, role, content, created_at
         FROM primo_messages WHERE conversation_id = ? ORDER BY id ASC'
    );
    $mstmt->bind_param('i', $id);
    $mstmt->execute();
    $mres = $mstmt->get_result();
    $messages = [];
    while ($m = $mres->fetch_assoc()) {
        $messages[] = [
            'id' => (int)$m['id'],
            'role' => (string)$m['role'],
            'content' => (string)$m['content'],
            'created_at' => (string)$m['created_at'],
        ];
    }
    $mstmt->close();

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
        $chk->bind_param('ii', $convId, $uid);
        $chk->execute();
        $owned = $chk->get_result()->fetch_assoc();
        $chk->close();
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
        $ins->bind_param('is', $uid, $title);
        if (!$ins->execute()) {
            $ins->close();
            ph_json(['ok' => false, 'error' => 'create_failed'], 500);
        }
        $convId = (int)$db->insert_id;
        $ins->close();
    }

    $msgIns = $db->prepare(
        'INSERT INTO primo_messages (conversation_id, role, content) VALUES (?, ?, ?)'
    );
    if ($userMsg !== '') {
        $role = 'user';
        $msgIns->bind_param('iss', $convId, $role, $userMsg);
        $msgIns->execute();
    }
    if ($botMsg !== '') {
        $role = 'bot';
        $msgIns->bind_param('iss', $convId, $role, $botMsg);
        $msgIns->execute();
    }
    $msgIns->close();

    $touch = $db->prepare('UPDATE primo_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
    $touch->bind_param('ii', $convId, $uid);
    $touch->execute();
    $touch->close();

    ph_json(['ok' => true, 'conversation_id' => $convId]);
}

if ($action === 'delete' && $method === 'POST') {
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        ph_json(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $delMsg = $db->prepare(
        'DELETE m FROM primo_messages m
         INNER JOIN primo_conversations c ON c.id = m.conversation_id
         WHERE m.conversation_id = ? AND c.user_id = ?'
    );
    $delMsg->bind_param('ii', $id, $uid);
    $delMsg->execute();
    $delMsg->close();

    $del = $db->prepare('DELETE FROM primo_conversations WHERE id = ? AND user_id = ?');
    $del->bind_param('ii', $id, $uid);
    $del->execute();
    $affected = $del->affected_rows;
    $del->close();
    if ($affected <= 0) {
        ph_json(['ok' => false, 'error' => 'not_found'], 404);
    }
    ph_json(['ok' => true]);
}

ph_json(['ok' => false, 'error' => 'unknown_action'], 400);
