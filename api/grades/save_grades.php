<?php
/**
 * TCM LMS — Grading Endpoint
 *
 * POST /api/grades/save_grades.php
 *   Grade a single submission (instructor enters raw_score + feedback)
 *
 * GET  /api/grades/save_grades.php
 *   ?class_section_id=N&period=prelim   → grade sheet for one class + period
 *   ?student_id=N&class_section_id=N    → one student's grades across all periods
 *
 * This endpoint backs:
 *   - instructor-dashboard.html "Grade Submission" modal
 *   - instructor-dashboard.html Grading tab (grade sheet view)
 *   - student-dashboard.html / profile.html grade tables
 *   - subject-detail.html Assignments tab (grade shown next to each row)
 *
 * Auth: instructor/admin (POST & GET grade sheet), student (GET own grades only)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════
// GET — fetch grades
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $auth = require_bearer_auth(['student', 'instructor', 'admin']);
    $pdo  = db();

    // ── Student: fetch their own period grades for all their classes ──────
    if ($auth['role'] === 'student') {
        $class_section_id = int_field($_GET, 'class_section_id');

        if ($class_section_id) {
            // All periods for one class
            $stmt = $pdo->prepare("
                SELECT
                    pg.grading_period,
                    pg.assignment_score,
                    pg.quiz_score,
                    pg.exam_score,
                    pg.period_grade,
                    pg.remarks,
                    sub.subject_code,
                    sub.title AS subject_title,
                    sub.weight_assignment,
                    sub.weight_quiz,
                    sub.weight_exam
                FROM period_grades pg
                JOIN class_sections cs ON cs.class_section_id = pg.class_section_id
                JOIN subjects sub      ON sub.subject_id = cs.subject_id
                WHERE pg.student_id = ? AND pg.class_section_id = ?
                ORDER BY FIELD(pg.grading_period,'prelim','midterm','prefinal','final')
            ");
            $stmt->execute([$auth['user_id'], $class_section_id]);
        } else {
            // All classes + all periods
            $stmt = $pdo->prepare("
                SELECT
                    pg.class_section_id,
                    pg.grading_period,
                    pg.assignment_score,
                    pg.quiz_score,
                    pg.exam_score,
                    pg.period_grade,
                    pg.remarks,
                    sub.subject_code,
                    sub.title AS subject_title
                FROM period_grades pg
                JOIN class_sections cs ON cs.class_section_id = pg.class_section_id
                JOIN subjects sub      ON sub.subject_id = cs.subject_id
                JOIN enrollments e     ON e.class_section_id = pg.class_section_id
                                      AND e.student_id = pg.student_id
                WHERE pg.student_id = ? AND e.status != 'dropped'
                ORDER BY sub.subject_code,
                         FIELD(pg.grading_period,'prelim','midterm','prefinal','final')
            ");
            $stmt->execute([$auth['user_id']]);
        }
        $grades = $stmt->fetchAll();
        success(['grades' => $grades, 'count' => count($grades)]);
    }

    // ── Instructor: grade sheet for one class + period ─────────────────────
    $class_section_id = int_field($_GET, 'class_section_id', required: true);
    $period           = str_field($_GET, 'period') ?? 'prelim';

    $valid_periods = ['prelim', 'midterm', 'prefinal', 'final'];
    if (!in_array($period, $valid_periods, true)) {
        error("Invalid period. Must be one of: " . implode(', ', $valid_periods), 422);
    }

    // Verify instructor owns this class
    if ($auth['role'] === 'instructor') {
        $owns = $pdo->prepare("
            SELECT class_section_id FROM class_sections
            WHERE class_section_id = ? AND instructor_id = ?
        ");
        $owns->execute([$class_section_id, $auth['user_id']]);
        if (!$owns->fetch()) {
            error('You do not have access to this class.', 403);
        }
    }

    // All enrolled students with their period grade for the requested period
    $stmt = $pdo->prepare("
        SELECT
            u.user_id,
            u.id_number,
            CONCAT(u.first_name,' ',u.last_name) AS full_name,
            u.first_name,
            u.last_name,
            pg.period_grade_id,
            pg.assignment_score,
            pg.quiz_score,
            pg.exam_score,
            pg.period_grade,
            pg.remarks,
            pg.computed_at
        FROM enrollments e
        JOIN users u ON u.user_id = e.student_id
        LEFT JOIN period_grades pg
            ON pg.student_id = e.student_id
           AND pg.class_section_id = e.class_section_id
           AND pg.grading_period = ?
        WHERE e.class_section_id = ? AND e.status != 'dropped'
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute([$period, $class_section_id]);
    $sheet = $stmt->fetchAll();

    // Also return the subject's weight config so frontend can show it
    $weights = $pdo->prepare("
        SELECT sub.weight_assignment, sub.weight_quiz, sub.weight_exam,
               sub.subject_code, sub.title, cs.section_code
        FROM class_sections cs
        JOIN subjects sub ON sub.subject_id = cs.subject_id
        WHERE cs.class_section_id = ?
    ");
    $weights->execute([$class_section_id]);
    $meta = $weights->fetch();

    success([
        'class_section_id' => $class_section_id,
        'period'           => $period,
        'meta'             => $meta,
        'students'         => $sheet,
        'count'            => count($sheet),
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — save grades for one submission OR upsert a period grade row
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $auth = require_bearer_auth(['instructor', 'admin']);
    $body = get_json_body();
    $pdo  = db();

    // Determine what kind of grading action this is:
    // (A) Grade a single submission (raw_score + feedback on one submission row)
    // (B) Save period grade (assignment_score + quiz_score + exam_score → compute period_grade)
    $action = str_field($body, 'action') ?? 'grade_submission';

    // ── (A) Grade a single submission ─────────────────────────────────────
    if ($action === 'grade_submission') {
        $submission_id = int_field($body, 'submission_id', required: true);
        $raw_score     = float_field($body, 'raw_score',   required: true);
        $feedback      = str_field($body, 'feedback');

        // Fetch submission + activity details
        $stmt = $pdo->prepare("
            SELECT
                s.submission_id, s.student_id, s.activity_id,
                s.penalty_applied, s.incentive_applied,
                a.total_points, a.class_section_id, a.title AS activity_title,
                cs.instructor_id
            FROM submissions s
            JOIN activities a  ON a.activity_id = s.activity_id
            JOIN class_sections cs ON cs.class_section_id = a.class_section_id
            WHERE s.submission_id = ?
        ");
        $stmt->execute([$submission_id]);
        $sub = $stmt->fetch();

        if (!$sub) {
            error('Submission not found.', 404);
        }

        // Instructor ownership check
        if ($auth['role'] === 'instructor' && (int)$sub['instructor_id'] !== (int)$auth['user_id']) {
            error('You do not have permission to grade this submission.', 403);
        }

        // Raw score must be between 0 and total_points
        if ($raw_score < 0 || $raw_score > (float)$sub['total_points']) {
            error("Raw score must be between 0 and {$sub['total_points']}.", 422);
        }

        // Compute final score: raw - penalty + incentive, floor at 0
        $final_score = max(0, $raw_score - (float)$sub['penalty_applied'] + (float)$sub['incentive_applied']);

        $pdo->prepare("
            UPDATE submissions
            SET raw_score    = ?,
                final_score  = ?,
                feedback     = ?,
                graded_by    = ?,
                graded_at    = NOW(),
                status       = 'graded'
            WHERE submission_id = ?
        ")->execute([$raw_score, $final_score, $feedback, $auth['user_id'], $submission_id]);

        // Notify student that their submission has been graded
        $pdo->prepare("
            INSERT INTO notifications (user_id, notif_type, title, body)
            VALUES (?, 'grade_released', ?, ?)
        ")->execute([
            $sub['student_id'],
            'Grade Released: ' . $sub['activity_title'],
            "Your submission for '{$sub['activity_title']}' has been graded. Final score: {$final_score} / {$sub['total_points']}.",
        ]);

        audit(
            'grade',
            "Graded submission_id={$submission_id} raw={$raw_score} final={$final_score}",
            $auth['user_id']
        );

        success([
            'submission_id'     => $submission_id,
            'raw_score'         => $raw_score,
            'penalty_applied'   => (float)$sub['penalty_applied'],
            'incentive_applied' => (float)$sub['incentive_applied'],
            'final_score'       => $final_score,
            'total_points'      => (float)$sub['total_points'],
        ], 'Submission graded successfully.');
    }

    // ── (B) Save / upsert a full period grade row ─────────────────────────
    if ($action === 'save_period_grade') {
        $student_id       = int_field($body, 'student_id',       required: true);
        $class_section_id = int_field($body, 'class_section_id', required: true);
        $period           = str_field($body, 'period',           required: true);
        $assignment_score = float_field($body, 'assignment_score');
        $quiz_score       = float_field($body, 'quiz_score');
        $exam_score       = float_field($body, 'exam_score');

        $valid_periods = ['prelim', 'midterm', 'prefinal', 'final'];
        if (!in_array($period, $valid_periods, true)) {
            error("Invalid period. Must be one of: " . implode(', ', $valid_periods), 422);
        }

        // Ownership check
        if ($auth['role'] === 'instructor') {
            $owns = $pdo->prepare("
                SELECT class_section_id FROM class_sections
                WHERE class_section_id = ? AND instructor_id = ?
            ");
            $owns->execute([$class_section_id, $auth['user_id']]);
            if (!$owns->fetch()) {
                error('You do not have permission to grade students in this class.', 403);
            }
        }

        // Fetch weight config from subjects
        $w = $pdo->prepare("
            SELECT sub.weight_assignment, sub.weight_quiz, sub.weight_exam
            FROM class_sections cs
            JOIN subjects sub ON sub.subject_id = cs.subject_id
            WHERE cs.class_section_id = ?
        ");
        $w->execute([$class_section_id]);
        $weights = $w->fetch();

        if (!$weights) {
            error('Class section or subject not found.', 404);
        }

        // Compute weighted period grade (all component scores are out of 100)
        $period_grade = null;
        if ($assignment_score !== null && $quiz_score !== null && $exam_score !== null) {
            $period_grade = round(
                ($assignment_score * $weights['weight_assignment'] / 100) +
                ($quiz_score       * $weights['weight_quiz']       / 100) +
                ($exam_score       * $weights['weight_exam']       / 100),
                2
            );
        }

        // Derive remarks based on the institution's passing threshold
        $passing = (float)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='default_passing_grade'")->fetchColumn() ?? 70);
        $remarks = null;
        if ($period_grade !== null) {
            $remarks = match(true) {
                $period_grade >= 90 => 'passed',
                $period_grade >= $passing => 'passing',
                $period_grade >= ($passing - 10) => 'at_risk',
                default => 'failed',
            };
        }

        // UPSERT — insert if first time grading this period, update if revising
        $pdo->prepare("
            INSERT INTO period_grades
                (student_id, class_section_id, grading_period,
                 assignment_score, quiz_score, exam_score,
                 period_grade, remarks, computed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                assignment_score = VALUES(assignment_score),
                quiz_score       = VALUES(quiz_score),
                exam_score       = VALUES(exam_score),
                period_grade     = VALUES(period_grade),
                remarks          = VALUES(remarks),
                computed_at      = NOW()
        ")->execute([
            $student_id, $class_section_id, $period,
            $assignment_score, $quiz_score, $exam_score,
            $period_grade, $remarks,
        ]);

        // Notify student if grade is now set
        if ($period_grade !== null) {
            $subj = $pdo->prepare("
                SELECT sub.subject_code FROM class_sections cs
                JOIN subjects sub ON sub.subject_id = cs.subject_id
                WHERE cs.class_section_id = ?
            ");
            $subj->execute([$class_section_id]);
            $subject_code = $subj->fetchColumn() ?? '';

            $pdo->prepare("
                INSERT INTO notifications (user_id, notif_type, title, body)
                VALUES (?, 'grade_released', ?, ?)
            ")->execute([
                $student_id,
                ucfirst($period) . " Grade Released: {$subject_code}",
                "Your " . ucfirst($period) . " grade for {$subject_code} is now available: {$period_grade}.",
            ]);
        }

        audit(
            'grade',
            "Period grade saved: student={$student_id} class={$class_section_id} period={$period} grade={$period_grade}",
            $auth['user_id']
        );

        success([
            'student_id'       => $student_id,
            'class_section_id' => $class_section_id,
            'grading_period'   => $period,
            'assignment_score' => $assignment_score,
            'quiz_score'       => $quiz_score,
            'exam_score'       => $exam_score,
            'period_grade'     => $period_grade,
            'remarks'          => $remarks,
        ], 'Period grade saved successfully.');
    }

    error("Unknown action '{$action}'.", 422);
}

error('Method not allowed.', 405);
