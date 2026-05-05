<?php
// actions/record_loan_payment.php
require_once '../includes/config.php';
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Ombi si sahihi.']); exit();
}

$loan_id      = intval($_POST['loan_id'] ?? 0);
$amount_paid  = floatval($_POST['amount_paid'] ?? 0);
$payment_date = $_POST['payment_date'] ?? date('Y-m-d');
$notes        = trim($_POST['payment_notes'] ?? '');

if (!$loan_id || $amount_paid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Jaza kiasi kinacholipwa.']); exit();
}

$loan_stmt = $pdo->prepare("SELECT * FROM loans WHERE loan_id=?");
$loan_stmt->execute([$loan_id]);
$loan = $loan_stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    echo json_encode(['success' => false, 'message' => 'Mkopo haupatikani.']); exit();
}

if ($loan['balance'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Mkopo huu umelipwa tayari.']); exit();
}

if ($amount_paid > $loan['balance']) {
    $amount_paid = $loan['balance']; // cap to remaining balance
}

try {
    $pdo->beginTransaction();

    $new_balance  = $loan['balance'] - $amount_paid;
    $total_paid   = $loan['total_paid'] + $amount_paid;
    $new_status   = ($new_balance <= 0) ? 'Repaid' : $loan['status'];
    $completed_at = ($new_balance <= 0) ? date('Y-m-d H:i:s') : null;

    // Update loan record
    $pdo->prepare("
        UPDATE loans SET balance=?, total_paid=?, status=?, last_payment_date=?,
        completed_at=COALESCE(completed_at, ?), updated_at=NOW() WHERE loan_id=?
    ")->execute([$new_balance, $total_paid, $new_status, $payment_date, $completed_at, $loan_id]);

    // Find next pending installment and mark it
    $inst = $pdo->prepare("SELECT * FROM loan_repayments WHERE loan_id=? AND status IN ('pending','partial') ORDER BY due_date ASC LIMIT 1");
    $inst->execute([$loan_id]);
    $installment = $inst->fetch(PDO::FETCH_ASSOC);

    if ($installment) {
        $already_paid = $installment['amount_paid'];
        $new_inst_paid = $already_paid + $amount_paid;
        $inst_due = $installment['amount'];
        $inst_status = ($new_inst_paid >= $inst_due) ? 'paid' : 'partial';
        $pdo->prepare("UPDATE loan_repayments SET amount_paid=?, status=?, payment_date=? WHERE id=?")
            ->execute([min($new_inst_paid, $inst_due), $inst_status, $payment_date, $installment['id']]);
    }

    $pdo->commit();

    // REAL LOGGING: Add entry to activity_logs
    try {
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, created_at) VALUES (?, ?, 'Mikopo', NOW())");
        $log_stmt->execute([$_SESSION['user_id'], "Amerekodi malipo ya TZS " . number_format($amount_paid) . " kwa mkopo ID: #{$loan_id}. Baki: TZS " . number_format($new_balance)]);
    } catch (Exception $logEx) {}

    $msg = "Malipo ya TZS " . number_format($amount_paid, 2) . " yamehifadhiwa.";
    if ($new_balance <= 0) $msg .= " 🎉 Mkopo umelipwa kikamilifu!";
    else $msg .= " Baki iliyobaki: TZS " . number_format($new_balance, 2);

    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Hitilafu: ' . $e->getMessage()]);
}
?>
