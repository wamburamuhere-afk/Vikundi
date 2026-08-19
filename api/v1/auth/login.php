<?php
/**
 * POST /api/v1/auth/login
 *
 * Exchanges a username/email + password for an access token and a refresh token.
 * The token equivalent of actions/login.php, and deliberately mirrors its rules:
 * the same blocked-status checks in the same order, the same audit-log calls, and
 * the same deleted-account exclusion. Two sign-in paths that disagree about who
 * may sign in is a security hole, not an inconsistency.
 *
 * Body: {"username": "...", "password": "..."}   (`username` accepts an email too)
 */

require_once __DIR__ . '/../../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['POST']);

if (vk_api_secret() === null) {
    vk_api_error(500, 'server_misconfigured', 'The API is not configured for authentication.');
}

$body = vk_api_body();
$loginInput = trim((string) ($body['username'] ?? ''));
$password   = (string) ($body['password'] ?? '');

if ($loginInput === '' || $password === '') {
    vk_api_error(422, 'missing_credentials', 'Both username/email and password are required.');
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status != 'deleted'");
$stmt->execute([$loginInput, $loginInput]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    logFailedLogin($loginInput, 'account not found', 0);
    // Same message and status whether the account is unknown or the password is
    // wrong: distinguishing them turns this endpoint into a tool for discovering
    // which usernames exist.
    vk_api_error(401, 'invalid_credentials', 'Incorrect username/email or password.');
}

if (!password_verify($password, $user['password'])) {
    logFailedLogin($loginInput, 'wrong password', (int) $user['user_id']);
    vk_api_error(401, 'invalid_credentials', 'Incorrect username/email or password.');
}

// Credentials were right but the account may still be barred. Recorded as a
// security event exactly as the web login does.
$status = (string) $user['status'];
if (in_array($status, ['pending', 'rejected', 'inactive', 'suspended'], true)) {
    logFailedLogin($loginInput, 'account ' . $status, (int) $user['user_id']);

    $messages = [
        'pending'   => 'Your account is pending approval by the Admin.',
        'rejected'  => 'Your membership application has been rejected.',
        'inactive'  => 'Your account is currently disabled. Please contact the Admin.',
        'suspended' => 'Your account is currently disabled. Please contact the Admin.',
    ];
    vk_api_error(403, 'account_' . $status, $messages[$status]);
}

$userId = (int) $user['user_id'];
$roleId = (int) ($user['role_id'] ?? 0);

$access  = vk_api_issue_access_token($userId, $roleId);
$refresh = vk_api_issue_refresh_token($pdo, $userId, $_SERVER['HTTP_USER_AGENT'] ?? null);

$pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")->execute([$userId]);

$fullname = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'];
logLogin($userId, $fullname);

vk_api_ok([
    'access_token'  => $access,
    'refresh_token' => $refresh,
    'token_type'    => 'Bearer',
    'expires_in'    => VK_API_ACCESS_TTL,
    'user'          => [
        'user_id'   => $userId,
        'username'  => (string) $user['username'],
        'full_name' => $fullname,
        'role_id'   => $roleId,
        'role'      => (string) ($user['user_role'] ?? ''),
        'language'  => (string) ($user['preferred_language'] ?? 'en'),
        'member_id' => vk_api_member_id($userId),
    ],
]);
