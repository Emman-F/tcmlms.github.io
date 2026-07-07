<?php
/**
 * TCM LMS — Notifications Endpoint
 *
 * GET  /api/notifications/index.php          → unread + recent notifications
 * POST /api/notifications/index.php
 *   action=mark_read   { notification_id }   → mark one notification read
 *   action=mark_all_read                     → mark all as read
 *   action=delete      { notification_id }   → delete one notification
 *
 * Backs:
 *   - Bell icon unread count badge on every dashboard page
 *   - Notification dropdown/panel on all three role dashboards
 *   - Profile page Activity Log (recent logins / system events)
 *
 * Auth: any authenticated user (own notifications only)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════
// GET — fetch notification feed for the current user
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $auth   = require_bearer_auth(['student', 'instructor', 'admin']);
    $pdo    = db();
    $limit  = min((int)($_GET['limit']  ?? 30), 100);
    $unread_only = !empty($_GET['unread_only']);

    $where = $unread_only ? "AND n.is_read = 0" : "";

    $stmt = $pdo->prepare("
        SELECT
            n.notification_id,
            n.notif_type,
            n.title,
            n.body,
            n.link_url,
            n.is_read,
            n.created_at
        FROM notifications n
        WHERE n.user_id = ? {$where}
        ORDER BY n.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$auth['user_id'], $limit]);
    $notifications = $stmt->fetchAll();

    // Unread count (always computed, regardless of the unread_only filter)
    $unread_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $unread_stmt->execute([$auth['user_id']]);
    $unread_count = (int)$unread_stmt->fetchColumn();

    success([
        'notifications' => $notifications,
        'unread_count'  => $unread_count,
        'total_fetched' => count($notifications),
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — mark read / mark all read / delete
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $auth   = require_bearer_auth(['student', 'instructor', 'admin']);
    $body   = get_json_body();
    $pdo    = db();
    $action = str_field($body, 'action', required: true);

    // ── Mark one notification as read ─────────────────────────────────────
    if ($action === 'mark_read') {
        $notification_id = int_field($body, 'notification_id', required: true);

        // Ownership check — user can only mark their own notifications
        $check = $pdo->prepare("
            SELECT notification_id FROM notifications
            WHERE notification_id = ? AND user_id = ?
        ");
        $check->execute([$notification_id, $auth['user_id']]);
        if (!$check->fetch()) {
            error('Notification not found.', 404);
        }

        $pdo->prepare("
            UPDATE notifications SET is_read = 1 WHERE notification_id = ?
        ")->execute([$notification_id]);

        // Return updated unread count so the bell badge can update immediately
        $unread = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
        );
        $unread->execute([$auth['user_id']]);

        success([
            'notification_id' => $notification_id,
            'unread_count'    => (int)$unread->fetchColumn(),
        ], 'Notification marked as read.');
    }

    // ── Mark all notifications as read ────────────────────────────────────
    if ($action === 'mark_all_read') {
        $pdo->prepare("
            UPDATE notifications SET is_read = 1
            WHERE user_id = ? AND is_read = 0
        ")->execute([$auth['user_id']]);

        $affected = $pdo->rowCount();
        success([
            'marked_count' => $affected,
            'unread_count' => 0,
        ], "All {$affected} notification(s) marked as read.");
    }

    // ── Delete one notification ───────────────────────────────────────────
    if ($action === 'delete') {
        $notification_id = int_field($body, 'notification_id', required: true);

        $check = $pdo->prepare("
            SELECT notification_id FROM notifications
            WHERE notification_id = ? AND user_id = ?
        ");
        $check->execute([$notification_id, $auth['user_id']]);
        if (!$check->fetch()) {
            error('Notification not found.', 404);
        }

        $pdo->prepare("DELETE FROM notifications WHERE notification_id = ?")
            ->execute([$notification_id]);

        // Return updated unread count
        $unread = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
        );
        $unread->execute([$auth['user_id']]);

        success([
            'notification_id' => $notification_id,
            'unread_count'    => (int)$unread->fetchColumn(),
        ], 'Notification deleted.');
    }

    error("Unknown action '{$action}'. Valid: mark_read, mark_all_read, delete.", 422);
}

error('Method not allowed.', 405);
