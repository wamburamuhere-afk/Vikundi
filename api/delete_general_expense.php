<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$id = $_POST['id'] ?? 0;

try {
    $stmt = $pdo->prepare("DELETE FROM general_expenses WHERE id = ? AND status != 'approved'");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        // ── Activity Log ──────────────────────────────────────────────────────
        require_once __DIR__ . '/../includes/activity_logger.php';
        $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
        logDelete('General Expenses', "EXPENSE#$id", "EXPENSE#$id");
        // ─────────────────────────────────────────────────────────────────────
        $msg = $is_sw ? 'Matumizi yamefutwa.' : 'Expense deleted successfully.';
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        throw new Exception("Huwezi kufuta matumizi yaliyoshidhinishwa au rekodi haijapatikana.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
