<?php
require_once __DIR__ . '/../../roots.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../../includes/require_auth.php';
requirePermissionJson('view', 'chart_of_accounts');
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

try {
    $categoryType = $_GET['type'] ?? '';
    
    $query = "
        SELECT 
            category_id,
            category_name,
            category_type,
            parent_category_id
        FROM account_categories
        WHERE category_type = ?
        ORDER BY category_name
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$categoryType]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);
    
} catch (Exception $e) {
    error_log('get_categories_by_type.php: ' . $e->getMessage()); // SEC-018
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.'
    ]);
}
?>
