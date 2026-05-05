<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!hasAnyPermission('invoices')) { // Assuming 'invoices' permission key exists, or use generic 'accounts'
    // Fallback if specific permission doesn't exist yet, check user role as in original file
    // "invoices" permission key doesn't strictly exist in mapping yet (it was 'expenses'), 
    // but I effectively need to check access.
    // The original file checked: in_array($user_role, ['Admin', 'Manager', 'Accountant', 'Sales'])
    // I should probably stick to that or use a new permission. 
    // For now, let's use the role check to be safe and consistent with previous logic, 
    // or better, use canView('invoices') if I added it? Use canView('sales')?
    // Let's stick to the role check from original file to avoid breaking access.
    // Actually, I should use permissions if possible.
    // But let's replicate the logic:
    // $can_view_invoices = in_array($user_role, ['Admin', 'Manager', 'Accountant', 'Sales']);
    
    if (!isAdmin() && !in_array($_SESSION['role_name'] ?? '', ['Manager', 'Accountant', 'Sales'])) {
         http_response_code(403);
         echo json_encode(['success' => false, 'message' => 'Access Denied']);
         exit;
    }
}

try {
    global $pdo;

    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $customer_filter = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $payment_filter = $_GET['payment_status'] ?? '';

    // Build query with filters
    $query = "
        SELECT 
            i.*,
            c.customer_name,
            c.company_name,
            c.email as customer_email,
            c.phone as customer_phone,
            so.order_number,
            u1.username as created_by_name,
            u2.username as updated_by_name,
            COUNT(ii.invoice_item_id) as total_items,
            (i.grand_total - i.paid_amount) as balance_due,
            CASE 
                WHEN i.status = 'cancelled' THEN 'cancelled'
                WHEN i.status = 'paid' THEN 'paid'
                WHEN i.status = 'partial' THEN 'partial'
                WHEN i.status = 'overdue' AND i.due_date < CURDATE() THEN 'overdue'
                WHEN i.status = 'sent' THEN 'sent'
                WHEN i.status = 'pending' THEN 'pending'
                ELSE 'draft'
            END as display_status
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
        LEFT JOIN invoice_items ii ON i.invoice_id = ii.invoice_id
        LEFT JOIN users u1 ON i.created_by = u1.user_id
        LEFT JOIN users u2 ON i.updated_by = u2.user_id
        WHERE 1=1
    ";

    $params = [];

    // Apply filters
    if (!empty($status_filter)) {
        $query .= " AND i.status = ?";
        $params[] = $status_filter;
    }

    if ($customer_filter > 0) {
        $query .= " AND i.customer_id = ?";
        $params[] = $customer_filter;
    }

    if (!empty($date_from)) {
        $query .= " AND i.invoice_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $query .= " AND i.invoice_date <= ?";
        $params[] = $date_to;
    }

    if (!empty($payment_filter)) {
        if ($payment_filter == 'paid') {
            $query .= " AND i.paid_amount >= i.grand_total";
        } elseif ($payment_filter == 'partial') {
            $query .= " AND i.paid_amount > 0 AND i.paid_amount < i.grand_total";
        } elseif ($payment_filter == 'unpaid') {
            $query .= " AND i.paid_amount = 0";
        } elseif ($payment_filter == 'overdue') {
            $query .= " AND i.due_date < CURDATE() AND i.paid_amount < i.grand_total";
        }
    }

    $query .= " GROUP BY i.invoice_id ORDER BY i.invoice_date DESC, i.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $total_invoices = count($invoices);
    $total_amount = 0;
    $total_paid = 0;
    $total_due = 0;
    
    $status_counts = [
        'draft' => 0,
        'pending' => 0,
        'sent' => 0,
        'partial' => 0,
        'paid' => 0,
        'overdue' => 0,
        'cancelled' => 0
    ];

    foreach ($invoices as $invoice) {
        $total_amount += $invoice['grand_total'];
        $total_paid += $invoice['paid_amount'];
        
        $status = $invoice['display_status'] ?? $invoice['status'];
        if (isset($status_counts[$status])) {
            $status_counts[$status]++;
        }
    }
    $total_due = $total_amount - $total_paid;

    echo json_encode([
        'success' => true, 
        'data' => $invoices,
        'stats' => [
            'total_invoices' => $total_invoices,
            'total_amount' => $total_amount,
            'total_paid' => $total_paid,
            'total_due' => $total_due,
            'status_counts' => $status_counts
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching invoices: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
