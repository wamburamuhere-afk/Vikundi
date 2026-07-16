<?php
// actions/save_writer_template.php — create / update a Document Writer template.
// The body is rich text from Summernote, so it is sanitised exactly like a
// document body before it is stored.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/document_sanitizer.php';

header('Content-Type: application/json');
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

$tpl_id = isset($_POST['tpl_id']) && ctype_digit((string) $_POST['tpl_id']) ? (int) $_POST['tpl_id'] : 0;
requirePermissionJson($tpl_id > 0 ? 'edit' : 'create', 'manage_documents');

$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '') {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Tafadhali weka jina.' : 'Please enter a name.']);
    exit;
}
$name = mb_substr($name, 0, 150);

$allowed_types  = ['letter', 'contract', 'notice', 'other'];
$doc_type       = in_array($_POST['doc_type'] ?? '', $allowed_types, true) ? $_POST['doc_type'] : 'letter';
$use_letterhead = (int) (($_POST['use_letterhead'] ?? '1') === '1');
$body_html      = vk_sanitize_document_html($_POST['body_html'] ?? '');
$user_id        = (int) ($_SESSION['user_id'] ?? 0);

if ($tpl_id > 0) {
    $pdo->prepare(
        "UPDATE authored_document_templates
            SET name = ?, doc_type = ?, body_html = ?, use_letterhead = ?
          WHERE id = ?"
    )->execute([$name, $doc_type, $body_html, $use_letterhead, $tpl_id]);
    logUpdate('Documents', "Updated template: $name", "TPL#$tpl_id");
    $msg = $is_sw ? 'Kiolezo kimesasishwa.' : 'Template updated.';
} else {
    $pdo->prepare(
        "INSERT INTO authored_document_templates (name, doc_type, body_html, use_letterhead, created_by)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$name, $doc_type, $body_html, $use_letterhead, $user_id]);
    $tpl_id = (int) $pdo->lastInsertId();
    logCreate('Documents', "Created template: $name", "TPL#$tpl_id");
    $msg = $is_sw ? 'Kiolezo kimehifadhiwa.' : 'Template saved.';
}

echo json_encode(['success' => true, 'tpl_id' => $tpl_id, 'message' => $msg]);
