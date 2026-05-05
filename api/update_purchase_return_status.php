<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!canCreate('purchase_returns')) { // Or specific permission like 'approve_purchase_returns'
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $returnId = $_POST['return_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $allowed_statuses = ['pending', 'approved', 'rejected', 'completed', 'cancelled'];
    
    if (!$returnId || !in_array($status, $allowed_statuses)) {
        throw new Exception("Invalid parameters");
    }

    $pdo->beginTransaction();

    $userId = $_SESSION['user_id'] ?? 0;

    // Update status
    $stmt = $pdo->prepare("
        UPDATE purchase_returns 
        SET status = ?, updated_by = ?, updated_at = NOW() 
        WHERE purchase_return_id = ?
    ");
    $stmt->execute([$status, $userId, $returnId]);

    // If implementing stock adjustments, handle it here on 'approved' or 'completed'
    // For now, simple status update.

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Return status updated to ' . ucfirst($status)]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
