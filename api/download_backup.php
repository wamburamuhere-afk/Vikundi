<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

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
