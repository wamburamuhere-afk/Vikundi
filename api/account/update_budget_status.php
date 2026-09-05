<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }

    // This endpoint had NO permission check at all, and let the caller set
    // status to 'approved' directly — a complete workflow bypass around
    // review_budget.php/approve_budget.php's canReview()/canApprove() checks
    // and assertReviewable()/assertApprovable() guards. Found while building
    // the mobile API's budgets module (Module 10). The only status this
    // endpoint's own caller (budget.php's "Reject" action) ever actually
    // sends is 'rejected', so it is now restricted to exactly that — approving
    // must go through approve_budget.php, which enforces the real workflow.
    if (!canReview('budget') && !canApprove('budget')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to change a budget\'s status.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $user_id   = getCurrentUserId();
    $budget_id = (int)($_POST['budget_id'] ?? 0);
    $status    = trim($_POST['status'] ?? '');

    if (!$budget_id || empty($status)) {
        throw new Exception('Budget ID and Status are required');
    }

    $allowed_statuses = ['rejected'];
    if (!in_array($status, $allowed_statuses)) {
        throw new Exception('Invalid status value');
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE budgets SET status = ?, updated_at = NOW() WHERE budget_id = ?");
    $result = $stmt->execute([$status, $budget_id]);

    if ($result) {
        if (function_exists('logUpdate')) {
            logUpdate('Budgets', "Budget Status Changed: $status", "BUDGET#$budget_id", $user_id);
        }
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        throw new Exception('Failed to update status in database');
    }

} catch (Exception $e) {
    error_log("Error in update_budget_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
