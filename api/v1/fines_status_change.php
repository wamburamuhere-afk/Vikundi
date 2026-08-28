<?php
/**
 * The shared body of POST /api/v1/fines/{id}/waive and /pay.
 *
 * Included by fines_waive.php and fines_pay.php with $vkFineTarget set. Both
 * exist as their own URLs because the router maps a sub-path onto a file and
 * because "waive this fine" is a distinct act in the group's minutes from
 * "record that it was paid" — the audit trail should say which one happened.
 *
 * Mirrors actions/update_fine_status.php.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_fines.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = $auth ?? vk_api_require_auth();

$target = $vkFineTarget ?? '';
if (!in_array($target, vk_fine_statuses(), true)) {
    // Defensive: only ever set by the two including files.
    vk_api_error(500, 'server_error', 'Unsupported fine transition.');
}

vk_api_fines_require_leader($auth, $target === 'paid' ? 'mark a fine paid' : 'waive a fine');

$fineId = (int) ($_GET['id'] ?? 0);
$existing = vk_api_fine_load($pdo, $fineId);

$current = vk_normalize_fine_status($existing['status'] ?? 'pending');
if ($current === $target) {
    // Not an error the app should have to special-case, but not a silent
    // success either: repeating it must not write a second audit entry saying
    // the treasurer did something they did not do.
    vk_api_error(
        409,
        'already_' . $target,
        $target === 'paid' ? 'This fine is already marked paid.' : 'This fine is already waived.'
    );
}

$st = $pdo->prepare('UPDATE fines SET status = ?, updated_at = NOW() WHERE fine_id = ?');
$st->execute([$target, $fineId]);

logUpdate(
    'Fines',
    (string) ($existing['member_name'] ?? '') . ' — ' . ($target === 'paid' ? 'marked paid' : 'waived'),
    'FINE#' . $fineId,
    (int) $auth['user_id']
);

$fine = vk_api_fine_row(vk_api_fine_load($pdo, $fineId), vk_api_member_id((int) $auth['user_id']));
$fine['actions'] = vk_api_fine_actions($auth, $fine['status']);

vk_api_ok([
    'fine'    => $fine,
    'message' => $target === 'paid' ? 'Fine marked paid.' : 'Fine waived.',
]);
