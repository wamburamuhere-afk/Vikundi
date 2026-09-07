<?php
/**
 * POST /api/v1/leadership-applications/{id}/approve — the Committee puts a
 * name on the ballot.
 *
 * Mirrors actions/review_leadership_application.php's approve branch exactly:
 * writes (or, on a re-approval after a reset, updates in place) a
 * vote_options row and links it via leadership_applications.vote_option_id.
 * The label is just the member's name, unless this election has candidates
 * for more than one distinct office among approved/pending applicants, in
 * which case it becomes "Name — Position" so the ballot stays readable.
 *
 * Refused once the election is no longer 'draft' (the ballot is fixed the
 * moment voting opens) or the application has been withdrawn.
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

$pdo->beginTransaction();

$distinctSt = $pdo->prepare("SELECT COUNT(DISTINCT position) FROM leadership_applications WHERE vote_id = ? AND status IN ('approved','pending')");
$distinctSt->execute([(int) $app['vote_id']]);
$manyOffices = ((int) $distinctSt->fetchColumn()) > 1;

$label = $manyOffices ? $app['member_name'] . ' — ' . $app['position'] : $app['member_name'];

if (!empty($app['vote_option_id'])) {
    $pdo->prepare('UPDATE vote_options SET label = ?, member_id = ? WHERE id = ?')
        ->execute([$label, (int) $app['member_id'], (int) $app['vote_option_id']]);
    $optionId = (int) $app['vote_option_id'];
} else {
    $posSt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM vote_options WHERE vote_id = ?');
    $posSt->execute([(int) $app['vote_id']]);
    $pdo->prepare('INSERT INTO vote_options (vote_id, label, member_id, position) VALUES (?, ?, ?, ?)')
        ->execute([(int) $app['vote_id'], $label, (int) $app['member_id'], (int) $posSt->fetchColumn()]);
    $optionId = (int) $pdo->lastInsertId();
}

$pdo->prepare("
    UPDATE leadership_applications
       SET status='approved', review_note = ?, reviewed_by = ?, reviewed_at = NOW(), vote_option_id = ?
     WHERE id = ?
")->execute([$note !== '' ? $note : null, (int) $auth['user_id'], $optionId, $id]);

$pdo->commit();

$_SESSION['user_id'] = (int) $auth['user_id'];
logUpdate('Leadership Applications', $app['member_name'] . ' — ' . $app['position'] . ' (approved)', 'LA#' . $id, (int) $auth['user_id']);

$updated = vk_api_application_load($pdo, $id);
$row = vk_api_application_row($updated);
$row['actions'] = vk_api_application_actions($auth, false, $updated, (string) $updated['election_status']);

vk_api_ok(['application' => $row, 'message' => 'Approved. The name is now on the ballot.']);
