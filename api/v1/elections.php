<?php
/**
 * GET  /api/v1/elections — every election, leadership only (mirrors api/get_votes.php)
 * POST /api/v1/elections — create a draft (delegates to elections_create.php)
 *
 * Same ordering as the web: open first, then draft, then closed
 * (`FIELD(status,'open','draft','closed')`) — leadership's attention belongs
 * on what's live right now.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/elections_create.php';
    exit;
}

vk_api_require_permission($auth, 'view', 'manage_voting');

// Auto-close any open election whose deadline has passed, same as the web list.
$pdo->exec("UPDATE votes SET status='closed' WHERE status='open' AND closes_at IS NOT NULL AND closes_at < NOW()");

$rows = $pdo->query("
    SELECT v.*,
           (SELECT COUNT(*) FROM vote_options o WHERE o.vote_id = v.id) AS option_count,
           (SELECT COUNT(*) FROM vote_eligibility e WHERE e.vote_id = v.id) AS eligible_count,
           (SELECT COUNT(*) FROM vote_participation p WHERE p.vote_id = v.id) AS voted_count
      FROM votes v
     ORDER BY FIELD(v.status,'open','draft','closed'), v.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok([
    'elections' => array_map(static function (array $r) use ($auth): array {
        $row = vk_api_election_row($r);
        $row['actions'] = vk_api_election_actions($auth, $r['status']);
        return $row;
    }, $rows),
]);
