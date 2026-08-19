<?php
/**
 * GET /api/v1/auth/me
 *
 * Who the caller is, what they may do, and whether they are a member.
 *
 * The app calls this on start-up to decide what to render: the permissions map
 * drives which screens and buttons exist, and `member_id` decides whether the
 * personal screens (My Contributions, My Fines) apply at all — an Admin login has
 * no customers row and therefore no personal statement.
 *
 * Permissions are read fresh from the database on every call (see the note in
 * includes/api_auth.php). What the client is told here is what the server will
 * actually enforce, so the UI cannot drift into offering an action that is then
 * refused.
 */

require_once __DIR__ . '/../../../includes/api_bootstrap.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
$user = $auth['user'];

$fullname = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'];

vk_api_ok([
    'user' => [
        'user_id'   => $auth['user_id'],
        'username'  => $auth['username'],
        'full_name' => $fullname,
        'email'     => (string) ($user['email'] ?? ''),
        'role_id'   => $auth['role_id'],
        'role'      => (string) ($user['user_role'] ?? ''),
        'language'  => (string) ($user['preferred_language'] ?? 'en'),
        'member_id' => vk_api_member_id($auth['user_id']),
        'is_admin'  => vk_api_is_admin($auth['role_id']),
    ],
    'permissions' => $auth['permissions'],
]);
