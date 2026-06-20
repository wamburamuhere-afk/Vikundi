<?php
// Workflow transition: reviewed → approved for a contribution.
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

if (!canApprove('manage_contributions')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to approve contributions.']);
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
    assertApprovable($row['status']);

    $actor = workflowActorSnapshot();

    $pdo->prepare('
        UPDATE contributions
           SET status = "approved", approved_by = ?, approved_at = NOW()
         WHERE contribution_id = ?
    ')->execute([$_SESSION['user_id'], $id]);

    $sigResult = workflowCaptureSignature($pdo, 'contribution', $id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    logActivity('Updated', 'Contributions',
        $actor['name'] . ' approved Contribution #' . $id,
        'CONTRIB#' . $id);

    $pdo->commit();

    $resp = ['success' => true, 'message' => 'Contribution approved.'];
    if (!$sigResult['has_signature']) {
        $resp['sig_warning'] = 'No e-signature on file — approve action recorded without signature image.';
    }
    echo json_encode($resp);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
