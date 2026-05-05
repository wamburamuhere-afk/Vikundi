<?php
// File: api/create_stock_adjustment.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Check permission
$allowed_roles = ['Admin', 'Manager', 'Inventory'];
if (!in_array($user_role, $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    // Get product details
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$_POST['product_id']]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new Exception('Product not found');
    }
    
    // Get current stock
    $stmt = $pdo->prepare("
        SELECT COALESCE(stock_quantity, 0) as stock_quantity,
               COALESCE(reserved_quantity, 0) as reserved_quantity
        FROM product_stocks 
        WHERE product_id = ? AND warehouse_id = ?
    ");
    $stmt->execute([$_POST['product_id'], $_POST['warehouse_id']]);
    $stock_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $current_stock = $stock_data ? $stock_data['stock_quantity'] : 0;
    $reserved_stock = $stock_data ? $stock_data['reserved_quantity'] : 0;
    $available_stock = $current_stock - $reserved_stock;
    
    // Calculate new stock
    $quantity = floatval($_POST['quantity']);
    $movement_type = $_POST['movement_type'];
    
    if (in_array($movement_type, ['adjustment_in', 'found'])) {
        $new_stock = $current_stock + $quantity;
    } else {
        $new_stock = $current_stock - $quantity;
    }
    
    // Unit cost - use provided or product cost
    $unit_cost = floatval($_POST['unit_cost']) > 0 ? floatval($_POST['unit_cost']) : floatval($product['cost_price']);
    
    // Generate reference number if not provided
    $reference_number = !empty($_POST['reference_number']) ? $_POST['reference_number'] : 
                        'ADJ-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    // Insert stock movement
    $stmt = $pdo->prepare("
        INSERT INTO stock_movements (
            product_id, movement_type, quantity, unit, unit_cost,
            reference_type, reference_number, warehouse_id,
            stock_before, stock_after, reason, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, 'manual', ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $_POST['product_id'],
        $movement_type,
        $quantity,
        $product['unit'],
        $unit_cost,
        $reference_number,
        $_POST['warehouse_id'],
        $current_stock,
        $new_stock,
        $_POST['reason'],
        $_POST['notes'] ?? '',
        $user_id
    ]);
    
    // Update product_stocks
    $stmt = $pdo->prepare("
        INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity)
    ");
    
    $stmt->execute([
        $_POST['product_id'],
        $_POST['warehouse_id'],
        $new_stock,
        $reserved_stock
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Stock adjustment recorded successfully',
        'reference_number' => $reference_number
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>