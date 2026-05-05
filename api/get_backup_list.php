<?php
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $backup_dir = __DIR__ . '/../backups/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }

    $backups = [];
    $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $stats = stat($backup_dir . $file);
            $backups[] = [
                'filename' => $file,
                'date' => date('Y-m-d H:i:s', $stats['mtime']),
                'size' => round($stats['size'] / 1024, 2) . ' KB'
            ];
        }
    }

    echo json_encode(['success' => true, 'backups' => $backups]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
