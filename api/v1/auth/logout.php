<?php
/**
 * POST /api/v1/auth/logout
 *
 * Revokes the caller's refresh token so the app cannot mint new access tokens.
 *
 * The access token itself is a stateless JWT and cannot be revoked — it simply
 * stops working when it expires, within the hour. That residual window is the
 * accepted cost of stateless access tokens; the refresh token dying immediately
 * is what makes logout meaningful.
 *
 * Body: {"refresh_token": "...", "all_devices": false}
 *
 * `all_devices: true` revokes every refresh token the user holds — the "sign me
 * out everywhere" case, e.g. a lost phone.
 */

require_once __DIR__ . '/../../../includes/api_bootstrap.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
$body = vk_api_body();

$allDevices = filter_var($body['all_devices'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($allDevices) {
    $revoked = vk_api_revoke_all_for_user($pdo, $auth['user_id']);
    vk_api_ok(['revoked' => $revoked, 'all_devices' => true]);
}

$raw = trim((string) ($body['refresh_token'] ?? ''));
if ($raw === '') {
    vk_api_error(422, 'missing_refresh_token', 'A refresh token is required, or pass all_devices: true.');
}

// Only revoke a token that belongs to the caller. Without this check, anyone
// holding any valid access token could revoke another user's session by
// presenting their refresh token.
$row = vk_api_find_refresh_token($pdo, $raw);
if ($row === null || $row['user_id'] !== $auth['user_id']) {
    // Already-revoked and not-yours are reported the same way, and as a success:
    // the caller's goal (that token is not usable by me) holds either way, and
    // distinguishing them would confirm whether a guessed token exists.
    vk_api_ok(['revoked' => 0, 'all_devices' => false]);
}

$did = vk_api_revoke_refresh_token($pdo, $raw);

vk_api_ok(['revoked' => $did ? 1 : 0, 'all_devices' => false]);
