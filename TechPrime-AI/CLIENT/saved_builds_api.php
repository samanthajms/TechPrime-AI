<?php
/**
 * Saved Builds API — persist / list / get / delete Tech & Match builds
 * for the authenticated client only.
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/pc_compatibility.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sb_ensure_table(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db->exec(
        "CREATE TABLE IF NOT EXISTS saved_builds (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            build_name VARCHAR(150) NOT NULL,
            components_json TEXT NOT NULL,
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            component_count INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $db->exec("CREATE INDEX IF NOT EXISTS idx_saved_builds_user ON saved_builds (user_id)");
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
    $stmt->execute([$uid]);
    $res = $stmt;
    $builds = [];
    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
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
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
            'description' => mb_substr((string)($item['description'] ?? ''), 0, 500),
        ];
        $total += $price;
        $count++;
    }
    if ($count <= 0) {
        sb_json(['ok' => false, 'error' => 'empty_build', 'message' => 'Select at least one component before saving.'], 400);
    }

    $compat = ep_pc_compat_validate_build($clean);
    if (!$compat['ok']) {
        $msg = "This build has incompatible components:\n• " . implode("\n• ", array_slice($compat['issues'], 0, 5));
        sb_json(['ok' => false, 'error' => 'incompatible', 'message' => $msg, 'issues' => $compat['issues']], 400);
    }

    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

    /* Update existing owned build when editing. */
    if ($updateId > 0) {
        $stmt = $db->prepare(
            'UPDATE saved_builds
             SET build_name = ?, components_json = ?, total_price = ?, component_count = ?
             WHERE id = ? AND user_id = ?'
        );
        $ok = $stmt->execute([$name, $json, $total, $count, $updateId, $uid]);
        $affected = $stmt->rowCount();
        if (!$ok || $affected < 0) {
            sb_json(['ok' => false, 'error' => 'save_failed', 'message' => 'Could not update your build. Please try again.'], 500);
        }
        if ($affected === 0) {
            /* Verify ownership — 0 rows may mean values unchanged or not found. */
            $chk = $db->prepare('SELECT id FROM saved_builds WHERE id = ? AND user_id = ? LIMIT 1');
            $chk->execute([$updateId, $uid]);
            $exists = $chk->fetch(PDO::FETCH_ASSOC);
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
    $ok = $stmt->execute([$uid, $name, $json, $total, $count]);
    $newId = (int)$db->lastInsertId();
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
    $stmt->execute([$id, $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        sb_json(['ok' => false, 'error' => 'not_found', 'message' => 'Build not found.'], 404);
    }
    $comps = json_decode((string)$row['components_json'], true);
    if (!is_array($comps) || empty($comps)) {
        sb_json(['ok' => false, 'error' => 'empty_build', 'message' => 'This build has no components.'], 400);
    }

    $compat = ep_pc_compat_validate_build($comps);
    if (!$compat['ok']) {
        $msg = "This saved build has incompatible components and cannot be added to cart:\n• " . implode("\n• ", array_slice($compat['issues'], 0, 5));
        sb_json(['ok' => false, 'error' => 'incompatible', 'message' => $msg, 'issues' => $compat['issues']], 400);
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
        'cart' => ep_get_cart_preview($db),
    ]);
}

if ($action === 'delete' && $method === 'POST') {
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        sb_json(['ok' => false, 'error' => 'invalid_id'], 400);
    }
    $stmt = $db->prepare('DELETE FROM saved_builds WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    $affected = $stmt->rowCount();
    if ($affected <= 0) {
        sb_json(['ok' => false, 'error' => 'not_found'], 404);
    }
    logActivity($db, $uid, 'delete_pc_build', 'Deleted saved build #' . $id);
    sb_json(['ok' => true]);
}

sb_json(['ok' => false, 'error' => 'unknown_action'], 400);
