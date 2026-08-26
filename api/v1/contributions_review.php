<?php
/**
 * POST /api/v1/contributions/{id}/review — pending -> reviewed.
 *
 * Step one of the group's three-approval rule. Mirrors api/review_contribution.php.
 *
 * The view check accompanies the review check because core/permissions.php's
 * canReview() is `isAdmin() || (canView() && review)` — a role holding review
 * without view is denied on the web, and the API must not be the softer door.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_contributions.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'manage_contributions');
vk_api_require_permission($auth, 'review', 'manage_contributions');

$result = vk_api_contrib_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'reviewed',
    ['pending'],
    'reviewed',
    'marked as reviewed'
);

vk_api_ok([
    'contribution' => $result['row'],
    'message'      => 'Contribution marked as reviewed.',
]);
