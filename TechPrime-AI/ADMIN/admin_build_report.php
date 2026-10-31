<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/build_report.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

// Shared with the other role: includes/build_report.php
staff_build_report_page($db, 'admin');
