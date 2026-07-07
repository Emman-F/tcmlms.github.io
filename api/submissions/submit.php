<?php
/**
 * TCM LMS — Assignment Submission Endpoint
 *
 * POST /api/submissions/submit.php
 *   multipart/form-data: file (optional), activity_id, notes
 *
 * GET  /api/submissions/submit.php
 *   ?activity_id=N                → instructor: all submissions for an activity
 *   ?activity_id=N&student=self   → student: own submission for an activity
 *
 * This endpoint backs:
 *   - student-dashboard.html  "Submit Assignment" flow
 *   - instructor-dashboard.html "Submitted Assignments" tab
 *   - submission-analytics.html (aggregated later by analytics endpoint)
 *
 * Auth: student (POST their own), instructor/admin (GET any in their class)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════
// GET — fetch submission(s) for an activity
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $auth        = require_bearer_auth(['student', 'instructor', 'admin']);
    $activity_id = int_field($_GET, 'activity_id', required: true);
    $pdo         = db();

    if ($auth['role'] === 'student') {
        // Student sees only their own submission
        $stmt = $pdo->prepare("
            SELECT
                s.submission_id, s.activity_id, s.student_id,
                s.file_path, s.notes, s.submitted_at,
                s.is_late, s.days_late,
                s.raw_score, s.penalty_applied, s.incentive_applied, s.final_score,
                s.feedback, s.graded_at, s.status,
                a.title AS activity_title, a.due_at,
                a.total_points, a.late_penalty_type, a.late_penalty_points,
                a.early_incentive_enabled, a.early_incentive_points
            FROM submissions s
            JOIN activities a ON a.activity_id = s.activity_id
            WHERE s.activity_id = ? AND s.student_id = ?
        ");
        $stmt->execute([$activity_id, $auth['user_id']]);
        $sub = $stmt->fetch();

        if (!$sub) {
            success(['submitted' => false, 'submission' => null]);
        }
        success(['submitted' => true, 'submission' => $sub]);

    } else {
        // Instructor sees all submissions for this activity
        // Verify instructor owns the class this activity belongs to
        if ($auth['role'] === 'instructor') {
            $owns = $pdo->prepare("
                SELECT a.activity_id FROM activities a
                JOIN class_sections cs ON cs.class_section_id = a.class_section_id
                WHERE a.activity_id = ? AND cs.instructor_id = ?
            ");
            $owns->execute([$activity_id, $auth['user_id']]);
            if (!$owns->fetch()) {
                error('You do not have access to this activity.', 403);
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                s.submission_id, s.file_path, s.notes, s.submitted_at,
                s.is_late, s.days_late,
                s.raw_score, s.penalty_applied, s.incentive_applied, s.final_score,
                s.feedback, s.graded_at, s.status,
                u.user_id   AS student_user_id,
                u.id_number AS student_id_number,
                CONCAT(u.first_name,' ',u.last_name) AS student_name
            FROM submissions s
            JOIN users u ON u.user_id = s.student_id
            WHERE s.activity_id = ?
            ORDER BY s.submitted_at ASC
        ");
        $stmt->execute([$activity_id]);
        $subs = $stmt->fetchAll();

        // Also return count of enrolled students so instructor can see who hasn't submitted
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) AS enrolled
            FROM enrollments e
            JOIN activities a ON a.class_section_id = e.class_section_id
            WHERE a.activity_id = ? AND e.status != 'dropped'
        ");
        $count_stmt->execute([$activity_id]);
        $enrolled = (int)$count_stmt->fetchColumn();

        success([
            'activity_id'      => $activity_id,
            'total_enrolled'   => $enrolled,
            'total_submitted'  => count($subs),
            'total_missing'    => max(0, $enrolled - count($subs)),
            'submissions'      => $subs,
        ]);
    }
}

// ════════════════════════════════════════════════════════════════════════
// POST — student submits a file or text for an activity
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $auth = require_bearer_auth(['student']);

    // With multipart/form-data, fields come via $_POST not JSON body
    $activity_id = isset($_POST['activity_id']) ? (int)$_POST['activity_id'] : null;
    $notes       = isset($_POST['notes'])       ? trim($_POST['notes'])       : null;

    if (!$activity_id) {
        error("Field 'activity_id' is required.", 422);
    }

    $pdo = db();

    // ── Fetch activity details ────────────────────────────────────────────
    $act_stmt = $pdo->prepare("
        SELECT
            a.activity_id, a.class_section_id, a.title, a.activity_type,
            a.due_at, a.open_at, a.status AS activity_status, a.total_points,
            a.late_penalty_type, a.late_penalty_points,
            a.early_incentive_enabled, a.early_incentive_points,
            a.early_incentive_days_threshold
        FROM activities a
        WHERE a.activity_id = ?
    ");
    $act_stmt->execute([$activity_id]);
    $activity = $act_stmt->fetch();

    if (!$activity) {
        error('Activity not found.', 404);
    }

    // ── Confirm student is enrolled in this activity's class ──────────────
    $enrolled_stmt = $pdo->prepare("
        SELECT enrollment_id FROM enrollments
        WHERE student_id = ? AND class_section_id = ? AND status != 'dropped'
    ");
    $enrolled_stmt->execute([$auth['user_id'], $activity['class_section_id']]);
    if (!$enrolled_stmt->fetch()) {
        error('You are not enrolled in the class this activity belongs to.', 403);
    }

    // ── Check for duplicate submission ────────────────────────────────────
    $dup = $pdo->prepare("
        SELECT submission_id FROM submissions
        WHERE activity_id = ? AND student_id = ?
    ");
    $dup->execute([$activity_id, $auth['user_id']]);
    if ($dup->fetch()) {
        error('You have already submitted this activity. Contact your instructor to allow a re-submission.', 409);
    }

    // ── Check activity is open ────────────────────────────────────────────
    if ($activity['activity_status'] === 'closed') {
        error('This activity is closed and no longer accepting submissions.', 403);
    }
    if ($activity['late_penalty_type'] === 'not_accepted') {
        // Hard deadline: reject submissions past due_at entirely
        if (new DateTime() > new DateTime($activity['due_at'])) {
            error('The deadline for this activity has passed. Late submissions are not accepted.', 403);
        }
    }

    // ── Handle file upload (if a file was sent) ───────────────────────────
    $file_path = null;
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file_path = handle_upload(
            'file',
            UPLOAD_SUBMISSIONS,
            ALLOWED_SUBMISSION_TYPES,
            MAX_SUBMISSION_BYTES
        );
    }

    // Quiz/exam type activities require a file or at least notes
    // (quiz_answers are handled separately via the quiz endpoint)
    if ($activity['activity_type'] !== 'quiz' && $activity['activity_type'] !== 'examination') {
        if (!$file_path && empty($notes)) {
            error('Please upload a file or include notes for this submission.', 422);
        }
    }

    // ── Compute timeliness ────────────────────────────────────────────────
    $now          = new DateTime();
    $due          = new DateTime($activity['due_at']);
    $diff_seconds = $now->getTimestamp() - $due->getTimestamp();
    $days_late    = (int)ceil($diff_seconds / 86400);  // ceil: partial day counts
    $is_late      = $days_late > 0 ? 1 : 0;

    // ── Compute penalty ───────────────────────────────────────────────────
    $penalty = 0.00;
    if ($is_late && $activity['late_penalty_type'] === 'per_day_fixed') {
        $penalty = min(
            (float)$activity['late_penalty_points'] * $days_late,
            (float)$activity['total_points']   // penalty can't exceed total points
        );
    }

    // ── Compute early incentive ───────────────────────────────────────────
    $incentive = 0.00;
    if (!$is_late && $activity['early_incentive_enabled']) {
        $days_early = abs($days_late);  // days_late is negative when early
        if ($days_early >= (int)$activity['early_incentive_days_threshold']) {
            $incentive = (float)$activity['early_incentive_points'];
        }
    }

    // ── Insert submission ─────────────────────────────────────────────────
    $pdo->prepare("
        INSERT INTO submissions
            (activity_id, student_id, file_path, notes,
             is_late, days_late, penalty_applied, incentive_applied, status)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, 'submitted')
    ")->execute([
        $activity_id,
        $auth['user_id'],
        $file_path,
        $notes,
        $is_late,
        $days_late,
        $penalty,
        $incentive,
    ]);
    $submission_id = (int)$pdo->lastInsertId();

    // ── Notify instructor of new submission ───────────────────────────────
    $instructor_stmt = $pdo->prepare("
        SELECT cs.instructor_id FROM class_sections cs
        JOIN activities a ON a.class_section_id = cs.class_section_id
        WHERE a.activity_id = ?
    ");
    $instructor_stmt->execute([$activity_id]);
    $instructor = $instructor_stmt->fetch();

    if ($instructor) {
        $notif_type = $is_late ? 'late_submission' : 'new_submission';
        $pdo->prepare("
            INSERT INTO notifications (user_id, notif_type, title, body)
            VALUES (?, ?, ?, ?)
        ")->execute([
            $instructor['instructor_id'],
            $notif_type,
            ($is_late ? 'Late Submission: ' : 'New Submission: ') . $activity['title'],
            "A student submitted '{$activity['title']}'" . ($is_late ? " ({$days_late} day(s) late)." : '.'),
        ]);
    }

    audit(
        'submission',
        "Student {$auth['user_id']} submitted activity_id={$activity_id}" .
        ($is_late ? " LATE ({$days_late} days)" : ' on time') .
        ($penalty  > 0 ? " penalty=-{$penalty}"  : '') .
        ($incentive > 0 ? " incentive=+{$incentive}" : ''),
        $auth['user_id']
    );

    success([
        'submission_id'     => $submission_id,
        'activity_id'       => $activity_id,
        'submitted_at'      => date('Y-m-d H:i:s'),
        'is_late'           => (bool)$is_late,
        'days_late'         => $days_late,
        'penalty_applied'   => $penalty,
        'incentive_applied' => $incentive,
        'file_path'         => $file_path,
    ], $is_late
        ? "Submitted {$days_late} day(s) late. A penalty of {$penalty} point(s) has been applied."
        : ($incentive > 0
            ? "Submitted early! You earned a {$incentive} point bonus."
            : 'Assignment submitted successfully.')
    );
}

error('Method not allowed.', 405);
