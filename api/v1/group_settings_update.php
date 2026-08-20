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
 * Keys are whitelisted in both directions. GET returns only what a client needs
 * to render; PUT accepts only what an officer may legitimately change, so
 * operational state kept in the same table — auto_termination_last_run, the
 * cached group_balance — can neither be read nor written through the API.
 */

// Required here, not just by the includer: the router can reach this file
// directly, and without the bootstrap vk_api_require_auth() would be undefined
// and the request would die on a fatal instead of a clean 401.
require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

// The router maps /api/v1/group/{id}/settings_update onto this file, so it
// authenticates itself rather than relying on group-settings.php having done so.
$auth = $auth ?? vk_api_require_auth();

$isSecretary = in_array(
    strtolower(trim((string) ($auth['user']['user_role'] ?? ''))),
    ['secretary', 'katibu'],
    true
);

if (!vk_role_is_admin($auth['role_id'], $auth['user']['user_role'] ?? null) && !$isSecretary) {
    vk_api_error(403, 'forbidden', 'You do not have permission to change group settings.');
}

/**
 * Writable keys, and how each is validated.
 *
 * 'text'  — trimmed string
 * 'money' — non-negative number
 * 'int'   — non-negative integer
 * 'enum'  — one of a fixed set
 *
 * Deliberately narrower than save_group_settings.php's list: this covers what a
 * phone can sensibly edit. The loan and share-out parameters are left to the web
 * until there is a screen asking for them, because a value nobody can see on the
 * device is a value nobody can check before saving.
 */
const VK_GROUP_SETTINGS_WRITABLE = [
    'group_name'               => 'text',
    'group_email'              => 'text',
    'group_phone'              => 'text',
    'group_physical_address'   => 'text',
    'group_postal_address'     => 'text',
    'group_registration_number' => 'text',
    'currency'                 => 'text',
    'meeting_day'              => 'text',
    'monthly_contribution'     => 'money',
    'entrance_fee'             => 'money',
    'meeting_absence_fine'     => 'money',
    'fine_late_meeting'        => 'money',
    'fine_late_contribution'   => 'money',
    'fine_absent_meeting'      => 'money',
    'max_members'              => 'int',
    'contribution_grace_days'  => 'int',
    'deadline_day'             => 'int',
    'auto_termination'         => 'enum:on,off',
];

$body = vk_api_body();

$updates = [];
$errors  = [];

foreach (VK_GROUP_SETTINGS_WRITABLE as $key => $rule) {
    if (!array_key_exists($key, $body)) {
        continue;
    }
    $raw = $body[$key];
    if (is_array($raw)) {
        $errors[] = "{$key} must be a single value.";
        continue;
    }
    $value = trim((string) $raw);

    if (str_starts_with($rule, 'enum:')) {
        $allowed = explode(',', substr($rule, 5));
        if (!in_array($value, $allowed, true)) {
            $errors[] = "{$key} must be one of: " . implode(', ', $allowed) . '.';
            continue;
        }
    } elseif ($rule === 'money' || $rule === 'int') {
        // An empty string is a real instruction here: clearing
        // monthly_contribution means "no monthly target", which switches the
        // arrears calculation off rather than setting it to zero. Preserved
        // rather than coerced to 0.
        if ($value !== '') {
            if (!is_numeric($value) || (float) $value < 0) {
                $errors[] = "{$key} must be a number of 0 or more.";
                continue;
            }
            $value = $rule === 'int' ? (string) (int) $value : (string) (float) $value;
        }
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
