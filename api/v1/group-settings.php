<?php
/**
 * GET /api/v1/group-settings
 *
 * The group's identity and configuration. Mirrors
 * app/bms/customer/group_settings.php.
 *
 * TWO AUDIENCES, TWO BLOCKS.
 *
 * The top-level blocks — group, contributions, fines, leadership_positions —
 * are readable by any signed-in user, because the app needs the group name,
 * logo and currency to render its own chrome, and the monthly target and
 * absence fine are the rules a member is personally held to. A member seeing
 * their own group's name is not a disclosure.
 *
 * The flat `settings` object is the EDIT FORM's pre-fill, so it is returned
 * only to those who may submit that form — the same admin/Secretary rule PUT
 * enforces, via the same helper. It is null for everyone else, and `can_edit`
 * says which case the client is in rather than making it infer from a null.
 *
 * That split is the point. The web settings page is admin/Secretary-only, so
 * publishing the whole configuration to every caller would make the API more
 * permissive than the screen it mirrors — which is exactly how the contribution
 * endpoints came to leak the group's finances to any member.
 *
 * Whitelisted rather than dumped: group_settings is a free-form key/value table
 * that also holds operational state (auto_termination_last_run, a cached
 * group_balance), and returning it wholesale would publish whatever anyone adds
 * to it next. The whitelist is includes/api_group_settings.php, shared with PUT
 * so that reading and writing cannot describe different fields.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_group_settings.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require __DIR__ . '/group_settings_update.php';
    exit;
}

$raw = $pdo->query('SELECT setting_key, setting_value FROM group_settings')
           ->fetchAll(PDO::FETCH_KEY_PAIR);

$writable = vk_group_settings_writable();
$defaults = vk_group_settings_defaults();

/** A stored value for one whitelisted key, with the web form's default applied. */
$stored = static function (string $key) use ($raw, $defaults): string {
    $value = (string) ($raw[$key] ?? '');
    if ($value === '' && isset($defaults[$key])) {
        return $defaults[$key];
    }
    return $value;
};

// The pre-fill block: every writable key, under the name PUT accepts, typed.
$settings = [];
foreach ($writable as $key => $rule) {
    $settings[$key] = vk_group_settings_cast($rule, $stored($key));
}

// The positions list is stored one per line; splitting it here saves every
// client reimplementing the same parse.
$positions = array_values(array_filter(array_map(
    'trim',
    preg_split('/\r\n|\r|\n/', (string) ($raw['leadership_positions'] ?? '')) ?: []
), static fn ($l) => $l !== ''));

$canEdit = vk_group_settings_may_edit($auth);

vk_api_ok([
    // Unchanged shape — Modules 1-3 are already built against these names.
    'group' => [
        'name' => $stored('group_name'),
        // The raw stored value: a bare filename, kept for the web and for any
        // consumer already reading it. It is NOT a URL.
        'logo' => (string) ($raw['group_logo'] ?? ''),
        // What a client can actually load. Absolute, and with the same default
        // the web pages fall back to, so the app and the site show one logo.
        'logo_url' => vk_group_settings_logo_url($raw['group_logo'] ?? null),
        'org_type' => (string) ($raw['company_type'] ?? ''),
        'currency' => $stored('currency'),
    ],
    'contributions' => [
        // Empty means "no monthly target set", which is a real state: with no
        // target there is no arrears calculation at all.
        'monthly_target' => $settings['monthly_contribution'],
    ],
    'fines' => [
        'meeting_absence' => $settings['meeting_absence_fine'],
    ],
    'leadership_positions' => $positions,

    'can_edit' => $canEdit,
    'settings' => $canEdit ? $settings : null,
]);
