<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // must be logged in
require_once __DIR__ . '/../includes/require_csrf.php';  // valid CSRF token
require_once __DIR__ . '/../core/permissions.php';
global $pdo;

header('Content-Type: application/json');
// Only leadership may delete group expenses.
requirePermissionJson('delete', 'expenses');

$id = $_POST['id'] ?? 0;

try {
    // Never delete an authorised expense — a paid one is even more final than an
    // approved one, so both are protected (only pending/rejected can be removed).
    $stmt = $pdo->prepare("DELETE FROM general_expenses WHERE id = ? AND status NOT IN ('approved','paid')");
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
