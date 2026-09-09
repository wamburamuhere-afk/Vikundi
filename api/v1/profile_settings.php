<?php
/**
 * GET /api/v1/profile/settings — theme, language, timezone, date format, notifications
 * PUT /api/v1/profile/settings — set them
 *
 * Mirrors app/constant/profile/my_settings.php's "Preferences" tab exactly —
 * including the fields todo.md's plan text abbreviated to "language,
 * notification prefs" but the actual page also carries (theme, timezone,
 * date_format). Self only, every authenticated user.
 *
 * PUT accepts a partial object — only fields present are changed, so a client
 * flipping one toggle doesn't have to resend every preference.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_profile.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();
$callerId = (int) $auth['user_id'];

$load = function () use ($pdo, $callerId): array {
    $st = $pdo->prepare('SELECT preferred_language, preferences, notification_preferences FROM users WHERE user_id = ?');
    $st->execute([$callerId]);
    return $st->fetch(PDO::FETCH_ASSOC);
};

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $existing = vk_api_profile_settings_row($load());
    $body = vk_api_body();

    $language = trim((string) ($body['language'] ?? $existing['language']));
    if (!in_array($language, vk_api_profile_settings_languages(), true)) {
        vk_api_error(422, 'invalid_language', 'language must be one of: ' . implode(', ', vk_api_profile_settings_languages()) . '.');
    }

    $theme = trim((string) ($body['theme'] ?? $existing['theme']));
    if (!in_array($theme, vk_api_profile_settings_themes(), true)) {
        vk_api_error(422, 'invalid_theme', 'theme must be one of: ' . implode(', ', vk_api_profile_settings_themes()) . '.');
    }

    $timezone = trim((string) ($body['timezone'] ?? $existing['timezone']));
    if (!in_array($timezone, vk_api_profile_settings_timezones(), true)) {
        vk_api_error(422, 'invalid_timezone', 'timezone must be one of: ' . implode(', ', vk_api_profile_settings_timezones()) . '.');
    }

    $dateFormat = trim((string) ($body['date_format'] ?? $existing['date_format']));
    if (!in_array($dateFormat, vk_api_profile_settings_date_formats(), true)) {
        vk_api_error(422, 'invalid_date_format', 'date_format must be one of: ' . implode(', ', vk_api_profile_settings_date_formats()) . '.');
    }

    $emailNotif = array_key_exists('email_notifications', $body)
        ? (bool) $body['email_notifications']
        : $existing['email_notifications'];
    $smsNotif = array_key_exists('sms_notifications', $body)
        ? (bool) $body['sms_notifications']
        : $existing['sms_notifications'];

    $prefs = ['theme' => $theme, 'timezone' => $timezone, 'date_format' => $dateFormat];
    $notif = ['email' => $emailNotif, 'sms' => $smsNotif];

    $pdo->prepare('UPDATE users SET preferred_language = ?, preferences = ?, notification_preferences = ? WHERE user_id = ?')
        ->execute([$language, json_encode($prefs), json_encode($notif), $callerId]);

    $_SESSION['user_id'] = $callerId; // logUpdate() reads the session
    $_SESSION['preferred_language'] = $language;
    logUpdate('Profile Settings', 'Preferences', 'USER#' . $callerId, $callerId);

    vk_api_ok(['settings' => vk_api_profile_settings_row($load())]);
}

vk_api_ok(['settings' => vk_api_profile_settings_row($load())]);
