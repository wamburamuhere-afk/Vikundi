<?php
/**
 * actions/mark_expense_paid.php
 * -----------------------------
 * Record that an APPROVED expense was actually paid out (the money left the
 * account). "Approved" authorises the spend; this confirms the disbursement.
 * Reserved for the people who release the money — Treasurer + full admins
 * (canMarkPaid()). One endpoint for all three expense types.
 *
 * POST: type = death|general|petty , id = <row id> , csrf_token
 * Only an 'approved' row can move to 'paid' (idempotent-ish: a re-post on an
 * already-paid row is reported, not double-applied).
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/require_csrf.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canMarkPaid()) {
    echo json_encode(['success' => false, 'message' => 'Only the treasurer or an administrator can mark an expense as paid.']);
    exit;
}

// type -> table (whitelisted; the request never names a table directly)
$tables = [
    'death'   => 'death_expenses',
    'general' => 'general_expenses',
    'petty'   => 'petty_cash_vouchers',
];
$type = (string) ($_POST['type'] ?? '');
$id   = (int) ($_POST['id'] ?? 0);
$user_id = (int) ($_SESSION['user_id'] ?? 0);

if (!isset($tables[$type]) || $id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}
$table = $tables[$type];

try {
    $pdo->beginTransaction();

    $cur = $pdo->prepare("SELECT status FROM `$table` WHERE id = ? FOR UPDATE");
    $cur->execute([$id]);
    $status = $cur->fetchColumn();

    if ($status === false) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Expense not found.']);
        exit;
    }
    if ($status === 'paid') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'This expense is already marked as paid.']);
        exit;
    }
    if ($status !== 'approved') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Only an approved expense can be marked as paid.']);
        exit;
    }

    $pdo->prepare("UPDATE `$table` SET status = 'paid', paid_at = NOW(), paid_by = ? WHERE id = ?")
        ->execute([$user_id, $id]);

    $pdo->commit();

    require_once __DIR__ . '/../includes/activity_logger.php';
    logActivity('Marked Paid', 'Expenses', "Marked $type expense #$id as paid", strtoupper($type) . "-EXP#$id");

    echo json_encode(['success' => true, 'message' => 'Expense marked as paid.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('mark_expense_paid failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A system error occurred.']);
}
