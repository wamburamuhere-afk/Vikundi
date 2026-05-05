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
    $pdo->beginTransaction();

    $sales_order_id = isset($_POST['sales_order_id']) ? intval($_POST['sales_order_id']) : 0;
    $customer_id = $_POST['customer_id'] ?? 0;
    $order_date = $_POST['order_date'] ?? '';
    $delivery_date = $_POST['delivery_date'] ?? null;
    $salesperson_id = $_POST['salesperson_id'] ?? $_SESSION['user_id'];
    $currency = $_POST['currency'] ?? 'TZS';
    $payment_terms = $_POST['payment_terms'] ?? '';
    $reference = $_POST['reference'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $terms_conditions = $_POST['terms_conditions'] ?? '';
    $status = $_POST['status'] ?? 'draft';
    $is_quote = isset($_POST['is_quote']) && $_POST['is_quote'] == '1' ? 1 : 0;
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);

    if (empty($customer_id) || empty($order_date) || empty($items)) {
        throw new Exception("Missing required fields (Customer, Date, or Items)");
    }

    // Calculate totals
    $subtotal = 0;
    $tax_total = 0;
    $total_ordered = 0;
    
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $price = floatval($item['unit_price'] ?? 0);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        $discount_percent = floatval($item['discount_percent'] ?? 0);
        
        $line_subtotal = $qty * $price;
        $discount_amount = $line_subtotal * ($discount_percent / 100);
        $taxable_amount = $line_subtotal - $discount_amount;
        $line_tax = $taxable_amount * ($tax_rate / 100);
        
        $subtotal += $line_subtotal; // Subtotal before discount
        $tax_total += $line_tax;
        $total_ordered += $qty;
    }
    
    $shipping_cost = floatval($_POST['shipping_cost'] ?? 0);
    $discount_amount_total = array_reduce($items, function($carry, $item) {
        $line_subtotal = floatval($item['quantity'] ?? 0) * floatval($item['unit_price'] ?? 0);
        return $carry + ($line_subtotal * (floatval($item['discount_percent'] ?? 0) / 100));
    }, 0);
    
    $grand_total = ($subtotal - $discount_amount_total) + $tax_total + $shipping_cost;

    if ($sales_order_id > 0) {
        // Update
        $stmt = $pdo->prepare("
            UPDATE sales_orders SET 
                customer_id = ?, order_date = ?, delivery_date = ?, salesperson_id = ?,
                currency = ?, payment_terms = ?, reference = ?, 
                subtotal = ?, tax_amount = ?, discount_amount = ?, shipping_cost = ?, grand_total = ?,
                total_ordered = ?, notes = ?, terms_conditions = ?, status = ?, is_quote = ?, 
                updated_at = NOW(), updated_by = ?
            WHERE sales_order_id = ?
        ");
        $stmt->execute([
            $customer_id, $order_date, $delivery_date, $salesperson_id,
            $currency, $payment_terms, $reference,
            $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
            $total_ordered, $notes, $terms_conditions, $status, $is_quote,
            $_SESSION['user_id'], $sales_order_id
        ]);
        
        $pdo->prepare("DELETE FROM sales_order_items WHERE order_id = ?")->execute([$sales_order_id]);
    } else {
        // Insert
        $stmt = $pdo->query("SELECT MAX(sales_order_id) FROM sales_orders");
        $max_id = $stmt->fetchColumn();
        $prefix = $is_quote ? 'QT' : 'SO';
        $order_number = $prefix . '-' . date('Ymd') . '-' . str_pad(($max_id + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO sales_orders (
                order_number, customer_id, order_date, delivery_date, salesperson_id,
                currency, payment_terms, reference,
                subtotal, tax_amount, discount_amount, shipping_cost, grand_total,
                total_ordered, notes, terms_conditions, status, is_quote,
                created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $order_number, $customer_id, $order_date, $delivery_date, $salesperson_id,
            $currency, $payment_terms, $reference,
            $subtotal, $tax_total, $discount_amount_total, $shipping_cost, $grand_total,
            $total_ordered, $notes, $terms_conditions, $status, $is_quote,
            $_SESSION['user_id']
        ]);
        $sales_order_id = $pdo->lastInsertId();
    }

    // Insert Items
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 1);
        $price = floatval($item['unit_price'] ?? 0);
        $tax_rate = floatval($item['tax_rate'] ?? 0);
        $discount_percent = floatval($item['discount_percent'] ?? 0);
        
        $line_subtotal = $qty * $price;
        $discount_amount = $line_subtotal * ($discount_percent / 100);
        $taxable_amount = $line_subtotal - $discount_amount;
        $line_tax = $taxable_amount * ($tax_rate / 100);
        $line_total = $taxable_amount + $line_tax;

        $itemStmt = $pdo->prepare("
            INSERT INTO sales_order_items (
                order_id, product_id, product_name, sku, quantity, unit,
                unit_price, tax_rate, discount_percent, line_total, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $itemStmt->execute([
            $sales_order_id, $item['product_id'] ?: null, $item['product_name'], $item['sku'],
            $qty, $item['unit'] ?? 'pcs', $price, $tax_rate, $discount_percent, $line_total
        ]);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => 'Sales Order saved successfully', 
        'order_id' => $sales_order_id,
        'order_number' => $order_number ?? ''
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error saving sales order: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
