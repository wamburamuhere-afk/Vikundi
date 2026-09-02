<?php
/**
 * POST /api/v1/condolences/{id}/review — pending -> reviewed.
 *
 * Step one of the group's three-approval rule. Mirrors api/review_death_expense.php.
 *
 * The view check accompanies the review check because core/permissions.php's
 * canReview() is `isAdmin() || (canView() && review)` — a role holding review
 * without view is denied on the web, and the API must not be the softer door.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_death_expenses.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

vk_api_require_permission($auth, 'view', 'death_expenses');
vk_api_require_permission($auth, 'review', 'death_expenses');

$result = vk_api_death_transition(
    $pdo,
    $auth,
    (int) ($_GET['id'] ?? 0),
    'reviewed',
    ['pending'],
    'reviewed',
    'marked as reviewed'
);

$row = vk_api_death_row($result['row'], vk_api_member_id((int) $auth['user_id']));
$row['actions'] = vk_api_death_actions($auth, $row['status']);

vk_api_ok([
    'condolence' => $row,
    'message'    => 'Condolence case marked as reviewed.',
]);
