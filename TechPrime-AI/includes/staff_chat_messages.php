<?php
/**
 * AJAX endpoint for staff messaging (widget + full Messages pages).
 * Actions: get_staff | get_history | send | poll
 */
session_start();
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/staff_layout.php';
require_once __DIR__ . '/staff_chat_lib.php';

header('Content-Type: application/json');

$role = (string)($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !staff_chat_role_ok($role)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDbConnection();
$userId = (int)$_SESSION['user_id'];
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$hasRead = staff_chat_has_is_read($db);
$roleList = staff_chat_allowed_roles();
$roleIn = implode(',', array_fill(0, count($roleList), '?'));

function staff_chat_peer_id(): int
{
    return (int)($_GET['staff_id'] ?? $_POST['staff_id'] ?? $_GET['seller_id'] ?? $_POST['seller_id'] ?? 0);
}

// ── GET STAFF ────────────────────────────────────────────────────────────────
if ($action === 'get_staff' || $action === 'get_sellers') {
    $unreadSql = $hasRead
        ? "(SELECT COUNT(*) FROM messages m
            WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.is_read = 0) AS unread"
        : '0 AS unread';

    $sql = "SELECT
                u.id, u.name, u.surname, u.role,
                (SELECT m.message FROM messages m
                 WHERE (m.sender_id = u.id AND m.receiver_id = ?)
                    OR (m.sender_id = ? AND m.receiver_id = u.id)
                 ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
                (SELECT m.created_at FROM messages m
                 WHERE (m.sender_id = u.id AND m.receiver_id = ?)
                    OR (m.sender_id = ? AND m.receiver_id = u.id)
                 ORDER BY m.created_at DESC LIMIT 1) AS last_time,
                $unreadSql
            FROM users u
            WHERE u.role IN ($roleIn)
              AND COALESCE(u.is_locked, 0) = 0
              AND u.id != ?
            ORDER BY last_time DESC NULLS LAST, u.name ASC";

    $bind = [$userId, $userId, $userId, $userId];
    if ($hasRead) {
        $bind[] = $userId;
    }
    foreach ($roleList as $r) {
        $bind[] = $r;
    }
    $bind[] = $userId;

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $staff = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $last = (string)($row['last_msg'] ?? '');
        $staff[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'surname' => $row['surname'],
            'role' => $row['role'],
            'role_label' => staff_role_label((string)$row['role']),
            'last_msg' => $last !== '' ? mb_substr($last, 0, 50) . (mb_strlen($last) > 50 ? '…' : '') : null,
            'last_time' => $row['last_time'],
            'unread' => (int)$row['unread'],
        ];
    }
    echo json_encode(['staff' => $staff, 'sellers' => $staff]);
    exit;
}

// ── GET MESSAGE HISTORY ───────────────────────────────────────────────────────
if ($action === 'get_history') {
    $peerId = staff_chat_peer_id();
    if (!$peerId) {
        echo json_encode(['messages' => []]);
        exit;
    }

    if ($hasRead) {
        $mark = $db->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?');
        $mark->execute([$peerId, $userId]);
    }

    $sql = "SELECT id, sender_id, message, created_at
            FROM messages
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
            ORDER BY created_at ASC
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $peerId, $peerId, $userId]);
    $msgs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $msgs[] = [
            'id' => (int)$row['id'],
            'sender_id' => (int)$row['sender_id'],
            'mine' => (int)$row['sender_id'] === $userId,
            'message' => $row['message'],
            'created_at' => $row['created_at'],
        ];
    }
    echo json_encode(['messages' => $msgs]);
    exit;
}

// ── SEND MESSAGE ──────────────────────────────────────────────────────────────
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $peerId = staff_chat_peer_id();
    $message = trim((string)($_POST['message'] ?? ''));
    if (!$peerId || $peerId === $userId || $message === '') {
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    $checkSql = "SELECT id FROM users
                 WHERE id = ? AND role IN ($roleIn) AND COALESCE(is_locked, 0) = 0";
    $check = $db->prepare($checkSql);
    $check->execute(array_merge([$peerId], $roleList));
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['error' => 'Staff member not found']);
        exit;
    }

    $stmt = $db->prepare(
        'INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())'
    );
    if ($stmt->execute([$userId, $peerId, $message])) {
        echo json_encode([
            'ok' => true,
            'id' => (int)$db->lastInsertId(),
            'mine' => true,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } else {
        echo json_encode(['error' => 'DB error']);
    }
    exit;
}

// ── POLL NEW MESSAGES ─────────────────────────────────────────────────────────
if ($action === 'poll') {
    $peerId = staff_chat_peer_id();
    $lastMsgId = (int)($_GET['last_id'] ?? 0);
    if (!$peerId) {
        echo json_encode(['messages' => []]);
        exit;
    }

    $sql = "SELECT id, sender_id, message, created_at
            FROM messages
            WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
              AND id > ?
            ORDER BY created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $peerId, $peerId, $userId, $lastMsgId]);
    $msgs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($hasRead && (int)$row['sender_id'] === $peerId) {
            $mark = $db->prepare('UPDATE messages SET is_read = 1 WHERE id = ?');
            $mark->execute([(int)$row['id']]);
        }
        $msgs[] = [
            'id' => (int)$row['id'],
            'sender_id' => (int)$row['sender_id'],
            'mine' => (int)$row['sender_id'] === $userId,
            'message' => $row['message'],
            'created_at' => $row['created_at'],
        ];
    }
    echo json_encode(['messages' => $msgs]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
