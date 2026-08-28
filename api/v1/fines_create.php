<?php
/**
 * POST /api/v1/fines — record a fine against a member.
 *
 * Reached through fines.php, which has already authenticated.
 *
 * Mirrors actions/save_fine.php, including the rule that a REASON IS REQUIRED:
 * a fine with no reason is a figure nobody can defend when the member asks why.
 *
 * `create` on manage_fines, not `edit`. A role could legitimately be allowed to
 * record fines without being allowed to waive them, and collapsing the two would
 * remove that distinction from the group.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_fines.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (!isset($auth)) {
    $auth = vk_api_require_auth();
}

if (!vk_api_is_admin((int) ($auth['role_id'] ?? 0))
    && !vk_api_can($auth, 'create', 'manage_fines')) {
    vk_api_error(403, 'forbidden', 'You do not have permission to record a fine.');
}

$body = vk_api_body();

$memberId = (int) ($body['member_id'] ?? $body['customer_id'] ?? 0);
if ($memberId <= 0) {
    vk_api_error(422, 'member_required', 'member_id is required.');
}

$amount = vk_api_fine_amount($body['amount'] ?? null);
$reason = vk_api_fine_reason($body['reason'] ?? null);

// Only 'pending' or 'paid' at creation: waiving something that was never owed
// is not a state the group has a word for, and it would leave an audit trail
// nobody can read back.
$status = strtolower(trim((string) ($body['status'] ?? 'pending')));
if (!in_array($status, ['pending', 'paid'], true)) {
    vk_api_error(422, 'invalid_status', 'status must be pending or paid when recording a fine.');
}

// The member must exist and not be deleted — the same check save_fine.php makes.
// A fine against nobody is money the books show as owed by no one.
$c = $pdo->prepare(
    "SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS full_name
       FROM customers WHERE customer_id = ? AND (status IS NULL OR status <> 'deleted')"
);
$c->execute([$memberId]);
$memberName = (string) ($c->fetchColumn() ?: '');
if ($memberName === '') {
    vk_api_error(404, 'member_not_found', 'No member was found with that id.');
}

$meetingId = (int) ($body['meeting_id'] ?? 0);
if ($meetingId > 0) {
    $m = $pdo->prepare('SELECT COUNT(*) FROM meetings WHERE id = ?');
    $m->execute([$meetingId]);
    if ((int) $m->fetchColumn() === 0) {
        vk_api_error(404, 'meeting_not_found', 'No meeting was found with that id.');
    }
}

$st = $pdo->prepare(
    'INSERT INTO fines (customer_id, amount, reason, status, meeting_id, created_at)
     VALUES (?, ?, ?, ?, ?, NOW())'
);
$st->execute([$memberId, $amount, $reason, $status, $meetingId > 0 ? $meetingId : null]);
$newId = (int) $pdo->lastInsertId();

logCreate(
    'Fines',
    $memberName . ' — TSh ' . number_format($amount, 0),
    'FINE#' . $newId,
    (int) $auth['user_id']
);

$row = vk_api_fine_row(vk_api_fine_load($pdo, $newId), vk_api_member_id((int) $auth['user_id']));
$row['actions'] = vk_api_fine_actions($auth, $row['status']);

vk_api_ok(['fine' => $row, 'message' => 'Fine recorded.'], 201);
