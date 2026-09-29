<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
// safeLoad: on Render there is no .env file; values come from real environment variables.
$dotenv->safeLoad();

function getDbConnection(): PDO
{
    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
    $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT');
    $database = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    $username = $_ENV['DB_USER'] ?? getenv('DB_USER');
    $password = $_ENV['DB_PASS'] ?? getenv('DB_PASS');

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