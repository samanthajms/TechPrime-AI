<?php
/**
 * Saved Builds API — persist / list / get / delete Tech & Match builds
 * for the authenticated client only.
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sb_ensure_table(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db->query(
        "CREATE TABLE IF NOT EXISTS saved_builds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            build_name VARCHAR(150) NOT NULL,
            components_json MEDIUMTEXT NOT NULL,
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            component_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_saved_builds_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function sb_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

checkSessionTimeout();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    sb_json(['ok' => false, 'error' => 'auth_required', 'message' => 'Please log in to manage saved builds.'], 401);
}

$db = getDbConnection();
sb_ensure_table($db);
$uid = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
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
        sb_json(['ok' => false, 'error' => 'csrf', 'message' => 'Invalid security token. Refresh and try again.'], 400);
    }
}

if ($action === 'list') {
    $stmt = $db->prepare(
        'SELECT id, build_name, components_json, total_price, component_count, created_at
         FROM saved_builds WHERE user_id = ? ORDER BY created_at DESC, id DESC'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $builds = [];
    while ($row = $res->fetch_assoc()) {
        $comps = json_decode((string)$row['components_json'], true);
        $builds[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['build_name'],
            'total_price' => (float)$row['total_price'],
            'component_count' => (int)$row['component_count'],
            'created_at' => (string)$row['created_at'],
            'components' => is_array($comps) ? $comps : new stdClass(),
        ];
    }
    $stmt->close();
    sb_json(['ok' => true, 'builds' => $builds]);
}

if ($action === 'get') {
    $id = (int)($_GET['id'] ?? ($payload['id'] ?? 0));
    if ($id <= 0) {
        sb_json(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $stmt = $db->prepare(
        'SELECT id, build_name, components_json, total_price, component_count, created_at
         FROM saved_builds WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        sb_json(['ok' => false, 'error' => 'not_found'], 404);
    }
    $comps = json_decode((string)$row['components_json'], true);
    sb_json([
        'ok' => true,
        'build' => [
            'id' => (int)$row['id'],
            'name' => (string)$row['build_name'],
            'total_price' => (float)$row['total_price'],
            'component_count' => (int)$row['component_count'],
            'created_at' => (string)$row['created_at'],
            'components' => is_array($comps) ? $comps : new stdClass(),
        ],
    ]);
}

if ($action === 'save' && $method === 'POST') {
    $name = trim((string)($payload['build_name'] ?? ''));
    $components = $payload['components'] ?? null;
    $updateId = (int)($payload['id'] ?? 0);
    if ($name === '') {
        sb_json(['ok' => false, 'error' => 'empty_name', 'message' => 'Please enter a name for your build.'], 400);
    }
    if (mb_strlen($name) > 120) {
        sb_json(['ok' => false, 'error' => 'name_too_long', 'message' => 'Build name is too long.'], 400);
    }
    if (!is_array($components) || empty($components)) {
        sb_json(['ok' => false, 'error' => 'empty_build', 'message' => 'Select at least one component before saving.'], 400);
    }

    $clean = [];
    $total = 0.0;
    $count = 0;
    foreach ($components as $slot => $item) {
        if (!is_array($item)) {
            continue;
        }
        $slotKey = preg_replace('/[^a-z0-9_]/i', '', (string)$slot);
        if ($slotKey === '') {
            continue;
        }
        $pid = (int)($item['id'] ?? 0);
        $pname = trim((string)($item['name'] ?? ''));
        $price = (float)($item['price'] ?? 0);
        if ($pid <= 0 || $pname === '') {
            continue;
        }
        $clean[$slotKey] = [
            'id' => $pid,
            'name' => mb_substr($pname, 0, 255),
            'price' => round($price, 2),
            'image' => mb_substr((string)($item['image'] ?? ''), 0, 255),
            'category' => mb_substr((string)($item['category'] ?? ''), 0, 100),
        ];
        $total += $price;
        $count++;
    }
    if ($count <= 0) {
        sb_json(['ok' => false, 'error' => 'empty_build', 'message' => 'Select at least one component before saving.'], 400);
    }

    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

    /* Update existing owned build when editing. */
    if ($updateId > 0) {
        $stmt = $db->prepare(
            'UPDATE saved_builds
             SET build_name = ?, components_json = ?, total_price = ?, component_count = ?
             WHERE id = ? AND user_id = ?'
        );
        $stmt->bind_param('ssdiii', $name, $json, $total, $count, $updateId, $uid);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $affected < 0) {
            sb_json(['ok' => false, 'error' => 'save_failed', 'message' => 'Could not update your build. Please try again.'], 500);
        }
        if ($affected === 0) {
            /* Verify ownership — 0 rows may mean values unchanged or not found. */
            $chk = $db->prepare('SELECT id FROM saved_builds WHERE id = ? AND user_id = ? LIMIT 1');
            $chk->bind_param('ii', $updateId, $uid);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$exists) {
                sb_json(['ok' => false, 'error' => 'not_found', 'message' => 'Build not found.'], 404);
            }
        }
        logActivity($db, $uid, 'update_pc_build', 'Updated build #' . $updateId . ': ' . $name);
        sb_json([
            'ok' => true,
            'id' => $updateId,
            'name' => $name,
            'updated' => true,
            'message' => 'Build updated successfully.',
        ]);
    }

    $stmt = $db->prepare(
        'INSERT INTO saved_builds (user_id, build_name, components_json, total_price, component_count)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issdi', $uid, $name, $json, $total, $count);
    $ok = $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    if (!$ok) {
        sb_json(['ok' => false, 'error' => 'save_failed', 'message' => 'Could not save your build. Please try again.'], 500);
    }
    logActivity($db, $uid, 'save_pc_build', 'Saved build #' . $newId . ': ' . $name);
    sb_json([
        'ok' => true,
        'id' => $newId,
        'name' => $name,
        'message' => 'Build saved successfully.',
    ]);
}

