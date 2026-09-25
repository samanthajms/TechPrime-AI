<?php
/**
 * Lightweight product search suggestions for the client header search bar.
 * Returns JSON: { suggestions: [ { label, type } ] }
 */
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 80) {
    echo json_encode(['suggestions' => []]);
    exit;
}

$db = getDbConnection();
$like = '%' . $q . '%';
$prefix = $q . '%';
$vis = ias_client_product_list_sql_condition('p');

$suggestions = [];
$seen = [];

/* Prefer name matches, then category matches — real products only. */
$sql = "SELECT p.name, p.category
        FROM products p
        WHERE (p.name LIKE ? OR p.description LIKE ? OR COALESCE(p.category, '') LIKE ?)
          AND {$vis}
        ORDER BY
          CASE
            WHEN p.name LIKE ? THEN 0
            WHEN COALESCE(p.category, '') LIKE ? THEN 1
            ELSE 2
          END,
          p.name ASC
        LIMIT 24";

$stmt = $db->prepare($sql);
if (!$stmt) {
    echo json_encode(['suggestions' => []]);
    exit;
}
$stmt->execute([$like, $like, $like, $prefix, $prefix]);
$res = $stmt;
while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
    $name = trim((string)($row['name'] ?? ''));
    $cat = trim((string)($row['category'] ?? ''));

    if ($name !== '' && stripos($name, $q) !== false) {
        $key = 'p:' . mb_strtolower($name);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $suggestions[] = ['label' => $name, 'type' => 'product'];
        }
    }
    if ($cat !== '' && stripos($cat, $q) !== false) {
        $key = 'c:' . mb_strtolower($cat);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $suggestions[] = ['label' => $cat, 'type' => 'category'];
        }
    }
    if (count($suggestions) >= 8) {
        break;
    }
}
echo json_encode(['suggestions' => array_slice($suggestions, 0, 8)]);
