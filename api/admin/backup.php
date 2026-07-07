<?php
/**
 * TCM LMS — Database Backup & Restore Endpoint
 *
 * GET    /api/admin/backup.php            → list backup history
 * POST   /api/admin/backup.php            → trigger a new backup
 * DELETE /api/admin/backup.php            → delete a backup file
 *
 * POST body for restore:
 *   { "action": "restore", "backup_id": N }
 *
 * Backs:
 *   - admin-dashboard.html Backup & Restore tab
 *     (Create Backup button, backup history table, Restore / Download / Delete actions)
 *
 * How it works:
 *   - Uses mysqldump (available in XAMPP's bin/) to create a .sql dump
 *   - Stores the file in /uploads/backups/ (outside webroot is ideal for production)
 *   - Records the backup in the backups table
 *   - Restore reads the dump file and re-executes it via PDO exec()
 *
 * Auth: admin only
 *
 * NOTE: For production, restrict /uploads/backups/ via .htaccess so
 *       backup files are not publicly downloadable.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

// Backup files live here
define('BACKUP_DIR', UPLOAD_ROOT . 'backups/');
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0750, true);
}

$auth   = require_bearer_auth(['admin']);
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ════════════════════════════════════════════════════════════════════════
// GET — list backup history
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $limit  = min((int)($_GET['limit'] ?? 20), 50);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $stmt = $pdo->prepare("
        SELECT
            b.backup_id,
            b.filename,
            b.file_path,
            b.size_bytes,
            b.backup_type,
            b.created_at,
            CONCAT(u.first_name,' ',u.last_name) AS triggered_by_name
        FROM backups b
        LEFT JOIN users u ON u.user_id = b.triggered_by
        ORDER BY b.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $backups = $stmt->fetchAll();

    // Annotate each with whether the file still exists on disk
    foreach ($backups as &$b) {
        $b['file_exists'] = file_exists(BACKUP_DIR . $b['filename']);
        $b['size_human']  = format_bytes((int)$b['size_bytes']);
    }

    $total = (int)$pdo->query("SELECT COUNT(*) FROM backups")->fetchColumn();

    // Also return disk usage of the backups folder
    $backup_files    = glob(BACKUP_DIR . '*.sql') ?: [];
    $total_disk_bytes = array_sum(array_map('filesize', $backup_files));

    success([
        'backups'          => $backups,
        'total'            => $total,
        'limit'            => $limit,
        'offset'           => $offset,
        'disk_usage'       => format_bytes($total_disk_bytes),
        'disk_usage_bytes' => $total_disk_bytes,
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — trigger backup OR restore
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $body   = get_json_body();
    $action = str_field($body, 'action') ?? 'backup';

    // ── (A) Create a new backup ───────────────────────────────────────────
    if ($action === 'backup') {
        $filename  = 'tcm_lms_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
        $full_path = BACKUP_DIR . $filename;

        // Try mysqldump first (available in XAMPP)
        $mysqldump = find_mysqldump();

        if ($mysqldump) {
            // Build the command — credentials come from config.php constants
            $cmd = sprintf(
                '%s --host=%s --port=%d --user=%s %s --single-transaction --routines --triggers %s > %s 2>&1',
                escapeshellarg($mysqldump),
                escapeshellarg(DB_HOST),
                DB_PORT,
                escapeshellarg(DB_USER),
                DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '',
                escapeshellarg(DB_NAME),
                escapeshellarg($full_path)
            );
            exec($cmd, $output, $exit_code);

            if ($exit_code !== 0 || !file_exists($full_path)) {
                error('mysqldump failed. Check server permissions and mysqldump path.', 500);
            }
        } else {
            // Fallback: PHP-based export using SELECT INTO OUTFILE / PDO iteration
            // (Works without mysqldump; generates INSERT statements for all tables)
            $sql = php_dump_database($pdo);
            file_put_contents($full_path, $sql);
        }

        $size = filesize($full_path);

        $pdo->prepare("
            INSERT INTO backups (filename, file_path, size_bytes, backup_type, triggered_by)
            VALUES (?, ?, ?, 'manual', ?)
        ")->execute([$filename, $full_path, $size, $auth['user_id']]);

        $backup_id = (int)$pdo->lastInsertId();

        audit(
            'backup',
            "Manual backup created: {$filename} size=" . format_bytes($size),
            $auth['user_id']
        );

        success([
            'backup_id'   => $backup_id,
            'filename'    => $filename,
            'size_bytes'  => $size,
            'size_human'  => format_bytes($size),
            'created_at'  => date('Y-m-d H:i:s'),
        ], 'Backup created successfully.');
    }

    // ── (B) Restore from backup ───────────────────────────────────────────
    if ($action === 'restore') {
        $backup_id = int_field($body, 'backup_id', required: true);

        $backup_row = $pdo->prepare("SELECT * FROM backups WHERE backup_id = ?");
        $backup_row->execute([$backup_id]);
        $backup = $backup_row->fetch();

        if (!$backup) {
            error('Backup record not found.', 404);
        }

        $full_path = BACKUP_DIR . $backup['filename'];
        if (!file_exists($full_path)) {
            error('Backup file not found on disk. It may have been deleted.', 404);
        }

        $sql = file_get_contents($full_path);
        if (!$sql) {
            error('Backup file is empty or unreadable.', 500);
        }

        // Execute the dump SQL (this overwrites ALL current data — admin must confirm)
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $msg = (APP_ENV === 'development') ? $e->getMessage() : 'Restore failed. See server logs.';
            audit('restore', "Restore FAILED: backup_id={$backup_id}", $auth['user_id'], 'failed');
            error('Restore failed: ' . $msg, 500);
        }

        audit(
            'restore',
            "Database restored from backup_id={$backup_id} file={$backup['filename']}",
            $auth['user_id']
        );

        success([
            'backup_id' => $backup_id,
            'filename'  => $backup['filename'],
            'restored_at' => date('Y-m-d H:i:s'),
        ], 'Database restored successfully from backup.');
    }

    error("Unknown action '{$action}'. Valid: backup, restore.", 422);
}

// ════════════════════════════════════════════════════════════════════════
// DELETE — remove a backup file + its database record
// ════════════════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    $body      = get_json_body();
    $backup_id = int_field($body, 'backup_id', required: true);

    $backup_row = $pdo->prepare("SELECT * FROM backups WHERE backup_id = ?");
    $backup_row->execute([$backup_id]);
    $backup = $backup_row->fetch();

    if (!$backup) {
        error('Backup record not found.', 404);
    }

    // Delete physical file
    $full_path = BACKUP_DIR . $backup['filename'];
    if (file_exists($full_path)) {
        if (!@unlink($full_path)) {
            error('Failed to delete backup file. Check server permissions.', 500);
        }
    }

    $pdo->prepare("DELETE FROM backups WHERE backup_id = ?")->execute([$backup_id]);

    audit('backup', "Deleted backup_id={$backup_id} file={$backup['filename']}", $auth['user_id']);

    success(['backup_id' => $backup_id], 'Backup deleted.');
}

// ════════════════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════════════════

/**
 * Locate mysqldump binary in common XAMPP/WAMP locations.
 */
