<?php
// actions/delete_vote.php — delete a vote (not while it is open).
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php';  // audit B3
require_once __DIR__ . '/../includes/require_csrf.php';  // audit H6
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
requirePermissionJson('delete', 'manage_voting'); // audit H3

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$vote_id = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;

if ($vote_id <= 0) {
    echo json_encode(['success' => false, 'message' => $is_sw ? 'Kura haijapatikana.' : 'Vote not found.']);
    exit;
}

try {
    $cur = $pdo->prepare("SELECT title, status FROM votes WHERE id = ?");
    $cur->execute([$vote_id]);
    $vote = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$vote) {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Kura haijapatikana.' : 'Vote not found.']);
        exit;
    }
    if ($vote['status'] === 'open') {
        echo json_encode(['success' => false, 'message' => $is_sw ? 'Funga kura kwanza kabla ya kuifuta.' : 'Close the vote before deleting it.']);
        exit;
    }

    $pdo->beginTransaction();
    foreach (['vote_ballots', 'vote_participation', 'vote_eligibility', 'vote_options'] as $tbl) {
        $pdo->prepare("DELETE FROM `$tbl` WHERE vote_id = ?")->execute([$vote_id]);
    }
    $pdo->prepare("DELETE FROM votes WHERE id = ?")->execute([$vote_id]);
    $pdo->commit();

    logDelete('Voting', $vote['title'], "VOTE#$vote_id");
    echo json_encode(['success' => true, 'message' => $is_sw ? 'Kura imefutwa.' : 'Vote deleted.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
