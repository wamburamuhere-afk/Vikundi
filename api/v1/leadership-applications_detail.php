<?php
/**
 * PUT /api/v1/leadership-applications/{id} — edit YOUR OWN application by id.
 *
 * No GET/DELETE: a single-item GET isn't needed (the list and /mine responses
 * already carry every field), and there is no raw delete — withdrawing is the
 * only way to remove yourself, via POST .../{id}/withdraw, which preserves
 * the record for the audit trail exactly as the web does.
 *
 * Same rule as actions/save_leadership_application.php's edit path: only the
 * applicant themselves, only while the application is still 'pending' AND the
 * election is still 'draft' (vk_application_is_editable()).
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['PUT']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

$app = vk_api_application_load($pdo, $id);

$memberId = vk_api_member_id((int) $auth['user_id']);
$isOwner  = $memberId > 0 && (int) $app['member_id'] === $memberId;
if (!$isOwner) {
    // 404, not 403 — another member's application id existing is not this
    // caller's business, same not-my-record posture the rest of this API uses.
    vk_api_error(404, 'not_found', 'No application was found with that id.');
}
if (!vk_application_is_editable($app, (string) $app['election_status'])) {
    vk_api_error(409, 'not_editable', 'This application can no longer be edited.');
}

$body = vk_api_body();
$merged = [
    'position'    => array_key_exists('position', $body) ? $body['position'] : $app['position'],
    'statement'   => array_key_exists('statement', $body) ? $body['statement'] : $app['statement'],
    'declaration' => 1,
];
$positions = vk_leadership_positions($pdo);
$errors = vk_api_application_input_errors($merged, $positions);
if ($errors) {
    vk_api_error(422, 'invalid_application', implode(' ', $errors));
}

$experience = array_key_exists('experience', $body) ? trim((string) $body['experience']) : (string) ($app['experience'] ?? '');
$proposer   = array_key_exists('proposer_member_id', $body)
    ? ((int) $body['proposer_member_id'] ?: null)
    : ($app['proposer_member_id'] !== null ? (int) $app['proposer_member_id'] : null);

if ($proposer !== null) {
    if ($proposer === $memberId) {
        vk_api_error(422, 'invalid_proposer', 'You cannot propose yourself.');
    }
    $pc = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE customer_id = ? AND status <> 'deleted'");
    $pc->execute([$proposer]);
    if ((int) $pc->fetchColumn() === 0) {
        vk_api_error(404, 'proposer_not_found', 'No member was found with that proposer id.');
    }
}

$pdo->prepare('UPDATE leadership_applications SET position = ?, statement = ?, experience = ?, proposer_member_id = ? WHERE id = ?')
    ->execute([trim((string) $merged['position']), trim((string) $merged['statement']), $experience, $proposer, $id]);

$_SESSION['user_id'] = (int) $auth['user_id'];
$updated = vk_api_application_load($pdo, $id);
logUpdate('Leadership Applications', $updated['position'], 'LA#' . $id, (int) $auth['user_id']);

$row = vk_api_application_row($updated);
$row['actions'] = vk_api_application_actions($auth, true, $updated, (string) $updated['election_status']);

vk_api_ok(['application' => $row, 'message' => 'Application saved.']);
