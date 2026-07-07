<?php
/**
 * TCM LMS — Submission Analytics Endpoint
 *
 * GET /api/analytics/submissions.php
 *   ?scope=overview          → institution-wide stats (admin)
 *   ?scope=class&class_section_id=N  → one class stats (instructor)
 *   ?scope=incentive         → early submission report rows
 *   ?scope=penalty           → late submission report rows
 *   ?scope=by_subject        → per-subject compliance table
 *
 * Optional filters (any scope):
 *   &department=BSIT
 *   &activity_type=assignment
 *   &subject_id=N
 *
 * Backs: submission-analytics.html entirely (all 4 tabs).
 *
 * Auth: admin (all scopes), instructor (class scope only for their class)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    error('Method not allowed. Use GET.', 405);
}

$auth  = require_bearer_auth(['instructor', 'admin']);
$pdo   = db();
$scope = str_field($_GET, 'scope') ?? 'overview';

// Optional filters
$dept          = str_field($_GET, 'department');
$activity_type = str_field($_GET, 'activity_type');
$subject_id    = int_field($_GET, 'subject_id');
$class_id      = int_field($_GET, 'class_section_id');

// Instructors are restricted to their own classes only
if ($auth['role'] === 'instructor') {
    if ($scope !== 'class') {
        error('Instructors can only access class-level analytics. Use ?scope=class&class_section_id=N', 403);
    }
    if (!$class_id) {
        error('class_section_id is required for instructor analytics.', 422);
    }
    // Confirm ownership
    $owns = $pdo->prepare("
        SELECT class_section_id FROM class_sections
        WHERE class_section_id = ? AND instructor_id = ?
    ");
    $owns->execute([$class_id, $auth['user_id']]);
    if (!$owns->fetch()) {
        error('You do not have access to this class.', 403);
    }
}

// ── Reusable WHERE clause builder ────────────────────────────────────────────
function build_filters(
    ?string $dept,
    ?string $activity_type,
    ?int    $subject_id,
    ?int    $class_id,
    string  $sub_alias  = 'sub',
    string  $act_alias  = 'a',
    string  $cs_alias   = 'cs'
): array {
    $where  = [];
    $params = [];

    if ($dept) {
        $where[]  = "{$sub_alias}.department = ?";
        $params[] = $dept;
    }
    if ($activity_type) {
        $where[]  = "{$act_alias}.activity_type = ?";
        $params[] = $activity_type;
    }
    if ($subject_id) {
        $where[]  = "{$cs_alias}.subject_id = ?";
        $params[] = $subject_id;
    }
    if ($class_id) {
        $where[]  = "{$cs_alias}.class_section_id = ?";
        $params[] = $class_id;
    }

    $sql = $where ? ('AND ' . implode(' AND ', $where)) : '';
    return [$sql, $params];
}

[$filter_sql, $filter_params] = build_filters($dept, $activity_type, $subject_id, $class_id);

// ════════════════════════════════════════════════════════════════════════
// SCOPE: overview — top-level institution stats (all 4 stat cards +
// the weekly bar chart data + activity-type breakdown + tardy subjects)
// ════════════════════════════════════════════════════════════════════════
if ($scope === 'overview') {

    // Top-level counts
    $totals = $pdo->prepare("
        SELECT
            COUNT(*)                         AS total_submissions,
            SUM(s.is_late = 0)               AS on_time,
            SUM(s.is_late = 1)               AS late,
            SUM(s.status = 'missing')        AS missing
        FROM submissions s
        JOIN activities a  ON a.activity_id  = s.activity_id
        JOIN class_sections cs ON cs.class_section_id = a.class_section_id
        JOIN subjects sub  ON sub.subject_id = cs.subject_id
        WHERE 1=1 {$filter_sql}
    ");
    $totals->execute($filter_params);
    $t = $totals->fetch();

    $total     = (int)$t['total_submissions'];
    $on_time   = (int)$t['on_time'];
    $late      = (int)$t['late'];
    $missing   = (int)$t['missing'];
    $on_time_pct = $total > 0 ? round($on_time / $total * 100, 1) : 0;

    // Weekly breakdown: last 8 weeks (backs the bar chart)
    $weekly = $pdo->prepare("
        SELECT
            YEARWEEK(s.submitted_at, 1)  AS yr_week,
            MIN(DATE(s.submitted_at))    AS week_start,
            SUM(s.is_late = 0)           AS on_time,
            SUM(s.is_late = 1)           AS late
        FROM submissions s
        JOIN activities a  ON a.activity_id = s.activity_id
        JOIN class_sections cs ON cs.class_section_id = a.class_section_id
        JOIN subjects sub  ON sub.subject_id = cs.subject_id
        WHERE s.submitted_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
          {$filter_sql}
        GROUP BY YEARWEEK(s.submitted_at, 1)
        ORDER BY yr_week ASC
        LIMIT 8
    ");
    $weekly->execute($filter_params);

    // By activity type
    $by_type = $pdo->prepare("
        SELECT
            a.activity_type,
            COUNT(*)             AS total,
            SUM(s.is_late = 0)   AS on_time,
            ROUND(SUM(s.is_late = 0) / COUNT(*) * 100, 1) AS on_time_pct
        FROM submissions s
        JOIN activities a ON a.activity_id = s.activity_id
        JOIN class_sections cs ON cs.class_section_id = a.class_section_id
        JOIN subjects sub  ON sub.subject_id = cs.subject_id
        WHERE 1=1 {$filter_sql}
        GROUP BY a.activity_type
        ORDER BY on_time_pct ASC
    ");
    $by_type->execute($filter_params);

    // Top 5 tardy subjects
    $tardy = $pdo->prepare("
        SELECT
            sub.subject_code,
            sub.title,
            COUNT(*)              AS total,
            SUM(s.is_late = 1)    AS late,
            ROUND(SUM(s.is_late = 1) / COUNT(*) * 100, 1) AS late_pct
        FROM submissions s
        JOIN activities a  ON a.activity_id = s.activity_id
        JOIN class_sections cs ON cs.class_section_id = a.class_section_id
        JOIN subjects sub  ON sub.subject_id = cs.subject_id
        WHERE 1=1 {$filter_sql}
        GROUP BY sub.subject_id
        ORDER BY late_pct DESC
        LIMIT 5
    ");
    $tardy->execute($filter_params);

    success([
        'scope' => 'overview',
        'totals' => [
            'total_submissions' => $total,
            'on_time'           => $on_time,
            'late'              => $late,
            'missing'           => $missing,
            'on_time_pct'       => $on_time_pct,
        ],
        'weekly'        => $weekly->fetchAll(),
        'by_type'       => $by_type->fetchAll(),
        'tardy_subjects'=> $tardy->fetchAll(),
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// SCOPE: incentive — early submission report (incentive tab)
// ════════════════════════════════════════════════════════════════════════
if ($scope === 'incentive') {

    $total_earners = $pdo->prepare("
        SELECT
            COUNT(DISTINCT s.student_id) AS students_earned,
            SUM(s.incentive_applied)     AS total_bonus_points,
            COUNT(DISTINCT a.activity_id) AS activities_with_incentive
        FROM submissions s
        JOIN activities a ON a.activity_id = s.activity_id
        JOIN class_sections cs ON cs.class_section_id = a.class_section_id
        JOIN subjects sub  ON sub.subject_id = cs.subject_id
        WHERE s.incentive_applied > 0 {$filter_sql}
    ");
    $total_earners->execute($filter_params);

    $rows = $pdo->prepare("
        SELECT
            CONCAT(u.first_name,' ',u.last_name) AS student_name,
            u.id_number,
            a.title           AS activity_title,
            sub.subject_code,
            cs.section_code,
            s.submitted_at,
            ABS(s.days_late)  AS days_early,
            s.incentive_applied AS bonus_points
        FROM submissions s
        JOIN activities a  ON a.activity_id = s.activity_id
        JOIN class_sections cs ON cs.class_section_id = a.class_section_id
        JOIN subjects sub  ON sub.subject_id = cs.subject_id
        JOIN users u       ON u.user_id = s.student_id
        WHERE s.incentive_applied > 0 {$filter_sql}
        ORDER BY s.submitted_at DESC
        LIMIT 100
    ");
    $rows->execute($filter_params);

    success([
        'scope'   => 'incentive',
        'summary' => $total_earners->fetch(),
        'records' => $rows->fetchAll(),
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// SCOPE: penalty — late submission report (penalty tab)
// ════════════════════════════════════════════════════════════════════════
if ($scope === 'penalty') {

    $totals = $pdo->prepare("
        SELECT
            COUNT(*)                  AS total_late,
            SUM(s.penalty_applied)    AS total_points_deducted,
            COUNT(DISTINCT CASE WHEN a.late_penalty_type != 'none' THEN a.activity_id END) AS activities_with_penalty
        FROM submissions s
        JOIN activities a  ON a.activity_id = s.activity_id
        JOIN class_sections cs ON cs.class_section_id = a.class_section_id
        JOIN subjects sub  ON sub.subject_id = cs.subject_id
        WHERE s.is_late = 1 {$filter_sql}
    ");
    $totals->execute($filter_params);

    $rows = $pdo->prepare("
        SELECT
            CONCAT(u.first_name,' ',u.last_name) AS student_name,
            u.id_number,
            a.title           AS activity_title,
            a.late_penalty_type,
            sub.subject_code,
            cs.section_code,
            a.due_at,
            s.submitted_at,
            s.days_late,
            s.penalty_applied,
            CASE
                WHEN a.late_penalty_type = 'not_accepted' THEN 'not_accepted'
                WHEN s.penalty_applied > 0 THEN 'penalty_applied'
                ELSE 'no_penalty'
            END AS penalty_status
        FROM submissions s
        JOIN activities a  ON a.activity_id = s.activity_id
        JOIN class_sections cs ON cs.class_section_id = a.class_section_id
        JOIN subjects sub  ON sub.subject_id = cs.subject_id
        JOIN users u       ON u.user_id = s.student_id
        WHERE s.is_late = 1 {$filter_sql}
        ORDER BY s.submitted_at DESC
        LIMIT 100
    ");
    $rows->execute($filter_params);

    success([
        'scope'   => 'penalty',
        'summary' => $totals->fetch(),
        'records' => $rows->fetchAll(),
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// SCOPE: by_subject — per-subject compliance table (4th tab)
// ════════════════════════════════════════════════════════════════════════
if ($scope === 'by_subject') {

    $rows = $pdo->prepare("
        SELECT
            sub.subject_code,
            sub.title         AS subject_title,
            COUNT(DISTINCT a.activity_id)  AS total_activities,
            COUNT(s.submission_id)         AS total_submissions,
            SUM(s.is_late = 0)             AS on_time,
            SUM(s.is_late = 1)             AS late,
            SUM(s.status = 'missing')      AS missing,
            ROUND(
                CASE
                    WHEN COUNT(s.submission_id) > 0
                    THEN SUM(s.is_late = 0) / COUNT(s.submission_id) * 100
                    ELSE 0
                END, 1
            ) AS compliance_pct
        FROM subjects sub
        JOIN class_sections cs ON cs.subject_id = sub.subject_id
        JOIN activities a ON a.class_section_id = cs.class_section_id
        LEFT JOIN submissions s ON s.activity_id = a.activity_id
        WHERE 1=1 {$filter_sql}
        GROUP BY sub.subject_id
        ORDER BY compliance_pct DESC
    ");
    $rows->execute($filter_params);

    success([
        'scope'    => 'by_subject',
        'subjects' => $rows->fetchAll(),
    ]);
}

error("Unknown scope '{$scope}'. Valid: overview, incentive, penalty, by_subject.", 400);
