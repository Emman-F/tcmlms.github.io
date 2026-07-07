<?php
/**
 * TCM LMS — Database Configuration
 * The College of Maasin · "Nisi Dominus Frustra"
 *
 * XAMPP defaults are set here.
 * Before going live, change DB_PASS and SESSION_SECRET.
 */

// ── Database ────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'tcm_lms');
define('DB_USER', 'root');           // XAMPP default; change for production
define('DB_PASS', '');               // XAMPP default is blank; set one for production
define('DB_CHARSET', 'utf8mb4');

// ── Session ─────────────────────────────────────────────────
define('SESSION_SECRET', 'thecollegeofmaasin2026');
define('SESSION_LIFETIME', 60 * 60 * 8);  // 8 hours in seconds

// ── File uploads ─────────────────────────────────────────────
// Absolute path to the uploads folder (one level above api/).
define('UPLOAD_ROOT',     __DIR__ . '/../uploads/');
define('UPLOAD_SUBMISSIONS', UPLOAD_ROOT . 'submissions/');
define('UPLOAD_MATERIALS',   UPLOAD_ROOT . 'materials/');
define('UPLOAD_AVATARS',     UPLOAD_ROOT . 'avatars/');
define('UPLOAD_INSTITUTION', UPLOAD_ROOT . 'institution/');  // school logo

// Max file sizes in bytes
define('MAX_SUBMISSION_BYTES',  50 * 1024 * 1024);   // 50 MB
define('MAX_MATERIAL_BYTES',    50 * 1024 * 1024);   // 50 MB
define('MAX_AVATAR_BYTES',       5 * 1024 * 1024);   //  5 MB

// Allowed MIME types per upload category
define('ALLOWED_SUBMISSION_TYPES', [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip',
    'application/x-zip-compressed',
    'video/mp4',
]);
define('ALLOWED_MATERIAL_TYPES', [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/zip',
    'application/x-zip-compressed',
    'video/mp4',
    'image/png',
    'image/jpeg',
]);
define('ALLOWED_AVATAR_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// ── App ──────────────────────────────────────────────────────
define('APP_ENV', 'development');  // 'production' silences detailed error messages
define('APP_VERSION', '1.0.0');
define('INSTITUTION_NAME', 'The College of Maasin');
