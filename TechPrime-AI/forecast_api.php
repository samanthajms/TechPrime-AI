<?php
// Proxy: browser/PHP pages -> Render forecast service. Keeps the API key server-side.
// Usage: forecast_api.php?type=demand|revenue|metrics|categories&horizon=3&category=Memory&top=50
// `category` = a Client/Custodian label (Memory, Monitor, CPU Cooling ...) or a Client group (Component, Peripherals ...).
// .env (TechPrime-AI/.env, never committed):  FORECAST_URL=https://techprime-forecast.onrender.com   FORECAST_API_KEY=<same value as on Render>
declare(strict_types=1);
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Who may see which forecast (matches the screens):
//   Product Demand Forecast  -> Inventory Custodian + Admin
//   Sales / Revenue Forecast -> Retail Officer + Admin
//   Category list + model accuracy -> all three
$role = (string)($_SESSION['role'] ?? '');
$allowed = [
    'demand'     => ['admin', 'inventory_custodian'],
    'revenue'    => ['admin', 'retail_officer'],
    'categories' => ['admin', 'retail_officer', 'inventory_custodian'],
    'metrics'    => ['admin', 'retail_officer', 'inventory_custodian'],
];
$type = (string)($_GET['type'] ?? 'demand');
if (!isset($allowed[$type])) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad type']); exit; }
if (!in_array($role, $allowed[$type], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// Load .env exactly like the rest of the app (backend/config/database.php uses Dotenv safeLoad: on Render there is no
// .env file and the real environment variables are used). Including it only loads Composer + Dotenv; it opens no DB connection.
require_once __DIR__ . '/backend/config/database.php';

$base = rtrim((string)($_ENV['FORECAST_URL'] ?? getenv('FORECAST_URL') ?: ''), '/');
$key  = (string)($_ENV['FORECAST_API_KEY'] ?? getenv('FORECAST_API_KEY') ?: '');
if ($base === '' || $key === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Forecast service is not configured']);
    exit;
}

$routes  = ['demand' => '/api/forecast/demand', 'revenue' => '/api/forecast/revenue', 'metrics' => '/api/metrics',
            'categories' => '/api/categories'];   // categories = canonical Client/Custodian list for the filter dropdown

$query = ['horizon' => max(1, min((int)($_GET['horizon'] ?? 3), 6)), 'top' => max(1, min((int)($_GET['top'] ?? 50), 300))];
if (!empty($_GET['category'])) { $query['category'] = (string)$_GET['category']; }

$ch = curl_init($base . $routes[$type] . '?' . http_build_query($query));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 75,               // Render free tier can need ~50 s to wake from idle
    CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $key],
]);
$response = curl_exec($ch);
$code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false || $err) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Forecast service unreachable (it may be waking up - retry in a minute)']);
    exit;
}
http_response_code($code ?: 502);
echo $response;