<?php
/**
 * TCM LMS — Learning Materials Endpoint
 *
 * POST /api/materials/upload.php
 *   multipart/form-data: file (optional), title, class_section_id,
 *                        category, visibility, scheduled_release_at (optional)
 *   OR: { material_kind:"link", title, external_url, class_section_id, ... }
 *
 * GET  /api/materials/upload.php
 *   ?class_section_id=N             → all published materials (students)
 *   ?class_section_id=N&all=1       → all including drafts (instructor)
 *   ?material_id=N                  → single material detail
 *
 * PUT  /api/materials/upload.php
 *   Body: { material_id, title?, visibility?, scheduled_release_at? }
 *   Update metadata or publish/unpublish without re-uploading the file.
 *
 * DELETE /api/materials/upload.php
 *   Body: { material_id }
 *
 * Backs:
 *   - instructor-materials.html (full CRUD)
 *   - learning-materials.html   (student read-only view)
 *   - subject-detail.html       "Recent Materials" tab
 *
 * Auth: instructor/admin (POST/PUT/DELETE), all (GET published)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

set_json_headers();

$method = $_SERVER['REQUEST_METHOD'];

// ════════════════════════════════════════════════════════════════════════
// GET — fetch materials
// ════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $auth = require_bearer_auth(['student', 'instructor', 'admin']);
    $pdo  = db();

    // Single material detail
    $material_id = int_field($_GET, 'material_id');
    if ($material_id) {
        $stmt = $pdo->prepare("
            SELECT lm.*,
                   sub.subject_code, sub.title AS subject_title,
                   cs.section_code,
                   CONCAT(u.first_name,' ',u.last_name) AS uploaded_by_name
            FROM learning_materials lm
            JOIN class_sections cs ON cs.class_section_id = lm.class_section_id
            JOIN subjects sub      ON sub.subject_id = cs.subject_id
            JOIN users u           ON u.user_id = lm.uploaded_by
            WHERE lm.material_id = ?
        ");
        $stmt->execute([$material_id]);
        $mat = $stmt->fetch();

        if (!$mat) {
            error('Material not found.', 404);
        }
        // Students can only see published materials
        if ($auth['role'] === 'student' && $mat['visibility'] !== 'published') {
            error('This material is not yet available.', 403);
        }
        success(['material' => $mat]);
    }

    // List for a class
    $class_section_id = int_field($_GET, 'class_section_id', required: true);
    $show_all         = ($auth['role'] !== 'student') && !empty($_GET['all']);

    // Instructor ownership check when fetching drafts
    if ($show_all && $auth['role'] === 'instructor') {
        $owns = $pdo->prepare("
            SELECT class_section_id FROM class_sections
            WHERE class_section_id = ? AND instructor_id = ?
        ");
        $owns->execute([$class_section_id, $auth['user_id']]);
        if (!$owns->fetch()) {
            error('You do not have access to this class.', 403);
        }
    }

    // For scheduled materials: auto-publish if scheduled_release_at has passed
    $pdo->prepare("
        UPDATE learning_materials
        SET visibility = 'published'
        WHERE visibility = 'scheduled'
          AND scheduled_release_at IS NOT NULL
          AND scheduled_release_at <= NOW()
    ")->execute();

    $where_vis = $show_all ? "1=1" : "lm.visibility = 'published'";

    $stmt = $pdo->prepare("
        SELECT
            lm.material_id,
            lm.material_kind,
            lm.title,
            lm.description,
            lm.file_path,
            lm.file_type,
            lm.file_size_bytes,
            lm.external_url,
            lm.category,
            lm.visibility,
            lm.scheduled_release_at,
            lm.uploaded_at,
            CONCAT(u.first_name,' ',u.last_name) AS uploaded_by_name
        FROM learning_materials lm
        JOIN users u ON u.user_id = lm.uploaded_by
        WHERE lm.class_section_id = ? AND {$where_vis}
        ORDER BY lm.uploaded_at DESC
    ");
    $stmt->execute([$class_section_id]);
    $materials = $stmt->fetchAll();

    // Group by category for the frontend grid
    $grouped = [];
    foreach ($materials as $m) {
        $grouped[$m['category']][] = $m;
    }

    success([
        'class_section_id' => $class_section_id,
        'count'            => count($materials),
        'materials'        => $materials,
        'grouped'          => $grouped,
    ]);
}

// ════════════════════════════════════════════════════════════════════════
// POST — upload a file or add a link
// ════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $auth = require_bearer_auth(['instructor', 'admin']);
    $pdo  = db();

    // Determine if this is a JSON (link) or multipart (file upload) request
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    $is_multipart = str_contains($content_type, 'multipart/form-data');

    if ($is_multipart) {
        $data             = $_POST;
        $material_kind    = 'file';
    } else {
        $data             = get_json_body();
        $material_kind    = str_field($data, 'material_kind') ?? 'link';
    }

    $class_section_id    = int_field($data, 'class_section_id',    required: true);
    $title               = str_field($data, 'title',               required: true);
    $description         = str_field($data, 'description');
    $category            = str_field($data, 'category')            ?? 'other';
    $visibility          = str_field($data, 'visibility')          ?? 'draft';
    $scheduled_release   = str_field($data, 'scheduled_release_at');
    $external_url        = str_field($data, 'external_url');

    // Validate category and visibility enums
    $valid_cats = ['lecture_slides','reference_material','activity_sheet','supplemental_reading','template','other'];
    $valid_vis  = ['published','draft','scheduled'];
    if (!in_array($category,   $valid_cats, true)) $category   = 'other';
    if (!in_array($visibility, $valid_vis,  true)) $visibility = 'draft';

    if ($visibility === 'scheduled' && empty($scheduled_release)) {
        error("'scheduled_release_at' is required when visibility is 'scheduled'.", 422);
    }
    if ($visibility === 'scheduled') {
        $sched_dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduled_release)
                 ?: DateTime::createFromFormat('Y-m-d\TH:i', $scheduled_release);
        if (!$sched_dt) {
            error("'scheduled_release_at' must be a valid datetime (YYYY-MM-DD HH:MM:SS).", 422);
        }
        if ($sched_dt <= new DateTime()) {
            error("'scheduled_release_at' must be a future date/time.", 422);
        }
    }

    // Instructor ownership
    if ($auth['role'] === 'instructor') {
        $owns = $pdo->prepare("
            SELECT class_section_id FROM class_sections
            WHERE class_section_id = ? AND instructor_id = ?
        ");
        $owns->execute([$class_section_id, $auth['user_id']]);
        if (!$owns->fetch()) {
            error('You do not have permission to upload materials to this class.', 403);
        }
    }

    $file_path       = null;
    $file_type_ext   = null;
    $file_size_bytes = null;

    if ($material_kind === 'file') {
        // Handle the actual file upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
            error("A file must be uploaded when material_kind is 'file'.", 422);
        }
        $file_path       = handle_upload('file', UPLOAD_MATERIALS, ALLOWED_MATERIAL_TYPES, MAX_MATERIAL_BYTES);
        $file_type_ext   = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $file_size_bytes = $_FILES['file']['size'];

    } else {
        // Link type — validate URL
        if (empty($external_url)) {
            error("'external_url' is required when material_kind is 'link'.", 422);
        }
        if (!filter_var($external_url, FILTER_VALIDATE_URL)) {
            error("'external_url' must be a valid URL.", 422);
        }
    }

    $pdo->prepare("
        INSERT INTO learning_materials
            (class_section_id, material_kind, title, description,
             file_path, file_type, file_size_bytes, external_url,
             category, visibility, scheduled_release_at, uploaded_by)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $class_section_id, $material_kind, $title, $description,
        $file_path, $file_type_ext, $file_size_bytes, $external_url,
        $category, $visibility, $scheduled_release, $auth['user_id'],
    ]);
    $material_id = (int)$pdo->lastInsertId();

    // Notify enrolled students if publishing immediately
    if ($visibility === 'published') {
        $students_stmt = $pdo->prepare("
            SELECT student_id FROM enrollments
            WHERE class_section_id = ? AND status != 'dropped'
        ");
        $students_stmt->execute([$class_section_id]);
        $notify = $pdo->prepare("
            INSERT INTO notifications (user_id, notif_type, title, body)
            VALUES (?, 'new_material', ?, ?)
        ");
        foreach ($students_stmt->fetchAll() as $row) {
            $notify->execute([
                $row['student_id'],
                'New Material: ' . $title,
                "Your instructor posted a new learning material: \"{$title}\".",
            ]);
        }
    }

    audit(
        'material_upload',
        "Uploaded material_id={$material_id} kind={$material_kind} title=\"{$title}\" class={$class_section_id} vis={$visibility}",
        $auth['user_id']
    );

    success([
        'material_id'      => $material_id,
        'class_section_id' => $class_section_id,
        'material_kind'    => $material_kind,
        'title'            => $title,
        'visibility'       => $visibility,
        'file_path'        => $file_path,
        'external_url'     => $external_url,
    ], 'Material saved successfully.');
}

// ════════════════════════════════════════════════════════════════════════
// PUT — update metadata (publish/unpublish, rename, change category)
// ════════════════════════════════════════════════════════════════════════
if ($method === 'PUT') {
    $auth        = require_bearer_auth(['instructor', 'admin']);
    $body        = get_json_body();
    $pdo         = db();
    $material_id = int_field($body, 'material_id', required: true);

    // Fetch existing record + ownership check
    $mat = $pdo->prepare("
        SELECT lm.*, cs.instructor_id
        FROM learning_materials lm
        JOIN class_sections cs ON cs.class_section_id = lm.class_section_id
        WHERE lm.material_id = ?
    ");
    $mat->execute([$material_id]);
    $existing = $mat->fetch();

    if (!$existing) {
        error('Material not found.', 404);
    }
    if ($auth['role'] === 'instructor' && (int)$existing['instructor_id'] !== (int)$auth['user_id']) {
        error('You do not have permission to edit this material.', 403);
    }

    // Only update fields that were actually sent
    $new_title      = str_field($body, 'title')       ?? $existing['title'];
    $new_desc       = array_key_exists('description', $body) ? str_field($body, 'description') : $existing['description'];
    $new_category   = str_field($body, 'category')    ?? $existing['category'];
    $new_visibility = str_field($body, 'visibility')  ?? $existing['visibility'];
    $new_scheduled  = array_key_exists('scheduled_release_at', $body)
        ? str_field($body, 'scheduled_release_at')
        : $existing['scheduled_release_at'];

    $valid_cats = ['lecture_slides','reference_material','activity_sheet','supplemental_reading','template','other'];
    $valid_vis  = ['published','draft','scheduled'];
    if (!in_array($new_category,   $valid_cats, true)) $new_category   = $existing['category'];
    if (!in_array($new_visibility, $valid_vis,  true)) $new_visibility = $existing['visibility'];

    $pdo->prepare("
        UPDATE learning_materials
        SET title                = ?,
            description          = ?,
            category             = ?,
            visibility           = ?,
            scheduled_release_at = ?
        WHERE material_id = ?
    ")->execute([$new_title, $new_desc, $new_category, $new_visibility, $new_scheduled, $material_id]);

    audit('material_upload', "Updated material_id={$material_id} vis={$new_visibility}", $auth['user_id']);

    success([
        'material_id' => $material_id,
        'visibility'  => $new_visibility,
        'title'       => $new_title,
    ], 'Material updated successfully.');
}

// ════════════════════════════════════════════════════════════════════════
// DELETE — remove a material and its file from disk
// ════════════════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    $auth        = require_bearer_auth(['instructor', 'admin']);
    $body        = get_json_body();
    $pdo         = db();
    $material_id = int_field($body, 'material_id', required: true);

    $mat = $pdo->prepare("
        SELECT lm.*, cs.instructor_id
        FROM learning_materials lm
        JOIN class_sections cs ON cs.class_section_id = lm.class_section_id
        WHERE lm.material_id = ?
    ");
    $mat->execute([$material_id]);
    $existing = $mat->fetch();

    if (!$existing) {
        error('Material not found.', 404);
    }
    if ($auth['role'] === 'instructor' && (int)$existing['instructor_id'] !== (int)$auth['user_id']) {
        error('You do not have permission to delete this material.', 403);
    }

    // Delete the physical file if it exists
    if ($existing['file_path'] && $existing['material_kind'] === 'file') {
        $full_path = UPLOAD_MATERIALS . $existing['file_path'];
        if (file_exists($full_path)) {
            @unlink($full_path);
        }
    }

    $pdo->prepare("DELETE FROM learning_materials WHERE material_id = ?")
        ->execute([$material_id]);

    audit('material_upload', "Deleted material_id={$material_id} title=\"{$existing['title']}\"", $auth['user_id']);

    success(['material_id' => $material_id], 'Material deleted successfully.');
}

error('Method not allowed.', 405);
