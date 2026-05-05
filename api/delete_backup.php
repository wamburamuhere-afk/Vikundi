<?php
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $filename = $_POST['filename'] ?? '';
    if (empty($filename)) {
        echo json_encode(['success' => false, 'message' => 'Missing filename']);
        exit();
    }

    $backup_dir = __DIR__ . '/../backups/';
    $filepath = realpath($backup_dir . $filename);

    // Security check - ensure file is in backups folder
    if (strpos($filepath, realpath($backup_dir)) !== 0) {
        throw new Exception('Invalid file path');
    }

    if (file_exists($filepath)) {
        unlink($filepath);
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('File not found');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
