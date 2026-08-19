<?php
/**
 * POST /api/v1/auth/refresh
 *
 * Exchanges a refresh token for a fresh access token. This is what lets the app
 * stay usable for weeks while the access token it actually authenticates with
 * lives only an hour.
 *
 * ROTATION: the presented refresh token is revoked and a new one issued on every
 * successful call. If a refresh token is ever stolen and used, the legitimate
 * client's next refresh fails — the theft surfaces instead of granting the
 * attacker quiet, indefinite access.
 *
 * Body: {"refresh_token": "..."}
 */

require_once __DIR__ . '/../../../includes/api_bootstrap.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (vk_api_secret() === null) {
    vk_api_error(500, 'server_misconfigured', 'The API is not configured for authentication.');
}

$body = vk_api_body();
$raw  = trim((string) ($body['refresh_token'] ?? ''));

if ($raw === '') {
    vk_api_error(422, 'missing_refresh_token', 'A refresh token is required.');
}

$row = vk_api_find_refresh_token($pdo, $raw);
if ($row === null) {
    // Unknown, already revoked, or expired — one message for all three.
    vk_api_error(401, 'invalid_refresh_token', 'That refresh token is not valid. Please sign in again.');
}

$userId = $row['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// The account may have been disabled since the refresh token was issued. Refuse,
// and revoke every token it holds — a disabled account should not be able to keep
// refreshing its way back in.
if (!$user || in_array((string) $user['status'], vk_api_blocked_statuses(), true)) {
    vk_api_revoke_all_for_user($pdo, $userId);
    vk_api_error(403, 'account_blocked', 'This account is not currently active.');
}

$roleId = (int) ($user['role_id'] ?? 0);

// Rotate: the presented token dies here, whatever happens next.
vk_api_revoke_refresh_token($pdo, $raw);

$access     = vk_api_issue_access_token($userId, $roleId);
$newRefresh = vk_api_issue_refresh_token($pdo, $userId, $_SERVER['HTTP_USER_AGENT'] ?? null);

vk_api_ok([
    'access_token'  => $access,
    'refresh_token' => $newRefresh,
    'token_type'    => 'Bearer',
    'expires_in'    => VK_API_ACCESS_TTL,
]);
