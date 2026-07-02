<?php
// actions/set_vote_status.php — open a vote (snapshot eligibility) or close it.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
requirePermissionJson('edit', 'manage_voting'); // audit H3

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$vote_id = isset($_POST['vote_id']) && ctype_digit((string) $_POST['vote_id']) ? (int) $_POST['vote_id'] : 0;
$target  = strtolower(trim($_POST['status'] ?? ''));

if ($vote_id <= 0 || !in_array($target, ['open', 'closed'], true)) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Ombi si sahihi.' : 'Invalid request.']);
    exit;
}

try {
    $cur = $pdo->prepare("SELECT v.status, v.vote_type, (SELECT COUNT(*) FROM vote_options o WHERE o.vote_id = v.id) AS opts FROM votes v WHERE v.id = ?");
    $cur->execute([$vote_id]);
    $vote = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$vote) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Kura haijapatikana.' : 'Vote not found.']);
        exit;
    }

    if ($target === 'open') {
        if ($vote['status'] !== 'draft') {
            echo json_encode(['success' => false, 'message' => $is_sw ? 'Kura hii tayari imefunguliwa au imefungwa.' : 'This vote is already open or closed.']);
            exit;
        }
        if ((int) $vote['opts'] < 2) {
            echo json_encode(['success' => false, 'message' => $is_sw ? 'Kura inahitaji angalau chaguo mbili.' : 'A vote needs at least two options.']);
            exit;
        }
        $pdo->beginTransaction();
        // Snapshot who MAY vote = active, non-deceased members, right now.
        $pdo->prepare("
            INSERT IGNORE INTO vote_eligibility (vote_id, member_id)
            SELECT ?, c.customer_id FROM customers c
             WHERE COALESCE(c.is_deceased, 0) = 0
               AND (c.status IS NULL OR c.status = 'active')
        ")->execute([$vote_id]);
        $pdo->prepare("UPDATE votes SET status='open', opens_at = NOW() WHERE id = ?")->execute([$vote_id]);
        $pdo->commit();
        logUpdate('Voting', 'Opened', "VOTE#$vote_id");
        echo json_encode(['success' => true, 'message' => $is_sw ? 'Kura imefunguliwa.' : 'Vote opened.']);
        exit;
    }

    // close
    if ($vote['status'] !== 'open') {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Ni kura zilizofunguliwa pekee zinaweza kufungwa.' : 'Only an open vote can be closed.']);
        exit;
    }
    $pdo->prepare("UPDATE votes SET status='closed' WHERE id = ?")->execute([$vote_id]);
    logUpdate('Voting', 'Closed', "VOTE#$vote_id");
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Kura imefungwa.' : 'Vote closed.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
