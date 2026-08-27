<?php
/**
 * PUT /api/v1/group-settings
 *
 * Change the group's configuration. Mirrors actions/save_group_settings.php.
 *
 * Included from group-settings.php on PUT; $auth is resolved there.
 *
 * WHO. Admins — which includes the Chairperson, see includes/roles.php — plus
 * the Secretary. The same rule the web page's form is gated on.
 *
 * The web ACTION was gated on nothing but being signed in, so any member could
 * POST to it directly and rename the group, set monthly_contribution to 1 or
 * zero the fines. Because monthly_contribution drives the arrears calculation,
 * that single request cleared every member's arrears at once. Fixed in
 * actions/save_group_settings.php in the same change as this endpoint; the rule
 * lives in one place so the two transports cannot disagree about it again.
 *
 * Keys are whitelisted, and GET and PUT now share the one whitelist
 * (includes/api_group_settings.php) so an officer can read back exactly the
 * fields they may write, under the same names. Operational state kept in the
 * same free-form table — auto_termination_last_run, the cached group_balance —
 * is absent from that list and so can neither be read nor written here.
 */

// Required here, not just by the includer: the router can reach this file
// directly, and without the bootstrap vk_api_require_auth() would be undefined
// and the request would die on a fatal instead of a clean 401.
require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../includes/activity_logger.php';
require_once __DIR__ . '/../../includes/api_group_settings.php';

// The router maps /api/v1/group/{id}/settings_update onto this file, so it
// authenticates itself rather than relying on group-settings.php having done so.
$auth = $auth ?? vk_api_require_auth();

// Admin (which includes the Chairperson) or Secretary — the same helper GET
// uses to decide whether to return the pre-fill block, so a client is never
// shown a form it cannot submit.
if (!vk_group_settings_may_edit($auth)) {
    vk_api_error(403, 'forbidden', 'You do not have permission to change group settings.');
}

/**
 * The writable keys and their rules live in includes/api_group_settings.php,
 * shared with GET. They used to be a const here, which meant GET published
 * seven hand-named fields while PUT accepted eighteen raw ones — an edit form
 * could not pre-fill itself, and a client that tried had to keep its own
 * name-mapping table. One list, both directions, so they cannot drift.
 */
$body = vk_api_body();

$updates = [];
$errors  = [];

foreach (vk_group_settings_writable() as $key => $rule) {
    if (!array_key_exists($key, $body)) {
        continue; // absent means "leave alone" — this is a partial update
    }
    $raw = $body[$key];
    if (is_array($raw)) {
        $errors[] = "{$key} must be a single value.";
        continue;
    }

    [$value, $error] = vk_group_settings_validate($key, $rule, trim((string) $raw));
    if ($error !== null) {
        $errors[] = $error;
        continue;
    }

    if ($key === 'group_name' && $value === '') {
        $errors[] = 'group_name cannot be empty.';
        continue;
    }

    $updates[$key] = $value;
}

if ($errors) {
    vk_api_error(422, 'invalid_settings', implode(' ', $errors));
}

if (!$updates) {
    vk_api_error(422, 'no_fields', 'No writable settings were supplied.');
}

try {
    $pdo->beginTransaction();
    $st = $pdo->prepare(
        'INSERT INTO group_settings (setting_key, setting_value, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    foreach ($updates as $key => $value) {
        $st->execute([$key, $value]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    vk_api_error(500, 'update_failed', 'The settings could not be saved.');
}

// Explicit user id — logActivity() resolves 0 from $_SESSION, which the API has
// not got. Naming the keys makes a settings change reconstructible from the
// audit trail; "settings updated" alone is not.
logUpdate(
    'Group Settings',
    'System Configuration (' . implode(', ', array_keys($updates)) . ')',
    'SETTINGS',
    $auth['user_id']
);

vk_api_ok([
    'updated' => array_keys($updates),
    'count'   => count($updates),
]);
