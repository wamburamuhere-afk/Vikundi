<?php
/**
 * GET    /api/v1/documents/{id} — one library document's metadata
 * DELETE /api/v1/documents/{id} — delete
 *
 * No PUT: the web has no metadata-edit action for a library upload (only
 * upload and delete — see app/constant/document/document_library.php), so
 * this doesn't invent one.
 *
 * Delete mirrors deleteDocumentLocal(): ownership (uploader) or admin. This
 * module also added the missing document_library delete-permission gate to
 * that web action (it previously checked ownership only) — this endpoint
 * carries the same combined check from the start.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_documents.php';

vk_api_cors();
vk_api_require_method(['GET', 'DELETE']);

$auth = vk_api_require_auth();
if (!vk_api_doc_library_can($auth, 'view')) {
    vk_api_error(403, 'forbidden', 'You do not have permission to do that.');
}

$id  = (int) ($_GET['id'] ?? 0);
$doc = vk_api_doc_load($pdo, $id);
vk_api_doc_authorize_item($auth, $doc);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $actions = vk_api_doc_actions($auth, $doc);
    if (!$actions['delete']) {
        vk_api_error(403, 'forbidden', 'You do not have permission to delete this document.');
    }

    if (!empty($doc['file_path']) && file_exists($doc['file_path'])) {
        @unlink($doc['file_path']);
    }
    $pdo->prepare('DELETE FROM document_downloads WHERE document_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);

    $_SESSION['user_id'] = (int) $auth['user_id'];
    logDelete('Documents', $doc['document_name'], 'DOC#' . $id, (int) $auth['user_id']);

    vk_api_ok(['message' => 'Document deleted.']);
}

$row = vk_api_doc_row($doc);
$row['actions'] = vk_api_doc_actions($auth, $doc);

vk_api_ok(['document' => $row]);
