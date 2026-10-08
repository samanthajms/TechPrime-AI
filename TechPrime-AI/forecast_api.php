<?php
// Proxy: browser/PHP pages -> Render forecast service. Keeps the API key server-side.
// Usage: forecast_api.php?type=demand|revenue|metrics|categories&horizon=1|2|3&category=Memory&top=50&from=2026-01-01&to=2026-08-31
// from/to (optional, Y-m-d) choose which ACTUAL sales months are shown next to the forecast; they never change the forecast.
// `category` = a Client/Custodian label (Memory, Monitor, CPU Cooling ...) or a Client group (Component, Peripherals ...).
// .env (TechPrime-AI/.env, never committed):  FORECAST_URL=https://techprime-forecast.onrender.com   FORECAST_API_KEY=<same value as on Render>
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/security.php';
checkSessionTimeout();   // an idle (15 min) session must not keep reading forecasts; JSON callers get 401 session_expired

function forecast_fail(int $code, string $error): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

// Who may see which forecast (matches the screens):
//   Product Demand Forecast  -> Inventory Custodian, Retail Officer + Admin
//   Sales / Revenue Forecast -> Retail Officer + Admin
//   Category list + model accuracy -> all three
$role = (string)($_SESSION['role'] ?? '');
$allowed = [
    'demand'     => ['admin', 'inventory_custodian', 'retail_officer'],
    'revenue'    => ['admin', 'retail_officer'],
    'categories' => ['admin', 'retail_officer', 'inventory_custodian'],
    'metrics'    => ['admin', 'retail_officer', 'inventory_custodian'],
];
$type = (string)($_GET['type'] ?? 'demand');
if (!isset($allowed[$type])) { forecast_fail(400, 'bad type'); }
if (!in_array($role, $allowed[$type], true)) { forecast_fail(403, 'forbidden'); }

// Load .env exactly like the rest of the app (backend/config/database.php uses Dotenv safeLoad: on Render there is no
// .env file and the real environment variables are used). Including it only loads Composer + Dotenv; it opens no DB connection.
require_once __DIR__ . '/backend/config/database.php';

$base = rtrim((string)($_ENV['FORECAST_URL'] ?? getenv('FORECAST_URL') ?: ''), '/');
$key  = (string)($_ENV['FORECAST_API_KEY'] ?? getenv('FORECAST_API_KEY') ?: '');
if ($base === '' || $key === '') { forecast_fail(500, 'Forecast service is not configured'); }

// Production must talk to the service over HTTPS. Plain http is accepted only where traffic never leaves the machine or
// Render's private network: localhost, or a single-label host such as http://techprime-forecast:10000 (Private Service).
$baseParts = parse_url($base);
$host      = (string)($baseParts['host'] ?? '');
$isPrivate = in_array($host, ['localhost', '127.0.0.1'], true) || ($host !== '' && !str_contains($host, '.'));
if (($baseParts['scheme'] ?? '') !== 'https' && !$isPrivate) {
    error_log('forecast_api: FORECAST_URL must use https');
    forecast_fail(500, 'Forecast service is not configured');
}

$routes  = ['demand' => '/api/forecast/demand', 'revenue' => '/api/forecast/revenue', 'metrics' => '/api/metrics',
            'categories' => '/api/categories'];   // categories = canonical Client/Custodian list for the filter dropdown

// Forecast window: 1-3 months ahead only. Out-of-range values are rejected (the service enforces the same rule).
$horizon = 3;
if (isset($_GET['horizon']) && $_GET['horizon'] !== '') {
    $raw = (string)$_GET['horizon'];
    if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 3) { forecast_fail(400, 'horizon must be 1, 2 or 3 months'); }
    $horizon = (int)$raw;
}
$query = ['horizon' => $horizon, 'top' => max(1, min((int)($_GET['top'] ?? 50), 300))];
if (!empty($_GET['category'])) {
    $category = trim((string)$_GET['category']);
    if (mb_strlen($category) > 80 || !preg_match('/^[\p{L}\p{N} &.,\'()+\/:-]+$/u', $category)) {
        forecast_fail(400, 'invalid category');
    }
    $query['category'] = $category;
}

foreach (['from', 'to'] as $f) {
    if (isset($_GET[$f]) && $_GET[$f] !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', (string)$_GET[$f]);
        if (!$d || $d->format('Y-m-d') !== (string)$_GET[$f]) { forecast_fail(400, "$f must be a date like 2026-01-31"); }
        $query[$f] = $d->format('Y-m-d');
    }
}

$ch = curl_init($base . $routes[$type] . '?' . http_build_query($query));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 75,               // Render free tier can need ~50 s to wake from idle
    CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $key, 'Accept: application/json'],
    CURLOPT_FOLLOWLOCATION => false,            // never forward the API key to a redirect target
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false || $err) {
    error_log('forecast_api: upstream unreachable: ' . $err);
    forecast_fail(503, 'Forecast service unreachable (it may be waking up - retry in a minute)');
}
if ($code === 401 || $code === 403 || $code >= 500 || $code === 0) {   // never relay upstream auth/internal details
    error_log('forecast_api: upstream returned HTTP ' . $code);
    forecast_fail(502, 'Forecast service error');
}
http_response_code($code);
echo $response;
