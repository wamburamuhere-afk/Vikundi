<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

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

    // Check if approved - can't edit approved
    $stmt = $pdo->prepare("SELECT status FROM general_expenses WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() === 'approved') {
        throw new Exception("Huwezi kuhariri matumizi yaliyoshidhinishwa.");
    }

    $stmt = $pdo->prepare("UPDATE general_expenses SET expense_date = ?, description = ?, amount = ? WHERE id = ?");
    $stmt->execute([$expense_date, $description, $amount, $id]);

    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Mabadiliko yamehifadhiwa.' : 'Changes saved successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
