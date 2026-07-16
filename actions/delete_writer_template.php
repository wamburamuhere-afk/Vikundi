<?php
// actions/delete_writer_template.php — remove a Document Writer template.
// Deleting a template never touches documents already written from it.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../includes/require_csrf.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

requirePermissionJson('delete', 'manage_documents');

$tpl_id = isset($_POST['tpl_id']) && ctype_digit((string) $_POST['tpl_id']) ? (int) $_POST['tpl_id'] : 0;
if ($tpl_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Kiolezo hakijapatikana.' : 'Template not found.']);
    exit;
}

$chk = $pdo->prepare("SELECT name FROM authored_document_templates WHERE id = ?");
$chk->execute([$tpl_id]);
$name = $chk->fetchColumn();
if ($name === false) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Kiolezo hakijapatikana.' : 'Template not found.']);
    exit;
}

$pdo->prepare("DELETE FROM authored_document_templates WHERE id = ?")->execute([$tpl_id]);
logDelete('Documents', "Deleted template: $name", "TPL#$tpl_id");

echo json_encode(['success' => true, 'message' => $is_sw ? 'Kiolezo kimefutwa.' : 'Template deleted.']);
