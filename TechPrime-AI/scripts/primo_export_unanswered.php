<?php
/**
 * Export the questions Primo could not understand (table primo_unanswered) to a CSV for review.
 *
 * Usage:
 *   php scripts/primo_export_unanswered.php                     -> ~/primo_review_YYYY-MM-DD.csv
 *   php scripts/primo_export_unanswered.php C:\path\review.csv  [--days=30]
 *
 * Same messages are grouped (count, first/last seen), most frequent first. Fill the empty "intent"
 * column with one of Primo's intents (or leave it empty / write "skip"), then add the rows with
 * `python ml/primo/add_reviewed.py <file>`. The file holds real shopper text, so it is never written
 * inside the project: everything under htdocs is served by Apache.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/backend/config/database.php';

/**
 * @return int number of distinct messages written
 */
function primo_export_unanswered(PDO $db, string $path, int $days): int
{
    $stmt = $db->prepare(
        "SELECT LOWER(TRIM(message)) AS text,
                MODE() WITHIN GROUP (ORDER BY raw_intent) AS model_guess,
                ROUND(AVG(confidence)::numeric, 3) AS avg_confidence,
                MODE() WITHIN GROUP (ORDER BY reason) AS reason,
                COUNT(*) AS times_asked,
                MIN(created_at) AS first_seen,
                MAX(created_at) AS last_seen
         FROM primo_unanswered
         WHERE created_at >= CURRENT_TIMESTAMP - make_interval(days => ?)
         GROUP BY LOWER(TRIM(message))
         ORDER BY COUNT(*) DESC, MAX(created_at) DESC"
    );
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = fopen($path, 'wb');
    if ($out === false) {
        throw new RuntimeException("Cannot write {$path}");
    }
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel opens it as UTF-8
    fputcsv($out, ['text', 'intent', 'model_guess', 'avg_confidence', 'reason', 'times_asked', 'first_seen_utc', 'last_seen_utc']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['text'], '', $r['model_guess'], $r['avg_confidence'], $r['reason'], $r['times_asked'], $r['first_seen'], $r['last_seen']]);
    }
    fclose($out);
    return count($rows);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $days = 90;
    $path = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
            $days = max(1, (int) $m[1]);
        } elseif (!str_starts_with($arg, '--')) {
            $path = $arg;
        }
    }
    $home = getenv('USERPROFILE') ?: (getenv('HOME') ?: sys_get_temp_dir());
    $path = $path ?? ($home . DIRECTORY_SEPARATOR . 'primo_review_' . date('Y-m-d') . '.csv');

    $webRoot = realpath(dirname(__DIR__, 2));   // htdocs/TechPrime-AI: all of it is served by Apache
    $dir = realpath(dirname($path));
    if ($dir === false) {
        fwrite(STDERR, "Folder does not exist: " . dirname($path) . PHP_EOL);
        exit(1);
    }
    if ($webRoot !== false && stripos($dir . DIRECTORY_SEPARATOR, $webRoot . DIRECTORY_SEPARATOR) === 0) {
        fwrite(STDERR, "Refusing to write shopper messages inside the web root ({$webRoot}). Choose a folder outside it." . PHP_EOL);
        exit(1);
    }

    $db = getDbConnection();
    $exists = $db->query("SELECT 1 WHERE to_regclass('public.primo_unanswered') IS NOT NULL")->fetchColumn();
    if ($exists === false) {
        fwrite(STDERR, "Table primo_unanswered does not exist yet. Run database/migration_primo_unanswered.sql first." . PHP_EOL);
        exit(1);
    }
    $n = primo_export_unanswered($db, $path, $days);
    echo "Wrote {$n} distinct messages from the last {$days} days to {$path}" . PHP_EOL;
    echo "Fill the 'intent' column, then: python ml/primo/add_reviewed.py \"{$path}\"" . PHP_EOL;
}
