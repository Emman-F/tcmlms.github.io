<?php
/**
 * TCM LMS — Shared Utilities
 * Used by every API endpoint.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── CORS & JSON headers ──────────────────────────────────────
function set_json_headers(): void {
    // In production, replace * with your actual domain
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── Response helpers ─────────────────────────────────────────
function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function success(array $data = [], string $message = 'OK'): void {
    respond(['success' => true, 'message' => $message, 'data' => $data]);
}

function error(string $message, int $status = 400, array $extra = []): void {
    respond(array_merge(['success' => false, 'error' => $message], $extra), $status);
}

// ── Input helpers ────────────────────────────────────────────
/**
 * Safely read and JSON-decode the raw request body.
 * Used for POST/PUT requests sending application/json.
 */
function get_json_body(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error('Invalid JSON body: ' . json_last_error_msg(), 400);
    }
    return $decoded ?? [];
}

/**
 * Return a trimmed string from an array, or null if missing/empty.
 */
function str_field(array $data, string $key, bool $required = false): ?string {
    $val = isset($data[$key]) ? trim((string)$data[$key]) : null;
    if ($required && ($val === null || $val === '')) {
        error("Field '{$key}' is required.", 422);
    }
    return ($val === '') ? null : $val;
}

function int_field(array $data, string $key, bool $required = false): ?int {
    if (!isset($data[$key]) || $data[$key] === '') {
        if ($required) error("Field '{$key}' is required.", 422);
        return null;
    }
    if (!is_numeric($data[$key])) {
        error("Field '{$key}' must be an integer.", 422);
    }
    return (int)$data[$key];
}

function float_field(array $data, string $key, bool $required = false): ?float {
    if (!isset($data[$key]) || $data[$key] === '') {
        if ($required) error("Field '{$key}' is required.", 422);
        return null;
    }
    if (!is_numeric($data[$key])) {
        error("Field '{$key}' must be a number.", 422);
    }
    return (float)$data[$key];
}

// ── Session / Auth ───────────────────────────────────────────
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('tcm_lms_session');
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),  // HTTPS only in production
            'httponly' => true,                       // JS cannot read the cookie
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Require the request to have a valid session.
 * Optionally restrict to specific roles.
 *
 * @param string|array $roles  e.g. 'instructor' or ['instructor','admin']
 * @return array               The current session user data
 */
function require_auth(string|array $roles = []): array {
    start_secure_session();
    if (empty($_SESSION['user_id'])) {
        error('Unauthorized. Please log in.', 401);
    }
    if (!empty($roles)) {
        $allowed = is_array($roles) ? $roles : [$roles];
        if (!in_array($_SESSION['role'], $allowed, true)) {
            error('Forbidden. Insufficient permissions.', 403);
        }
    }
    return [
        'user_id'   => $_SESSION['user_id'],
        'role'      => $_SESSION['role'],
        'id_number' => $_SESSION['id_number'] ?? null,
    ];
}

/**
 * Regenerate session ID to prevent session fixation attacks.
 * Call once right after a successful login.
 */
function regenerate_session(): void {
    start_secure_session();
    session_regenerate_id(true);
}

// ── Token-based auth (alternative to cookie sessions for API clients) ──
/**
 * Generate a cryptographically random session token.
 */
function generate_token(int $bytes = 48): string {
    return bin2hex(random_bytes($bytes));
}

/**
 * Validate a Bearer token from the Authorization header.
 * Returns the session row or calls error() if invalid.
 */
