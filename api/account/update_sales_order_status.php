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
if (!isAdmin() && !in_array($_SESSION['role_name'] ?? '', ['Manager', 'Sales'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

try {
    global $pdo;
    
    $order_id = $_POST['order_id'] ?? 0;
    $status = $_POST['status'] ?? '';

    if (!$order_id || !$status) {
        throw new Exception("Missing order ID or status");
    }

    $valid_statuses = ['draft', 'pending', 'approved', 'processing', 'delivered', 'completed', 'cancelled'];
    if (!in_array($status, $valid_statuses)) {
        throw new Exception("Invalid status");
    }

    $stmt = $pdo->prepare("UPDATE sales_orders SET status = ?, updated_at = NOW(), updated_by = ? WHERE sales_order_id = ?");
    $stmt->execute([$status, $_SESSION['user_id'], $order_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Order not found or status already set");
    }

    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
