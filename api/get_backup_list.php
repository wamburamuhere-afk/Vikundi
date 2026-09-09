<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';

// Same SEC-002-class gap as api/delete_backup.php: session-only, not gated on
// backup_restore. Lower severity (filenames/dates/sizes, not DB content), but
// still an unnecessary disclosure to any authenticated Member, and this file
// remains independently routed even though backup_restore.php's own UI lists
// backups server-side inline rather than calling this endpoint.
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../core/permissions.php';
requirePermissionJson('view', 'backup_restore');

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
