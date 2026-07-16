<?php
// api/get_writer_template.php — return one Document Writer template so the editor
// can pre-fill a new document from it.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/document_sanitizer.php';

global $pdo;
header('Content-Type: application/json');

requirePermissionJson('view', 'manage_documents');

$tpl_id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
if ($tpl_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Template not found.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, doc_type, body_html, use_letterhead FROM authored_document_templates WHERE id = ?");
$stmt->execute([$tpl_id]);
$tpl = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tpl) {
    echo json_encode(['status' => 'error', 'message' => 'Template not found.']);
    exit;
}

// The body was sanitised on save; sanitise again on the way out so a row written
// by any other path can never inject into the editor.
$tpl['body_html']      = vk_sanitize_document_html((string) $tpl['body_html']);
$tpl['use_letterhead'] = (int) $tpl['use_letterhead'];

echo json_encode(['status' => 'success', 'data' => $tpl]);
