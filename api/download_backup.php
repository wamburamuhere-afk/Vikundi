<?php
// Guard session_start: when reached via the front controller (index.php ->
// roots.php) a session is already active, and an unconditional session_start()
// emits a notice BEFORE the file headers below — which corrupts the download.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Audit SEC-002: previously any logged-in member could fetch a full mysqldump —
// every users.password bcrypt hash and all member PII. backups/.htaccess correctly
// denies direct HTTP access; that control was undone by this ungated reader in
// front of it. Both guards run before any file header is emitted.
// The path handling below (realpath + prefix + extension) was verified
// traversal-safe and is deliberately left unchanged — only authorisation was wrong.
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../core/permissions.php';
requirePermissionJson('view', 'backup_restore');

$filename = $_GET['file'] ?? '';
if (empty($filename)) {
    die('Missing filename');
}

$backup_dir = __DIR__ . '/../backups/';
$filepath = realpath($backup_dir . $filename);

// Ensure the file is inside the backups directory
if (strpos($filepath, realpath($backup_dir)) !== 0) {
    die('Invalid file path');
}

if (file_exists($filepath) && pathinfo($filepath, PATHINFO_EXTENSION) === 'sql') {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
} else {
    die('File not found');
}
