<?php
require_once __DIR__ . '/../includes/config.php';
// Audit SEC-003/004/005: this endpoint was reachable with no session at all.
require_once __DIR__ . '/../includes/require_auth.php';
require_once __DIR__ . '/../core/permissions.php';
requirePermissionJson('view', 'expenses');
global $pdo;

header('Content-Type: application/json');

$id = $_GET['id'] ?? 0;

try {
    $stmt = $pdo->prepare("SELECT * FROM general_expenses WHERE id = ?");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) throw new Exception("Record Not Found");

    echo json_encode(['success' => true, 'expense' => $expense]);
} catch (Exception $e) {
    error_log('get_general_expense_details.php: ' . $e->getMessage()); // SEC-018
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.']);
}
?>
