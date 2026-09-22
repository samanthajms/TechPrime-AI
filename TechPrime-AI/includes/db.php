<?php
// Shared Database Connection (Supabase PostgreSQL)
function getDb() {
    $host = 'aws-0-ap-northeast-2.pooler.supabase.com';
    $port = '6543';
    $db   = 'postgres';
    $user = 'postgres.ttirxnljpoqkroisuyks';
    $pass = 'TechprimeCapstone2026';

    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
        $conn = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $conn;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}
?>