<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!canCreate('purchase_returns')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $pdo->beginTransaction();

    $returnId = $_POST['return_id'] ?? 0;
    
    // Only allow editing if pending (usually)
    // Check status
    $stmt = $pdo->prepare("SELECT status FROM purchase_returns WHERE purchase_return_id = ?");
    $stmt->execute([$returnId]);
    $status = $stmt->fetchColumn();
    
    if (!$status) {
        throw new Exception("Return record not found");
    }
    
    if ($status != 'pending') {
        throw new Exception("Cannot edit a return that is not pending");
    }

    // Update main record fields
    // Assuming we only allow editing details not header info like supplier? Or if needed add those.
    // Based on edit modal inputs: reason, reason_details, notes.
    // If more fields are editable, add them here.
    
    $reason = $_POST['reason'] ?? '';
    $reasonDetails = $_POST['reason_details'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $userId = $_SESSION['user_id'] ?? 0;

    $updateStmt = $pdo->prepare("
        UPDATE purchase_returns 
        SET reason = ?, reason_details = ?, notes = ?, updated_by = ?, updated_at = NOW()
        WHERE purchase_return_id = ?
    ");
    $updateStmt->execute([$reason, $reasonDetails, $notes, $userId, $returnId]);

    // Items update? The modal doesn't seem to have items editing in "Edit Return Modal" (id: editReturnModal)
    // It only shows fields: reason, reason_details, notes.
    // So we don't update items here unless the form includes them.
    // Looking at `purchase_returns.php`, the Edit Modal form HTML only has those 3 text fields.
    // So we skip item updates for this specific "Edit Return" feature unless requested.

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Purchase return updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
