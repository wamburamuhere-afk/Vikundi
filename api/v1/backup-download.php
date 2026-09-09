<?php
/**
 * GET /api/v1/backup-download?file=<filename> — stream one backup file
 *
 * Mirrors api/download_backup.php's path handling exactly (realpath + prefix
 * check + .sql extension) — verified traversal-safe there under SEC-002 and
 * deliberately left unchanged, just re-authenticated for a token caller.
 * ADMIN/CHAIRPERSON ONLY. See api/v1/settings_backup.php for why this is its
 * own top-level resource rather than /settings/backup/{id}/download.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_settings.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_settings_require_admin($auth);

$filename = (string) ($_GET['file'] ?? '');
if ($filename === '') {
    vk_api_error(422, 'file_required', 'file is required.');
}

$backupsDir = realpath(__DIR__ . '/../../backups');
$filepath = $backupsDir ? realpath($backupsDir . '/' . $filename) : false;

if (!$backupsDir || !$filepath || strpos($filepath, $backupsDir) !== 0) {
    vk_api_error(404, 'not_found', 'No backup was found with that filename.');
}
if (!file_exists($filepath) || pathinfo($filepath, PATHINFO_EXTENSION) !== 'sql') {
    vk_api_error(404, 'not_found', 'No backup was found with that filename.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit;
