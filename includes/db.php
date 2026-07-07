<?php
/**
 * TCM LMS — Database Connection
 * Returns a singleton PDO instance configured for tcm_lms.
 *
 * Usage in any API file:
 *   require_once __DIR__ . '/db.php';
 *   $pdo = db();
 */

require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // throw exceptions on error
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // always return assoc arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                   // real prepared statements
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Never expose DSN/credentials in the response body
        http_response_code(500);
        header('Content-Type: application/json');
        $msg = (APP_ENV === 'development')
            ? $e->getMessage()
            : 'Database connection failed. Contact the system administrator.';
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    return $pdo;
}
