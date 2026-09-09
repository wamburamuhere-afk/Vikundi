<?php
/**
 * POST /api/v1/profile/password — change the caller's own password
 *
 * Mirrors app/constant/profile/my_settings.php's "Security" tab. Added beyond
 * todo.md's plan (only GET/PUT /profile/settings was listed) — a separate
 * endpoint rather than folding current_password/new_password into
 * PUT /profile/settings, a different shape of request entirely. See
 * includes/api_profile.php's file header for the password-policy hardening.
 *
 * No `all_devices` refresh-token revocation here (unlike auth/logout) —
 * todo.md's plan didn't ask for it and my_settings.php's own web flow doesn't
 * do it either; out of scope for a straightforward parity build.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_profile.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
$callerId = (int) $auth['user_id'];

$st = $pdo->prepare('SELECT password FROM users WHERE user_id = ?');
$st->execute([$callerId]);
$currentHash = (string) $st->fetchColumn();

$body = vk_api_body();
$lang = (string) ($auth['user']['preferred_language'] ?? 'en');

$currentPassword = (string) ($body['current_password'] ?? '');
$newPassword     = (string) ($body['new_password'] ?? '');
$confirmPassword = (string) ($body['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    vk_api_error(422, 'fields_required', 'current_password, new_password and confirm_password are all required.');
}
if (!password_verify($currentPassword, $currentHash)) {
    vk_api_error(401, 'wrong_password', $lang === 'sw' ? 'Neno la siri la sasa si sahihi.' : 'Current password is incorrect.');
}
if ($newPassword !== $confirmPassword) {
    vk_api_error(422, 'mismatch', $lang === 'sw' ? 'Neno la siri jipya halilingani.' : 'New passwords do not match.');
}
vk_api_profile_validate_password($newPassword, $lang);

$pdo->prepare('UPDATE users SET password = ?, password_changed_at = NOW() WHERE user_id = ?')
    ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $callerId]);

$_SESSION['user_id'] = $callerId; // logUpdate() reads the session
logUpdate('Profile', 'Password changed', 'USER#' . $callerId, $callerId);

vk_api_ok(['message' => $lang === 'sw' ? 'Neno la siri limebadilishwa kikamilifu.' : 'Password changed successfully.']);
