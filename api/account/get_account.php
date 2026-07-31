<?php
// ajax/get_account.php
require_once __DIR__ . '/../../roots.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../../includes/require_auth.php';
requirePermissionJson('view', 'chart_of_accounts');
global $pdo, $pdo_accounts;
header('Content-Type: application/json');

try {
    $account_id = $_GET['account_id'] ?? '';
    
    if (empty($account_id)) {
        throw new Exception('Account ID is required');
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            at.type_name as account_type,
            a.category_id,
            a.description,
            a.opening_balance,
            a.current_balance,
            a.parent_account_id,
            a.status
        FROM accounts a
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        WHERE a.account_id = ?
    ");
    
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        throw new Exception('Account not found');
    }
    
    echo json_encode([
        'success' => true,
        'account' => $account
    ]);
    
} catch (Exception $e) {
    error_log('get_account.php: ' . $e->getMessage()); // SEC-018
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.'
    ]);
}
?>
