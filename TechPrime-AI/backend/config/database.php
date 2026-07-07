<?php
/**
 * Backend-ready MySQL connection helper for IAS E-commerce.
 * Replace credentials with your environment values.
 */
function getDbConnection(): mysqli
{
    $host = 'localhost';
    $username = 'root';
    $password = '';
    $database = 'ias_ecommerce';

    $connection = new mysqli($host, $username, $password, $database);

    if ($connection->connect_error) {
        die('Database connection failed: ' . $connection->connect_error);
    }

    return $connection;
}
