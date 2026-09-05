<?php
// actions/delete_document.php — delete an authored document.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
$is_sw   = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$doc_id  = isset($_POST['doc_id']) && ctype_digit((string) $_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;

requirePermissionJson('delete', 'manage_documents');

if ($doc_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.']);
    exit;
}

try {
    $cur = $pdo->prepare("SELECT title, created_by, visibility FROM authored_documents WHERE id=?");
    $cur->execute([$doc_id]);
    $cur = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$cur) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Nyaraka haijapatikana.' : 'Document not found.']);
        exit;
    }

    // Same rule as actions/save_document.php: someone else's private document
    // is not theirs to touch, delete-rights on manage_documents notwithstanding.
    $is_author = (int) $cur['created_by'] === $user_id;
    $is_admin  = isAdmin();
    if ($cur['visibility'] === 'private' && !$is_author && !$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Huna ruhusa ya kufuta nyaraka hii.' : 'You do not have permission to delete this document.']);
        exit;
    }

    $pdo->prepare("DELETE FROM authored_documents WHERE id=?")->execute([$doc_id]);
    logDelete('Documents', $cur['title'], "DOC#$doc_id");
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Nyaraka imefutwa.' : 'Document deleted.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
