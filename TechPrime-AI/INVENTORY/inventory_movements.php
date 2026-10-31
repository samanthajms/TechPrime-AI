<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/stock_movement_report.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

// Shared with the other role: includes/stock_movement_report.php
staff_stock_movement_page($db, 'inventory_custodian');
