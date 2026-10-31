<?php
// Proxy: browser/PHP pages -> Render forecast service. Keeps the API key server-side.
// Usage: trends_api.php?type=demand|revenue|actions|metrics|categories&horizon=1|2|3&category=Memory&top=50&from=2026-01-01&to=2026-08-31
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

// Who may see which forecast (matches the screens): the Forecast page is shared by Admin, Retail Officer and
// Inventory Custodian (includes/staff_trends_page.php), so all three see Product Demand (with the inventory
// actions: reorder point, safety stock, days of inventory, order qty), Sales / Revenue, categories and model accuracy.
$role = (string)($_SESSION['role'] ?? '');
$allowed = [
    'demand'     => ['admin', 'inventory_custodian', 'retail_officer'],
    'revenue'    => ['admin', 'inventory_custodian', 'retail_officer'],
    'actions'    => ['admin', 'inventory_custodian', 'retail_officer'],
    'categories' => ['admin', 'retail_officer', 'inventory_custodian'],
    'metrics'    => ['admin', 'retail_officer', 'inventory_custodian'],
];
$type = (string)($_GET['type'] ?? 'demand');
if (!isset($allowed[$type])) { forecast_fail(400, 'bad type'); }
if (!in_array($role, $allowed[$type], true)) { forecast_fail(403, 'forbidden'); }

// Nothing below writes to $_SESSION, so drop the session lock now. Otherwise this request (up to 75 s on a cold
// Render start) would block every other request from the same browser, including the parallel demand/actions fetch.
release_session_lock();

// The service call (config, HTTPS check, timeouts, error mapping) is shared with trends_export.php
require_once __DIR__ . '/includes/trends_client.php';

try {
    $query = ias_forecast_query($_GET);
} catch (InvalidArgumentException $e) {
    forecast_fail(400, $e->getMessage());
}
$r = ias_forecast_call($type, $query);
if (!$r['ok']) { forecast_fail($r['code'], $r['error']); }
http_response_code($r['code']);
echo $r['body'];
