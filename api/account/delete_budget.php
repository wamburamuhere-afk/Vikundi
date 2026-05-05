<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get POST data
$budget_id = $_POST['budget_id'] ?? 0;

if (empty($budget_id)) {
    echo json_encode(['success' => false, 'message' => 'Budget ID is required']);
    exit();
}

try {
    // Get budget details for logging before deleting
    $stmt = $pdo->prepare("SELECT * FROM budgets WHERE budget_id = ?");
    $stmt->execute([$budget_id]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$budget) {
        echo json_encode(['success' => false, 'message' => 'Budget not found']);
        exit();
    }

    $pdo->beginTransaction();

    // 1. Delete items first (Foreign key safety)
    $stmt_items = $pdo->prepare("DELETE FROM budget_items WHERE budget_id = ?");
    $stmt_items->execute([$budget_id]);

    // 2. Delete budget header
    $stmt_budget = $pdo->prepare("DELETE FROM budgets WHERE budget_id = ?");
    $stmt_budget->execute([$budget_id]);

    $pdo->commit();
    
    // Log the action correctly
    if (function_exists('logDelete')) {
        logDelete('Budgets', ($budget['budget_name'] ?: 'Budget #'.$budget_id), "BUDGET#$budget_id", $_SESSION['user_id']);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Budget and its items deleted successfully'
    ]);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
