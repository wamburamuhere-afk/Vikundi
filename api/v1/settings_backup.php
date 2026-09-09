<?php
/**
 * GET  /api/v1/settings/backup — list existing database backups
 * POST /api/v1/settings/backup — create a new one
 *
 * Mirrors app/constant/settings/backup_restore.php's list + api/backup_actions.php's
 * create_backup action — vikundi_write_dump() (core/backup.php), the pure-PHP,
 * PDO-driven dump engine the live UI actually uses (handles VIEWS correctly),
 * not api/create_backup.php's older exec()/mysqldump path. ADMIN/CHAIRPERSON ONLY.
 *
 * No restore endpoint. api/backup_actions.php's restore_backup/upload_restore
 * actions overwrite the live database — todo.md's plan never asked for this,
 * and it is far too destructive to add without being asked.
 *
 * Downloading a specific backup is its own endpoint, GET /api/v1/backup-download
 * — backups are files, not rows with a numeric id, so
 * /api/v1/settings/backup/{id}/download (todo.md's literal plan text) cannot
 * route: roots.php's REST id pattern requires a numeric id, and there is no
 * third static segment before one either way. Same router-constraint class as
 * Module 13/15's flattened paths.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_settings.php';
require_once __DIR__ . '/../../includes/activity_logger.php';
require_once __DIR__ . '/../../core/backup.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();
vk_api_settings_require_admin($auth);
$callerId = (int) $auth['user_id'];

$backupsDir = realpath(__DIR__ . '/../../backups') ?: (__DIR__ . '/../../backups');
if (!is_dir($backupsDir)) {
    mkdir($backupsDir, 0755, true); // matches api/backup_actions.php and backup_restore.php's own mode
    $backupsDir = realpath(__DIR__ . '/../../backups');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filename = 'backup_v_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupsDir . DIRECTORY_SEPARATOR . $filename;

    try {
        vikundi_write_dump($pdo, $filepath);
    } catch (Throwable $e) {
        if (file_exists($filepath)) {
            @unlink($filepath);
        }
        vk_api_error(500, 'backup_failed', 'The backup could not be created.');
    }

    $kb = round(filesize($filepath) / 1024, 2);
    $sizeLabel = $kb >= 1024 ? round($kb / 1024, 2) . ' MB' : $kb . ' KB';

    $_SESSION['user_id'] = $callerId; // logActivity() reads the session
    logActivity('Created', 'Backup', "Created database backup: $filename ($sizeLabel)", $filename, $callerId);

    vk_api_ok(['filename' => $filename, 'size' => $sizeLabel], 201);
}

$backups = [];
foreach (scandir($backupsDir, SCANDIR_SORT_DESCENDING) ?: [] as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }
    if (pathinfo($file, PATHINFO_EXTENSION) !== 'sql') {
        continue;
    }
    $stats = stat($backupsDir . DIRECTORY_SEPARATOR . $file);
    $kb = round($stats['size'] / 1024, 2);
    $backups[] = [
        'filename'    => $file,
        'size'        => $kb >= 1024 ? round($kb / 1024, 2) . ' MB' : $kb . ' KB',
        'size_bytes'  => (int) $stats['size'],
        'created_at'  => date(DATE_ATOM, $stats['mtime']),
    ];
}

vk_api_ok(['backups' => $backups]);
