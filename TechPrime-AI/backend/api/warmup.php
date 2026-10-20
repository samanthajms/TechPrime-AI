<?php
/**
 * Render warm-up ping (fire-and-forget from the browser, see includes/service_warmup.js).
 *
 * GET warmup.php?svc=forecast   staff roles that see the Forecast page (admin, retail_officer, inventory_custodian)
 * GET warmup.php?svc=primo      anyone who sees the Primo widget (guests chat too)
 *
 * Calls the service's open /health route so a sleeping Render free instance starts booting before the user
 * opens Forecast or sends Primo a message. The browser never waits on this response.
 * - Does not call checkSessionTimeout(): a background ping must not keep an idle session alive.
 * - Releases the session lock at once, so it never blocks the user's other requests.
 * - Throttled per service across all users (one ping per WARMUP_INTERVAL_SECONDS), so it cannot be used to hammer Render.
 * Response: HTTP 200 {ok:true, svc, status: awake|waking|skipped}; 4xx only for method/auth/bad svc.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../includes/security.php';   // starts the session

const WARMUP_INTERVAL_SECONDS = 300;   // Render free instances sleep after 15 min without traffic

function warmup_reply(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    warmup_reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$svc = (string)($_GET['svc'] ?? '');
$role = (string)($_SESSION['role'] ?? '');
release_session_lock();   // read-only from here on

if ($svc === 'forecast') {
    if (!in_array($role, ['admin', 'retail_officer', 'inventory_custodian'], true)) {
        warmup_reply(403, ['ok' => false, 'error' => 'forbidden']);
    }
} elseif ($svc !== 'primo') {
    warmup_reply(400, ['ok' => false, 'error' => 'bad svc']);
}

// Same env lookups as forecast_api.php and primo_chat.php. Including database.php only loads Composer + Dotenv.
require_once __DIR__ . '/../config/database.php';
if ($svc === 'forecast') {
    $base = (string)($_ENV['FORECAST_URL'] ?? getenv('FORECAST_URL') ?: '');
} else {
    $base = $_ENV['PRIMO_API_URL'] ?? $_SERVER['PRIMO_API_URL'] ?? getenv('PRIMO_API_URL');
    $base = is_string($base) && trim($base) !== '' ? trim($base) : 'http://127.0.0.1:5055';
}
$base = rtrim($base, '/');
if ($base === '' || !function_exists('curl_init')) {
    warmup_reply(200, ['ok' => true, 'svc' => $svc, 'status' => 'skipped']);
}

// Throttle across all users. The stamp lives in the system temp dir, outside the web root.
$stamp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'techprime_warmup_' . $svc . '.stamp';
$fh = @fopen($stamp, 'c');
if ($fh === false) {
    warmup_reply(200, ['ok' => true, 'svc' => $svc, 'status' => 'skipped']);
}
// Non-blocking lock: if another request is pinging right now, this one has nothing to add.
if (!flock($fh, LOCK_EX | LOCK_NB)) {
    fclose($fh);
    warmup_reply(200, ['ok' => true, 'svc' => $svc, 'status' => 'skipped']);
}
clearstatcache(true, $stamp);
$last = (int)@filemtime($stamp);
$fresh = (int)@filesize($stamp) > 0 && time() - $last < WARMUP_INTERVAL_SECONDS;
if ($fresh) {
    flock($fh, LOCK_UN);
    fclose($fh);
    warmup_reply(200, ['ok' => true, 'svc' => $svc, 'status' => 'skipped']);
}
ftruncate($fh, 0);
fwrite($fh, (string)time());
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

// Short timeouts on purpose: the request only has to reach Render for the instance to start booting.
// Getting no answer within the timeout means it is still waking up, which is fine.
$ch = curl_init($base . '/health');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

warmup_reply(200, ['ok' => true, 'svc' => $svc, 'status' => ($code >= 200 && $code < 300) ? 'awake' : 'waking']);
