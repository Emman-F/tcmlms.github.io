<?php
/**
 * TCM LMS — Student Management Endpoints
 *
 * POST   /api/students/add_to_class.php    Add a student to instructor's class (manual add)
 * GET    /api/students/add_to_class.php    List students in a class
 * DELETE /api/students/add_to_class.php    Remove student from class
 *
 * This endpoint backs the "Add Student to Class" modal in
 * instructor-dashboard.html — the explicitly-required capstone feature.
 *
 * Auth: instructor or admin only.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════
// GET — list all students in a class
// Query params: ?class_section_id=5  (optional: &search=juan)
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $auth = require_bearer_auth(['instructor', 'admin', 'department_head']);

    $class_section_id = int_field($_GET, 'class_section_id', required: true);
    $search           = str_field($_GET, 'search') ?? '';

    $pdo = db();

    // Instructors can only see their own classes
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

    $like  = '%' . $search . '%';
    $query = "
        SELECT
            e.enrollment_id,
            e.status            AS enrollment_status,
            e.added_by_instructor,
            e.enrolled_at,
            u.user_id,
            u.id_number         AS student_id_number,
            u.first_name,
            u.last_name,
            CONCAT(u.first_name, ' ', u.last_name) AS full_name,
            u.school_email,
            s.section,
            s.academic_track
        FROM enrollments e
        JOIN students s   ON s.student_id  = e.student_id
        JOIN users u      ON u.user_id     = s.student_id
        WHERE e.class_section_id = ?
          AND (? = '' OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.id_number LIKE ?)
        ORDER BY u.last_name, u.first_name
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$class_section_id, $search, $like, $like, $like]);
    $students = $stmt->fetchAll();

    success([
        'class_section_id' => $class_section_id,
        'count'            => count($students),
        'students'         => $students,
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — manually add a student to the class
// Body: { class_section_id, first_name, last_name, id_number, email?, section?, status? }
//
// Two cases:
//  (A) Student already has an account: just enroll them
//  (B) Student has no account yet: create the user+student row, then enroll
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $auth = require_bearer_auth(['instructor', 'admin']);

    $body             = get_json_body();
    $class_section_id = int_field($body,  'class_section_id',  required: true);
    $first_name       = str_field($body,  'first_name',        required: true);
    $last_name        = str_field($body,  'last_name',         required: true);
    $id_number        = str_field($body,  'id_number',         required: true);
    $email            = str_field($body,  'email');
    $section          = str_field($body,  'section')   ?? '3-B';
    $enr_status       = str_field($body,  'status')    ?? 'active';

    // Validate enrollment status value
    $valid_statuses = ['active', 'irregular', 'transferee'];
    if (!in_array($enr_status, $valid_statuses, true)) {
        error("Invalid status '{$enr_status}'. Must be one of: " . implode(', ', $valid_statuses), 422);
    }

    // Validate id_number format (students: 20YY-NNNNN, staff: EMP-NNNN)
    if (!preg_match('/^(20\d{2}-\d{4,6}|EMP-\d{4,6})$/', $id_number)) {
        error("ID number format must be YYYY-NNNNN (e.g. 2024-00123).", 422);
    }

    $pdo = db();

    // Confirm instructor owns this class
    if ($auth['role'] === 'instructor') {
        $owns = $pdo->prepare("
            SELECT class_section_id FROM class_sections
            WHERE class_section_id = ? AND instructor_id = ?
        ");
        $owns->execute([$class_section_id, $auth['user_id']]);
        if (!$owns->fetch()) {
            error('You do not have permission to add students to this class.', 403);
        }
    }

    // Check if student already exists in users table
    $existing = $pdo->prepare("SELECT user_id, role FROM users WHERE id_number = ? LIMIT 1");
    $existing->execute([$id_number]);
    $found_user = $existing->fetch();

    $pdo->beginTransaction();
    try {

        if ($found_user) {
            // Case A: user exists
            if ($found_user['role'] !== 'student') {
                $pdo->rollBack();
                error("ID number {$id_number} belongs to a non-student account.", 409);
            }
            $student_user_id = $found_user['user_id'];

        } else {
            // Case B: create the account
            // Generate a default password: first 3 chars of last name + id_number (e.g. del2024-00123)
            $default_pw = strtolower(substr($last_name, 0, 3)) . $id_number;
            $hash       = password_hash($default_pw, PASSWORD_BCRYPT, ['cost' => 10]);

            $school_email = $email ?? (strtolower(str_replace(' ', '.', $first_name)) . '.' . strtolower(substr($last_name, 0, 1)) . '@tcm.edu.ph');

            $ins = $pdo->prepare("
                INSERT INTO users
                    (id_number, role, first_name, last_name, school_email, personal_email, password_hash, status)
                VALUES
                    (?, 'student', ?, ?, ?, ?, ?, 'active')
            ");
            $ins->execute([$id_number, $first_name, $last_name, $school_email, $email, $hash]);
            $student_user_id = (int)$pdo->lastInsertId();

            // Create the students row
            $pdo->prepare("
                INSERT INTO students (student_id, section, academic_track)
                VALUES (?, ?, 'regular')
            ")->execute([$student_user_id, $section]);

            // Default notification preferences
            $pdo->prepare("INSERT INTO notification_preferences (user_id) VALUES (?)")
                ->execute([$student_user_id]);
        }

        // Check for duplicate enrollment in this specific class
        $dup = $pdo->prepare("
            SELECT enrollment_id FROM enrollments
            WHERE student_id = ? AND class_section_id = ?
        ");
        $dup->execute([$student_user_id, $class_section_id]);
        if ($dup->fetch()) {
            $pdo->rollBack();
            error("Student {$id_number} is already enrolled in this class.", 409);
        }

        // Enroll the student
        $pdo->prepare("
            INSERT INTO enrollments
                (student_id, class_section_id, status, added_by_instructor, added_by_user_id)
            VALUES
                (?, ?, ?, 1, ?)
        ")->execute([$student_user_id, $class_section_id, $enr_status, $auth['user_id']]);

        $enrollment_id = (int)$pdo->lastInsertId();
        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $msg = (APP_ENV === 'development') ? $e->getMessage() : 'Database error while adding student.';
        error($msg, 500);
    }

    audit(
        'user_create',
        "Instructor {$auth['user_id']} manually added student {$id_number} to class_section {$class_section_id}",
        $auth['user_id']
    );

    success([
        'enrollment_id'    => $enrollment_id,
        'student_user_id'  => $student_user_id,
        'id_number'        => $id_number,
        'full_name'        => trim($first_name . ' ' . $last_name),
        'class_section_id' => $class_section_id,
        'added_by_instructor' => true,
        'account_created'  => !$found_user,
        'default_password' => !$found_user ? 'first 3 chars of last name + ID number' : null,
    ], 'Student added to class successfully.');
}

// ════════════════════════════════════════════════════════════════════════
// DELETE — remove a student from the class (instructor removes from roster)
// Body: { enrollment_id }
// ════════════════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    $auth          = require_bearer_auth(['instructor', 'admin']);
    $body          = get_json_body();
    $enrollment_id = int_field($body, 'enrollment_id', required: true);

    $pdo = db();

    // Fetch enrollment and confirm instructor owns the class
    $stmt = $pdo->prepare("
        SELECT e.enrollment_id, e.student_id, e.class_section_id,
               u.id_number, cs.instructor_id
        FROM enrollments e
        JOIN class_sections cs ON cs.class_section_id = e.class_section_id
        JOIN users u ON u.user_id = e.student_id
        WHERE e.enrollment_id = ?
    ");
    $stmt->execute([$enrollment_id]);
    $row = $stmt->fetch();

    if (!$row) {
        error('Enrollment not found.', 404);
    }
    if ($auth['role'] === 'instructor' && (int)$row['instructor_id'] !== (int)$auth['user_id']) {
        error('You do not have permission to remove students from this class.', 403);
    }

    $pdo->prepare("DELETE FROM enrollments WHERE enrollment_id = ?")
        ->execute([$enrollment_id]);

    audit(
        'user_update',
        "Removed student {$row['id_number']} from class_section {$row['class_section_id']}",
        $auth['user_id']
    );

    success(['enrollment_id' => $enrollment_id], 'Student removed from class.');
}

error('Method not allowed.', 405);
