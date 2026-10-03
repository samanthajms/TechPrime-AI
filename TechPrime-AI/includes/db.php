<?php
// Legacy wrapper — credentials live in .env; use getDbConnection() directly.
require_once __DIR__ . '/../backend/config/database.php';

function getDb() {
    return getDbConnection();
}
?>
