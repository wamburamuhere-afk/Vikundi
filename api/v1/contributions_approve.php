<?php
/**
 * POST /api/v1/contributions/{id}/approve — reviewed -> approved.
 *
 * This is the transition that moves money: an approved contribution counts
 * toward the member's savings and the group's total (see
 * includes/contribution_standing.php), so it is the one that must not be
 * reachable from a pending row. vk_api_contrib_transition() enforces that the
 * row was reviewed first, under a row lock.
 *
 * sig_warning, not an error, when the approver has no e-signature on file —
 * matching api/approve_contribution.php. Refusing the approval would block the
 * group's books on a cosmetic record; saying nothing would let an unsigned
 * approval pass for a signed one.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_contributions.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'manage_contributions');
vk_api_require_permission($auth, 'approve', 'manage_contributions');

$result = vk_api_contrib_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'approved',
    ['reviewed'],
    'approved',
    'approved'
);

$payload = [
    'contribution' => $result['row'],
    'message'      => 'Contribution approved.',
];
if (!$result['has_signature']) {
    $payload['sig_warning'] =
        'No e-signature on file — the approval was recorded without a signature image.';
}

vk_api_ok($payload);
