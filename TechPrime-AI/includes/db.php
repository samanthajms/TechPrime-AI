<?php
// Shared Database Connection
function getDb() {
    $host = 'localhost';
    $user = 'root';
    $pass = '';
    $db   = 'techprime_ai';
    
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}
?>
