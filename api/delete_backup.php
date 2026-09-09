<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';

// SEC-002-class gap: this file only ever checked that SOMEONE was logged in,
// not the backup_restore permission its sibling api/backup_actions.php
// already correctly requires for the same destructive action (delete). Any
// authenticated Member could delete a backup file. api/backup_actions.php's
// own delete_backup action is what the live UI actually calls; this file
// stays independently routed (roots.php), so it needs the same gate for
// defense in depth, not because the UI still uses it.
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../core/permissions.php';
requirePermissionJson('delete', 'backup_restore');

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
