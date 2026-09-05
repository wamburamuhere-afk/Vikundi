<?php
/**
 * GET /api/v1/documents/{id}/download — stream a library document's file.
 *
 * Mirrors downloadDocumentLocal() in app/constant/document/document_library.php:
 * same visibility check, same document_downloads audit row + download_count
 * bump, same on-disk path fallback (a file_path saved as an absolute or
 * differently-rooted path is retried under uploads/document_library/basename).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_documents.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'document_library');

$id  = (int) ($_GET['id'] ?? 0);
$doc = vk_api_doc_load($pdo, $id);
vk_api_doc_authorize_item($auth, $doc);

$filePath = (string) $doc['file_path'];
if (!file_exists($filePath) && file_exists('uploads/document_library/' . basename($filePath))) {
    $filePath = 'uploads/document_library/' . basename($filePath);
}
if (!file_exists($filePath)) {
    vk_api_error(404, 'file_missing', 'The stored file could not be found on the server.');
}

$pdo->prepare('INSERT INTO document_downloads (document_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)')
    ->execute([$id, (int) $auth['user_id'], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
$pdo->prepare('UPDATE documents SET download_count = download_count + 1 WHERE id = ?')->execute([$id]);

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? finfo_file($finfo, $filePath) : false;
if ($finfo) {
    finfo_close($finfo);
}
if (!$mime || $mime === 'text/plain') {
    $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = $ext === 'pdf' ? 'application/pdf' : ($mime ?: 'application/octet-stream');
}

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $doc['original_filename'] . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-store');
readfile($filePath);
exit;
