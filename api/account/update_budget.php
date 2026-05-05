<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    $budget_id = $_POST['budget_id'] ?? 0;
    $budget_year = $_POST['budget_year'] ?? '';
    $budget_month = $_POST['budget_month'] ?? '';
    $category_id = $_POST['category_id'] ?? '';
    $allocated_amount = $_POST['allocated_amount'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'pending';

    if ($budget_id <= 0 || empty($budget_year) || empty($budget_month) || empty($category_id) || empty($allocated_amount)) {
        throw new Exception('All required fields must be filled');
    }

    // Check if budget exists
    $stmt = $pdo->prepare("SELECT * FROM budgets WHERE budget_id = ?");
    $stmt->execute([$budget_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Budget not found');
    }

    // Get actual expenses for variance calculation
    $expenses_stmt = $pdo->prepare("
        SELECT SUM(e.amount) as total_expenses 
        FROM expenses e
        JOIN accounts a ON e.expense_account_id = a.account_id
        JOIN expense_categories ec ON a.account_name = ec.category_name
        WHERE ec.category_id = ? 
        AND YEAR(e.expense_date) = ? 
        AND MONTH(e.expense_date) = ?
        AND e.status IN ('approved', 'paid')
    ");
    $expenses_stmt->execute([$category_id, $budget_year, $budget_month]);
    $actual_expenses = $expenses_stmt->fetchColumn() ?? 0;

    $variance = $allocated_amount - $actual_expenses;
    $variance_percentage = $allocated_amount > 0 ? ($variance / $allocated_amount) * 100 : 0;

    $sql = "UPDATE budgets SET 
            category_id = ?, 
            budget_year = ?, 
            budget_month = ?, 
            allocated_amount = ?, 
            actual_amount = ?, 
            status = ?, 
            notes = ?, 
            variance = ?, 
            variance_percentage = ?, 
            updated_at = NOW() 
            WHERE budget_id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $category_id, $budget_year, $budget_month, $allocated_amount, 
        $actual_expenses, $status, $notes, $variance, $variance_percentage, 
        $budget_id
    ]);

    logActivity($pdo, $_SESSION['user_id'], "Updated budget ID: $budget_id (Category ID: $category_id, Period: $budget_month/$budget_year, Amount: $allocated_amount)");

    echo json_encode(['success' => true, 'message' => 'Budget updated successfully']);

} catch (Exception $e) {
    error_log("Error in update_budget.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
