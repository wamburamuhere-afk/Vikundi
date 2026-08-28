<?php
/**
 * PUT /api/v1/fines/{id} — change a fine's amount, reason or status.
 *
 * Included from fines_detail.php; $auth is resolved there.
 *
 * `edit` on manage_fines — the same permission actions/update_fine_status.php
 * requires. Send only what you are changing; an omitted field is left alone.
 *
 * A status sent here goes through the same validation the dedicated
 * /pay and /waive endpoints use, so there is no back door that writes an
 * arbitrary string into the column.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_fines.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

$auth = $auth ?? vk_api_require_auth();
vk_api_fines_require_leader($auth, 'edit a fine');

$fineId = (int) ($_GET['id'] ?? 0);
$existing = vk_api_fine_load($pdo, $fineId);

$body = vk_api_body();

$updates = [];
$params  = [];

if (array_key_exists('amount', $body)) {
    $updates[] = 'amount = ?';
    $params[]  = vk_api_fine_amount($body['amount']);
}

if (array_key_exists('reason', $body)) {
    // Validated, not merely trimmed: blanking the reason would leave a figure
    // nobody can defend, which is the state save_fine.php refuses to create.
    $updates[] = 'reason = ?';
    $params[]  = vk_api_fine_reason($body['reason']);
}

if (array_key_exists('status', $body)) {
    $status = strtolower(trim((string) $body['status']));
    // in_array against the real list, NOT vk_normalize_fine_status(), which
    // silently turns anything unrecognised into 'pending' — a typo would
    // reopen a settled fine without saying so.
    if (!in_array($status, vk_fine_statuses(), true)) {
        vk_api_error(422, 'invalid_status', 'status must be one of: '
            . implode(', ', vk_fine_statuses()) . '.');
    }
    $updates[] = 'status = ?';
    $params[]  = $status;
}

if (!$updates) {
    vk_api_error(422, 'no_fields', 'Send at least one of: amount, reason, status.');
}

$params[] = $fineId;
$st = $pdo->prepare('UPDATE fines SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE fine_id = ?');
$st->execute($params);

logUpdate(
    'Fines',
    (string) ($existing['member_name'] ?? '') . ' — ' . implode(', ', array_map(
        static fn(string $u): string => trim(explode('=', $u)[0]),
        $updates
    )),
    'FINE#' . $fineId,
    (int) $auth['user_id']
);

$fine = vk_api_fine_row(vk_api_fine_load($pdo, $fineId), vk_api_member_id((int) $auth['user_id']));
$fine['actions'] = vk_api_fine_actions($auth, $fine['status']);

vk_api_ok(['fine' => $fine, 'message' => 'Fine updated.']);
