<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check user role (Only Admin can delete suppliers)
$role_stmt = $pdo->prepare("SELECT role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$role_stmt->execute([$_SESSION['user_id']]);
$user_role = $role_stmt->fetchColumn();

if ($user_role != 'Admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only administrators can delete suppliers']);
    exit();
}

// Get POST data
$supplier_id = $_POST['supplier_id'] ?? '';

if (empty($supplier_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
    exit();
}

// Get supplier details for logging
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    exit();
}

// Check if supplier has associated records
$orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
$orders_stmt->execute([$supplier_id]);
$order_count = $orders_stmt->fetchColumn();

$payments_stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_payments WHERE supplier_id = ?");
$payments_stmt->execute([$supplier_id]);
$payment_count = $payments_stmt->fetchColumn();

if ($order_count > 0 || $payment_count > 0) {
    // Soft delete (change status to deleted)
    $delete_stmt = $pdo->prepare("UPDATE suppliers SET status = 'deleted', updated_by = ?, updated_at = NOW() WHERE supplier_id = ?");
    
    try {
        $delete_stmt->execute([$_SESSION['user_id'], $supplier_id]);
        
        // Log the action
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, ip_address, user_agent, description) 
            VALUES (?, 'soft_delete_supplier', ?, ?, ?)
        ");
        $log_stmt->execute([
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            "Soft deleted supplier: " . $supplier['supplier_name'] . " (has associated records)"
        ]);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Supplier marked as deleted (soft delete due to associated records)'
        ]);
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    // Hard delete (remove from database)
    $delete_stmt = $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
    
    try {
        $delete_stmt->execute([$supplier_id]);
        
        // Log the action
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, ip_address, user_agent, description) 
            VALUES (?, 'delete_supplier', ?, ?, ?)
        ");
        $log_stmt->execute([
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            "Deleted supplier: " . $supplier['supplier_name']
        ]);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => 'Supplier permanently deleted'
        ]);
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}