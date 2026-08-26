<?php
/**
 * POST /api/v1/contributions/{id}/cancel — pending|reviewed -> cancelled.
 *
 * The exit for a contribution entered in error. Approved rows are NOT cancellable
 * here: once money is approved it is in the group's totals and in members'
 * statements, and quietly reversing it would move every downstream figure with
 * no record of a reversal. Correcting approved money is a ledger adjustment, not
 * a status flip.
 *
 * There is no DELETE. A contribution that existed and was withdrawn is part of
 * the audit trail; removing the row destroys the evidence of the mistake.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_contributions.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'edit', 'manage_contributions');

$result = vk_api_contrib_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'cancelled',
    ['pending', 'reviewed'],
    '', // no signature stage: cancelling is not an approval step
    'cancelled'
);

vk_api_ok([
    'contribution' => $result['row'],
    'message'      => 'Contribution cancelled.',
]);
