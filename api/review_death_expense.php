<?php
// Workflow transition: pending → reviewed for a death expense.
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

if (!canReview('death_expenses')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to review death expenses.']);
    exit;
}

try {
    global $pdo;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) throw new Exception('Missing ID.');

    $pdo->beginTransaction();

    $cur = $pdo->prepare('SELECT status FROM death_expenses WHERE id = ? FOR UPDATE');
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception('Death expense not found.');
    assertReviewable($row['status']);

    $actor = workflowActorSnapshot();

    $pdo->prepare('
        UPDATE death_expenses
           SET status = "reviewed", reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ?
    ')->execute([$_SESSION['user_id'], $id]);

    workflowCaptureSignature($pdo, 'death_expense', $id, 'reviewed',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity('Updated', 'Death Expenses',
        $actor['name'] . ' reviewed Death Expense #' . $id, 'DE#' . $id);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Death expense marked as reviewed.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
