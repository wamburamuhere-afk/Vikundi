<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    $id = intval($_GET['id'] ?? 0);

    if (!$id) {
        throw new Exception('Template ID is required');
    }

    $stmt = $pdo->prepare("SELECT * FROM document_templates WHERE id = ?");
    $stmt->execute([$id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        throw new Exception('Template not found');
    }

    echo json_encode(['success' => true, 'data' => $template]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
