<?php
// actions/delete_document.php — delete an authored document.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
$is_sw  = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$doc_id = isset($_POST['doc_id']) && ctype_digit((string) $_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;

requirePermissionJson('delete', 'manage_documents');

if ($doc_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.']);
    exit;
}

try {
    $t = $pdo->prepare("SELECT title FROM authored_documents WHERE id=?");
    $t->execute([$doc_id]);
    $title = (string) $t->fetchColumn();
    $pdo->prepare("DELETE FROM authored_documents WHERE id=?")->execute([$doc_id]);
    logDelete('Documents', $title, "DOC#$doc_id");
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Nyaraka imefutwa.' : 'Document deleted.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
