<?php
// actions/download_members_template.php
// Serves the members bulk-upload CSV template for download.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // audit: refusal must not return HTTP 200
    die('Unauthorized.');
}

$file = __DIR__ . '/../templates/members_template.csv';
if (!is_file($file)) {
    http_response_code(404);
    die('Template not found.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="members_template.csv"');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
