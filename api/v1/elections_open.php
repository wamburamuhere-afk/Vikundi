<?php
/**
 * POST /api/v1/elections/{id}/open — draft -> open. Mirrors
 * actions/set_vote_status.php's open path exactly: refuses under two options,
 * snapshots eligibility (active, non-deceased members, right now — frozen for
 * the life of the election), sets opens_at.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'edit', 'manage_voting');

$id = (int) ($_GET['id'] ?? 0);
$election = vk_api_election_load($pdo, $id);

if ($election['status'] !== 'draft') {
    vk_api_error(409, 'not_draft', 'This election is already open or closed.');
}

$optCountSt = $pdo->prepare('SELECT COUNT(*) FROM vote_options WHERE vote_id = ?');
$optCountSt->execute([$id]);
$optCount = (int) $optCountSt->fetchColumn();

if ($optCount < 2) {
    vk_api_error(422, 'too_few_options', 'An election needs at least two options.');
}

$pdo->beginTransaction();
$pdo->prepare("
    INSERT IGNORE INTO vote_eligibility (vote_id, member_id)
    SELECT ?, c.customer_id FROM customers c
     WHERE COALESCE(c.is_deceased, 0) = 0 AND (c.status IS NULL OR c.status = 'active')
")->execute([$id]);
$pdo->prepare("UPDATE votes SET status='open', opens_at = NOW() WHERE id = ?")->execute([$id]);
$pdo->commit();

$_SESSION['user_id'] = (int) $auth['user_id'];
logUpdate('Voting', 'Opened', 'VOTE#' . $id, (int) $auth['user_id']);

$updated = vk_api_election_load($pdo, $id);
$row = vk_api_election_row($updated);
$row['actions'] = vk_api_election_actions($auth, $updated['status']);

vk_api_ok(['election' => $row, 'message' => 'Election opened.']);
