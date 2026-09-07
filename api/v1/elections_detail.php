<?php
/**
 * GET    /api/v1/elections/{id} — one election + its options, leadership only
 *        (mirrors api/get_vote.php)
 * DELETE /api/v1/elections/{id} — added: actions/delete_vote.php is a real,
 *        used, guarded action (refuses while status='open'), and cascades to
 *        every table that hangs off a vote id, including
 *        leadership_applications — added later on the web specifically
 *        because leaving it out orphaned applications pointing at a deleted
 *        election id.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['GET', 'DELETE']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'manage_voting');

$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    vk_api_require_permission($auth, 'delete', 'manage_voting');

    $loaded = vk_api_election_load($pdo, $id);
    if ($loaded['status'] === 'open') {
        vk_api_error(409, 'election_open', 'Close the election before deleting it.');
    }

    $pdo->beginTransaction();
    foreach (['vote_ballots', 'vote_participation', 'vote_eligibility', 'vote_options', 'leadership_applications'] as $tbl) {
        $pdo->prepare("DELETE FROM `{$tbl}` WHERE vote_id = ?")->execute([$id]);
    }
    $pdo->prepare('DELETE FROM votes WHERE id = ?')->execute([$id]);
    $pdo->commit();

    $_SESSION['user_id'] = (int) $auth['user_id'];
    logDelete('Voting', $loaded['title'], 'VOTE#' . $id, (int) $auth['user_id']);

    vk_api_ok(['message' => 'Election deleted.']);
}

$loaded = vk_api_election_load($pdo, $id);
$row = vk_api_election_row($loaded);
$row['actions'] = vk_api_election_actions($auth, $loaded['status']);
$row['options'] = array_map('vk_api_election_option_row', vk_api_election_options($pdo, $id));

vk_api_ok(['election' => $row]);
