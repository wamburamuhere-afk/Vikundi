<?php
// Workflow transition: pending → reviewed for a budget.
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

if (!canReview('budget')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to review budgets.']);
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
    assertReviewable($row['status']);

    $actor = workflowActorSnapshot();

    $pdo->prepare('
        UPDATE budgets
           SET status = "reviewed", reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
         WHERE budget_id = ?
    ')->execute([$_SESSION['user_id'], $id]);

    workflowCaptureSignature($pdo, 'budget', $id, 'reviewed',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity('Updated', 'Budget',
        $actor['name'] . ' reviewed Budget #' . $id . ' — ' . ($row['budget_name'] ?? ''), 'BUDGET#' . $id);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Budget marked as reviewed.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
