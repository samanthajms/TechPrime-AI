<?php
/**
 * chat_messages.php — AJAX endpoint for the floating chat widget
 * Place in: /CLIENT/chat_messages.php
 * Actions: get_sellers | get_history | send
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit;
}

$db     = getDbConnection();
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── GET SELLERS ───────────────────────────────────────────────────────────────
if ($action === 'get_sellers') {
    // All verified seller accounts + last message snippet + unread count
    $sql = "SELECT 
                u.id, u.name, u.surname, u.shop_description,
                (SELECT m.message FROM messages m 
                 WHERE (m.sender_id = u.id AND m.receiver_id = ?) 
                    OR (m.sender_id = ? AND m.receiver_id = u.id)
                 ORDER BY m.created_at DESC LIMIT 1) AS last_msg,
                (SELECT m.created_at FROM messages m 
                 WHERE (m.sender_id = u.id AND m.receiver_id = ?) 
                    OR (m.sender_id = ? AND m.receiver_id = u.id)
                 ORDER BY m.created_at DESC LIMIT 1) AS last_time,
                (SELECT COUNT(*) FROM messages m 
                 WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.is_read = 0) AS unread
            FROM users u
            WHERE u.role = 'seller' AND u.is_verified = 1 AND u.is_locked = 0
            ORDER BY last_time DESC, u.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("iiiii", $userId, $userId, $userId, $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $sellers = [];
    while ($row = $result->fetch_assoc()) {
        $sellers[] = [
            'id'          => (int)$row['id'],
            'name'        => $row['name'],
            'surname'     => $row['surname'],
            'shop_desc'   => $row['shop_description'] ? mb_substr($row['shop_description'], 0, 60) . '…' : '',
            'last_msg'    => $row['last_msg'] ? mb_substr($row['last_msg'], 0, 50) . (mb_strlen($row['last_msg']) > 50 ? '…' : '') : null,
            'last_time'   => $row['last_time'],
            'unread'      => (int)$row['unread'],
        ];
    }
    echo json_encode(['sellers' => $sellers]);
    exit;
}

// ── GET MESSAGE HISTORY ───────────────────────────────────────────────────────
if ($action === 'get_history') {
    $sellerId = (int)($_GET['seller_id'] ?? 0);
    if (!$sellerId) { echo json_encode(['messages' => []]); exit; }

    // Mark messages from seller as read
    $mark = $db->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
    $mark->bind_param("ii", $sellerId, $userId);
    $mark->execute();

    $sql = "SELECT id, sender_id, message, created_at 
            FROM messages 
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
            ORDER BY created_at ASC
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iiii", $userId, $sellerId, $sellerId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $msgs = [];
    while ($row = $result->fetch_assoc()) {
        $msgs[] = [
            'id'         => (int)$row['id'],
            'sender_id'  => (int)$row['sender_id'],
            'mine'       => (int)$row['sender_id'] === $userId,
            'message'    => $row['message'],
            'created_at' => $row['created_at'],
        ];
    }
    echo json_encode(['messages' => $msgs]);
    exit;
}

// ── SEND MESSAGE ──────────────────────────────────────────────────────────────
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sellerId = (int)($_POST['seller_id'] ?? 0);
    $message  = trim($_POST['message'] ?? '');

    if (!$sellerId || $message === '') {
        echo json_encode(['error' => 'Invalid input']); exit;
    }

    // Verify target is actually a seller
    $check = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'seller' AND is_locked = 0");
    $check->bind_param("i", $sellerId);
    $check->execute();
    if (!$check->get_result()->num_rows) {
        echo json_encode(['error' => 'Seller not found']); exit;
    }

    $stmt = $db->prepare("INSERT INTO messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $userId, $sellerId, $message);
    if ($stmt->execute()) {
        $newId = $db->insert_id;
        echo json_encode([
            'ok'         => true,
            'id'         => $newId,
            'mine'       => true,
            'message'    => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } else {
        echo json_encode(['error' => 'DB error']);
    }
    exit;
}

// ── POLL NEW MESSAGES ─────────────────────────────────────────────────────────
if ($action === 'poll') {
    $sellerId  = (int)($_GET['seller_id'] ?? 0);
    $lastMsgId = (int)($_GET['last_id'] ?? 0);
    if (!$sellerId) { echo json_encode(['messages' => []]); exit; }

    $sql = "SELECT id, sender_id, message, created_at 
            FROM messages 
            WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
              AND id > ?
            ORDER BY created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iiiii", $userId, $sellerId, $sellerId, $userId, $lastMsgId);
    $stmt->execute();
    $result = $stmt->get_result();

    $msgs = [];
    while ($row = $result->fetch_assoc()) {
        // mark incoming as read
        if ((int)$row['sender_id'] === $sellerId) {
            $mark = $db->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
            $mark->bind_param("i", $row['id']);
            $mark->execute();
        }
        $msgs[] = [
            'id'         => (int)$row['id'],
            'sender_id'  => (int)$row['sender_id'],
            'mine'       => (int)$row['sender_id'] === $userId,
            'message'    => $row['message'],
            'created_at' => $row['created_at'],
        ];
    }
    echo json_encode(['messages' => $msgs]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
