<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/staff_profile.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

staff_profile_page($db, 'retail_officer');
