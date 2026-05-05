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
    // Start transaction
    $pdo->beginTransaction();

    // Get input data
    $supplierId = $_POST['supplier_id'] ?? null;
    $purchaseOrderId = !empty($_POST['purchase_order_id']) ? $_POST['purchase_order_id'] : null;
    $returnDate = $_POST['return_date'] ?? date('Y-m-d');
    $reason = $_POST['reason'] ?? '';
    $reasonDetails = $_POST['reason_details'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $items = $_POST['items'] ?? [];

    if (empty($supplierId) || empty($returnDate) || empty($items)) {
        throw new Exception("Please fill in all required fields and add at least one item.");
    }

    // Generate specific return number
    // Format: RET-YYYYMMDD-XXXX
    $prefix = 'RET-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_returns WHERE return_number LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = $stmt->fetchColumn();
    $returnNumber = $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

    // Insert Return Record
    $stmt = $pdo->prepare("
        INSERT INTO purchase_returns (
            supplier_id, purchase_order_id, return_number, return_date, 
            status, reason, reason_details, notes, created_by
        ) VALUES (
            ?, ?, ?, ?, 'pending', ?, ?, ?, ?
        )
    ");
    
    $userId = $_SESSION['user_id'] ?? 0;
    $stmt->execute([
        $supplierId, $purchaseOrderId, $returnNumber, $returnDate, 
        $reason, $reasonDetails, $notes, $userId
    ]);
    
    $returnId = $pdo->lastInsertId();

    // Insert Items
    $itemStmt = $pdo->prepare("
        INSERT INTO purchase_return_items (
            purchase_return_id, product_name, quantity, unit_price, item_reason
        ) VALUES (
            ?, ?, ?, ?, ?
        )
    ");

    foreach ($items as $item) {
        // Handle array structure from form serialization items[0][name] etc
        $productName = $item['name'] ?? '';
        $quantity = floatval($item['quantity'] ?? 0);
        $unitPrice = floatval($item['unit_price'] ?? 0);
        $itemReason = $item['item_reason'] ?? '';

        if (empty($productName) || $quantity <= 0) {
            continue;
        }

        // Note: Schema might vary, assuming product_name text based on JS form
        // If there's a product_id column, we might need adjustments, but assuming text for now as per previous code
        // Alternatively, check input: <input ... name="items[${index}][name]" placeholder="Product name">
        
        $itemStmt->execute([
            $returnId, $productName, $quantity, $unitPrice, $itemReason
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Purchase return created successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