function require_bearer_auth(string|array $roles = []): array {
    // Apache/XAMPP may strip the Authorization header.
    // Try all known fallback locations before giving up.
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

    // Final fallback: use apache_request_headers() if available
    if (empty($header) && function_exists('apache_request_headers')) {
        $apache_headers = apache_request_headers();
        $header = $apache_headers['Authorization']
               ?? $apache_headers['authorization']
               ?? '';
    }
    if (!str_starts_with($header, 'Bearer ')) {
        error('Unauthorized. No token provided.', 401);
    }
    $token = substr($header, 7);

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT ls.user_id, u.role, u.id_number, u.first_name, u.last_name, ls.session_token
        FROM login_sessions ls
        JOIN users u ON u.user_id = ls.user_id
        WHERE ls.session_token = ? AND ls.revoked_at IS NULL
          AND ls.last_active_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$token, SESSION_LIFETIME]);
    $session = $stmt->fetch();

    if (!$session) {
        error('Unauthorized. Invalid or expired token.', 401);
    }

    if (!empty($roles)) {
        $allowed = is_array($roles) ? $roles : [$roles];
        if (!in_array($session['role'], $allowed, true)) {
            error('Forbidden. Insufficient permissions.', 403);
        }
    }

    // Refresh last_active_at to keep the session alive
    $pdo->prepare("UPDATE login_sessions SET last_active_at = NOW() WHERE session_token = ?")
        ->execute([$token]);

    return $session;
}

// ── Audit logging ────────────────────────────────────────────
/**
 * Write one row to audit_logs.
 * Call this after every meaningful action (login, grade, upload, etc.)
 */
function audit(
    string $action_type,
    string $details,
    ?int   $user_id = null,
    string $status = 'success'
): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? null;

    try {
        db()->prepare("
            INSERT INTO audit_logs (user_id, action_type, details, ip_address, status)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$user_id, $action_type, $details, $ip, $status]);
    } catch (PDOException) {
        // Audit failure must never crash the main request
    }
}

// ── File upload handler ──────────────────────────────────────
/**
 * Validate and move an uploaded file to the correct destination folder.
 *
 * @param  string $field         $_FILES key name
 * @param  string $dest_dir      Absolute destination directory (use UPLOAD_* constants)
 * @param  array  $allowed_types Allowed MIME types array (use ALLOWED_* constants)
 * @param  int    $max_bytes     Maximum file size in bytes
 * @return string                Stored filename (relative, safe to put in DB)
 */
function handle_upload(
    string $field,
    string $dest_dir,
    array  $allowed_types,
    int    $max_bytes
): string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES[$field]['error'] ?? -1;
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        error($messages[$code] ?? 'Upload failed with error code ' . $code, 400);
    }

    $file      = $_FILES[$field];
    $file_size = $file['size'];
    $tmp_path  = $file['tmp_name'];
    $orig_name = basename($file['name']);

    // Size check
    if ($file_size > $max_bytes) {
        $mb = round($max_bytes / (1024 * 1024));
        error("File too large. Maximum allowed size is {$mb} MB.", 413);
    }

    // MIME check using finfo (not trusting the client-supplied MIME type)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = $finfo->file($tmp_path);
    if (!in_array($real_mime, $allowed_types, true)) {
        error("File type '{$real_mime}' is not allowed.", 415);
    }

    // Build a safe, unique filename: timestamp_randomhex_sanitizedoriginalname
    $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $safe_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($orig_name, PATHINFO_FILENAME));
    $filename  = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $safe_base . '.' . $ext;

    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }

    if (!move_uploaded_file($tmp_path, $dest_dir . $filename)) {
        error('Failed to save file. Check server permissions.', 500);
    }

    return $filename;
}

// ── Rate limiting (simple, file-based — good enough for a local LMS) ──
/**
 * Prevent brute-force login. Allows $max_attempts per $window_seconds per IP.
 * Stores counters in PHP sessions (fine for a single-server school LMS).
 */
function check_rate_limit(string $key, int $max_attempts = 10, int $window_seconds = 300): void {
    start_secure_session();
    $now = time();
    $sess_key = 'rate_' . $key;

    $record = $_SESSION[$sess_key] ?? ['count' => 0, 'window_start' => $now];

    if (($now - $record['window_start']) > $window_seconds) {
        // Window expired, reset
        $record = ['count' => 0, 'window_start' => $now];
    }

    $record['count']++;
    $_SESSION[$sess_key] = $record;

    if ($record['count'] > $max_attempts) {
        $wait = $window_seconds - ($now - $record['window_start']);
        error("Too many attempts. Please wait {$wait} seconds before trying again.", 429);
    }
}