function find_mysqldump(): string|false {
    $candidates = [
        'mysqldump',                                          // in PATH
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',              // Windows XAMPP
        'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
        '/Applications/XAMPP/xamppfiles/bin/mysqldump',      // macOS XAMPP
        '/usr/bin/mysqldump',                                 // Linux
        '/usr/local/bin/mysqldump',
    ];
    foreach ($candidates as $bin) {
        if (is_executable($bin)) return $bin;
        // Check via `which` on Unix-like systems
        if (PHP_OS_FAMILY !== 'Windows') {
            $found = trim(shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null') ?? '');
            if ($found && is_executable($found)) return $found;
        }
    }
    return false;
}

/**
 * PHP-based database dump fallback (no mysqldump needed).
 * Generates SQL INSERT statements for all tables.
 */
function php_dump_database(PDO $pdo): string {
    $sql  = "-- TCM LMS PHP Database Dump\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // Table structure
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        $sql   .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql   .= $create[1] . ";\n\n";

        // Table data
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_NUM);
        if (!$rows) continue;

        $cols    = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $col_str = '`' . implode('`, `', $cols) . '`';

        $chunks = array_chunk($rows, 100);
        foreach ($chunks as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $escaped = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                $values[] = '(' . implode(', ', $escaped) . ')';
            }
            $sql .= "INSERT INTO `{$table}` ({$col_str}) VALUES\n" . implode(",\n", $values) . ";\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

/**
 * Human-readable byte size.
 */
function format_bytes(int $bytes): string {
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1048576)     return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824)  return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

error('Method not allowed.', 405);
