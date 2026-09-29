<?php
/**
 * Shared helpers for staff-only messaging (admin / retail / inventory).
 */

if (!function_exists('staff_chat_allowed_roles')) {
    /** @return list<string> */
    function staff_chat_allowed_roles(): array
    {
        return ['admin', 'retail_officer', 'inventory_custodian'];
    }
}

if (!function_exists('staff_chat_role_ok')) {
    function staff_chat_role_ok(?string $role): bool
    {
        return $role !== null && in_array($role, staff_chat_allowed_roles(), true);
    }
}

if (!function_exists('staff_chat_has_is_read')) {
    function staff_chat_has_is_read(PDO $db): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'messages' AND column_name = 'is_read'"
        );
        $stmt->execute();
        $has = (bool)$stmt->fetchColumn();
        return $has;
    }
}

if (!function_exists('staff_chat_endpoint_href')) {
    function staff_chat_endpoint_href(): string
    {
        return '../includes/staff_chat_messages.php';
    }
}

if (!function_exists('staff_messages_page_href')) {
    function staff_messages_page_href(): string
    {
        return match ((string)($_SESSION['role'] ?? '')) {
            'admin' => 'admin_messages.php',
            'retail_officer' => 'retail_messages.php',
            'inventory_custodian' => 'inventory_messages.php',
            default => '#',
        };
    }
}

if (!function_exists('staff_chat_current_url')) {
    /** Path + query for the current page (no host), suitable for in-app share links. */
    function staff_chat_current_url(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $uri = strtok($uri, '#') ?: '';
        return $uri !== '' ? $uri : (string)($_SERVER['PHP_SELF'] ?? '');
    }
}
