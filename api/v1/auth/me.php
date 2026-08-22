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
require_once __DIR__ . '/../../../includes/roles.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
$user = $auth['user'];

$fullname = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'];

$isAdmin      = vk_api_is_admin($auth['role_id']);
$isLeadership = vk_role_is_leadership($auth['role_id'], $user['user_role'] ?? null);

/**
 * EFFECTIVE permissions, not the raw role_permissions rows.
 *
 * vk_api_can() short-circuits for an admin and never consults the map:
 *
 *     if (vk_api_is_admin($auth['role_id'])) return true;
 *
 * So an admin is granted everything regardless of what role_permissions says —
 * and on the live system it says very little, because the web app never needed
 * those rows: isAdmin() has always bypassed the check. The demo Admin holds 10
 * page keys and `customers` is not among them.
 *
 * Returning that raw map broke the promise this endpoint makes. A client that
 * did exactly what the documentation says — render from `permissions` — hid
 * Members List from the Admin while the server would have served it. Reported
 * from the Flutter side, and it was the API at fault, not the client.
 *
 * The fix belongs here rather than in every client. One server inconsistency
 * that every consumer must special-case is a server bug wearing a client's
 * clothes; the second consumer would have hit it too.
 */
$permissions = $auth['permissions'];

if ($isAdmin) {
    // Every page key the system knows about, all actions granted — which is what
    // the server will actually do. Read from `permissions` so a page added later
    // appears here without this file changing.
    $rows = $pdo->query('SELECT page_key FROM permissions ORDER BY page_key')
                ->fetchAll(PDO::FETCH_COLUMN);

    $granted = [];
    foreach ($rows as $pageKey) {
        $granted[(string) $pageKey] = [
            'view' => true, 'create' => true, 'edit' => true,
            'delete' => true, 'review' => true, 'approve' => true,
        ];
    }

    // Union rather than replacement: a key present in the role's own rows but
    // missing from the catalogue still comes through, so this can only ever add
    // access for an admin, never remove it.
    $permissions = $granted + $permissions;
}

$memberId = vk_api_member_id($auth['user_id']);

vk_api_ok([
    'user' => [
        'user_id'   => $auth['user_id'],
        'username'  => $auth['username'],
        'full_name' => $fullname,
        'email'     => (string) ($user['email'] ?? ''),
        'role_id'   => $auth['role_id'],
        'role'      => (string) ($user['user_role'] ?? ''),
        'language'  => (string) ($user['preferred_language'] ?? 'en'),
        // null, not 0. /api/v1/dashboard already returned null for an account
        // with no member record and this returned 0, so the same field had two
        // meanings depending on which endpoint you asked. A client null-checking
        // one and integer-checking the other is a bug waiting for whichever
        // screen is written second.
        'member_id' => $memberId > 0 ? $memberId : null,
        'is_admin'  => $isAdmin,
        // Present in /dashboard's role block from the start; missing here, so a
        // client could not tell a Secretary from a Member without inspecting the
        // permission map itself.
        'is_leadership' => $isLeadership,
    ],
    'permissions' => $permissions,
]);
