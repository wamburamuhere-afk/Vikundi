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

// Logic to check create/edit permissions
if (!isAdmin() && !in_array($_SESSION['role_name'] ?? '', ['Manager', 'Purchasing', 'Accountant'])) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'Access Denied']);
     exit;
}

try {
    global $pdo;
    $pdo->beginTransaction();

    $purchase_order_id = isset($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : 0;
    $supplier_id = $_POST['supplier_id'] ?? 0;
    $order_date = $_POST['order_date'] ?? '';
    $expected_date = $_POST['expected_delivery_date'] ?? null;
    $warehouse_id = $_POST['warehouse_id'] ?? 0;
    $currency = $_POST['currency'] ?? 'TZS';
    $payment_terms = $_POST['payment_terms'] ?? '';
    $shipping_address = $_POST['shipping_address'] ?? ''; // Added field if available
    $shipping_method = $_POST['shipping_method_id'] ?? ''; // or name
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'draft'; // draft, pending, approved
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);

    if (empty($supplier_id) || empty($order_date) || empty($items)) {
        throw new Exception("Missing required fields (Supplier, Date, or Items)");
    }

    // Calculate totals based on actual items
    $subtotal = 0;
    $tax_total = 0;
    
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $price = floatval($item['unit_price'] ?? 0);
        $tax_rate_percentage = 0;
        
        if (!empty($item['tax_rate_id'])) {
            $tax_stmt = $pdo->prepare("SELECT rate_percentage FROM tax_rates WHERE rate_id = ?");
            $tax_stmt->execute([$item['tax_rate_id']]);
            $tax_rate_percentage = floatval($tax_stmt->fetchColumn());
        }
        
        $line_subtotal = $qty * $price;
        $line_tax = $line_subtotal * ($tax_rate_percentage / 100);
        
        $subtotal += $line_subtotal;
        $tax_total += $line_tax;
    }
    
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $grand_total = $subtotal + $tax_total + $shipping_cost;

    if ($purchase_order_id > 0) {
        // Update existing
        $stmt = $pdo->prepare("
            UPDATE purchase_orders SET 
                supplier_id = ?, order_date = ?, expected_date = ?, 
                total_amount = ?, tax_amount = ?, grand_total = ?,
                currency = ?, payment_terms = ?, shipping_method = ?, notes = ?, 
                status = ?, updated_at = NOW()
            WHERE purchase_order_id = ?
        ");
        $stmt->execute([
            $supplier_id, $order_date, $expected_date,
            $subtotal, $tax_total, $grand_total,
            $currency, $payment_terms, $shipping_method, $notes,
            $status, $purchase_order_id
        ]);
        
        // Clear existing items
        $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$purchase_order_id]);
        
    } else {
        // Generate order number
        $stmt = $pdo->query("SELECT MAX(purchase_order_id) FROM purchase_orders");
        $max_id = $stmt->fetchColumn();
        $order_number = 'PO-' . date('Ymd') . '-' . str_pad(($max_id + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO purchase_orders (
                order_number, supplier_id, order_date, expected_date,
                total_amount, tax_amount, grand_total,
                currency, payment_terms, shipping_method, notes,
                status, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $order_number, $supplier_id, $order_date, $expected_date,
            $subtotal, $tax_total, $grand_total,
            $currency, $payment_terms, $shipping_method, $notes,
            $status, $_SESSION['user_id']
        ]);
        $purchase_order_id = $pdo->lastInsertId();
    }

    // Insert Items
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $price = floatval($item['unit_price'] ?? 0);
        
        // Get product details if not provided
        $product_name = $item['product_name'] ?? '';
        if (empty($product_name) && !empty($item['product_id'])) {
            $p_stmt = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
            $p_stmt->execute([$item['product_id']]);
            $product_name = $p_stmt->fetchColumn();
        }

        $tax_rate_percentage = 0;
        if (!empty($item['tax_rate_id'])) {
            $tax_stmt = $pdo->prepare("SELECT rate_percentage FROM tax_rates WHERE rate_id = ?");
            $tax_stmt->execute([$item['tax_rate_id']]);
            $tax_rate_percentage = floatval($tax_stmt->fetchColumn());
        }

        $line_subtotal = $qty * $price;
        $line_tax = $line_subtotal * ($tax_rate_percentage / 100);
        $line_total = $line_subtotal + $line_tax;

        $itemStmt = $pdo->prepare("
            INSERT INTO purchase_order_items (
                purchase_order_id, product_id, item_name, quantity, 
                unit_price, tax_rate, tax_amount, line_total, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $itemStmt->execute([
            $purchase_order_id, $item['product_id'] ?: null, $product_name,
            $qty, $price, $tax_rate_percentage, $line_tax, $line_total
        ]);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => 'Purchase Order saved successfully', 
        'purchase_order_id' => $purchase_order_id,
        'order_number' => $order_number ?? ''
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving purchase order: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
