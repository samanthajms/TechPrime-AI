<?php
/**
 * Backend-ready PostgreSQL connection helper for TechPrime-AI (Supabase).
 */
function getDbConnection(): PDO
{
    $host = 'aws-0-ap-northeast-2.pooler.supabase.com';
    $port = '6543';
    $database = 'postgres';
    $username = 'postgres.ttirxnljpoqkroisuyks';
    $password = 'TechprimeCapstone2026';

    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=require";
        $connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $connection;
    } catch (PDOException $e) {
        die('Database connection failed: ' . $e->getMessage());
    }
}
?>