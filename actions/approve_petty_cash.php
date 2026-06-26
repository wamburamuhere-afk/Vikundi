<?php
// actions/approve_petty_cash.php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/require_csrf.php'; // audit H6: valid CSRF token required
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canApprove('petty_cash')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to approve petty cash vouchers.']);
    exit();
}

$user_id    = $_SESSION['user_id'] ?? 0;
$voucher_id = intval($_POST['id'] ?? 0);

if (!$user_id || !$voucher_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    global $pdo;

    $pdo->beginTransaction();

    $stmt_check = $pdo->prepare("SELECT id, voucher_no, payee_name, status FROM petty_cash_vouchers WHERE id = ? FOR UPDATE");
    $stmt_check->execute([$voucher_id]);
    $voucher = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) throw new Exception('Voucher not found.');
    assertApprovable($voucher['status']);

    $actor = workflowActorSnapshot();

    $pdo->prepare("UPDATE petty_cash_vouchers SET status = 'approved', approved_by = ?, approval_date = NOW() WHERE id = ?")
        ->execute([$user_id, $voucher_id]);

    workflowCaptureSignature($pdo, 'petty_cash', $voucher_id, 'approved',
        $user_id, $actor['name'], $actor['role']);

    $pdo->commit();

    $isSwahili = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $logDesc = $isSwahili
        ? "Alidhinisha vocha ya petty cash ya " . $voucher['payee_name']
        : "Approved petty cash voucher for " . $voucher['payee_name'];
    logActivity("Approved", "Petty Cash", $logDesc, $voucher['voucher_no']);

    echo json_encode(['success' => true, 'message' => $isSwahili ? 'Vocha imepitishwa' : 'Voucher approved successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
