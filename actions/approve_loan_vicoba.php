<?php
// actions/approve_loan_vicoba.php
require_once '../includes/config.php';
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Ombi si sahihi.']); exit();
}

$loan_id = intval($_POST['loan_id'] ?? 0);
$action  = $_POST['action'] ?? '';

if (!$loan_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Data haitoshi.']); exit();
}

$loan = $pdo->prepare("SELECT * FROM loans WHERE loan_id = ?");
$loan->execute([$loan_id]);
$loan = $loan->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    echo json_encode(['success' => false, 'message' => 'Mkopo haupatikani.']); exit();
}

if (strtolower($loan['status']) !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'Mkopo huu tayari umeshafanyiwa uamuzi.']); exit();
}

try {
    if ($action === 'approve') {
        $new_status   = 'Disbursed';
        $approve_date = date('Y-m-d');
        $pdo->prepare("UPDATE loans SET status=?, approval_date=?, disbursement_date=?, updated_at=NOW() WHERE loan_id=?")
            ->execute([$new_status, $approve_date, $approve_date, $loan_id]);
        
        logActivity($pdo, $_SESSION['user_id'], 'Approved Loan', "Ameidhinisha na kutoa mkopo ID: #{$loan_id}", 'Mikopo');
        
        echo json_encode(['success' => true, 'message' => 'Mkopo umeidhinishwa na kutolewa kwa mwanachama.']);
    } else {
        $pdo->prepare("UPDATE loans SET status='Rejected', updated_at=NOW() WHERE loan_id=?")
            ->execute([$loan_id]);
        // Remove pending repayment schedule
        $pdo->prepare("DELETE FROM loan_repayments WHERE loan_id=? AND status='pending'")->execute([$loan_id]);
        
        logActivity($pdo, $_SESSION['user_id'], 'Rejected Loan', "Amekataa ombi la mkopo ID: #{$loan_id}", 'Mikopo');
        
        echo json_encode(['success' => true, 'message' => 'Mkopo umekataliwa.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Hitilafu: ' . $e->getMessage()]);
}
?>
