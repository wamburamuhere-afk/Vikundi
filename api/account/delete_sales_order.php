<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check permissions
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

try {
    global $pdo;
    
    $order_id = $_POST['order_id'] ?? 0;

    if (!$order_id) {
        throw new Exception("Missing order ID");
    }

    // Only allow deletion of drafts (as per sales_orders.php logic)
    $stmt = $pdo->prepare("SELECT status FROM sales_orders WHERE sales_order_id = ?");
    $stmt->execute([$order_id]);
    $status = $stmt->fetchColumn();

    if ($status !== 'draft') {
        throw new Exception("Only draft orders can be deleted");
    }

    $pdo->beginTransaction();

    // Delete items first
    $pdo->prepare("DELETE FROM sales_order_items WHERE order_id = ?")->execute([$order_id]);
    
    // Delete order
    $pdo->prepare("DELETE FROM sales_orders WHERE sales_order_id = ?")->execute([$order_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
