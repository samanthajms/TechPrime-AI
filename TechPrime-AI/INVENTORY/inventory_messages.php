<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/staff_messages_ui.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

define('STAFF_CHAT_SKIP_WIDGET', true);

logActivity($db, (int)$_SESSION['user_id'], 'view_messages', 'Inventory Custodian viewed messages');

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Messages',
    'active' => 'messages',
    'heading' => 'Messages',
    'subtitle' => 'Chat with admins and retail officers',
    'extra_head' => staff_messages_extra_head(),
]);

staff_messages_render();

staff_page_end();
