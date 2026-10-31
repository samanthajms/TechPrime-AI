<?php

require_once __DIR__ . '/../../vendor/autoload.php';
// Branch timezone (Asia/Manila) for PHP + helpers for reading the UTC timestamps stored in the database.
require_once __DIR__ . '/../../includes/timezone_helpers.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
// safeLoad: on Render there is no .env file; values come from real environment variables.
$dotenv->safeLoad();

/**
 * Open a database connection. Throws PDOException when the database cannot be reached;
 * pages use getDbConnection() (service-unavailable screen), optional work uses ias_db_try().
 */
function ias_db_open(): PDO
{
    $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
    $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT');
    $database = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
    $username = $_ENV['DB_USER'] ?? getenv('DB_USER');
    $password = $_ENV['DB_PASS'] ?? getenv('DB_PASS');

    $dsn = "pgsql:host=$host;port=$port;dbname=$database;sslmode=require";
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        /* The Supabase pooler (transaction mode) can run a statement's prepare and execute on
           different server connections ("prepared statement pdo_stmt_... does not exist").
           Client-side prepares send each query in one step; values are still escaped by PDO. */
        PDO::ATTR_EMULATE_PREPARES => true
    ]);
}

function getDbConnection(): PDO
{
    try {
        return ias_db_open();
    } catch (PDOException $e) {
        // Details (host, user) go to the server log only — never to the visitor.
        error_log('Database connection failed: ' . $e->getMessage());
        require_once __DIR__ . '/../../includes/service_unavailable.php';
        ias_service_unavailable(ias_service_unavailable_reason($e->getMessage()));
    }
}

/**
 * A connection for optional work (logging, analytics) that must never stop the page:
 * returns null instead of showing the service-unavailable screen.
 */
function ias_db_try(): ?PDO
{
    try {
        return ias_db_open();
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}