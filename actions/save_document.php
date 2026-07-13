<?php
// actions/save_document.php — create or update an authored document (letter /
// contract / notice). The rich-text body is sanitised before it is stored.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // must be logged in
require_once __DIR__ . '/../includes/require_csrf.php';  // valid CSRF token
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/document_sanitizer.php';

header('Content-Type: application/json');

$is_sw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$doc_id  = isset($_POST['doc_id']) && ctype_digit((string) $_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;

// Authorization: create vs edit.
requirePermissionJson($doc_id > 0 ? 'edit' : 'create', 'manage_documents');

$title = trim($_POST['title'] ?? '');
if ($title === '') {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Tafadhali weka kichwa cha nyaraka.' : 'Please enter a document title.']);
    exit;
}

$allowed_types  = ['letter', 'contract', 'notice', 'other'];
$doc_type       = in_array($_POST['doc_type'] ?? '', $allowed_types, true) ? $_POST['doc_type'] : 'letter';
$status         = ($_POST['status'] ?? 'draft') === 'final' ? 'final' : 'draft';
$use_letterhead = !empty($_POST['use_letterhead']) ? 1 : 0;
$body_html      = vk_sanitize_document_html($_POST['body_html'] ?? '');

try {
    if ($doc_id > 0) {
        $pdo->prepare("UPDATE authored_documents SET title=?, doc_type=?, body_html=?, use_letterhead=?, status=? WHERE id=?")
            ->execute([$title, $doc_type, $body_html, $use_letterhead, $status, $doc_id]);
        logUpdate('Documents', $title, "DOC#$doc_id");
        $id = $doc_id;
    } else {
        $pdo->prepare("INSERT INTO authored_documents (title, doc_type, body_html, use_letterhead, status, created_by) VALUES (?,?,?,?,?,?)")
            ->execute([$title, $doc_type, $body_html, $use_letterhead, $status, $user_id]);
        $id = (int) $pdo->lastInsertId();
        logCreate('Documents', $title, "DOC#$id");
    }
    echo json_encode(['success' => true, 'id' => $id, 'message' => $is_sw ? 'Nyaraka imehifadhiwa.' : 'Document saved.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
