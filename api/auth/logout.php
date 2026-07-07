<?php
/**
 * TCM LMS — Logout Endpoint
 *
 * POST /api/auth/logout.php
 * Headers: Authorization: Bearer <token>   (or cookie session)
 *
 * Called by every "Sign out" link in the sidebar of all dashboards.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error('Method not allowed. Use POST.', 405);
}

$pdo = db();

// Revoke Bearer token if present
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($header, 'Bearer ')) {
    $token = substr($header, 7);
    $stmt = $pdo->prepare("
        SELECT ls.user_id FROM login_sessions ls WHERE ls.session_token = ?
    ");
    $stmt->execute([$token]);
    $sess = $stmt->fetch();

    if ($sess) {
        $pdo->prepare("UPDATE login_sessions SET revoked_at = NOW() WHERE session_token = ?")
            ->execute([$token]);
        audit('logout', 'Token-based logout', $sess['user_id']);
    }
}

// Destroy cookie session if active
start_secure_session();
$user_id = $_SESSION['user_id'] ?? null;
if ($user_id) {
    audit('logout', 'Session logout', $user_id);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

success([], 'Logged out successfully.');
