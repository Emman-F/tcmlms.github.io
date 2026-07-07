<?php
/**
 * TCM LMS — Authentication Endpoint
 *
 * POST /api/auth/login.php
 * Body: { "id_number": "2024-00123", "password": "secret", "role": "student" }
 *
 * Returns: { success, message, data: { user, token, redirect } }
 *
 * This endpoint is called by tcm-lms-login.html's handleLogin() function.
 * On success the frontend does: window.location.href = data.redirect
 * matching the three routes already wired in the HTML.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error('Method not allowed. Use POST.', 405);
}

// ── 1. Rate limit: max 10 login attempts per 5 minutes per IP ───────────────
$ip_key = 'login_' . preg_replace('/[^a-zA-Z0-9]/', '_', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
check_rate_limit($ip_key, 10, 300);

// ── 2. Parse & validate input ────────────────────────────────────────────────
$body       = get_json_body();
$id_number  = str_field($body, 'id_number', required: true);
$password   = str_field($body, 'password',  required: true);
$role       = str_field($body, 'role',      required: true);

if (!in_array($role, ['student', 'instructor', 'admin', 'department_head'], true)) {
    error("Invalid role '{$role}'.", 422);
}

// Passwords must be at least 1 char (length enforcement is on registration,
// not login — we just need a non-empty value to hash-check against)
if (strlen($password) < 1) {
    error('Password cannot be empty.', 422);
}

// ── 3. Look up the user ───────────────────────────────────────────────────────
$pdo  = db();
$stmt = $pdo->prepare("
    SELECT
        u.user_id,
        u.id_number,
        u.role,
        u.first_name,
        u.last_name,
        u.school_email,
        u.password_hash,
        u.status,
        u.avatar_path,
        u.last_login_at
    FROM users u
    WHERE u.id_number = ?
    LIMIT 1
");
$stmt->execute([$id_number]);
$user = $stmt->fetch();

// ── 4. Verify: user exists, password matches, role matches, account active ──
// Timing-safe: always run password_verify even if user not found
// (prevents timing-based enumeration of valid ID numbers)
$dummy_hash = '$2y$10$dummyhashtopreventtimingattacksonnonexistentaccounts123';
$hash_to_check = $user ? $user['password_hash'] : $dummy_hash;

$password_ok = password_verify($password, $hash_to_check);

if (!$user || !$password_ok) {
    audit('login', "Failed login for id_number={$id_number} role={$role}", null, 'failed');
    error('Incorrect ID number or password.', 401);
}

if ($user['role'] !== $role) {
    audit('login', "Role mismatch: id={$id_number} submitted role={$role} but actual role={$user['role']}", $user['user_id'], 'failed');
    error('Incorrect role selected for this account.', 401);
}

if (!in_array($user['status'], ['active'], true)) {
    $msg = match($user['status']) {
        'pending'   => 'Your account is pending approval by an administrator.',
        'suspended' => 'Your account has been suspended. Contact the system administrator.',
        'inactive'  => 'Your account is inactive. Contact the system administrator.',
        default     => 'Your account is not active.',
    };
    audit('login', "Blocked login for inactive account id={$id_number} status={$user['status']}", $user['user_id'], 'failed');
    error($msg, 403);
}

// ── 5. Check if password needs rehashing (e.g. bcrypt cost factor changed) ──
if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 10])) {
    $new_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
        ->execute([$new_hash, $user['user_id']]);
}

// ── 6. Create a session ───────────────────────────────────────────────────────
regenerate_session();
start_secure_session();

$_SESSION['user_id']   = $user['user_id'];
$_SESSION['role']      = $user['role'];
$_SESSION['id_number'] = $user['id_number'];

// ── 7. Generate a token for clients that prefer token-based auth ──────────────
$token      = generate_token(48);
$device_label = substr(
    preg_replace('/[^\w\s\-]/', '', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'),
    0, 150
);
$pdo->prepare("
    INSERT INTO login_sessions (session_token, user_id, device_label, ip_address)
    VALUES (?, ?, ?, ?)
")->execute([
    $token,
    $user['user_id'],
    $device_label,
    $_SERVER['REMOTE_ADDR'] ?? null,
]);

// ── 8. Update last_login_at ───────────────────────────────────────────────────
$pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?")
    ->execute([$user['user_id']]);

// ── 9. Audit log ─────────────────────────────────────────────────────────────
audit('login', "Successful login: id_number={$id_number} role={$role}", $user['user_id']);

// ── 10. Role → dashboard redirect map (mirrors tcm-lms-login.html routes) ───
$redirects = [
    'student'         => 'student-dashboard.html',
    'instructor'      => 'instructor-dashboard.html',
    'admin'           => 'admin-dashboard.html',
    'department_head' => 'admin-dashboard.html',
];

// ── 11. Build role-specific extra data for the frontend ──────────────────────
$extra = [];

if ($role === 'student') {
    $row = $pdo->prepare("
        SELECT program, year_level, section, enrollment_status
        FROM students WHERE student_id = ?
    ");
    $row->execute([$user['user_id']]);
    $extra = $row->fetch() ?: [];
}

if (in_array($role, ['instructor', 'department_head'], true)) {
    $row = $pdo->prepare("
        SELECT department, specialization, academic_rank FROM instructors WHERE instructor_id = ?
    ");
    $row->execute([$user['user_id']]);
    $extra = $row->fetch() ?: [];
}

// ── 12. Respond ──────────────────────────────────────────────────────────────
success([
    'user' => [
        'user_id'      => $user['user_id'],
        'id_number'    => $user['id_number'],
        'role'         => $user['role'],
        'first_name'   => $user['first_name'],
        'last_name'    => $user['last_name'],
        'full_name'    => trim($user['first_name'] . ' ' . $user['last_name']),
        'school_email' => $user['school_email'],
        'avatar_path'  => $user['avatar_path'],
        'last_login'   => $user['last_login_at'],
    ] + $extra,
    'token'    => $token,   // store in localStorage or pass as Authorization: Bearer <token>
    'redirect' => $redirects[$user['role']] ?? 'tcm-lms-login.html',
], 'Login successful.');
