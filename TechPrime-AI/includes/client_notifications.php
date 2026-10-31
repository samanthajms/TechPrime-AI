<?php
/**
 * Client notification helpers shared by the header dropdown (CLIENT/ep_header.php)
 * and the full page (CLIENT/notifications.php). Rows come from the notifications table
 * (see includes/inventory_alerts.php); created_at holds UTC.
 */
require_once __DIR__ . '/inventory_alerts.php';

if (!function_exists('ep_notif_meta')) {
    /** Display label, Font Awesome icon, colour tone and filter tab for a notification type. */
    function ep_notif_meta(string $type): array
    {
        $map = [
            'order_placed'     => ['label' => 'Order Placed',           'icon' => 'fa-receipt',        'tone' => 'blue',  'tab' => 'orders'],
            'order_paid'       => ['label' => 'Payment Confirmed',      'icon' => 'fa-credit-card',    'tone' => 'green', 'tab' => 'payments'],
            'order_status'     => ['label' => 'Order Update',           'icon' => 'fa-truck',          'tone' => 'teal',  'tab' => 'orders'],
            'order_received'   => ['label' => 'Order Completed',        'icon' => 'fa-box-open',       'tone' => 'green', 'tab' => 'orders'],
            'cancel_requested' => ['label' => 'Cancellation Requested', 'icon' => 'fa-hourglass-half', 'tone' => 'amber', 'tab' => 'cancellations'],
            'order_cancelled'  => ['label' => 'Order Cancelled',        'icon' => 'fa-ban',            'tone' => 'red',   'tab' => 'cancellations'],
        ];
        return $map[$type] ?? ['label' => 'Notification', 'icon' => 'fa-bell', 'tone' => 'gray', 'tab' => 'other'];
    }
}

if (!function_exists('ep_notif_tabs')) {
    /** Filter tabs on the notifications page: key => [label, notification types (null = no type filter)]. */
    function ep_notif_tabs(): array
    {
        return [
            'all'           => ['label' => 'All',           'types' => null],
            'unread'        => ['label' => 'Unread',        'types' => null],
            'orders'        => ['label' => 'Orders',        'types' => ['order_placed', 'order_status', 'order_received']],
            'payments'      => ['label' => 'Payments',      'types' => ['order_paid']],
            'cancellations' => ['label' => 'Cancellations', 'types' => ['cancel_requested', 'order_cancelled']],
        ];
    }
}

if (!function_exists('ep_notif_safe_link')) {
    /** Only same-folder page links (e.g. "user_dashboard.php?status=to_pay") are followed. */
    function ep_notif_safe_link($link): ?string
    {
        $link = trim((string)$link);
        if ($link === '' || !preg_match('/^[a-z0-9_\-]+\.php(\?[a-z0-9_\-=&%.]*)?$/i', $link)) {
            return null;
        }
        return $link;
    }
}

if (!function_exists('ep_notif_timestamp')) {
    /** created_at is a UTC timestamp without time zone. */
    function ep_notif_timestamp(string $createdAt): ?int
    {
        try {
            return (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ep_notif_local')) {
    function ep_notif_local(int $ts): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Manila'));
    }
}

if (!function_exists('ep_notif_relative_time')) {
    function ep_notif_relative_time(?int $ts): string
    {
        if ($ts === null) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $m = (int)floor($diff / 60);
            return $m . ' min' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $h = (int)floor($diff / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 604800) {
            $d = (int)floor($diff / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        return ep_notif_local($ts)->format('M j, Y');
    }
}

if (!function_exists('ep_notif_day_label')) {
    /** "Today", "Yesterday" or a date (Asia/Manila) — used to group the full page. */
    function ep_notif_day_label(?int $ts): string
    {
        if ($ts === null) {
            return 'Earlier';
        }
        $day = ep_notif_local($ts)->format('Y-m-d');
        $today = ep_notif_local(time());
        if ($day === $today->format('Y-m-d')) {
            return 'Today';
        }
        if ($day === $today->modify('-1 day')->format('Y-m-d')) {
            return 'Yesterday';
        }
        return ep_notif_local($ts)->format('l, M j, Y');
    }
}

if (!function_exists('ep_notif_message_html')) {
    /** Escaped message with order references (#ORD-13) emphasised. */
    function ep_notif_message_html(string $message): string
    {
        return preg_replace('/#ORD-\d+/', '<strong>$0</strong>', h($message));
    }
}

if (!function_exists('ep_notif_unread_count')) {
    function ep_notif_unread_count(PDO $db, int $userId): int
    {
        inv_notifications_ensure_schema($db);
        $st = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('ep_notif_render_item')) {
    /**
     * One notification row. $variant 'dropdown' (header preview) or 'page' (notifications.php).
     * Read/delete buttons and the row link are wired up by the script in ep_header.php.
     */
    function ep_notif_render_item(array $n, string $variant = 'dropdown'): string
    {
        $meta = ep_notif_meta((string)($n['type'] ?? ''));
        $isRead = (int)($n['is_read'] ?? 0) === 1;
        $ts = ep_notif_timestamp((string)($n['created_at'] ?? ''));
        $link = ep_notif_safe_link($n['link'] ?? null);
        $id = (int)($n['id'] ?? 0);
        $full = $ts !== null ? ep_notif_local($ts)->format('M j, Y · g:i A') : '';
        $isPage = $variant === 'page';

        $tag = $link !== null ? 'a' : 'div';
        $href = $link !== null ? ' href="' . h($link) . '"' : '';

        $html  = '<li class="ep-notif-item ' . ($isRead ? 'is-read' : 'is-unread') . '" data-id="' . $id . '">';
        $html .= '<' . $tag . $href . ' class="ep-notif-main" data-notif-open>';
        $html .= '<span class="ep-notif-icon tone-' . h($meta['tone']) . '" aria-hidden="true"><i class="fas ' . h($meta['icon']) . '"></i></span>';
        $html .= '<span class="ep-notif-body">';
        $html .= '<span class="ep-notif-title">' . h($meta['label']);
        if (!$isRead) {
            $html .= '<span class="ep-notif-sr"> (unread)</span>';
        }
        $html .= '</span>';
        $html .= '<span class="ep-notif-msg">' . ep_notif_message_html((string)($n['message'] ?? '')) . '</span>';
        $html .= '<span class="ep-notif-meta"><time class="ep-notif-time" title="' . h($full) . '">'
            . '<i class="far fa-clock" aria-hidden="true"></i> ' . h($isPage && $ts !== null ? ep_notif_local($ts)->format('g:i A') . ' · ' . ep_notif_relative_time($ts) : ep_notif_relative_time($ts))
            . '</time>';
        if ($isPage && $link !== null) {
            $html .= '<span class="ep-notif-cta">View order <i class="fas fa-arrow-right" aria-hidden="true"></i></span>';
        }
        $html .= '</span>';
        $html .= '</span>';
        $html .= '</' . $tag . '>';
        $html .= '<span class="ep-notif-dot" aria-hidden="true"></span>';
        $html .= '<span class="ep-notif-actions">';
        if (!$isRead) {
            $html .= '<button type="button" class="ep-notif-act" data-notif-action="read" title="Mark as read" aria-label="Mark as read"><i class="fas fa-check" aria-hidden="true"></i></button>';
        }
        $html .= '<button type="button" class="ep-notif-act is-danger" data-notif-action="delete" title="Remove" aria-label="Remove notification"><i class="far fa-trash-alt" aria-hidden="true"></i></button>';
        $html .= '</span>';
        $html .= '</li>';
        return $html;
    }
}
