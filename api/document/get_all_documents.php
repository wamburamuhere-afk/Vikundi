<?php
require_once __DIR__ . '/../../roots.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../../includes/require_auth.php';
requirePermissionJson('view', 'document_workflow');
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("
        SELECT id, document_name, file_type 
        FROM documents 
        ORDER BY document_name ASC
    ");
    $stmt->execute();
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    error_log('get_all_documents.php: ' . $e->getMessage()); // SEC-018
    echo json_encode(['error' => 'An unexpected error occurred. Please try again.']);
}
