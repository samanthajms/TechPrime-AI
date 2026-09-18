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
$stmt->bind_param('sssss', $like, $like, $like, $prefix, $prefix);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
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
$stmt->close();

/* If still short, add remaining product names from the result set even if only description matched. */
if (count($suggestions) < 8) {
    $stmt2 = $db->prepare(
        "SELECT DISTINCT p.name
         FROM products p
         WHERE (p.name LIKE ? OR p.description LIKE ? OR COALESCE(p.category, '') LIKE ?)
           AND {$vis}
         ORDER BY p.name ASC
         LIMIT 8"
    );
    if ($stmt2) {
        $stmt2->bind_param('sss', $like, $like, $like);
        $stmt2->execute();
        $r2 = $stmt2->get_result();
        while ($row = $r2->fetch_assoc()) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = 'p:' . mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $suggestions[] = ['label' => $name, 'type' => 'product'];
            if (count($suggestions) >= 8) {
                break;
            }
        }
        $stmt2->close();
    }
}

echo json_encode(['suggestions' => array_slice($suggestions, 0, 8)]);
