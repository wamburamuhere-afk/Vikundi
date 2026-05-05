<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

try {
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
    $budget_id = (int)($_POST['budget_id'] ?? 0);

    if (!$budget_id) {
        throw new Exception('Invalid Budget ID');
    }

    // Get POST data
    $budget_year  = trim($_POST['budget_year']  ?? '');
    $budget_month = trim($_POST['budget_month'] ?? '');
    $budget_name  = trim($_POST['budget_name']  ?? '');
    $notes        = trim($_POST['notes']        ?? '');

    // Item arrays
    $item_descriptions = $_POST['item_description'] ?? [];
    $item_units        = $_POST['item_units']        ?? [];
    $item_qtys         = $_POST['item_qty']          ?? [];
    $item_prices       = $_POST['item_price']        ?? [];

    if (empty($budget_year) || empty($budget_month) || empty($budget_name)) {
        throw new Exception('Year, Month and Budget Name are required');
    }

    // Calculate grand total
    $allocated_amount = 0;
    foreach ($item_qtys as $i => $qty) {
        $allocated_amount += floatval($qty) * floatval($item_prices[$i] ?? 0);
    }

    $pdo->beginTransaction();

    // 1. Update budget header
    $stmt = $pdo->prepare("
        UPDATE budgets 
        SET budget_year = ?, budget_month = ?, budget_name = ?, 
            allocated_amount = ?, variance = ?, notes = ?, updated_at = NOW()
        WHERE budget_id = ?
    ");
    $stmt->execute([
        $budget_year,
        $budget_month,
        $budget_name,
        $allocated_amount,
        $allocated_amount, // Reset variance for simplicity
        $notes,
        $budget_id
    ]);

    // 2. Delete old items
    $pdo->prepare("DELETE FROM budget_items WHERE budget_id = ?")->execute([$budget_id]);

    // 3. Insert new items
    $item_stmt = $pdo->prepare("
        INSERT INTO budget_items (budget_id, description, units, qty, price_per_item, total_amount)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($item_descriptions as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') continue;
        $qty   = floatval($item_qtys[$i]  ?? 1);
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

    if (function_exists('logUpdate')) {
        logUpdate('Budgets', $budget_name, "BUDGET#$budget_id", $user_id);
    }

    echo json_encode(['success' => true, 'message' => 'Budget updated successfully']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Error in edit_budget.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
