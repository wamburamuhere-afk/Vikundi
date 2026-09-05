<?php
/**
 * POST /api/v1/authored-documents — write a new letter / contract / notice.
 *
 * Reached through authored-documents.php, which has already authenticated.
 * Mirrors actions/save_document.php's create path.
 *
 * template_id (optional) pre-fills doc_type/body_html/use_letterhead from
 * authored_document_templates — the same "start from a template" the New
 * Document editor offers via ?tpl=ID. Fields sent alongside template_id still
 * win: a client may start from a template and then override anything.
 *
 * The body is treated as pre-sanitised, already-resolved HTML from the
 * client's editor — unlike the web form, this endpoint has no merge-field
 * resolution step (member_id / letterhead placeholders), since a mobile
 * client composing a document has no equivalent rich-text authoring surface
 * yet. vk_sanitize_document_html() still runs, so stored markup is never
 * trusted as-is.
 */

require_once __DIR__ . '/../../includes/document_sanitizer.php';

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}
vk_api_require_permission($auth, 'create', 'manage_documents');

$body = vk_api_body();

$templateId = (int) ($body['template_id'] ?? 0);
$fromTemplate = ['doc_type' => 'letter', 'body_html' => '', 'use_letterhead' => 1];
if ($templateId > 0) {
    $ts = $pdo->prepare('SELECT doc_type, body_html, use_letterhead FROM authored_document_templates WHERE id = ?');
    $ts->execute([$templateId]);
    $trow = $ts->fetch(PDO::FETCH_ASSOC);
    if (!$trow) {
        vk_api_error(404, 'template_not_found', 'No template was found with that id.');
    }
    $fromTemplate = $trow;
}

$merged = array_merge($fromTemplate, $body);
$errors = vk_api_authored_input_errors($merged);
if ($errors) {
    vk_api_error(422, 'invalid_document', implode(' ', $errors));
}

$title         = trim((string) $merged['title']);
$docType       = (string) $merged['doc_type'];
$bodyHtml      = vk_sanitize_document_html((string) ($merged['body_html'] ?? ''));
$useLetterhead = !empty($merged['use_letterhead']) ? 1 : 0;
$status        = ((string) ($merged['status'] ?? 'draft')) === 'final' ? 'final' : 'draft';
$visibility    = ((string) ($merged['visibility'] ?? 'shared')) === 'private' ? 'private' : 'shared';

$pdo->prepare(
    'INSERT INTO authored_documents (title, doc_type, body_html, use_letterhead, status, visibility, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([$title, $docType, $bodyHtml, $useLetterhead, $status, $visibility, (int) $auth['user_id']]);
$id = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = (int) $auth['user_id'];
logCreate('Documents', $title, 'DOC#' . $id, (int) $auth['user_id']);

$created = vk_api_authored_load($pdo, $id);
$row = vk_api_authored_row($created);
$row['body_html'] = (string) $created['body_html'];
$row['actions'] = vk_api_authored_actions($auth, true, $visibility);

vk_api_ok(['document' => $row, 'message' => 'Document saved.'], 201);
