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
if (!isAdmin() && !in_array($_SESSION['role_name'] ?? '', ['Manager', 'Accountant', 'Purchasing'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

try {
    global $pdo;

    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $supplier_filter = intval($_GET['supplier'] ?? 0);
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

    // Build query
    $query = "
        SELECT 
            pr.*,
            s.supplier_name,
            s.company_name,
            po.order_number as po_number,
            u1.username as created_by_name,
            u2.username as updated_by_name,
            COUNT(pri.return_item_id) as item_count,
            SUM(pri.quantity * pri.unit_price) as calculated_total
        FROM purchase_returns pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        LEFT JOIN purchase_return_items pri ON pr.purchase_return_id = pri.purchase_return_id
        LEFT JOIN users u1 ON pr.created_by = u1.user_id
        LEFT JOIN users u2 ON pr.updated_by = u2.user_id
        WHERE 1=1
    ";

    $params = [];

    if (!empty($status_filter)) {
        $query .= " AND pr.status = ?";
        $params[] = $status_filter;
    }

    if ($supplier_filter > 0) {
        $query .= " AND pr.supplier_id = ?";
        $params[] = $supplier_filter;
    }

    if (!empty($date_from)) {
        $query .= " AND pr.return_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $query .= " AND pr.return_date <= ?";
        $params[] = $date_to;
    }

    $query .= " GROUP BY pr.purchase_return_id ORDER BY pr.return_date DESC, pr.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $total_returns = count($returns);
    $total_amount = array_sum(array_column($returns, 'total_amount'));
    
    $status_counts = [
        'pending' => 0,
        'approved' => 0,
        'completed' => 0,
        'rejected' => 0
    ];

    foreach ($returns as $return) {
        if (isset($status_counts[$return['status']])) {
            $status_counts[$return['status']]++;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $returns,
        'stats' => [
            'total_returns' => $total_returns,
            'total_amount' => $total_amount,
            'pending_count' => $status_counts['pending'],
            'approved_count' => $status_counts['approved'],
            'completed_count' => $status_counts['completed'],
            'rejected_count' => $status_counts['rejected']
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching purchase returns: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
