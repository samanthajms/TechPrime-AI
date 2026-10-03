<?php
header('Content-Type: application/json');

$category = $_GET['category'] ?? 'MEMORY';
$horizon  = $_GET['horizon'] ?? 3;

// Phase 1 (Testing): Local Python instance
// Phase 2 (Production): Swap with "https://<your-service>.onrender.com/api/forecast?"
$api_base_url = "http://127.0.0.1:5001/api/forecast?";
$api_key      = "sb_publishable_McnJFvYwmb6_CDyHIh8JzA_qdPe2-ir"; 

$url = $api_base_url . http_build_query([
    'category' => $category,
    'horizon'  => $horizon
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 12);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-Key: ' . $api_key,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(503);
    echo json_encode(['error' => 'Forecast service unreachable', 'details' => $curlErr]);
    exit;
}

http_response_code($httpCode ?: 500);
echo $response;