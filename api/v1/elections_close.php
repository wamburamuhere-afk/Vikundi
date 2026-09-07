<?php
/**
 * POST /api/v1/elections/{id}/close — open -> closed. Mirrors
 * actions/set_vote_status.php's close path: nothing is deleted or
 * recomputed — ballots and participation simply persist, and results become
 * visible per GET .../results' own secrecy rule.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'edit', 'manage_voting');

$id = (int) ($_GET['id'] ?? 0);
$election = vk_api_election_load($pdo, $id);

if ($election['status'] !== 'open') {
    vk_api_error(409, 'not_open', 'Only an open election can be closed.');
}

$pdo->prepare("UPDATE votes SET status='closed' WHERE id = ?")->execute([$id]);

$_SESSION['user_id'] = (int) $auth['user_id'];
logUpdate('Voting', 'Closed', 'VOTE#' . $id, (int) $auth['user_id']);

$updated = vk_api_election_load($pdo, $id);
$row = vk_api_election_row($updated);
$row['actions'] = vk_api_election_actions($auth, $updated['status']);

vk_api_ok(['election' => $row, 'message' => 'Election closed.']);
