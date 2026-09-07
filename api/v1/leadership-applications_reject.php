<?php
/**
 * POST /api/v1/leadership-applications/{id}/reject — a reason is required
 * (mirrors actions/review_leadership_application.php exactly: enforced
 * server-side, not just left to the client). If the application had already
 * been approved (had a vote_option_id), that ballot row is deleted — a
 * rejected candidate is removed from the ballot.
 *
 * Same guards as approve: refused once the election is no longer 'draft', or
 * the application has been withdrawn.
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

$body = vk_api_body();
$note = trim((string) ($body['note'] ?? ''));
if ($note === '') {
    vk_api_error(422, 'reason_required', 'Please give a reason for rejecting.');
}

$pdo->beginTransaction();
if (!empty($app['vote_option_id'])) {
    $pdo->prepare('DELETE FROM vote_options WHERE id = ?')->execute([(int) $app['vote_option_id']]);
}
$pdo->prepare("
    UPDATE leadership_applications
       SET status='rejected', review_note = ?, reviewed_by = ?, reviewed_at = NOW(), vote_option_id = NULL
     WHERE id = ?
")->execute([$note, (int) $auth['user_id'], $id]);
$pdo->commit();

$_SESSION['user_id'] = (int) $auth['user_id'];
logUpdate('Leadership Applications', $app['member_name'] . ' — ' . $app['position'] . ' (rejected)', 'LA#' . $id, (int) $auth['user_id']);

$updated = vk_api_application_load($pdo, $id);
$row = vk_api_application_row($updated);
$row['actions'] = vk_api_application_actions($auth, false, $updated, (string) $updated['election_status']);

vk_api_ok(['application' => $row, 'message' => 'Application rejected.']);
