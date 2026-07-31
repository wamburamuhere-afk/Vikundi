<?php
require_once __DIR__ . '/../../roots.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../../includes/require_auth.php';
requirePermissionJson('view', 'chart_of_accounts');
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

try {
    $category_id = $_GET['category_id'] ?? 0;
    
    if (!$category_id) {
        throw new Exception('Category ID is required');
    }

    $stmt = $pdo->prepare("
        SELECT 
            c.*, 
            at.type_name as category_type
        FROM account_categories c
        LEFT JOIN account_types at ON c.account_type_id = at.type_id
        WHERE c.category_id = ?
    ");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($category) {
        echo json_encode(['success' => true, 'category' => $category]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Category not found']);
    }
} catch (Exception $e) {
    error_log('get_account_category.php: ' . $e->getMessage()); // SEC-018
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}
?>
