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
$visibility     = ($_POST['visibility'] ?? 'shared') === 'private' ? 'private' : 'shared';
$use_letterhead = !empty($_POST['use_letterhead']) ? 1 : 0;
$body_html      = vk_sanitize_document_html($_POST['body_html'] ?? '');

try {
    if ($doc_id > 0) {
        $cur = $pdo->prepare("SELECT created_by, visibility FROM authored_documents WHERE id = ?");
        $cur->execute([$doc_id]);
        $cur = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            echo json_encode(['success' => false, 'message' => $is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.']);
            exit;
        }
        $is_author = (int) $cur['created_by'] === $user_id;
        $is_admin  = isAdmin();

        // Someone else's private document is not theirs to edit.
        if ($cur['visibility'] === 'private' && !$is_author && !$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $is_sw ? 'Huna ruhusa ya kuhariri nyaraka hii.' : 'You do not have permission to edit this document.']);
            exit;
        }
        // Only the author (or an admin) may change visibility — another leadership
        // user editing a shared document leaves that setting as it was.
        $new_visibility = ($is_author || $is_admin) ? $visibility : $cur['visibility'];

        $pdo->prepare("UPDATE authored_documents SET title=?, doc_type=?, body_html=?, use_letterhead=?, status=?, visibility=? WHERE id=?")
            ->execute([$title, $doc_type, $body_html, $use_letterhead, $status, $new_visibility, $doc_id]);
        logUpdate('Documents', $title, "DOC#$doc_id");
        $id = $doc_id;
    } else {
        $pdo->prepare("INSERT INTO authored_documents (title, doc_type, body_html, use_letterhead, status, visibility, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$title, $doc_type, $body_html, $use_letterhead, $status, $visibility, $user_id]);
        $id = (int) $pdo->lastInsertId();
        logCreate('Documents', $title, "DOC#$id");
    }
    echo json_encode(['success' => true, 'id' => $id, 'message' => $is_sw ? 'Nyaraka imehifadhiwa.' : 'Document saved.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
