<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/eod_review.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

staff_eod_review_page($db, 'retail_officer');
