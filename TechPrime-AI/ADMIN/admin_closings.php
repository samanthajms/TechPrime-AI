<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/eod_review.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

staff_eod_review_page($db, 'admin');
