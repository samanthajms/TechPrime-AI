<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';
require_once __DIR__ . '/../includes/staff_messages_ui.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('admin');

define('STAFF_CHAT_SKIP_WIDGET', true);

// Floating chat widget iframe: bare UI, no activity log entry per open.
if (staff_messages_is_embed()) {
    staff_messages_embed_page();
}

logActivity($db, (int)$_SESSION['user_id'], 'view_messages', 'Admin viewed messages');

staff_page_start([
    'role' => 'admin',
    'title' => 'Messages',
    'active' => 'messages',
    'heading' => 'Messages',
    'subtitle' => 'Chat with retail officers and inventory custodians',
    'extra_head' => staff_messages_extra_head(),
]);

staff_messages_render();

staff_page_end();