if ($action === 'add_to_cart' && $method === 'POST') {
    require_once __DIR__ . '/../includes/client_helpers.php';
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        sb_json(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $stmt = $db->prepare(
        'SELECT id, build_name, components_json FROM saved_builds WHERE id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        sb_json(['ok' => false, 'error' => 'not_found', 'message' => 'Build not found.'], 404);
    }
    $comps = json_decode((string)$row['components_json'], true);
    if (!is_array($comps) || empty($comps)) {
        sb_json(['ok' => false, 'error' => 'empty_build', 'message' => 'This build has no components.'], 400);
    }

    $added = [];
    $skipped = [];
    foreach ($comps as $slot => $item) {
        if (!is_array($item)) {
            continue;
        }
        $pid = (int)($item['id'] ?? 0);
        $pname = trim((string)($item['name'] ?? 'Product'));
        if ($pid <= 0) {
            continue;
        }
        if (ep_add_product_to_cart($db, $pid, 1)) {
            $added[] = $pname;
        } else {
            $skipped[] = $pname;
        }
    }

    if (empty($added) && empty($skipped)) {
        sb_json(['ok' => false, 'error' => 'empty_build', 'message' => 'No products found in this build.'], 400);
    }
    if (empty($added)) {
        sb_json([
            'ok' => false,
            'error' => 'unavailable',
            'message' => 'None of the components in this build are currently available to add to cart.',
            'skipped' => $skipped,
        ], 400);
    }

    $msg = count($added) . ' item' . (count($added) === 1 ? '' : 's') . ' added to cart.';
    if (!empty($skipped)) {
        $msg .= ' Unavailable: ' . implode(', ', array_slice($skipped, 0, 5));
        if (count($skipped) > 5) {
            $msg .= '…';
        }
    }
    logActivity($db, $uid, 'saved_build_to_cart', 'Build #' . $id . ' → cart (' . count($added) . ' items)');
    sb_json([
        'ok' => true,
        'added' => count($added),
        'skipped' => $skipped,
        'message' => $msg,
    ]);
}

if ($action === 'delete' && $method === 'POST') {
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        sb_json(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $stmt = $db->prepare('DELETE FROM saved_builds WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected <= 0) {
        sb_json(['ok' => false, 'error' => 'not_found'], 404);
    }
    logActivity($db, $uid, 'delete_pc_build', 'Deleted saved build #' . $id);
    sb_json(['ok' => true]);
}

sb_json(['ok' => false, 'error' => 'unknown_action'], 400);
