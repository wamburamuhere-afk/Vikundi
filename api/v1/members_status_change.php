<?php
/**
 * Shared body for POST /api/v1/members/{id}/{approve|reject|reactivate}.
 *
 * All three do the same thing — move a member between the statuses the `users`
 * enum allows ('pending','active','rejected','dormant') and mirror it onto the
 * customers row — so they share one implementation rather than three that drift.
 *
 * The including file sets $vkTargetStatus and $vkAuditVerb before requiring this.
 *
 * WHO MAY DO IT. approve_member.php allows Admin, Secretary and Katibu. That
 * list omitted the Chairperson (fixed separately); expressed here through
 * vk_role_is_admin() plus the officer check, so the two cannot diverge again.
 *
 * The customers row is matched by user_id, not by email. approve_member.php
 * matches on email — which silently updates nothing when the address differs by
 * case or was never set, leaving users.status and customers.status disagreeing
 * about whether someone is a member.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

// This file is an include, but it sits in api/v1 and the router maps
// /api/v1/members/{id}/status_change straight onto it. Reached that way the
// caller chooses no status, so refuse rather than run with an undefined target.
if (!isset($vkTargetStatus, $vkAuditVerb)) {
    vk_api_error(404, 'not_found', 'Unknown endpoint.');
}

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();

$isOfficer = in_array(
    strtolower(trim((string) ($auth['user']['user_role'] ?? ''))),
    ['secretary', 'katibu'],
    true
);
if (!vk_role_is_admin($auth['role_id'], $auth['user']['user_role'] ?? null)
    && !$isOfficer
    && !vk_api_can($auth, 'edit', 'customers')) {
    vk_api_error(403, 'forbidden', 'You do not have permission to change a member\'s status.');
}

$memberId = (int) ($_GET['id'] ?? 0);
if ($memberId <= 0) {
    vk_api_error(422, 'invalid_id', 'A numeric member id is required.');
}

$st = $pdo->prepare(
    'SELECT c.customer_id, c.user_id, c.customer_name, u.status AS user_status
       FROM customers c
       LEFT JOIN users u ON u.user_id = c.user_id
      WHERE c.customer_id = ?'
);
$st->execute([$memberId]);
$member = $st->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    vk_api_error(404, 'not_found', 'Member not found.');
}
if (empty($member['user_id'])) {
    vk_api_error(409, 'no_login', 'That member has no login account, so their status cannot be changed.');
}

// Nothing to do is a success, not an error: a client retrying after a dropped
// response must not see a failure for work that already happened.
if (($member['user_status'] ?? '') === $vkTargetStatus) {
    vk_api_ok([
        'member_id' => $memberId,
        'status'    => $vkTargetStatus,
        'changed'   => false,
    ]);
}

$customerStatus = $vkTargetStatus === 'active' ? 'active' : 'inactive';

try {
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE users SET status = ?, is_active = ? WHERE user_id = ?')
        ->execute([$vkTargetStatus, $vkTargetStatus === 'active' ? 1 : 0, (int) $member['user_id']]);

    // By user_id. See the note above on approve_member.php matching by email.
    $pdo->prepare('UPDATE customers SET status = ? WHERE customer_id = ?')
        ->execute([$customerStatus, $memberId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    vk_api_error(500, 'update_failed', 'The member status could not be changed.');
}

// user_id passed explicitly: logActivity() resolves 0 from $_SESSION and the API
// has none, so omitting it files the action against user 0.
logUpdate('Members', $vkAuditVerb . ': ' . (string) $member['customer_name'],
    "MEMBER#{$memberId}", $auth['user_id']);

vk_api_ok([
    'member_id'       => $memberId,
    'full_name'       => (string) $member['customer_name'],
    'status'          => $vkTargetStatus,
    'previous_status' => (string) ($member['user_status'] ?? ''),
    'changed'         => true,
]);
