<?php
/**
 * Session status / keep-alive for includes/session_timeout.js.
 *   GET  ?action=status  seconds left before the idle timeout (does NOT count as activity)
 *   POST ?action=ping    counts as activity; JSON body {csrf_token}
 * An idle session is ended here like checkSessionTimeout() does. Being signed out is a normal
 * answer for a status check, so it is 200 {ok:false, error:'session_expired', session:{...}}
 * or 200 {ok:false, error:'signed_out'} (a 401 would log a console error on every expiry).
 */
require_once __DIR__ . '/../../includes/security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function session_api_json(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (ias_session_idle_expired()) {
    ias_expire_session();
}

if (empty($_SESSION['user_id'])) {
    $expired = $_SESSION['session_expired'] ?? null;
    if (is_array($expired)) {
        session_api_json(200, [
            'ok' => false,
            'error' => 'session_expired',
            'message' => 'Your session expired due to inactivity. Please log in again.',
            'session' => $expired,
        ]);
    }
    session_api_json(200, ['ok' => false, 'error' => 'signed_out', 'message' => 'You are no longer signed in.']);
}

$action = (string)($_GET['action'] ?? 'status');
if ($action === 'ping') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        session_api_json(405, ['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Method not allowed.']);
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input) || !verifyCsrfToken((string)($input['csrf_token'] ?? ''))) {
        session_api_json(400, ['ok' => false, 'error' => 'invalid_token', 'message' => 'Security token expired. Reload the page.']);
    }
    $_SESSION['last_activity'] = time();
} elseif ($action !== 'status') {
    session_api_json(400, ['ok' => false, 'error' => 'invalid_request', 'message' => 'Unknown action.']);
}

session_api_json(200, [
    'ok' => true,
    'remaining' => ias_session_remaining(),
    'timeout' => IAS_SESSION_IDLE_TIMEOUT,
]);
