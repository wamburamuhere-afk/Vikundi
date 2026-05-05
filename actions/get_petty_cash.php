<?php
// actions/get_petty_cash.php
require_once __DIR__ . '/../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = $_GET['id'] ?? 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Missing ID']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT v.*, 
               u1.first_name as prep_fn, u1.last_name as prep_ln,
               u2.first_name as appr_fn, u2.last_name as appr_ln
        FROM petty_cash_vouchers v
        LEFT JOIN users u1 ON v.prepared_by = u1.user_id
        LEFT JOIN users u2 ON v.approved_by = u2.user_id
        WHERE v.id = ?
    ");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($voucher) {
        $voucher['amount_formatted'] = number_format($voucher['amount'], 2);
        $voucher['prepared_by_name'] = trim(($voucher['prep_fn'] ?? '') . ' ' . ($voucher['prep_ln'] ?? ''));
        $voucher['approved_by_name'] = trim(($voucher['appr_fn'] ?? '') . ' ' . ($voucher['appr_ln'] ?? ''));
        
        echo json_encode(['success' => true, 'data' => $voucher]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Voucher not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
