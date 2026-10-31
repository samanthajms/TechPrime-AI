<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_forecast_page.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

// Shared with the other staff roles: includes/staff_forecast_page.php
staff_forecast_page($db, 'retail_officer');
