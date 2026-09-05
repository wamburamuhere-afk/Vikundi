<?php
/**
 * GET    /api/v1/authored-documents/{id} — full detail: body_html + signatories
 * PUT    /api/v1/authored-documents/{id} — edit
 * DELETE /api/v1/authored-documents/{id} — delete
 *
 * Mirrors app/constant/document/view_document.php (GET), actions/save_document.php
 * (PUT) and actions/delete_document.php (DELETE) — including this module's own
 * fix to the latter (see includes/api_documents.php's header): someone else's
 * PRIVATE document may never be edited or deleted, ownership/admin aside.
 *
 * A document the caller cannot see 404s (vk_api_authored_authorize_item()) —
 * existence of someone else's private document is not revealed either way.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_documents.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT', 'DELETE']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

$doc  = vk_api_authored_load($pdo, $id);
$ctx  = vk_api_authored_authorize_item($pdo, $auth, $doc);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $actions = vk_api_authored_actions($auth, $ctx['is_author'], (string) $doc['visibility']);
    if (!vk_api_can($auth, 'delete', 'manage_documents') || !$actions['delete']) {
        vk_api_error(403, 'forbidden', 'You do not have permission to delete this document.');
    }

    // There is no FK between document_signatories and authored_documents, and
    // actions/delete_document.php never clears these either — an orphaned
    // signatory row this endpoint would otherwise leave behind, harmlessly
    // (nothing joins to a deleted document), but cheap enough to clean up here.
    $pdo->prepare('DELETE FROM document_signatories WHERE document_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM authored_documents WHERE id = ?')->execute([$id]);

    $_SESSION['user_id'] = (int) $auth['user_id'];
    logDelete('Documents', $doc['title'], 'DOC#' . $id, (int) $auth['user_id']);

    vk_api_ok(['message' => 'Document deleted.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $actions = vk_api_authored_actions($auth, $ctx['is_author'], (string) $doc['visibility']);
    if (!$actions['edit']) {
        vk_api_error(403, 'forbidden', 'You do not have permission to edit this document.');
    }

    require_once __DIR__ . '/../../includes/document_sanitizer.php';

    $body = vk_api_body();
    $sets = [];
    $vals = [];

    if (array_key_exists('title', $body) || array_key_exists('doc_type', $body)) {
        $merged = [
            'title'    => array_key_exists('title', $body) ? $body['title'] : $doc['title'],
            'doc_type' => array_key_exists('doc_type', $body) ? $body['doc_type'] : $doc['doc_type'],
        ];
        $errors = vk_api_authored_input_errors($merged);
        if ($errors) {
            vk_api_error(422, 'invalid_document', implode(' ', $errors));
        }
        $sets[] = 'title = ?';
        $vals[] = trim((string) $merged['title']);
        $sets[] = 'doc_type = ?';
        $vals[] = (string) $merged['doc_type'];
    }
    if (array_key_exists('body_html', $body)) {
        $sets[] = 'body_html = ?';
        $vals[] = vk_sanitize_document_html((string) $body['body_html']);
    }
    if (array_key_exists('use_letterhead', $body)) {
        $sets[] = 'use_letterhead = ?';
        $vals[] = !empty($body['use_letterhead']) ? 1 : 0;
    }
    if (array_key_exists('status', $body)) {
        $sets[] = 'status = ?';
        $vals[] = ((string) $body['status']) === 'final' ? 'final' : 'draft';
    }
    // Only the author (or an admin) may change visibility — matches
    // actions/save_document.php exactly: another leadership user editing a
    // shared document leaves that setting as it was.
    if (array_key_exists('visibility', $body)) {
        $isAdmin = vk_api_is_admin((int) $auth['role_id']);
        if ($isAdmin || $ctx['is_author']) {
            $sets[] = 'visibility = ?';
            $vals[] = ((string) $body['visibility']) === 'private' ? 'private' : 'shared';
        }
    }

    if (!$sets) {
        vk_api_error(422, 'no_fields', 'Nothing to update — send at least one editable field.');
    }

    $vals[] = $id;
    $pdo->prepare('UPDATE authored_documents SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

    $_SESSION['user_id'] = (int) $auth['user_id'];
    $updated = vk_api_authored_load($pdo, $id);
    logUpdate('Documents', $updated['title'], 'DOC#' . $id, (int) $auth['user_id']);

    $row = vk_api_authored_row($updated);
    $row['body_html'] = (string) $updated['body_html'];
    $row['actions'] = vk_api_authored_actions($auth, $ctx['is_author'], (string) $updated['visibility']);

    vk_api_ok(['document' => $row, 'message' => 'Document saved.']);
}

$row = vk_api_authored_row($doc);
$row['body_html'] = (string) $doc['body_html'];
$row['actions'] = vk_api_authored_actions($auth, $ctx['is_author'], (string) $doc['visibility']);
$row['signatories'] = array_map('vk_api_signatory_row', vk_doc_signatories($pdo, $id));

vk_api_ok(['document' => $row]);
