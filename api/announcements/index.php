<?php
/**
 * TCM LMS — Announcements Endpoint
 *
 * GET    /api/announcements/index.php   → fetch announcement feed
 * POST   /api/announcements/index.php   → create announcement
 * DELETE /api/announcements/index.php   → delete announcement
 *
 * Query params for GET:
 *   ?audience=all|students|instructors   (optional filter)
 *   ?class_section_id=N                  (class-specific only)
 *   ?limit=20&offset=0                   (pagination)
 *
 * Backs:
 *   - student-dashboard.html  Announcements feed card
 *   - admin-dashboard.html    Announcements tab (manage)
 *   - instructor-dashboard.html Announcements section
 *
 * Auth: all roles GET, admin/instructor POST, admin DELETE
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════
// GET — fetch announcements feed
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $auth   = require_bearer_auth(['student', 'instructor', 'admin']);
    $pdo    = db();
    $limit  = min((int)($_GET['limit']  ?? 20), 50);
    $offset = max((int)($_GET['offset'] ?? 0),   0);
    $class_section_id = int_field($_GET, 'class_section_id');

    // Auto-expire announcements whose expiry_at has passed
    $pdo->prepare("
        UPDATE announcements
        SET status = 'expired'
        WHERE status = 'published'
          AND expiry_at IS NOT NULL
          AND expiry_at < NOW()
    ")->execute();

    // Also auto-publish scheduled announcements whose publish_at has passed
    $pdo->prepare("
        UPDATE announcements
        SET status = 'published'
        WHERE status = 'scheduled'
          AND publish_at <= NOW()
    ")->execute();

    // Build audience filter based on role
    // Students see: audience='all' OR audience='students'
    // Instructors see: audience='all' OR audience='instructors'
    // Admins see everything
    $audience_where = match($auth['role']) {
        'student'    => "AND (a.audience IN ('all','students') OR a.class_section_id IS NOT NULL)",
        'instructor' => "AND a.audience IN ('all','instructors')",
        default      => "",  // admin sees all
    };

    $class_where  = $class_section_id ? "AND (a.class_section_id = ? OR a.class_section_id IS NULL)" : "";
    $status_where = $auth['role'] === 'admin' ? "" : "AND a.status = 'published'";

    $params = [];
    if ($class_section_id) $params[] = $class_section_id;
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare("
        SELECT
            a.announcement_id,
            a.title,
            a.message,
            a.audience,
            a.priority,
            a.class_section_id,
            a.publish_at,
            a.expiry_at,
            a.status,
            CONCAT(u.first_name,' ',u.last_name) AS posted_by_name,
            u.role AS poster_role,
            sub.subject_code,
            cs.section_code
        FROM announcements a
        JOIN users u ON u.user_id = a.posted_by
        LEFT JOIN class_sections cs  ON cs.class_section_id = a.class_section_id
        LEFT JOIN subjects sub       ON sub.subject_id = cs.subject_id
        WHERE 1=1
          {$audience_where}
          {$class_where}
          {$status_where}
          AND a.publish_at <= NOW()
        ORDER BY
            FIELD(a.priority,'urgent','important','normal'),
            a.publish_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $announcements = $stmt->fetchAll();

    // Total count for pagination
    $count_params = $class_section_id ? [$class_section_id] : [];
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM announcements a
        WHERE 1=1 {$audience_where} {$class_where} {$status_where} AND a.publish_at <= NOW()
    ");
    $count_stmt->execute($count_params);
    $total = (int)$count_stmt->fetchColumn();

    success([
        'announcements' => $announcements,
        'total'         => $total,
        'limit'         => $limit,
        'offset'        => $offset,
        'has_more'      => ($offset + $limit) < $total,
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — create an announcement
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $auth = require_bearer_auth(['instructor', 'admin']);
    $body = get_json_body();
    $pdo  = db();

    $title            = str_field($body, 'title',    required: true);
    $message          = str_field($body, 'message',  required: true);
    $audience         = str_field($body, 'audience') ?? 'all';
    $priority         = str_field($body, 'priority') ?? 'normal';
    $class_section_id = int_field($body, 'class_section_id');
    $expiry_at        = str_field($body, 'expiry_at');
    $publish_at       = str_field($body, 'publish_at') ?? date('Y-m-d H:i:s');

    // Validate enums
    $valid_audiences = ['all', 'students', 'instructors', 'department'];
    $valid_priorities = ['normal', 'important', 'urgent'];
    if (!in_array($audience, $valid_audiences, true))  $audience = 'all';
    if (!in_array($priority, $valid_priorities, true)) $priority = 'normal';

    // Instructors can only post to their own class; admins can post anywhere
    if ($auth['role'] === 'instructor') {
        if (!$class_section_id) {
            error('Instructors must specify a class_section_id for their announcement.', 422);
        }
        $owns = $pdo->prepare("
            SELECT class_section_id FROM class_sections
            WHERE class_section_id = ? AND instructor_id = ?
        ");
        $owns->execute([$class_section_id, $auth['user_id']]);
        if (!$owns->fetch()) {
            error('You do not have permission to post to this class.', 403);
        }
        $audience = 'students'; // instructor posts always target students
    }

    // Determine status
    $status = (new DateTime($publish_at)) > new DateTime() ? 'scheduled' : 'published';

    $pdo->prepare("
        INSERT INTO announcements
            (title, message, audience, priority, class_section_id,
             publish_at, expiry_at, posted_by, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $title, $message, $audience, $priority, $class_section_id,
        $publish_at, $expiry_at, $auth['user_id'], $status,
    ]);
    $announcement_id = (int)$pdo->lastInsertId();

    // Notify enrolled students if class-specific and publishing now
    if ($class_section_id && $status === 'published') {
        $students = $pdo->prepare("
            SELECT student_id FROM enrollments
            WHERE class_section_id = ? AND status != 'dropped'
        ");
        $students->execute([$class_section_id]);
        $notify = $pdo->prepare("
            INSERT INTO notifications (user_id, notif_type, title, body)
            VALUES (?, 'announcement', ?, ?)
        ");
        foreach ($students->fetchAll() as $row) {
            $notify->execute([$row['student_id'], $title, $message]);
        }
    }

    audit('announcement', "Created announcement_id={$announcement_id} title=\"{$title}\"", $auth['user_id']);

    success([
        'announcement_id' => $announcement_id,
        'title'           => $title,
        'status'          => $status,
        'audience'        => $audience,
    ], 'Announcement created successfully.');
}

// ════════════════════════════════════════════════════════════════════════
// DELETE — remove an announcement (admin only)
// ════════════════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    $auth            = require_bearer_auth(['admin']);
    $body            = get_json_body();
    $announcement_id = int_field($body, 'announcement_id', required: true);
    $pdo             = db();

    $existing = $pdo->prepare("SELECT announcement_id, title FROM announcements WHERE announcement_id = ?");
    $existing->execute([$announcement_id]);
    $ann = $existing->fetch();

    if (!$ann) {
        error('Announcement not found.', 404);
    }

    $pdo->prepare("DELETE FROM announcements WHERE announcement_id = ?")
        ->execute([$announcement_id]);

    audit('announcement', "Deleted announcement_id={$announcement_id} title=\"{$ann['title']}\"", $auth['user_id']);

    success(['announcement_id' => $announcement_id], 'Announcement deleted.');
}

error('Method not allowed.', 405);
