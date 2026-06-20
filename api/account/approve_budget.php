<?php
// Workflow transition: reviewed → approved for a budget.
require_once __DIR__ . '/../../roots.php';

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

if (!canApprove('budget')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to approve budgets.']);
    exit;
}

try {
    global $pdo;
    $id = intval($_POST['budget_id'] ?? $_POST['id'] ?? 0);
    if (!$id) throw new Exception('Missing budget ID.');

    $pdo->beginTransaction();

    $cur = $pdo->prepare('SELECT status, budget_name FROM budgets WHERE budget_id = ? FOR UPDATE');
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception('Budget not found.');
    assertApprovable($row['status']);

    $actor = workflowActorSnapshot();

    $pdo->prepare('
        UPDATE budgets
           SET status = "approved", approved_by = ?, approved_at = NOW(), updated_at = NOW()
         WHERE budget_id = ?
    ')->execute([$_SESSION['user_id'], $id]);

    workflowCaptureSignature($pdo, 'budget', $id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity('Approved', 'Budget',
        $actor['name'] . ' approved Budget #' . $id . ' — ' . ($row['budget_name'] ?? ''), 'BUDGET#' . $id);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Budget approved successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
