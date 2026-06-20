<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/includes/activity_logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if (!canDelete('document_templates')) {
        http_response_code(403);
        throw new Exception('Access denied: cannot delete document templates');
    }

    $id = intval($_POST['id'] ?? 0);
    if (!$id) throw new Exception('Template ID is required');

    $stmt = $pdo->prepare("SELECT template_name, file_path FROM document_templates WHERE id = ?");
    $stmt->execute([$id]);
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tpl) throw new Exception('Template not found');

    if ($tpl['file_path'] && file_exists(ROOT_DIR . '/' . $tpl['file_path'])) {
        unlink(ROOT_DIR . '/' . $tpl['file_path']);
    }

    $pdo->prepare("DELETE FROM document_templates WHERE id = ?")->execute([$id]);

    logDelete('Document Templates', $tpl['template_name'] ?? "ID $id", 'TMPL#' . $id);

    echo json_encode(['success' => true, 'message' => 'Template deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
