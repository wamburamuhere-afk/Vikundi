<?php
/**
 * GET /api/v1/settings/system — the group's system-wide configuration
 * PUT /api/v1/settings/system — update one or more sections
 *
 * Mirrors app/constant/settings/system_settings.php's five save_* forms:
 * general, email (SMTP), sms (gateway), security, and group (a passthrough
 * JSON blob of contribution rates/schedules — a different thing from the
 * `group_settings` TABLE Module 3's /api/v1/group-settings already covers;
 * this is the `system_settings` table's own 'group_settings' key).
 *
 * ADMIN/CHAIRPERSON ONLY, matching this page's own web fix (see
 * sessions.md's 2026-09-09 hotfix entry — this page had NO gate at all until
 * that fix, found while building this exact endpoint).
 *
 * PUT accepts any combination of {general, email, sms, security, group} —
 * only the sections present are validated and saved, matching the web's own
 * independent per-section Save buttons. Sending smtp_password/sms_api_secret
 * back is deliberate parity with the web (an Admin managing their own
 * configured secrets needs to see and edit them) — the vulnerability fixed
 * alongside this module was that a non-Admin could reach this at all, not
 * that an Admin can see their own settings.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_settings.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();
vk_api_settings_require_admin($auth);
$callerId = (int) $auth['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body = vk_api_body();
    $saved = [];

    foreach (VK_API_SETTINGS_SECTIONS as $section => $keys) {
        if (!array_key_exists($section, $body)) {
            continue;
        }
        if (!is_array($body[$section])) {
            vk_api_error(422, 'invalid_section', "$section must be an object.");
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $body[$section])) {
                $value = $body[$section][$key];
                vk_api_settings_save($pdo, $key, is_bool($value) ? (int) $value : (string) $value);
            }
        }
        $saved[] = $section;
    }

    if (array_key_exists('group', $body)) {
        if (!is_array($body['group'])) {
            vk_api_error(422, 'invalid_section', 'group must be an object.');
        }
        vk_api_settings_save($pdo, 'group_settings', json_encode($body['group']));
        $saved[] = 'group';
    }

    if (!$saved) {
        vk_api_error(422, 'nothing_to_save', 'Provide at least one of: general, email, sms, security, group.');
    }

    $_SESSION['user_id'] = $callerId; // logUpdate() reads the session
    logUpdate('System Settings', implode(', ', $saved), 'SETTINGS', $callerId);
}

vk_api_ok(['settings' => vk_api_settings_shape(vk_api_settings_get($pdo))]);
