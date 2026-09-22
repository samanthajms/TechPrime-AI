<?php
/**
 * Inventory Custodian: notifications + stock-alert helpers.
 * Uses the existing notifications / logs tables.
 */

if (!function_exists('inv_notifications_ensure_schema')) {
    function inv_notifications_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $cols = [];
        $res = $db->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'notifications'"
        );
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower((string)$row['column_name'])] = true;
            }
        }
        if (empty($cols['is_read'])) {
            @$db->query('ALTER TABLE notifications ADD COLUMN IF NOT EXISTS is_read SMALLINT NOT NULL DEFAULT 0');
        }
        if (empty($cols['type'])) {
            @$db->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type VARCHAR(40) NULL DEFAULT 'info'");
        }
        if (empty($cols['link'])) {
            @$db->query('ALTER TABLE notifications ADD COLUMN IF NOT EXISTS link VARCHAR(255) NULL DEFAULT NULL');
        }
    }
}

if (!function_exists('inv_notify_user')) {
    function inv_notify_user(PDO $db, int $userId, string $message, string $type = 'info', ?string $link = null): bool
    {
        if ($userId <= 0 || trim($message) === '') {
            return false;
        }
        inv_notifications_ensure_schema($db);
        $type = preg_replace('/[^a-z0-9_\-]/i', '', $type) ?: 'info';
        $link = $link !== null ? substr($link, 0, 255) : null;
        $stmt = $db->prepare(
            'INSERT INTO notifications (user_id, message, is_read, type, link) VALUES (?, ?, 0, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }
        $ok = $stmt->execute([$userId, $message, $type, $link]);
        return (bool)$ok;
    }
}

if (!function_exists('inv_notify_role')) {
    /** Notify all active users with a given role (e.g. inventory_custodian). */
    function inv_notify_role(PDO $db, string $role, string $message, string $type = 'info', ?string $link = null): int
    {
        $count = 0;
        $stmt = $db->prepare('SELECT id FROM users WHERE role = ? AND COALESCE(is_locked, 0) = 0');
        if (!$stmt) {
            return 0;
        }
        $stmt->execute([$role]);
        $res = $stmt;
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            if (inv_notify_user($db, (int)$row['id'], $message, $type, $link)) {
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('inv_notification_recent_exists')) {
    /** Avoid duplicate alerts for the same product/type within N hours. */
    function inv_notification_recent_exists(PDO $db, int $userId, string $needle, string $type, int $hours = 24): bool
    {
        inv_notifications_ensure_schema($db);
        $like = '%' . $needle . '%';
        $stmt = $db->prepare(
            "SELECT id FROM notifications
             WHERE user_id = ? AND type = ? AND message LIKE ?
               AND created_at >= (NOW() - (? * INTERVAL '1 hour'))
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->execute([$userId, $type, $like, $hours]);
        $exists = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        return $exists;
    }
}

if (!function_exists('inv_stock_status_label')) {
    function inv_stock_status_label(int $stock): string
    {
        if ($stock <= 0) {
            return 'out';
        }
        if ($stock <= 5) {
            return 'critical';
        }
        if ($stock <= 15) {
            return 'low';
        }
        return 'ok';
    }
}

if (!function_exists('inv_notify_stock_change')) {
    /**
     * Create inventory notifications when stock crosses low / out thresholds,
     * or when stock is replenished from a low/out state.
     */
    function inv_notify_stock_change(
        PDO $db,
        string $productName,
        int $productId,
        int $oldStock,
        int $newStock
    ): void {
        $oldStatus = inv_stock_status_label($oldStock);
        $newStatus = inv_stock_status_label($newStock);
        $link = 'inventory_stocks.php';
        $delta = $newStock - $oldStock;
        $safeName = $productName !== '' ? $productName : ('Product #' . $productId);

        if ($newStatus === 'out' && $oldStatus !== 'out') {
            $msg = $safeName . ' is out of stock.';
            inv_notify_custodians_deduped($db, $safeName, $msg, 'out_of_stock', $link);
        } elseif (in_array($newStatus, ['critical', 'low'], true) && !in_array($oldStatus, ['critical', 'low', 'out'], true)) {
            $msg = $safeName . ' is low in stock (' . $newStock . ' left).';
            inv_notify_custodians_deduped($db, $safeName, $msg, 'low_stock', $link);
        } elseif ($newStatus === 'critical' && $oldStatus === 'ok') {
            $msg = $safeName . ' is critically low (' . $newStock . ' left).';
            inv_notify_custodians_deduped($db, $safeName, $msg, 'low_stock', $link);
        } elseif (in_array($oldStatus, ['out', 'critical', 'low'], true) && $newStatus === 'ok' && $delta > 0) {
            $msg = $safeName . ' stock increased by ' . $delta . ' (now ' . $newStock . ').';
            inv_notify_custodians_deduped($db, $safeName, $msg, 'stock_updated', $link);
        } elseif ($delta > 0 && $oldStatus === 'out' && $newStatus !== 'out') {
            $msg = $safeName . ' stock increased by ' . $delta . ' (now ' . $newStock . ').';
            inv_notify_custodians_deduped($db, $safeName, $msg, 'stock_updated', $link);
        }
    }
}

if (!function_exists('inv_notify_custodians_deduped')) {
    function inv_notify_custodians_deduped(
        PDO $db,
        string $needle,
        string $message,
        string $type,
        string $link
    ): void {
        $stmt = $db->prepare('SELECT id FROM users WHERE role = ? AND COALESCE(is_locked, 0) = 0');
        if (!$stmt) {
            return;
        }
        $role = 'inventory_custodian';
        $stmt->execute([$role]);
        $res = $stmt;
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)$row['id'];
            if (inv_notification_recent_exists($db, $uid, $needle, $type, 12)) {
                continue;
            }
            inv_notify_user($db, $uid, $message, $type, $link);
        }
    }
}

if (!function_exists('inv_sync_stock_alerts')) {
    /**
     * On dashboard load: ensure current low/out products have a recent alert.
     * Deduped so it does not flood the inbox.
     */
    function inv_sync_stock_alerts(PDO $db): void
    {
        $res = $db->query('SELECT id, name, stock FROM products WHERE stock <= 15 ORDER BY stock ASC, name ASC LIMIT 40');
        if (!$res) {
            return;
        }
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $stock = (int)$row['stock'];
            $name = trim((string)$row['name']);
            $id = (int)$row['id'];
            if ($stock <= 0) {
                inv_notify_custodians_deduped(
                    $db,
                    $name !== '' ? $name : ('#' . $id),
                    ($name !== '' ? $name : ('Product #' . $id)) . ' is out of stock.',
                    'out_of_stock',
                    'inventory_stocks.php'
                );
            } else {
                inv_notify_custodians_deduped(
                    $db,
                    $name !== '' ? $name : ('#' . $id),
                    ($name !== '' ? $name : ('Product #' . $id)) . ' is low in stock (' . $stock . ' left).',
                    'low_stock',
                    'inventory_stocks.php'
                );
            }
        }
    }
}

if (!function_exists('inv_user_notifications')) {
    /** @return array{items: array<int,array>, unread: int} */
    function inv_user_notifications(PDO $db, int $userId, int $limit = 20): array
    {
        inv_notifications_ensure_schema($db);
        $items = [];
        $unread = 0;
        $cnt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        if ($cnt) {
            $cnt->execute([$userId]);
            $unread = (int)($cnt->fetchColumn() ?? 0);
        }
        $limit = max(1, min(50, $limit));
        $stmt = $db->prepare(
            'SELECT id, message, is_read, type, link, created_at
             FROM notifications WHERE user_id = ?
             ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        if ($stmt) {
            $stmt->execute([$userId]);
            $res = $stmt;
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $items[] = $row;
            }
        }
        return ['items' => $items, 'unread' => $unread];
    }
}

if (!function_exists('inv_mark_notification_read')) {
    function inv_mark_notification_read(PDO $db, int $userId, int $notifId): bool
    {
        inv_notifications_ensure_schema($db);
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        if (!$stmt) {
            return false;
        }
        $ok = $stmt->execute([$notifId, $userId]);
        return (bool)$ok;
    }
}

if (!function_exists('inv_mark_all_notifications_read')) {
    function inv_mark_all_notifications_read(PDO $db, int $userId): bool
    {
        inv_notifications_ensure_schema($db);
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        if (!$stmt) {
            return false;
        }
        $ok = $stmt->execute([$userId]);
        return (bool)$ok;
    }
}

if (!function_exists('inv_relative_time')) {
    function inv_relative_time(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $m = (int)floor($diff / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $h = (int)floor($diff / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 604800) {
            $d = (int)floor($diff / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        return date('M j, Y g:i A', $ts);
    }
}

if (!function_exists('inv_parse_log_change')) {
    /**
     * Parse structured inventory log details for UI badges.
     * @return array{product:string,change:string,change_class:string,status:string}
     */
    function inv_parse_log_change(string $action, string $details): array
    {
        $product = '—';
        $change = 'Updated';
        $changeClass = 'chg-updated';
        $status = 'Completed';

        if (preg_match('/product\s*#(\d+)/i', $details, $m)) {
            $product = 'Product #' . $m[1];
        }
        if (preg_match('/"([^"]{1,120})"/', $details, $m)) {
            $product = $m[1];
        } elseif (preg_match('/Added product:\s*(.+?)(?:\s*\(|$)/i', $details, $m)) {
            $product = trim($m[1]);
        } elseif (preg_match('/Deleted product:\s*(.+)$/i', $details, $m)) {
            $product = trim($m[1]);
        }

        if (preg_match('/stock\s+(\d+)\s*→\s*(\d+)/u', $details, $m)
            || preg_match('/stock\s+(\d+)\s*->\s*(\d+)/i', $details, $m)) {
            $delta = (int)$m[2] - (int)$m[1];
            if ($delta > 0) {
                $change = '+' . $delta;
                $changeClass = 'chg-plus';
                $status = 'Completed';
            } elseif ($delta < 0) {
                $change = (string)$delta;
                $changeClass = 'chg-minus';
                $status = 'Adjusted';
            } else {
                $change = '0';
                $changeClass = 'chg-updated';
                $status = 'Adjusted';
            }
        } elseif ($action === 'add_product') {
            if (preg_match('/stock:\s*(\d+)/i', $details, $m)) {
                $change = '+' . (int)$m[1];
                $changeClass = 'chg-plus';
            } else {
                $change = 'Added';
                $changeClass = 'chg-plus';
            }
            $status = 'Completed';
        } elseif ($action === 'delete_product') {
            $change = 'Removed';
            $changeClass = 'chg-minus';
            $status = 'Completed';
        } elseif ($action === 'edit_product') {
            $change = 'Updated';
            $changeClass = 'chg-updated';
            $status = 'Adjusted';
        }

        return [
            'product' => $product,
            'change' => $change,
            'change_class' => $changeClass,
            'status' => $status,
        ];
    }
}

if (!function_exists('inv_action_label')) {
    function inv_action_label(string $action): string
    {
        $map = [
            'add_product' => 'Stock Added',
            'edit_product' => 'Stock Updated',
            'delete_product' => 'Product Removed',
            'update_order_status' => 'Order Updated',
            'view_dashboard' => 'Viewed Dashboard',
            'profile_update' => 'Profile Updated',
            'password_change' => 'Password Changed',
        ];
        return $map[$action] ?? ucwords(str_replace('_', ' ', $action));
    }
}
