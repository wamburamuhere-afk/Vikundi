<?php
/**
 * GET /api/v1/group-settings
 *
 * The group's identity and configuration — name, logo, currency, organisation
 * type, monthly target, leadership positions. Mirrors
 * app/bms/customer/group_settings.php.
 *
 * Readable by any signed-in user: the app needs the group name, logo and
 * currency to render its own chrome, and a member seeing their own group's name
 * is not a disclosure. Writing is a separate, restricted endpoint.
 *
 * Whitelisted rather than dumped. group_settings is a free-form key/value table
 * that later features may use for operational state — auto_termination_last_run
 * and a cached group_balance already live there — and returning the whole table
 * would publish whatever anyone adds to it next.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require __DIR__ . '/group_settings_update.php';
    exit;
}

/** Keys the client legitimately needs, and their defaults. */
const VK_GROUP_SETTING_KEYS = [
    'group_name'           => '',
    'group_logo'           => '',
    'company_type'         => '',
    'currency'             => 'TZS',
    'monthly_contribution' => '',
    'meeting_absence_fine' => '',
    'leadership_positions' => '',
];

$raw = $pdo->query('SELECT setting_key, setting_value FROM group_settings')
           ->fetchAll(PDO::FETCH_KEY_PAIR);

$settings = [];
foreach (VK_GROUP_SETTING_KEYS as $key => $default) {
    $settings[$key] = (string) ($raw[$key] ?? $default);
}

// The positions list is stored one per line; splitting it here saves every
// client reimplementing the same parse.
$positions = array_values(array_filter(array_map(
    'trim',
    preg_split('/\r\n|\r|\n/', $settings['leadership_positions']) ?: []
), static fn ($l) => $l !== ''));

vk_api_ok([
    'group' => [
        'name'         => $settings['group_name'],
        'logo'         => $settings['group_logo'],
        'org_type'     => $settings['company_type'],
        'currency'     => $settings['currency'] !== '' ? $settings['currency'] : 'TZS',
    ],
    'contributions' => [
        // Empty string means "no monthly target set", which is a real state:
        // with no target there is no arrears calculation at all.
        'monthly_target' => $settings['monthly_contribution'] !== ''
            ? (float) $settings['monthly_contribution'] : null,
    ],
    'fines' => [
        'meeting_absence' => $settings['meeting_absence_fine'] !== ''
            ? (float) $settings['meeting_absence_fine'] : null,
    ],
    'leadership_positions' => $positions,
]);
