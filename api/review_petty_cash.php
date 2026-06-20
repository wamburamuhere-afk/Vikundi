<?php
// Workflow transition: pending → reviewed for a petty cash voucher.
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!canReview('petty_cash')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to review petty cash vouchers.']);
    exit;
}

try {
    global $pdo;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) throw new Exception('Missing ID.');

    $pdo->beginTransaction();

    $cur = $pdo->prepare('SELECT status, voucher_no FROM petty_cash_vouchers WHERE id = ? FOR UPDATE');
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception('Voucher not found.');
    assertReviewable($row['status']);

    $actor = workflowActorSnapshot();

    $pdo->prepare('
        UPDATE petty_cash_vouchers
           SET status = "reviewed", reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ?
    ')->execute([$_SESSION['user_id'], $id]);

    workflowCaptureSignature($pdo, 'petty_cash', $id, 'reviewed',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity('Updated', 'Petty Cash',
        $actor['name'] . ' reviewed Petty Cash Voucher #' . $row['voucher_no'], 'PCV#' . $row['voucher_no']);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Voucher marked as reviewed.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
