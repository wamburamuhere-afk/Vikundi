<?php
/**
 * POST /api/v1/leadership-applications/{id}/reset — the Committee changed
 * its mind before voting opened. Not in todo.md's original plan, but a real,
 * used web action (actions/review_leadership_application.php's 'reset'
 * decision) — added for the same reason prior modules added a real, gated
 * web action found while building (e.g. Meetings' fine-absentees). Without
 * this, an approve made in error could never be corrected via the API.
 *
 * Reverts an 'approved' application back to 'pending' and, critically,
 * DELETES the vote_options row it created — a reversed decision genuinely
 * removes the candidate from the ballot, not merely flips a status flag.
 *
 * Same guards as approve/reject: refused once the election is no longer
 * 'draft', or the application has been withdrawn.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'edit', 'manage_leadership_applications');

$id  = (int) ($_GET['id'] ?? 0);
$app = vk_api_application_load($pdo, $id);

if ($app['election_status'] !== 'draft') {
    vk_api_error(409, 'voting_started', 'Voting has started; applications can no longer be changed.');
}
if ($app['status'] === 'withdrawn') {
    vk_api_error(409, 'withdrawn', 'The member has withdrawn this application.');
}

$pdo->beginTransaction();
if (!empty($app['vote_option_id'])) {
    $pdo->prepare('DELETE FROM vote_options WHERE id = ?')->execute([(int) $app['vote_option_id']]);
}
$pdo->prepare("
    UPDATE leadership_applications
       SET status='pending', review_note = NULL, reviewed_by = NULL, reviewed_at = NULL, vote_option_id = NULL
     WHERE id = ?
")->execute([$id]);
$pdo->commit();

$_SESSION['user_id'] = (int) $auth['user_id'];
logUpdate('Leadership Applications', $app['member_name'] . ' — ' . $app['position'] . ' (reopened)', 'LA#' . $id, (int) $auth['user_id']);

$updated = vk_api_application_load($pdo, $id);
$row = vk_api_application_row($updated);
$row['actions'] = vk_api_application_actions($auth, false, $updated, (string) $updated['election_status']);

vk_api_ok(['application' => $row, 'message' => 'Application returned to pending.']);
