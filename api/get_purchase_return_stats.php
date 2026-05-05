<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// Check permission
if (!hasPermission('purchase_returns')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

try {
    $stats = [
        'total_returns' => 0,
        'pending' => 0,
        'approved' => 0,
        'completed' => 0,
        'rejected' => 0,
        'total_value' => 0
    ];

    // Count by status
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as count,
            status 
        FROM purchase_returns 
        GROUP BY status
    ");
    $status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats['pending'] = $status_counts['pending'] ?? 0;
    $stats['approved'] = $status_counts['approved'] ?? 0;
    $stats['completed'] = $status_counts['completed'] ?? 0;
    $stats['rejected'] = $status_counts['rejected'] ?? 0;
    
    // Total Returns
    $stats['total_returns'] = array_sum($stats) - ($stats['total_value'] ?? 0); // Oops, total value is not a count.
    $stats['total_returns'] = 0;
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM purchase_returns");
    $stats['total_returns'] = $count_stmt->fetchColumn();

    // Total Value
    // We need to join items to calculate value
    $value_stmt = $pdo->query("
        SELECT SUM(pri.quantity * pri.unit_price) 
        FROM purchase_return_items pri
        JOIN purchase_returns pr ON pri.purchase_return_id = pr.purchase_return_id
        WHERE pr.status != 'cancelled'
    ");
    $stats['total_value'] = floatval($value_stmt->fetchColumn());

    echo json_encode(['success' => true, 'data' => $stats]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
