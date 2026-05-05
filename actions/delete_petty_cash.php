<?php
// actions/delete_petty_cash.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/activity_logger.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;
$voucher_id = $_POST['id'] ?? 0;

if (!$user_id || !$voucher_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or missing parameters']);
    exit();
}

try {
    // Get voucher info for logging
    $stmt_info = $pdo->prepare("SELECT voucher_no, payee_name FROM petty_cash_vouchers WHERE id = ?");
    $stmt_info->execute([$voucher_id]);
    $voucher = $stmt_info->fetch();

    if (!$voucher) {
        echo json_encode(['success' => false, 'message' => 'Voucher not found']);
        exit();
    }

    // Delete
    $stmt = $pdo->prepare("DELETE FROM petty_cash_vouchers WHERE id = ?");
    $success = $stmt->execute([$voucher_id]);

    if ($success) {
        $isSwahili = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
        $logDesc = $isSwahili ? "Alifuta vocha ya petty cash ya " . $voucher['payee_name'] : "Deleted petty cash voucher for " . $voucher['payee_name'];
        logActivity("Deleted", "Petty Cash", $logDesc, $voucher['voucher_no']);
        
        echo json_encode(['success' => true, 'message' => $isSwahili ? 'Vocha imefutwa kikamilifu' : 'Voucher deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete voucher']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
