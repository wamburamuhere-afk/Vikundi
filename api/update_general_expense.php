<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // must be logged in
require_once __DIR__ . '/../includes/require_csrf.php';  // valid CSRF token
require_once __DIR__ . '/../core/permissions.php';
global $pdo;

header('Content-Type: application/json');
// Only leadership may edit group expenses.
requirePermissionJson('edit', 'expenses');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$id = $_POST['expense_id'] ?? 0;
$expense_date = $_POST['expense_date'] ?? date('Y-m-d');
$description = $_POST['description'] ?? '';
$amount = $_POST['amount'] ?? 0;

try {
    if (empty($id)) throw new Exception("ID haijapatikana.");

    // Can't edit an approved OR already-paid expense. This used to check only
    // 'approved', so a paid expense — money that has already left the account
    // — could still be silently edited. Found while building the mobile API's
    // expenses module (Module 9).
    $stmt = $pdo->prepare("SELECT status FROM general_expenses WHERE id = ?");
    $stmt->execute([$id]);
    if (in_array($stmt->fetchColumn(), ['approved', 'paid'], true)) {
        throw new Exception("Huwezi kuhariri matumizi yaliyoshidhinishwa au yaliyolipwa.");
    }

    $stmt = $pdo->prepare("UPDATE general_expenses SET expense_date = ?, description = ?, amount = ? WHERE id = ?");
    $stmt->execute([$expense_date, $description, $amount, $id]);

    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Mabadiliko yamehifadhiwa.' : 'Changes saved successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
