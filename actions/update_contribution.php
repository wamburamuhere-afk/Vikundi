<?php
/**
 * actions/update_contribution.php — cancel a contribution from the ledger.
 *
 * WHAT THIS USED TO DO. It took $_POST['status'] and wrote it straight into the
 * row behind a single canEdit('manage_contributions') check:
 *
 *     $pdo->prepare("UPDATE contributions SET status = ? ...")->execute([$status, $id]);
 *
 * The page only ever sends 'cancelled', but the endpoint accepted anything. So
 * anyone holding EDIT could post status=approved and:
 *
 *   - approve without holding the approve permission, and
 *   - skip review entirely — a pending row straight to approved, and
 *   - do it with no workflow signature and no reviewer/approver recorded.
 *
 * Approved contributions count toward every member's savings and the group's
 * total (includes/contribution_standing.php), so that was a route to moving the
 * group's books while bypassing the three-approval rule those books rest on.
 *
 * Now: a whitelist of one transition, the correct permission for it, and the
 * same FROM-status guard the review/approve endpoints use. Approving and
 * reviewing keep their own endpoints (api/approve_contribution.php,
 * api/review_contribution.php), which capture signatures.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3: must be logged in
require_once __DIR__ . '/../includes/require_csrf.php'; // audit H6: valid CSRF token required
require_once __DIR__ . '/../core/permissions.php';

header('Content-Type: application/json');
requirePermissionJson('edit', 'manage_contributions'); // audit H3

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id     = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');

if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing contribution ID']);
    exit;
}

// The only transition this endpoint performs. Reviewing and approving are
// separate permissions with separate endpoints that record who signed.
if ($status !== 'cancelled') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'This action can only cancel a contribution. '
                   . 'Use Review or Approve for the approval workflow.',
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $cur = $pdo->prepare('SELECT status FROM contributions WHERE contribution_id = ? FOR UPDATE');
    $cur->execute([$id]);
    $from = $cur->fetchColumn();

    if ($from === false) {
        throw new Exception('Contribution not found.');
    }

    // Approved money is already in members' statements and the group total.
    // Reversing it silently would move every downstream figure with no record
    // of a reversal; that is a ledger adjustment, not a status flip.
    if (!in_array((string) $from, ['pending', 'reviewed'], true)) {
        throw new Exception('A contribution that is ' . $from . ' cannot be cancelled.');
    }

    $pdo->prepare(
        'UPDATE contributions SET status = "cancelled", updated_at = CURRENT_TIMESTAMP
          WHERE contribution_id = ?'
    )->execute([$id]);

    require_once __DIR__ . '/../includes/activity_logger.php';
    $lang = $_SESSION['preferred_language'] ?? 'en';
    $desc = $lang === 'sw'
        ? "Mchango #$id umefutwa"
        : "Contribution #$id was cancelled";
    logActivity('Cancelled', 'Contributions', $desc, "CONTRIB#$id");

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
