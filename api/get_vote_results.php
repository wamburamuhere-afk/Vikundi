<?php
// api/get_vote_results.php — a vote's options, tally and turnout, with the
// secrecy rule: the tally is NEVER exposed while the vote is open.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/vote_helpers.php';
global $pdo;

header('Content-Type: application/json');

$id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;

// Auto-close if the deadline passed, so results become visible on time.
$pdo->prepare("UPDATE votes SET status='closed' WHERE id = ? AND status='open' AND closes_at IS NOT NULL AND closes_at < NOW()")->execute([$id]);

try {
    $v = $pdo->prepare("SELECT * FROM votes WHERE id = ?");
    $v->execute([$id]);
    $vote = $v->fetch(PDO::FETCH_ASSOC);
    if (!$vote) { echo json_encode(['success' => false, 'message' => 'Vote not found.']); exit; }

    $isLeader = isAdmin() || canView('manage_voting');

    // Who may see the tally: leadership after close; members only if the result
    // was published. Nobody sees the tally while the vote is open.
    $canSeeTally = ($vote['status'] === 'closed') && ($isLeader || (int) $vote['publish_results'] === 1);

    $options = $pdo->prepare("SELECT id, label, position FROM vote_options WHERE vote_id = ? ORDER BY position, id");
    $options->execute([$id]);
    $options = $options->fetchAll(PDO::FETCH_ASSOC);

    $eligible = (int) $pdo->query("SELECT COUNT(*) FROM vote_eligibility WHERE vote_id = " . (int) $id)->fetchColumn();
    $voted    = (int) $pdo->query("SELECT COUNT(*) FROM vote_participation WHERE vote_id = " . (int) $id)->fetchColumn();

    $resp = [
        'success' => true,
        'status' => $vote['status'],
        'title' => $vote['title'],
        'turnout' => ['voted' => $voted, 'eligible' => $eligible, 'percent' => vk_turnout_percent($voted, $eligible)],
        'can_see_tally' => $canSeeTally,
    ];

    if ($canSeeTally) {
        $c = $pdo->prepare("SELECT option_id, COUNT(*) AS n FROM vote_ballots WHERE vote_id = ? GROUP BY option_id");
        $c->execute([$id]);
        $counts = [];
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) { $counts[(int) $r['option_id']] = (int) $r['n']; }
        $resp['tally'] = vk_vote_tally($options, $counts);
    } else {
        // Without the tally, still show the option labels (no counts).
        $resp['options'] = array_map(fn($o) => ['id' => (int) $o['id'], 'label' => $o['label']], $options);
    }

    echo json_encode($resp);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
