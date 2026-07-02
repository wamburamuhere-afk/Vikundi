<?php
// api/get_votes.php — list of votes for the leadership Manage Voting page.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3
require_once __DIR__ . '/../core/permissions.php';
global $pdo;

header('Content-Type: application/json');

if (!isAdmin() && !canView('manage_voting')) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized.']);
    exit;
}

// Auto-close any open vote whose deadline has passed.
$pdo->prepare("UPDATE votes SET status='closed' WHERE status='open' AND closes_at IS NOT NULL AND closes_at < NOW()")->execute();

try {
    $rows = $pdo->query("
        SELECT v.*,
               (SELECT COUNT(*) FROM vote_options o WHERE o.vote_id = v.id) AS option_count,
               (SELECT COUNT(*) FROM vote_eligibility e WHERE e.vote_id = v.id) AS eligible_count,
               (SELECT COUNT(*) FROM vote_participation p WHERE p.vote_id = v.id) AS voted_count
          FROM votes v
         ORDER BY FIELD(v.status,'open','draft','closed'), v.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['data' => $rows]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
