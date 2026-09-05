<?php
/**
 * POST /api/v1/budgets/{id}/reject — pending|reviewed -> rejected.
 *
 * Not in the original plan, but the web has always had this action —
 * budget.php's "Reject" button — and its own endpoint,
 * api/account/update_budget_status.php, was a complete workflow bypass: no
 * permission check, and it let the caller set status to 'approved' directly.
 * That endpoint is now restricted to exactly 'rejected' and gated properly;
 * this is the API's own version of the same action, going through the same
 * transition helper review/approve use rather than a raw UPDATE.
 *
 * Gated on review OR approve — whoever can move the workflow forward should
 * also be able to stop it, same rule the fixed web endpoint now uses.
 * No e-signature captured: rejection was never one of the three sign-off
 * steps, and the web action never captured one either.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_budgets.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

if (!vk_api_can($auth, 'review', 'budget') && !vk_api_can($auth, 'approve', 'budget')) {
    vk_api_error(403, 'forbidden', 'You do not have permission to reject a budget.');
}

$result = vk_api_budgets_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'rejected',
    ['pending', 'reviewed'],
    null,
    'rejected'
);

$row = vk_api_budgets_row($result['row'], $result['items']);
$row['actions'] = vk_api_budgets_actions($auth, $row['status']);

vk_api_ok([
    'budget'  => $row,
    'message' => 'Budget rejected.',
]);
