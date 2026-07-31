<?php
// ajax/delete_collateral_document.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// The preceding check is authentication only, with no ownership test — it deletes
// any collateral_attachments row by id. That was harmless while this file fataled
// on an unresolvable roots.php require; correcting that path (previous commit)
// makes it reachable, so authorisation has to exist before it does. Keyed on the
// seeded loan_collaterals permission, which ordinary members do not hold.
requirePermissionJson('delete', 'loan_collaterals');

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit();
}

try {
    // Get file path first
    $stmt = $pdo->prepare("SELECT file_path FROM collateral_attachments WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if ($doc) {
        if (file_exists('../' . $doc['file_path'])) {
            unlink('../' . $doc['file_path']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM collateral_attachments WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
