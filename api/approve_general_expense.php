<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;

try {
    $pdo->beginTransaction();

    // 1. Get the expense details
    $stmt = $pdo->prepare("SELECT amount, status FROM general_expenses WHERE id = ?");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) throw new Exception("Matumizi hayakupatikana.");
    if ($expense['status'] === 'approved') throw new Exception("Matumizi haya yameshashidhinishwa tayari.");

    $amount = $expense['amount'];

    // 2. Fetch current group balance from settings
    $stmt = $pdo->query("SELECT setting_value FROM group_settings WHERE setting_key = 'group_balance'");
    $current_balance = (float)$stmt->fetchColumn();

    if ($current_balance < $amount) {
        throw new Exception("Salio la kikundi halitoshi. Salio la sasa: " . number_format($current_balance, 2));
    }

    // 3. Update group balance
    $new_balance = $current_balance - $amount;
    $stmt = $pdo->prepare("UPDATE group_settings SET setting_value = ? WHERE setting_key = 'group_balance'");
    $stmt->execute([$new_balance]);

    // 4. Update status
    $stmt = $pdo->prepare("UPDATE general_expenses SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$user_id, $id]);

    $pdo->commit();

    // ── Activity Log ──────────────────────────────────────────────────────────
    require_once __DIR__ . '/../includes/activity_logger.php';
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $log_desc = $is_sw
        ? "Matumizi #$id yameidhinishwa. Kiasi: TZS " . number_format($amount, 2)
        : "General expense #$id approved. Amount: TZS " . number_format($amount, 2);
    logActivity('Approved', 'General Expenses', $log_desc, "EXPENSE#$id");
    // ─────────────────────────────────────────────────────────────────────────

    $msg = $is_sw ? 'Matumizi yameidhinishwa na salio la kikundi limepunguzwa.' : 'General expense approved and group balance updated.';
    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
