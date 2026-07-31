<?php
require_once __DIR__ . '/../../roots.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../../includes/require_auth.php';
requirePermissionJson('view', 'chart_of_accounts');
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

try {
    $categoryId = $_GET['category_id'] ?? null;
    
    if (!$categoryId) {
        throw new Exception('Category ID is required');
    }
    
    $query = "
        SELECT 
            c.*,
            at.type_name as category_type,
            p.category_name as parent_category_name,
            (SELECT COUNT(*) FROM accounts WHERE category_id = c.category_id) as account_count,
            (SELECT COUNT(*) FROM account_categories WHERE parent_category_id = c.category_id) as subcategory_count
        FROM account_categories c
        LEFT JOIN account_categories p ON c.parent_category_id = p.category_id
        LEFT JOIN account_types at ON c.account_type_id = at.type_id
        WHERE c.category_id = ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$category) {
        throw new Exception('Category not found');
    }
    
    echo json_encode([
        'success' => true,
        'category' => $category
    ]);
    
} catch (Exception $e) {
    error_log('get_category_details.php: ' . $e->getMessage()); // SEC-018
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.'
    ]);
}
?>
