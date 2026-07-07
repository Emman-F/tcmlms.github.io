<?php
/**
 * TCM LMS — Quiz Answer Submission Endpoint
 *
 * POST /api/submissions/quiz_submit.php
 *   Body: {
 *     activity_id: N,
 *     time_taken_seconds: N,
 *     answers: [
 *       { question_id: N, choice_index: N, was_flagged: false },   // MCQ
 *       { question_id: N, boolean_answer: true, was_flagged: false }, // T/F
 *       { question_id: N, text_answer: "...", was_flagged: false },   // Fill
 *       { question_id: N, text_answer: "...", was_flagged: false }    // Essay
 *     ]
 *   }
 *
 * GET /api/submissions/quiz_submit.php?activity_id=N
 *   Returns the student's own submitted answers + auto-grading results
 *   (used for quiz-exam.html Review Answers modal)
 *
 * Backs:
 *   - quiz-exam.html  "Submit Quiz" button
 *   - quiz-exam.html  Review Answers section (GET)
 *   - instructor-dashboard.html Grading tab (essay answers shown for manual grading)
 *
 * Scoring logic:
 *   - MCQ, True/False, Fill-in: auto-scored at submit time
 *   - Essay: left null, flagged for instructor manual grading
 *   - final_score on the parent submission row updated if all questions are auto-scoreable
 *
 * Auth: student (POST own answers, GET own results), instructor/admin (GET all)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════
// GET — fetch submitted answers and results for a quiz
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $auth        = require_bearer_auth(['student', 'instructor', 'admin']);
    $pdo         = db();
    $activity_id = int_field($_GET, 'activity_id', required: true);

    if ($auth['role'] === 'student') {
        // Student gets their own answers + correctness
        $sub = $pdo->prepare("
            SELECT s.submission_id, s.submitted_at, s.final_score,
                   s.raw_score, s.penalty_applied, s.incentive_applied, s.status,
                   a.total_points, a.title, a.activity_type
            FROM submissions s
            JOIN activities a ON a.activity_id = s.activity_id
            WHERE s.activity_id = ? AND s.student_id = ?
        ");
        $sub->execute([$activity_id, $auth['user_id']]);
        $submission = $sub->fetch();

        if (!$submission) {
            success(['submitted' => false]);
        }

        $answers = $pdo->prepare("
            SELECT
                qa.answer_id,
                qa.question_id,
                qa.answer_choice_index,
                qa.answer_boolean,
                qa.answer_text,
                qa.is_correct,
                qa.was_flagged,
                qq.question_text,
                qq.question_type,
                qq.question_order,
                qq.choices_json,
                qq.correct_choice_index,
                qq.correct_boolean,
                qq.correct_text,
                qq.points
            FROM quiz_answers qa
            JOIN quiz_questions qq ON qq.question_id = qa.question_id
            WHERE qa.submission_id = ?
            ORDER BY qq.question_order
        ");
        $answers->execute([$submission['submission_id']]);

        success([
            'submitted'  => true,
            'submission' => $submission,
            'answers'    => $answers->fetchAll(),
        ]);
    }

    // Instructor: all submissions for this quiz with answers
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

    // Return summary: per-question score breakdown
    $question_stats = $pdo->prepare("
        SELECT
            qq.question_id,
            qq.question_order,
            qq.question_text,
            qq.question_type,
            qq.points,
            COUNT(qa.answer_id)             AS total_answers,
            SUM(qa.is_correct = 1)          AS correct_count,
            SUM(qa.is_correct = 0)          AS wrong_count,
            SUM(qa.is_correct IS NULL)      AS pending_count
        FROM quiz_questions qq
        LEFT JOIN quiz_answers qa ON qa.question_id = qq.question_id
        LEFT JOIN submissions s   ON s.submission_id = qa.submission_id
                                  AND s.activity_id = ?
        WHERE qq.activity_id = ?
        GROUP BY qq.question_id
        ORDER BY qq.question_order
    ");
    $question_stats->execute([$activity_id, $activity_id]);

    success([
        'activity_id'    => $activity_id,
        'question_stats' => $question_stats->fetchAll(),
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — student submits quiz answers
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $auth = require_bearer_auth(['student']);
    $body = get_json_body();
    $pdo  = db();

    $activity_id        = int_field($body, 'activity_id',         required: true);
    $time_taken_seconds = int_field($body, 'time_taken_seconds')  ?? 0;
    $answers            = $body['answers'] ?? [];

    if (empty($answers) || !is_array($answers)) {
        error("'answers' must be a non-empty array.", 422);
    }

    // ── Fetch activity + questions ────────────────────────────────────────
    $act = $pdo->prepare("
        SELECT a.activity_id, a.class_section_id, a.title, a.activity_type,
               a.due_at, a.status AS activity_status, a.total_points,
               a.time_limit_minutes,
               a.late_penalty_type, a.late_penalty_points,
               a.early_incentive_enabled, a.early_incentive_points,
               a.early_incentive_days_threshold
        FROM activities a
        WHERE a.activity_id = ?
          AND a.activity_type IN ('quiz','examination')
    ");
    $act->execute([$activity_id]);
    $activity = $act->fetch();

    if (!$activity) {
        error('Quiz or examination not found.', 404);
    }
    if ($activity['activity_status'] === 'closed') {
        error('This quiz is closed and no longer accepting submissions.', 403);
    }

    // Confirm enrollment
    $enrolled = $pdo->prepare("
        SELECT enrollment_id FROM enrollments
        WHERE student_id = ? AND class_section_id = ? AND status != 'dropped'
    ");
    $enrolled->execute([$auth['user_id'], $activity['class_section_id']]);
    if (!$enrolled->fetch()) {
        error('You are not enrolled in the class this quiz belongs to.', 403);
    }

    // Check for duplicate submission
    $dup = $pdo->prepare("
        SELECT submission_id FROM submissions
        WHERE activity_id = ? AND student_id = ?
    ");
    $dup->execute([$activity_id, $auth['user_id']]);
    if ($dup->fetch()) {
        error('You have already submitted this quiz.', 409);
    }

    // Fetch all questions for this activity (for auto-scoring)
    $q_stmt = $pdo->prepare("
        SELECT question_id, question_type, points,
               correct_choice_index, correct_boolean, correct_text
        FROM quiz_questions WHERE activity_id = ?
    ");
    $q_stmt->execute([$activity_id]);
    $questions = [];
    foreach ($q_stmt->fetchAll() as $q) {
        $questions[$q['question_id']] = $q;
    }

    // ── Timeliness computation (same logic as submit.php) ─────────────────
    $now          = new DateTime();
    $due          = new DateTime($activity['due_at']);
    $diff_seconds = $now->getTimestamp() - $due->getTimestamp();
    $days_late    = (int)ceil($diff_seconds / 86400);
    $is_late      = $days_late > 0 ? 1 : 0;

    $penalty = 0.0;
    if ($is_late && $activity['late_penalty_type'] === 'per_day_fixed') {
        $penalty = min(
            (float)$activity['late_penalty_points'] * $days_late,
            (float)$activity['total_points']
        );
    }
    $incentive = 0.0;
    if (!$is_late && $activity['early_incentive_enabled']) {
        $days_early = abs($days_late);
        if ($days_early >= (int)$activity['early_incentive_days_threshold']) {
            $incentive = (float)$activity['early_incentive_points'];
        }
    }

    // ── Auto-score answers ────────────────────────────────────────────────
    $scored_answers = [];
    $raw_score      = 0.0;
    $has_essay      = false;

    foreach ($answers as $ans) {
        $qid  = isset($ans['question_id']) ? (int)$ans['question_id'] : null;
        if (!$qid || !isset($questions[$qid])) continue;

        $q          = $questions[$qid];
        $is_correct = null;
        $points_earned = 0.0;

        $choice_index  = isset($ans['choice_index'])   ? (int)$ans['choice_index']   : null;
        $bool_answer   = isset($ans['boolean_answer'])  ? (bool)$ans['boolean_answer'] : null;
        $text_answer   = isset($ans['text_answer'])     ? trim((string)$ans['text_answer']) : null;
        $was_flagged   = !empty($ans['was_flagged']) ? 1 : 0;

        switch ($q['question_type']) {
            case 'multiple':
                $is_correct = ($choice_index !== null && $choice_index === (int)$q['correct_choice_index']) ? 1 : 0;
                $points_earned = $is_correct ? (float)$q['points'] : 0.0;
                break;

            case 'truefalse':
                $is_correct = ($bool_answer !== null && $bool_answer === (bool)$q['correct_boolean']) ? 1 : 0;
                $points_earned = $is_correct ? (float)$q['points'] : 0.0;
                break;

            case 'fill':
                // Case-insensitive match with trimmed whitespace
                $is_correct = ($text_answer !== null &&
                    mb_strtolower($text_answer) === mb_strtolower(trim($q['correct_text'] ?? ''))) ? 1 : 0;
                $points_earned = $is_correct ? (float)$q['points'] : 0.0;
                break;

            case 'essay':
                // Manual grading required; is_correct stays null
                $is_correct    = null;
                $points_earned = 0.0;
                $has_essay     = true;
                break;
        }

        $raw_score += $points_earned;

        $scored_answers[] = [
            'question_id'        => $qid,
            'answer_choice_index'=> $choice_index,
            'answer_boolean'     => $bool_answer !== null ? (int)$bool_answer : null,
            'answer_text'        => $text_answer,
            'is_correct'         => $is_correct,
            'was_flagged'        => $was_flagged,
        ];
    }

    // Final score (null if essays need grading)
    $final_score = $has_essay
        ? null
        : max(0, round($raw_score - $penalty + $incentive, 2));

    $sub_status = $has_essay ? 'submitted' : 'graded';

    // ── Transaction: insert submission + all answers ───────────────────────
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO submissions
                (activity_id, student_id, notes, is_late, days_late,
                 raw_score, penalty_applied, incentive_applied, final_score, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $activity_id, $auth['user_id'],
            "Time taken: {$time_taken_seconds}s",
            $is_late, $days_late,
            round($raw_score, 2), $penalty, $incentive, $final_score,
            $sub_status,
        ]);
        $submission_id = (int)$pdo->lastInsertId();

        $ins_answer = $pdo->prepare("
            INSERT INTO quiz_answers
                (submission_id, question_id,
                 answer_choice_index, answer_boolean, answer_text,
                 is_correct, was_flagged)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($scored_answers as $sa) {
            $ins_answer->execute([
                $submission_id,
                $sa['question_id'],
                $sa['answer_choice_index'],
                $sa['answer_boolean'],
                $sa['answer_text'],
                $sa['is_correct'],
                $sa['was_flagged'],
            ]);
        }

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $msg = (APP_ENV === 'development') ? $e->getMessage() : 'Database error while saving quiz.';
        error($msg, 500);
    }

    // Notify instructor of new quiz submission
    $instr = $pdo->prepare("
        SELECT cs.instructor_id FROM class_sections cs
        JOIN activities a ON a.class_section_id = cs.class_section_id
        WHERE a.activity_id = ?
    ");
    $instr->execute([$activity_id]);
    $instructor = $instr->fetch();
    if ($instructor) {
        $pdo->prepare("
            INSERT INTO notifications (user_id, notif_type, title, body)
            VALUES (?, 'new_submission', ?, ?)
        ")->execute([
            $instructor['instructor_id'],
            'Quiz Submitted: ' . $activity['title'],
            "A student completed \"{$activity['title']}\"" .
            ($has_essay ? ' (contains essay — manual grading required).' : '.'),
        ]);
    }

    audit(
        'submission',
        "Quiz submitted: activity={$activity_id} student={$auth['user_id']}" .
        " raw_score={$raw_score} is_late={$is_late}",
        $auth['user_id']
    );

    $msg = $has_essay
        ? 'Quiz submitted. Objective questions have been auto-scored. Essay questions are pending instructor grading.'
        : "Quiz submitted. Your score: {$final_score} / {$activity['total_points']}.";

    success([
        'submission_id'     => $submission_id,
        'activity_id'       => $activity_id,
        'raw_score'         => round($raw_score, 2),
        'penalty_applied'   => $penalty,
        'incentive_applied' => $incentive,
        'final_score'       => $final_score,
        'total_points'      => (float)$activity['total_points'],
        'has_essay'         => $has_essay,
        'is_late'           => (bool)$is_late,
        'answers_saved'     => count($scored_answers),
    ], $msg);
}

error('Method not allowed.', 405);
