<?php
/**
 * Server-side client for the Render forecast service (XGBoost). Shared by forecast_api.php (JSON proxy for the
 * Forecast page) and forecast_export.php (CSV / Excel / PDF downloads), so the API key never reaches the browser.
 * .env (TechPrime-AI/.env, never committed):  FORECAST_URL=https://techprime-forecast.onrender.com   FORECAST_API_KEY=<same value as on Render>
 */

// Load .env exactly like the rest of the app (backend/config/database.php uses Dotenv safeLoad: on Render there is no
// .env file and the real environment variables are used). Including it only loads Composer + Dotenv; it opens no DB connection.
require_once __DIR__ . '/../backend/config/database.php';

const IAS_FORECAST_ROUTES = [
    'demand' => '/api/forecast/demand', 'revenue' => '/api/forecast/revenue', 'metrics' => '/api/metrics',
    'actions' => '/api/inventory/actions',
    'categories' => '/api/categories',   // categories = canonical Client/Custodian list for the filter dropdown
];

/**
 * Service query from request parameters: horizon (1-3), top, category, from/to (Y-m-d).
 * from/to choose which ACTUAL sales months are shown next to the forecast; they never change the forecast.
 * @throws InvalidArgumentException with the message for a 400 answer
 */
function ias_forecast_query(array $in): array
{
    // Forecast window: 1-3 months ahead only. Out-of-range values are rejected (the service enforces the same rule).
    $horizon = 3;
    if (isset($in['horizon']) && $in['horizon'] !== '') {
        $raw = (string)$in['horizon'];
        if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 3) {
            throw new InvalidArgumentException('horizon must be 1, 2 or 3 months');
        }
        $horizon = (int)$raw;
    }
    $query = ['horizon' => $horizon, 'top' => max(1, min((int)($in['top'] ?? 50), 300))];
    if (!empty($in['category'])) {
        $category = trim((string)$in['category']);
        if (mb_strlen($category) > 80 || !preg_match('/^[\p{L}\p{N} &.,\'()+\/:-]+$/u', $category)) {
            throw new InvalidArgumentException('invalid category');
        }
        $query['category'] = $category;
    }
    foreach (['from', 'to'] as $f) {
        if (isset($in[$f]) && $in[$f] !== '') {
            $d = DateTime::createFromFormat('!Y-m-d', (string)$in[$f]);
            if (!$d || $d->format('Y-m-d') !== (string)$in[$f]) {
                throw new InvalidArgumentException("$f must be a date like 2026-01-31");
            }
            $query[$f] = $d->format('Y-m-d');
        }
    }
    return $query;
}

/**
 * One call to the forecast service.
 * @return array{ok:bool, code:int, body:?string, error:string}  code = the HTTP status to answer with
 */
function ias_forecast_call(string $type, array $query): array
{
    $fail = fn(int $code, string $error) => ['ok' => false, 'code' => $code, 'body' => null, 'error' => $error];
    if (!isset(IAS_FORECAST_ROUTES[$type])) {
        return $fail(400, 'bad type');
    }
    $base = rtrim((string)($_ENV['FORECAST_URL'] ?? getenv('FORECAST_URL') ?: ''), '/');
    $key  = (string)($_ENV['FORECAST_API_KEY'] ?? getenv('FORECAST_API_KEY') ?: '');
    if ($base === '' || $key === '') {
        return $fail(500, 'Forecast service is not configured');
    }

    // Production must talk to the service over HTTPS. Plain http is accepted only where traffic never leaves the machine or
    // Render's private network: localhost, or a single-label host such as http://techprime-forecast:10000 (Private Service).
    $baseParts = parse_url($base);
    $host      = (string)($baseParts['host'] ?? '');
    $isPrivate = in_array($host, ['localhost', '127.0.0.1'], true) || ($host !== '' && !str_contains($host, '.'));
    if (($baseParts['scheme'] ?? '') !== 'https' && !$isPrivate) {
        error_log('forecast_api: FORECAST_URL must use https');
        return $fail(500, 'Forecast service is not configured');
    }

    $ch = curl_init($base . IAS_FORECAST_ROUTES[$type] . '?' . http_build_query($query));
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
        return $fail(503, 'Forecast service unreachable (it may be waking up - retry in a minute)');
    }
    // The inventory-actions route answers 503 until a stock-on-hand snapshot exists on the forecast service. That is an
    // expected "not connected yet" state, not an outage, so the screen gets a clear message instead of a generic error.
    if ($type === 'actions' && $code === 503) {
        return $fail(503, 'Stock levels are not connected to the forecast service yet');
    }
    if ($code === 401 || $code === 403 || $code >= 500 || $code === 0) {   // never relay upstream auth/internal details
        error_log('forecast_api: upstream returned HTTP ' . $code);
        return $fail(502, 'Forecast service error');
    }
    return ['ok' => true, 'code' => $code, 'body' => (string)$response, 'error' => ''];
}

/** Decoded JSON of a successful call with a 2xx answer, or null (the export then reports the forecast as unavailable). */
function ias_forecast_json(string $type, array $query): ?array
{
    $r = ias_forecast_call($type, $query);
    if (!$r['ok'] || $r['code'] < 200 || $r['code'] >= 300) {
        return null;
    }
    $data = json_decode((string)$r['body'], true);
    return is_array($data) && ($data['ok'] ?? true) !== false ? $data : null;
}
