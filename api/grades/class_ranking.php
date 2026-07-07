<?php
/**
 * TCM LMS — Class Ranking Endpoint
 *
 * GET /api/grades/class_ranking.php
 *   ?class_section_id=N              → full ranking for one class
 *   ?class_section_id=N&period=final → ranking for one grading period only
 *
 * Backs:
 *   - instructor-dashboard.html Class Ranking tab
 *   - admin-dashboard.html Analytics (class ranking widget)
 *   - student-dashboard.html rank badge ("Rank 3 of 35")
 *
 * Ranking logic:
 *   - Computes each student's average across all graded periods
 *   - Ranks by average DESC (highest grade = Rank 1)
 *   - Ties share the same rank (standard competition ranking: 1,2,2,4...)
 *   - Returns With Honors / Passed / Conditional / At Risk labels
 *     matching the exact badge text already shown in the frontend
 *
 * Auth: instructor/admin (all students), student (own rank only)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error('Method not allowed. Use GET.', 405);
}

$auth             = require_bearer_auth(['student', 'instructor', 'admin']);
$pdo              = db();
$class_section_id = int_field($_GET, 'class_section_id', required: true);
$period           = str_field($_GET, 'period'); // null = all periods averaged

// Validate period if provided
$valid_periods = ['prelim', 'midterm', 'prefinal', 'final'];
if ($period !== null && !in_array($period, $valid_periods, true)) {
    error("Invalid period. Must be one of: " . implode(', ', $valid_periods), 422);
}

// Instructor ownership check
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

// Students may only see the ranking for a class they are enrolled in
if ($auth['role'] === 'student') {
    $enrolled = $pdo->prepare("
        SELECT enrollment_id FROM enrollments
        WHERE student_id = ? AND class_section_id = ? AND status != 'dropped'
    ");
    $enrolled->execute([$auth['user_id'], $class_section_id]);
    if (!$enrolled->fetch()) {
        error('You are not enrolled in this class.', 403);
    }
}

// ── Fetch class meta ──────────────────────────────────────────────────────────
$meta_stmt = $pdo->prepare("
    SELECT
        cs.class_section_id,
        cs.section_code,
        cs.academic_year,
        cs.semester,
        sub.subject_code,
        sub.title AS subject_title,
        CONCAT(u.first_name,' ',u.last_name) AS instructor_name
    FROM class_sections cs
    JOIN subjects sub  ON sub.subject_id = cs.subject_id
    JOIN users u       ON u.user_id = cs.instructor_id
    WHERE cs.class_section_id = ?
");
$meta_stmt->execute([$class_section_id]);
$meta = $meta_stmt->fetch();

if (!$meta) {
    error('Class section not found.', 404);
}

// ── Compute grades per student ────────────────────────────────────────────────
if ($period) {
    // Rank for one specific period
    $grades_stmt = $pdo->prepare("
        SELECT
            u.user_id,
            u.id_number,
            CONCAT(u.first_name,' ',u.last_name) AS full_name,
            u.first_name,
            u.last_name,
            pg.period_grade   AS grade,
            pg.grading_period AS period,
            pg.remarks
        FROM enrollments e
        JOIN users u ON u.user_id = e.student_id
        LEFT JOIN period_grades pg
            ON pg.student_id = e.student_id
           AND pg.class_section_id = e.class_section_id
           AND pg.grading_period = ?
        WHERE e.class_section_id = ? AND e.status != 'dropped'
        ORDER BY pg.period_grade DESC NULLS LAST, u.last_name ASC
    ");
    $grades_stmt->execute([$period, $class_section_id]);
} else {
    // Rank by average across ALL graded periods
    $grades_stmt = $pdo->prepare("
        SELECT
            u.user_id,
            u.id_number,
            CONCAT(u.first_name,' ',u.last_name) AS full_name,
            u.first_name,
            u.last_name,
            ROUND(AVG(pg.period_grade), 2) AS grade,
            'average'                       AS period,
            COUNT(pg.period_grade)          AS periods_graded
        FROM enrollments e
        JOIN users u ON u.user_id = e.student_id
        LEFT JOIN period_grades pg
            ON pg.student_id = e.student_id
           AND pg.class_section_id = e.class_section_id
        WHERE e.class_section_id = ? AND e.status != 'dropped'
        GROUP BY u.user_id
        ORDER BY grade DESC, u.last_name ASC
    ");
    $grades_stmt->execute([$class_section_id]);
}

$rows = $grades_stmt->fetchAll();

// ── Assign ranks (standard competition ranking, ties share rank) ──────────────
$passing = (float)($pdo->query(
    "SELECT setting_value FROM system_settings WHERE setting_key='default_passing_grade'"
)->fetchColumn() ?? 70);

$ranked    = [];
$rank      = 1;
$prev_grade = null;
$prev_rank  = 1;

foreach ($rows as $i => $row) {
    $grade = $row['grade'] !== null ? (float)$row['grade'] : null;

    // Assign rank (null grades go to the bottom with no rank)
    if ($grade === null) {
        $student_rank = null;
    } elseif ($grade === $prev_grade) {
        $student_rank = $prev_rank;     // tie: same rank as previous
    } else {
        $student_rank = $rank;
        $prev_rank    = $rank;
        $prev_grade   = $grade;
    }
    $rank++;

    // Honor / status labels matching the exact badges in the frontend UI:
    // "With Honors" | "Passed" | "Passing" | "Conditional" | "At Risk" | "No Grade"
    if ($grade === null) {
        $status_label = 'No Grade';
    } elseif ($grade >= 90) {
        $status_label = 'With Honors';
    } elseif ($grade >= $passing) {
        $status_label = 'Passed';
    } elseif ($grade >= ($passing - 5)) {
        $status_label = 'Conditional';
    } else {
        $status_label = 'At Risk';
    }

    $ranked[] = [
        'rank'          => $student_rank,
        'user_id'       => (int)$row['user_id'],
        'id_number'     => $row['id_number'],
        'full_name'     => $row['full_name'],
        'first_name'    => $row['first_name'],
        'last_name'     => $row['last_name'],
        'grade'         => $grade,
        'period'        => $row['period'],
        'periods_graded'=> $row['periods_graded'] ?? null,
        'status_label'  => $status_label,
    ];
}

// ── Compute class statistics ──────────────────────────────────────────────────
$graded_rows = array_filter($ranked, fn($r) => $r['grade'] !== null);
$grades_only = array_column(array_values($graded_rows), 'grade');

$class_avg = count($grades_only) > 0
    ? round(array_sum($grades_only) / count($grades_only), 2)
    : null;
$highest = count($grades_only) > 0 ? max($grades_only) : null;
$lowest  = count($grades_only) > 0 ? min($grades_only) : null;
$passed  = count(array_filter($grades_only, fn($g) => $g >= $passing));
$honors  = count(array_filter($grades_only, fn($g) => $g >= 90));

// If student role, only return their own row + summary stats (not the full list)
if ($auth['role'] === 'student') {
    $own = array_values(array_filter($ranked, fn($r) => $r['user_id'] === $auth['user_id']));
    success([
        'class_section_id' => $class_section_id,
        'meta'             => $meta,
        'period'           => $period ?? 'average',
        'total_students'   => count($ranked),
        'your_rank'        => $own[0] ?? null,
        'stats' => [
            'class_average' => $class_avg,
            'highest_grade' => $highest,
            'lowest_grade'  => $lowest,
            'passed_count'  => $passed,
            'honors_count'  => $honors,
        ],
    ]);
}

// Instructor / admin: full ranked list
success([
    'class_section_id' => $class_section_id,
    'meta'             => $meta,
    'period'           => $period ?? 'average',
    'total_students'   => count($ranked),
    'stats' => [
        'class_average'  => $class_avg,
        'highest_grade'  => $highest,
        'lowest_grade'   => $lowest,
        'passed_count'   => $passed,
        'honors_count'   => $honors,
        'passing_rate'   => count($grades_only) > 0
            ? round($passed / count($grades_only) * 100, 1)
            : null,
    ],
    'ranking' => $ranked,
]);
