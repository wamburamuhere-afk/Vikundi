<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permissions
if (!isAdmin() && !in_array($_SESSION['role_name'] ?? '', ['Manager', 'Accountant', 'Sales'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

try {
    global $pdo;

    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $customer_filter = intval($_GET['customer'] ?? 0);
    $salesperson_filter = intval($_GET['salesperson'] ?? 0);
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

    // Build query
    $query = "
        SELECT 
            so.sales_order_id, so.order_number, so.customer_id, so.order_date, so.delivery_date, 
            so.status, so.grand_total, so.tax_amount, so.discount_amount, so.shipping_cost,
            so.currency, so.payment_terms, so.reference, so.total_ordered, so.notes,
            so.total_delivered, so.total_invoiced,
            c.customer_name,
            c.company_name,
            c.phone as customer_phone,
            c.email as customer_email,
            u1.username as created_by_name,
            u2.username as salesperson_name,
            u3.username as updated_by_name,
            (SELECT COUNT(*) FROM sales_order_items WHERE order_id = so.sales_order_id) as total_items,
            COUNT(DISTINCT i.invoice_id) as invoice_count,
            COALESCE(SUM(p.amount), 0) as total_paid,
            CASE 
                WHEN so.status = 'cancelled' THEN 'cancelled'
                WHEN so.status = 'completed' THEN 'completed'
                WHEN so.status = 'delivered' THEN 'delivered'
                WHEN so.total_delivered > 0 AND so.total_delivered < so.total_ordered THEN 'partially_delivered'
                WHEN so.status = 'approved' THEN 'approved'
                WHEN so.status = 'pending' THEN 'pending'
                ELSE 'draft'
            END as display_status
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.customer_id
        LEFT JOIN users u1 ON so.created_by = u1.user_id
        LEFT JOIN users u2 ON so.salesperson_id = u2.user_id
        LEFT JOIN users u3 ON so.updated_by = u3.user_id
        LEFT JOIN invoices i ON so.sales_order_id  = i.order_id AND i.status != 'cancelled'
        LEFT JOIN payments p ON i.invoice_id = p.invoice_id AND p.status = 'completed'
        WHERE 1=1
    ";

    $params = [];

    if (!empty($status_filter)) {
        if ($status_filter === 'partially_delivered') {
            $query .= " AND so.total_delivered > 0 AND so.total_delivered < so.total_ordered";
        } else {
            $query .= " AND so.status = ?";
            $params[] = $status_filter;
        }
    }

    if ($customer_filter > 0) {
        $query .= " AND so.customer_id = ?";
        $params[] = $customer_filter;
    }

    if ($salesperson_filter > 0) {
        $query .= " AND so.salesperson_id = ?";
        $params[] = $salesperson_filter;
    }

    if (!empty($date_from)) {
        $query .= " AND so.order_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $query .= " AND so.order_date <= ?";
        $params[] = $date_to;
    }

    $query .= " GROUP BY so.sales_order_id ORDER BY so.order_date DESC, so.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $total_orders = count($orders);
    $total_value = array_sum(array_column($orders, 'grand_total'));
    
    $status_counts = [
        'pending' => 0,
        'approved' => 0,
        'completed' => 0,
        'processing' => 0,
        'draft' => 0
    ];

    foreach ($orders as $order) {
        $status = $order['display_status'] ?? $order['status'];
        if (isset($status_counts[$status])) {
            $status_counts[$status]++;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $orders,
        'stats' => [
            'total_orders' => $total_orders,
            'total_value' => $total_value,
            'pending_count' => $status_counts['pending'] ?? 0,
            'approved_count' => $status_counts['approved'] ?? 0,
            'completed_count' => $status_counts['completed'] ?? 0,
            'processing_count' => $status_counts['processing'] ?? 0,
            'draft_count' => $status_counts['draft'] ?? 0
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching sales orders: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
