<?php
/**
 * TCM LMS — Attendance Endpoint
 *
 * POST /api/attendance/record.php
 *   Body: { class_section_id, session_date, records: [{student_id, status, remarks?}] }
 *   Creates attendance_session + bulk-inserts attendance_records in one transaction.
 *
 * GET  /api/attendance/record.php
 *   ?class_section_id=N            → all sessions + records for a class
 *   ?class_section_id=N&date=YYYY-MM-DD  → single session
 *   ?student_id=N&class_section_id=N     → one student's attendance in a class
 *
 * Backs:
 *   - instructor-dashboard.html Attendance tab (take + view attendance)
 *   - student-dashboard.html  attendance % card
 *   - profile.html            attendance records list
 *   - subject-detail.html     97% attendance stat card
 *
 * Auth: instructor/admin (POST), all roles (GET own data)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════
// GET — fetch attendance records
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $auth = require_bearer_auth(['student', 'instructor', 'admin']);
    $pdo  = db();

    $class_section_id = int_field($_GET, 'class_section_id', required: true);
    $date             = str_field($_GET, 'date');
    $student_id_param = int_field($_GET, 'student_id');

    // Students can only see their own attendance
    if ($auth['role'] === 'student') {
        $student_id_param = $auth['user_id'];
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

    // ── Single student's attendance summary ────────────────────────────────
    if ($student_id_param) {
        $stmt = $pdo->prepare("
            SELECT
                ar.record_id,
                ar.status,
                ar.remarks,
                ar.time_recorded,
                ass.session_date,
                DAYNAME(ass.session_date) AS day_name
            FROM attendance_records ar
            JOIN attendance_sessions ass ON ass.session_id = ar.session_id
            WHERE ar.student_id = ? AND ass.class_section_id = ?
            ORDER BY ass.session_date DESC
        ");
        $stmt->execute([$student_id_param, $class_section_id]);
        $records = $stmt->fetchAll();

        // Compute summary counts
        $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
        foreach ($records as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        }
        $total    = count($records);
        $attended = $counts['present'] + $counts['late'] + $counts['excused'];
        $pct      = $total > 0 ? round(($attended / $total) * 100, 1) : 0;

        success([
            'student_id'       => $student_id_param,
            'class_section_id' => $class_section_id,
            'summary' => [
                'total_sessions' => $total,
                'present'   => $counts['present'],
                'absent'    => $counts['absent'],
                'late'      => $counts['late'],
                'excused'   => $counts['excused'],
                'attendance_pct' => $pct,
            ],
            'records' => $records,
        ]);
    }

    // ── Single session (by date) ───────────────────────────────────────────
    if ($date) {
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            error("Date must be in YYYY-MM-DD format.", 422);
        }

        $sess = $pdo->prepare("
            SELECT session_id, session_date, recorded_by
            FROM attendance_sessions
            WHERE class_section_id = ? AND session_date = ?
        ");
        $sess->execute([$class_section_id, $date]);
        $session = $sess->fetch();

        if (!$session) {
            success(['session' => null, 'records' => []]);
        }

        $recs = $pdo->prepare("
            SELECT
                ar.record_id,
                ar.student_id,
                ar.status,
                ar.remarks,
                ar.time_recorded,
                u.id_number,
                CONCAT(u.first_name,' ',u.last_name) AS full_name
            FROM attendance_records ar
            JOIN users u ON u.user_id = ar.student_id
            WHERE ar.session_id = ?
            ORDER BY u.last_name, u.first_name
        ");
        $recs->execute([$session['session_id']]);

        success([
            'session'  => $session,
            'records'  => $recs->fetchAll(),
        ]);
    }

    // ── All sessions for a class (overview list) ───────────────────────────
    $sess_stmt = $pdo->prepare("
        SELECT
            ass.session_id,
            ass.session_date,
            DAYNAME(ass.session_date) AS day_name,
            COUNT(ar.record_id)                                        AS total_recorded,
            SUM(ar.status = 'present')                                 AS present_count,
            SUM(ar.status = 'absent')                                  AS absent_count,
            SUM(ar.status = 'late')                                    AS late_count,
            SUM(ar.status = 'excused')                                 AS excused_count
        FROM attendance_sessions ass
        LEFT JOIN attendance_records ar ON ar.session_id = ass.session_id
        WHERE ass.class_section_id = ?
        GROUP BY ass.session_id
        ORDER BY ass.session_date DESC
    ");
    $sess_stmt->execute([$class_section_id]);
    $sessions = $sess_stmt->fetchAll();

    // Total enrolled for context
    $enrolled_cnt = $pdo->prepare("
        SELECT COUNT(*) FROM enrollments
        WHERE class_section_id = ? AND status != 'dropped'
    ");
    $enrolled_cnt->execute([$class_section_id]);

    success([
        'class_section_id' => $class_section_id,
        'total_enrolled'   => (int)$enrolled_cnt->fetchColumn(),
        'total_sessions'   => count($sessions),
        'sessions'         => $sessions,
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — record attendance for a session (bulk insert)
// Body:
// {
//   "class_section_id": 1,
//   "session_date": "2026-07-02",
//   "records": [
//     { "student_id": 5, "status": "present", "remarks": null },
//     { "student_id": 7, "status": "late",    "remarks": "10 min late" },
//     { "student_id": 9, "status": "absent",  "remarks": null }
//   ]
// }
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $auth = require_bearer_auth(['instructor', 'admin']);
    $body = get_json_body();
    $pdo  = db();

    $class_section_id = int_field($body, 'class_section_id', required: true);
    $session_date     = str_field($body, 'session_date',     required: true);
    $records          = $body['records'] ?? [];

    // Validate date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)) {
        error("session_date must be in YYYY-MM-DD format.", 422);
    }
    $date_obj = DateTime::createFromFormat('Y-m-d', $session_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $session_date) {
        error("session_date is not a valid calendar date.", 422);
    }
    // Cannot record attendance for a future date
    if ($date_obj > new DateTime('today')) {
        error("Cannot record attendance for a future date.", 422);
    }

    if (empty($records) || !is_array($records)) {
        error("'records' must be a non-empty array of student attendance entries.", 422);
    }

    // Instructor ownership check
    if ($auth['role'] === 'instructor') {
        $owns = $pdo->prepare("
            SELECT class_section_id FROM class_sections
            WHERE class_section_id = ? AND instructor_id = ?
        ");
        $owns->execute([$class_section_id, $auth['user_id']]);
        if (!$owns->fetch()) {
            error('You do not have permission to record attendance for this class.', 403);
        }
    }

    // Validate all records before touching the DB
    $valid_statuses = ['present', 'absent', 'late', 'excused'];
    $student_ids    = [];
    foreach ($records as $i => $rec) {
        $sid    = isset($rec['student_id']) ? (int)$rec['student_id'] : null;
        $status = isset($rec['status'])     ? trim($rec['status'])     : null;
        if (!$sid)   error("Record #{$i}: 'student_id' is required.", 422);
        if (!$status || !in_array($status, $valid_statuses, true)) {
            error("Record #{$i}: 'status' must be one of: " . implode(', ', $valid_statuses), 422);
        }
        $student_ids[] = $sid;
    }

    // Confirm all student_ids are actually enrolled in this class
    $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $enrolled_check = $pdo->prepare("
        SELECT student_id FROM enrollments
        WHERE class_section_id = ? AND student_id IN ({$placeholders}) AND status != 'dropped'
    ");
    $enrolled_check->execute(array_merge([$class_section_id], $student_ids));
    $enrolled_ids = array_column($enrolled_check->fetchAll(), 'student_id');
    $not_enrolled = array_diff($student_ids, $enrolled_ids);
    if (!empty($not_enrolled)) {
        error('Some student IDs are not enrolled in this class: ' . implode(', ', $not_enrolled), 422);
    }

    // ── Transaction: create session + insert records ──────────────────────
    $pdo->beginTransaction();
    try {
        // Upsert attendance_session (safe to re-submit the same date)
        $pdo->prepare("
            INSERT INTO attendance_sessions (class_section_id, session_date, recorded_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE recorded_by = VALUES(recorded_by)
        ")->execute([$class_section_id, $session_date, $auth['user_id']]);

        // Get the session_id (whether just created or already existed)
        $sess_id_stmt = $pdo->prepare("
            SELECT session_id FROM attendance_sessions
            WHERE class_section_id = ? AND session_date = ?
        ");
        $sess_id_stmt->execute([$class_section_id, $session_date]);
        $session_id = (int)$sess_id_stmt->fetchColumn();

        // Bulk upsert attendance records
        $insert_rec = $pdo->prepare("
            INSERT INTO attendance_records
                (session_id, student_id, status, remarks, time_recorded)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                status       = VALUES(status),
                remarks      = VALUES(remarks),
                time_recorded = NOW()
        ");

        $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
        foreach ($records as $rec) {
            $status  = trim($rec['status']);
            $remarks = isset($rec['remarks']) ? trim($rec['remarks']) : null;
            $insert_rec->execute([$session_id, (int)$rec['student_id'], $status, $remarks ?: null]);
            $summary[$status]++;
        }

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $msg = (APP_ENV === 'development') ? $e->getMessage() : 'Database error while saving attendance.';
        error($msg, 500);
    }

    // Notify absent students (optional — send a notification to each absent student)
    foreach ($records as $rec) {
        if ($rec['status'] === 'absent') {
            try {
                $pdo->prepare("
                    INSERT INTO notifications (user_id, notif_type, title, body)
                    VALUES (?, 'attendance_marked', 'Absence Recorded', ?)
                ")->execute([
                    (int)$rec['student_id'],
                    "You were marked absent on {$session_date}. Contact your instructor if this is an error.",
                ]);
            } catch (PDOException) {
                // Non-fatal — don't fail the whole request over a notification
            }
        }
    }

    audit(
        'attendance_marked',
        "Attendance recorded: class={$class_section_id} date={$session_date} records=" . count($records),
        $auth['user_id']
    );

    success([
        'session_id'       => $session_id,
        'class_section_id' => $class_section_id,
        'session_date'     => $session_date,
        'records_saved'    => count($records),
        'summary'          => $summary,
    ], 'Attendance recorded successfully.');
}

// ════════════════════════════════════════════════════════════════════════
// PUT — update a single student's status in an existing session
// Body: { session_id, student_id, status, remarks? }
// (Used when instructor corrects a single entry after the fact)
// ════════════════════════════════════════════════════════════════════════
if ($method === 'PUT') {
    $auth       = require_bearer_auth(['instructor', 'admin']);
    $body       = get_json_body();
    $pdo        = db();

    $session_id = int_field($body, 'session_id', required: true);
    $student_id = int_field($body, 'student_id', required: true);
    $status     = str_field($body, 'status',     required: true);
    $remarks    = str_field($body, 'remarks');

    $valid_statuses = ['present', 'absent', 'late', 'excused'];
    if (!in_array($status, $valid_statuses, true)) {
        error("status must be one of: " . implode(', ', $valid_statuses), 422);
    }

    // Instructor ownership via the session's class
    if ($auth['role'] === 'instructor') {
        $owns = $pdo->prepare("
            SELECT cs.class_section_id FROM attendance_sessions ass
            JOIN class_sections cs ON cs.class_section_id = ass.class_section_id
            WHERE ass.session_id = ? AND cs.instructor_id = ?
        ");
        $owns->execute([$session_id, $auth['user_id']]);
        if (!$owns->fetch()) {
            error('You do not have permission to edit this attendance session.', 403);
        }
    }

    $pdo->prepare("
        UPDATE attendance_records
        SET status = ?, remarks = ?, time_recorded = NOW()
        WHERE session_id = ? AND student_id = ?
    ")->execute([$status, $remarks, $session_id, $student_id]);

    if ($pdo->rowCount() === 0) {
        error('Attendance record not found for this student and session.', 404);
    }

    audit(
        'attendance_marked',
        "Attendance updated: session={$session_id} student={$student_id} status={$status}",
        $auth['user_id']
    );

    success([
        'session_id' => $session_id,
        'student_id' => $student_id,
        'status'     => $status,
    ], 'Attendance record updated.');
}

error('Method not allowed.', 405);
