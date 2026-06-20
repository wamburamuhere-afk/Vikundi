<?php
// Workflow transition: pending → reviewed for a contribution.
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

if (!canReview('manage_contributions')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to review contributions.']);
    exit;
}

try {
    global $pdo;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) throw new Exception('Missing contribution ID.');

    $pdo->beginTransaction();

    $cur = $pdo->prepare('SELECT status FROM contributions WHERE contribution_id = ? FOR UPDATE');
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception('Contribution not found.');
    assertReviewable($row['status']);

    $actor = workflowActorSnapshot();

    $pdo->prepare('
        UPDATE contributions
           SET status = "reviewed", reviewed_by = ?, reviewed_at = NOW()
         WHERE contribution_id = ?
    ')->execute([$_SESSION['user_id'], $id]);

    workflowCaptureSignature($pdo, 'contribution', $id, 'reviewed',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity('Updated', 'Contributions',
        $actor['name'] . ' marked Contribution #' . $id . ' as reviewed',
        'CONTRIB#' . $id);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Contribution marked as reviewed.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
