<?php
/**
 * TCM LMS — User Management Endpoint
 *
 * GET    /api/admin/users.php            → list all users (filterable)
 * POST   /api/admin/users.php            → create new user
 * PUT    /api/admin/users.php            → edit user info / change status
 * DELETE /api/admin/users.php            → deactivate (soft delete)
 *
 * Query params for GET:
 *   ?role=student|instructor|admin
 *   ?status=active|inactive|pending|suspended
 *   ?search=juan
 *   ?limit=20&offset=0
 *
 * Backs:
 *   - admin-dashboard.html User Management tab
 *     (Add New User modal, Edit modal, Activate/Deactivate toggle,
 *      search + filter, user list table)
 *
 * Auth: admin only for all methods
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$auth   = require_bearer_auth(['admin']);
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ════════════════════════════════════════════════════════════════════════
// GET — list users
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $role   = str_field($_GET, 'role');
    $status = str_field($_GET, 'status');
    $search = str_field($_GET, 'search') ?? '';
    $limit  = min((int)($_GET['limit']  ?? 20), 100);
    $offset = max((int)($_GET['offset'] ?? 0),   0);

    $where  = [];
    $params = [];

    if ($role) {
        $valid_roles = ['student','instructor','admin','department_head'];
        if (in_array($role, $valid_roles, true)) {
            $where[]  = "u.role = ?";
            $params[] = $role;
        }
    }
    if ($status) {
        $valid_statuses = ['active','inactive','pending','suspended'];
        if (in_array($status, $valid_statuses, true)) {
            $where[]  = "u.status = ?";
            $params[] = $status;
        }
    }
    if ($search) {
        $like     = '%' . $search . '%';
        $where[]  = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.id_number LIKE ? OR u.school_email LIKE ?)";
        $params[] = $like; $params[] = $like;
        $params[] = $like; $params[] = $like;
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Fetch users (no password_hash in response, ever)
    $stmt = $pdo->prepare("
        SELECT
            u.user_id,
            u.id_number,
            u.role,
            u.first_name,
            u.last_name,
            CONCAT(u.first_name,' ',u.last_name) AS full_name,
            u.school_email,
            u.personal_email,
            u.mobile_number,
            u.status,
            u.email_verified,
            u.avatar_path,
            u.created_at,
            u.last_login_at,
            -- Extra fields depending on role
            s.program, s.year_level, s.section, s.enrollment_status,
            i.department, i.academic_rank, i.employment_status,
            ad.access_level
        FROM users u
        LEFT JOIN students    s  ON s.student_id    = u.user_id AND u.role = 'student'
        LEFT JOIN instructors i  ON i.instructor_id = u.user_id AND u.role = 'instructor'
        LEFT JOIN admins      ad ON ad.admin_id     = u.user_id AND u.role = 'admin'
        {$where_sql}
        ORDER BY u.last_name, u.first_name
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    // Total for pagination
    $count_params = array_slice($params, 0, -2);
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM users u {$where_sql}
    ");
    $count_stmt->execute($count_params);
    $total = (int)$count_stmt->fetchColumn();

    success([
        'users'   => $users,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset,
        'has_more'=> ($offset + $limit) < $total,
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — create a new user account
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $body = get_json_body();

    $role        = str_field($body, 'role',         required: true);
    $first_name  = str_field($body, 'first_name',   required: true);
    $last_name   = str_field($body, 'last_name',    required: true);
    $id_number   = str_field($body, 'id_number',    required: true);
    $school_email= str_field($body, 'school_email', required: true);
    $middle_name = str_field($body, 'middle_name');
    $mobile      = str_field($body, 'mobile_number');
    $department  = str_field($body, 'department');
    $section     = str_field($body, 'section');
    $password    = str_field($body, 'password');

    $valid_roles = ['student','instructor','admin','department_head'];
    if (!in_array($role, $valid_roles, true)) {
        error("Invalid role '{$role}'.", 422);
    }
    if (!filter_var($school_email, FILTER_VALIDATE_EMAIL)) {
        error("'school_email' must be a valid email address.", 422);
    }

    // Check for existing id_number or email
    $dup = $pdo->prepare("
        SELECT user_id FROM users WHERE id_number = ? OR school_email = ? LIMIT 1
    ");
    $dup->execute([$id_number, $school_email]);
    if ($dup->fetch()) {
        error('A user with this ID number or email already exists.', 409);
    }

    // Default password: first 3 chars of last name + id_number
    if (!$password) {
        $password = strtolower(substr($last_name, 0, 3)) . $id_number;
    }
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO users
                (id_number, role, first_name, middle_name, last_name,
                 school_email, mobile_number, password_hash, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ")->execute([
            $id_number, $role, $first_name, $middle_name, $last_name,
            $school_email, $mobile, $hash,
        ]);
        $user_id = (int)$pdo->lastInsertId();

        // Create the role-specific extension row
        match($role) {
            'student' => $pdo->prepare("
                INSERT INTO students (student_id, section, department)
                VALUES (?, ?, ?)
            ")->execute([$user_id, $section ?? '1-A', $department ?? 'BSIT']),

            'instructor', 'department_head' => $pdo->prepare("
                INSERT INTO instructors (instructor_id, department)
                VALUES (?, ?)
            ")->execute([$user_id, $department ?? 'BSIT']),

            'admin' => $pdo->prepare("
                INSERT INTO admins (admin_id, access_level)
                VALUES (?, 'admin')
            ")->execute([$user_id]),

            default => null,
        };

        // Default notification preferences
        $pdo->prepare("INSERT INTO notification_preferences (user_id) VALUES (?)")
            ->execute([$user_id]);

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $msg = (APP_ENV === 'development') ? $e->getMessage() : 'Database error while creating user.';
        error($msg, 500);
    }

    audit(
        'user_create',
        "Admin created user: id_number={$id_number} role={$role} name=\"{$first_name} {$last_name}\"",
        $auth['user_id']
    );

    success([
        'user_id'       => $user_id,
        'id_number'     => $id_number,
        'role'          => $role,
        'full_name'     => trim($first_name . ' ' . $last_name),
        'school_email'  => $school_email,
        'default_password' => !str_field($body, 'password')
            ? 'first 3 chars of last name + ID number'
            : null,
    ], 'User account created successfully.');
}

// ════════════════════════════════════════════════════════════════════════
// PUT — edit user info or change account status
// ════════════════════════════════════════════════════════════════════════
if ($method === 'PUT') {
    $body    = get_json_body();
    $user_id = int_field($body, 'user_id', required: true);

    // Fetch existing user
    $existing = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $existing->execute([$user_id]);
    $user = $existing->fetch();
    if (!$user) {
        error('User not found.', 404);
    }

    // Admins cannot edit another super_admin unless they are one themselves
    if ($user['role'] === 'admin') {
        $target_admin = $pdo->prepare("SELECT access_level FROM admins WHERE admin_id = ?");
        $target_admin->execute([$user_id]);
        $ta = $target_admin->fetch();
        if ($ta && $ta['access_level'] === 'super_admin') {
            $self_admin = $pdo->prepare("SELECT access_level FROM admins WHERE admin_id = ?");
            $self_admin->execute([$auth['user_id']]);
            $sa = $self_admin->fetch();
            if (!$sa || $sa['access_level'] !== 'super_admin') {
                error('Only a super admin can edit another super admin account.', 403);
            }
        }
    }

    // Build update: only change fields that were actually sent
    $updates = [];
    $params  = [];

    $fields = [
        'first_name'     => str_field($body, 'first_name'),
        'middle_name'    => str_field($body, 'middle_name'),
        'last_name'      => str_field($body, 'last_name'),
        'personal_email' => str_field($body, 'personal_email'),
        'mobile_number'  => str_field($body, 'mobile_number'),
        'status'         => str_field($body, 'status'),
    ];

    $valid_statuses = ['active','inactive','pending','suspended'];
    if (isset($fields['status']) && $fields['status'] !== null) {
        if (!in_array($fields['status'], $valid_statuses, true)) {
            error("Invalid status. Must be one of: " . implode(', ', $valid_statuses), 422);
        }
    }

    foreach ($fields as $col => $val) {
        if ($val !== null) {
            $updates[] = "{$col} = ?";
            $params[]  = $val;
        }
    }

    // Optional: reset password
    $new_password = str_field($body, 'new_password');
    if ($new_password) {
        if (strlen($new_password) < 8) {
            error('New password must be at least 8 characters.', 422);
        }
        $updates[] = "password_hash = ?";
        $params[]  = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    if (empty($updates)) {
        error('No fields to update were provided.', 422);
    }

    $params[] = $user_id;
    $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ?")
        ->execute($params);

    audit(
        'user_update',
        "Admin updated user_id={$user_id} fields: " . implode(', ', array_keys($fields)),
        $auth['user_id']
    );

    success(['user_id' => $user_id], 'User updated successfully.');
}

// ════════════════════════════════════════════════════════════════════════
// DELETE — deactivate (soft-delete: set status to 'inactive')
// Hard delete is intentionally not supported to preserve grade history.
// ════════════════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    $body    = get_json_body();
    $user_id = int_field($body, 'user_id', required: true);

    // Cannot deactivate yourself
    if ($user_id === $auth['user_id']) {
        error('You cannot deactivate your own account.', 400);
    }

    $existing = $pdo->prepare("SELECT user_id, id_number, role FROM users WHERE user_id = ?");
    $existing->execute([$user_id]);
    $user = $existing->fetch();
    if (!$user) {
        error('User not found.', 404);
    }

    $pdo->prepare("UPDATE users SET status = 'inactive' WHERE user_id = ?")
        ->execute([$user_id]);

    // Revoke all active sessions for this user
    $pdo->prepare("UPDATE login_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")
        ->execute([$user_id]);

    audit(
        'user_deactivate',
        "Deactivated user_id={$user_id} id_number={$user['id_number']} role={$user['role']}",
        $auth['user_id']
    );

    success(['user_id' => $user_id], 'User account deactivated.');
}

error('Method not allowed.', 405);
