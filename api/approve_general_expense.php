<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/finance.php'; // getGroupFundBalance() (audit H1)
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!canApprove('expenses')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to approve expenses.']); exit;
}

$id = intval($_POST['id'] ?? 0);
$user_id = $_SESSION['user_id'] ?? 0;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT amount, status FROM general_expenses WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) throw new Exception("Matumizi hayakupatikana.");
    assertApprovable($expense['status']);

    $amount = $expense['amount'];

    // Gate on the REAL available fund, computed from records (audit H1).
    $current_balance = getGroupFundBalance($pdo);
    if ($current_balance < $amount) {
        throw new Exception("Salio la kikundi halitoshi. Salio la sasa: " . number_format($current_balance, 2));
    }
    // Fund is derived from records: approving this expense reduces it automatically.

    $actor = workflowActorSnapshot();
    $pdo->prepare("UPDATE general_expenses SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id, $id]);
    workflowCaptureSignature($pdo, 'general_expense', $id, 'approved',
        $user_id, $actor['name'], $actor['role']);

    $pdo->commit();

    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $log_desc = $is_sw
        ? "Matumizi #$id yameidhinishwa. Kiasi: TZS " . number_format($amount, 2)
        : "General expense #$id approved. Amount: TZS " . number_format($amount, 2);
    logActivity('Approved', 'General Expenses', $log_desc, "EXPENSE#$id");

    // Cash basis: approval authorises the expense; the balance drops only when
    // the treasurer marks it paid (disbursed).
    $msg = $is_sw ? 'Matumizi yameidhinishwa. Salio litapungua yatakapowekwa "imelipwa".' : 'General expense approved. The balance will update once it is marked paid.';
    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
