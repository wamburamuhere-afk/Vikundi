<?php
/**
 * POST /api/v1/leadership-applications/{id}/withdraw — own only, and only
 * while still 'pending' (mirrors actions/save_leadership_application.php's
 * withdraw branch). Cannot withdraw an approved or already-rejected
 * application — nothing to withdraw once the Committee has ruled.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_voting.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
$id   = (int) ($_GET['id'] ?? 0);

$app = vk_api_application_load($pdo, $id);

$memberId = vk_api_member_id((int) $auth['user_id']);
if ($memberId <= 0 || (int) $app['member_id'] !== $memberId) {
    vk_api_error(404, 'not_found', 'No application was found with that id.');
}
if ($app['status'] !== 'pending') {
    vk_api_error(409, 'not_pending', 'There is no pending application to withdraw.');
}

$pdo->prepare("UPDATE leadership_applications SET status='withdrawn' WHERE id = ?")->execute([$id]);

$_SESSION['user_id'] = (int) $auth['user_id'];
logUpdate('Leadership Applications', $app['election_title'], 'LA#' . $id, (int) $auth['user_id']);

$updated = vk_api_application_load($pdo, $id);
$row = vk_api_application_row($updated);
$row['actions'] = vk_api_application_actions($auth, true, $updated, (string) $updated['election_status']);

vk_api_ok(['application' => $row, 'message' => 'Your application has been withdrawn.']);
