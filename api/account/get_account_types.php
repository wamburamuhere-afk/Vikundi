<?php
// api/get_account_types.php
require_once __DIR__ . '/../../roots.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../../includes/require_auth.php';
requirePermissionJson('view', 'chart_of_accounts');
global $pdo, $pdo_accounts;

try {
    $stmt = $pdo->query("SELECT * FROM account_types ORDER BY type_name");
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $types
    ]);
} catch (Exception $e) {
    error_log('get_account_types.php: ' . $e->getMessage()); // SEC-018
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.'
    ]);
}
?>
