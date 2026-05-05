<?php
require_once __DIR__ . '/roots.php';
global $pdo;

try {
    // Check tables
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE expenses;");
    $pdo->exec("TRUNCATE TABLE deleted_expenses;");
    // Also clear transactions related to these if any (optional, but safer for a "new system")
    // $pdo->exec("DELETE FROM transactions WHERE transaction_type = 'Expense';");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "Expenses cleared successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
