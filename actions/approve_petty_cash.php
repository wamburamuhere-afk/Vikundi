<?php
// actions/approve_petty_cash.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/activity_logger.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$user_id    = $_SESSION['user_id'] ?? 0;
$voucher_id = $_POST['id'] ?? 0;

if (!$user_id || !$voucher_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or missing parameters']);
    exit();
}

try {
    // Check current status
    $stmt_check = $pdo->prepare("SELECT voucher_no, payee_name FROM petty_cash_vouchers WHERE id = ? AND status = 'pending'");
    $stmt_check->execute([$voucher_id]);
    $voucher = $stmt_check->fetch();

    if (!$voucher) {
        echo json_encode(['success' => false, 'message' => 'Voucher not found or already processed']);
        exit();
    }

    // Approve
    $stmt = $pdo->prepare("UPDATE petty_cash_vouchers SET status = 'approved', approved_by = ?, approval_date = NOW() WHERE id = ?");
    $success = $stmt->execute([$user_id, $voucher_id]);

    if ($success) {
        $isSwahili = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
        $logDesc   = $isSwahili ? "Alidhinisha malipo ya petty cash ya " . $voucher['payee_name'] : "Approved petty cash payment for " . $voucher['payee_name'];
        // Fixed logActivity call with 3 arguments
        logActivity("Approved", "Petty Cash", $logDesc, $voucher['voucher_no']);
        
        echo json_encode(['success' => true, 'message' => $isSwahili ? 'Vocha imepitishwa' : 'Voucher approved successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to approve voucher']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
