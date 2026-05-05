<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check user role (Admin, Manager, Purchasing can update status)
$role_stmt = $pdo->prepare("SELECT role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$role_stmt->execute([$_SESSION['user_id']]);
$user_role = $role_stmt->fetchColumn();

if (!in_array($user_role, ['Admin', 'Manager', 'Purchasing'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Get POST data
$supplier_id = $_POST['supplier_id'] ?? '';
$status = $_POST['status'] ?? '';

// Validate required fields
if (empty($supplier_id) || empty($status)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier ID and status are required']);
    exit();
}

// Validate status
$valid_statuses = ['active', 'inactive', 'suspended', 'blacklisted'];
if (!in_array($status, $valid_statuses)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

// Get supplier
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    exit();
}

// Check if there are pending orders when trying to deactivate
if (in_array($status, ['inactive', 'suspended', 'blacklisted']) && $supplier['status'] == 'active') {
    $orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status IN ('pending', 'ordered')");
    $orders_stmt->execute([$supplier_id]);
    $pending_orders = $orders_stmt->fetchColumn();
    
    if ($pending_orders > 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Cannot deactivate supplier with pending orders']);
        exit();
    }
}

// Update supplier status
$update_stmt = $pdo->prepare("
    UPDATE suppliers SET
        status = ?,
        updated_by = ?,
        updated_at = NOW()
    WHERE supplier_id = ?
");

try {
    $update_stmt->execute([$status, $_SESSION['user_id'], $supplier_id]);
    
    // Log the action
    $action = ($status == 'active') ? 'activate' : 
              (($status == 'inactive') ? 'deactivate' : 
              (($status == 'suspended') ? 'suspend' : 'blacklist'));
    
    $log_stmt = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, ip_address, user_agent, description) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $log_stmt->execute([
        $_SESSION['user_id'],
        $action . '_supplier',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        "Supplier $action: " . $supplier['supplier_name']
    ]);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => "Supplier status updated to $status"
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}