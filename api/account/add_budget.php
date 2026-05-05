<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

try {
    // Auth check
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $user_id = getCurrentUserId();

    // Get POST data
    $budget_year = trim($_POST['budget_year'] ?? '');
    $budget_month = trim($_POST['budget_month'] ?? '');
    $budget_name = trim($_POST['budget_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = trim($_POST['status'] ?? 'pending');

    // Item arrays
    $item_descriptions = $_POST['item_description'] ?? [];
    $item_units = $_POST['item_units'] ?? [];
    $item_qtys = $_POST['item_qty'] ?? [];
    $item_prices = $_POST['item_price'] ?? [];

    // Validate required
    if (empty($budget_year) || empty($budget_month) || empty($budget_name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Year, Month and Budget Name are required']);
        exit;
    }

    // Calculate grand total from items
    $allocated_amount = 0;
    foreach ($item_qtys as $i => $qty) {
        $allocated_amount += floatval($qty) * floatval($item_prices[$i] ?? 0);
    }

    $pdo->beginTransaction();

    // Insert budget header
    $stmt = $pdo->prepare("
        INSERT INTO budgets
            (category_id, budget_year, budget_month, budget_name,
             allocated_amount, actual_amount, status, notes,
             created_by, variance, variance_percentage, created_at, updated_at)
        VALUES
            (NULL, ?, ?, ?, ?, 0, ?, ?, ?, ?, 100.00, NOW(), NOW())
    ");
    $stmt->execute([
        $budget_year,
        $budget_month,
        $budget_name,
        $allocated_amount,
        $status,
        $notes,
        $user_id,
        $allocated_amount,
    ]);

    $budget_id = $pdo->lastInsertId();

    // Insert budget items
    $item_stmt = $pdo->prepare("
        INSERT INTO budget_items (budget_id, description, units, qty, price_per_item, total_amount)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($item_descriptions as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '')
            continue;
        $qty = floatval($item_qtys[$i] ?? 1);
        $price = floatval($item_prices[$i] ?? 0);
        $item_stmt->execute([
            $budget_id,
            $desc,
            trim($item_units[$i] ?? ''),
            $qty,
            $price,
            $qty * $price,
        ]);
    }

    $pdo->commit();

    // Log activity using the correct system helper
    if (function_exists('logCreate')) {
        logCreate('Budgets', $budget_name, "BUDGET#$budget_id", $user_id);
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Budget saved successfully',
        'budget_id' => $budget_id
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    error_log("DB error in add_budget.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    error_log("Error in add_budget.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>