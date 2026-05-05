<?php
// actions/disburse_loan.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Hujaingia kwenye mfumo.']);
    exit();
}

$loan_id = $_POST['loan_id'] ?? 0;

if (!$loan_id) {
    echo json_encode(['success' => false, 'message' => 'Mkopo haukutambuliwa.']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT status, amount FROM loans WHERE loan_id = ?");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();

    if (!$loan) {
        echo json_encode(['success' => false, 'message' => 'Mkopo haupatikani.']);
        exit();
    }

    if (strtolower($loan['status']) !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'Mkopo lazima uwe umeidhinishwa (Approved) kwanza kabla ya kutoa fedha.']);
        exit();
    }

    // Update status to Disbursed
    $stmt = $pdo->prepare("UPDATE loans SET status = 'Disbursed', updated_at = NOW() WHERE loan_id = ?");
    $stmt->execute([$loan_id]);

    echo json_encode(['success' => true, 'message' => 'Fedha zimekabidhiwa! Mwanachama sasa anaanza kurejesha mkopo.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
