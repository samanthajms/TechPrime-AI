<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_stock_alerts_page.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

// Shared with the other staff roles: includes/staff_stock_alerts_page.php
staff_stock_alerts_page($db, 'admin');
