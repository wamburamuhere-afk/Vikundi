<?php
/**
 * GET /api/v1/members
 *
 * The member roster, paginated. Mirrors app/bms/customer/customers.php.
 *
 * WHO SEES WHAT. The gate is the `customers` view permission, which an ordinary
 * Member holds — they can see who else is in the group, which is exactly right
 * for a savings group. What they may NOT see is each other's phone number, NIDA,
 * address, next of kin or initial savings. That distinction is enforced by
 * vk_mask_member_row(), the same function the web list uses, so the two can
 * never drift apart: adding a sensitive column to one masks it in both.
 *
 * The masking happens server-side, before serialisation. A JSON response has no
 * template to hide behind — anything placed in the body is readable by whoever
 * holds the token, regardless of what the app chooses to render.
 *
 * A member is a `users` row; `customers` holds the profile and links back via
 * customers.user_id. The list is therefore driven from `users`, matching the web
 * page, so a member with no profile row still appears.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../helpers.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'customers');

// -----------------------------------------------------------------------------
// May this caller see sensitive fields?
//
// Mirrors core/permissions.php::canSeeMemberSensitiveData(): admins, plus anyone
// who may edit members. Expressed against the token's role rather than
// $_SESSION, because the API has no session.
// -----------------------------------------------------------------------------
$canSeeSensitive = vk_role_is_admin($auth['role_id'], $auth['user']['user_role'] ?? null)
    || vk_api_can($auth, 'edit', 'customers');

// The caller's own member record is always fully visible to them.
$ownUserId = $auth['user_id'];

// -----------------------------------------------------------------------------
// Filters and pagination
// -----------------------------------------------------------------------------
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);
$perPage = max(1, min(100, $perPage)); // a mobile client must never be able to ask for the whole table

$status = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$groupId = (int) ($_GET['group_id'] ?? 0);

$where  = ["u.user_role <> 'Admin'", "(c.is_deceased IS NULL OR c.is_deceased = 0)"];
$params = [];

if ($status !== '') {
    // Only the statuses the roster page itself exposes.
    if (!in_array($status, ['active', 'pending', 'rejected', 'dormant'], true)) {
        vk_api_error(422, 'invalid_status', 'status must be one of: active, pending, rejected, dormant.');
    }
    $where[] = 'u.status = ?';
    $params[] = $status;
} else {
    $where[] = "u.status IN ('active', 'pending', 'suspended')";
}

if ($search !== '') {
    $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR c.customer_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($groupId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM customer_group_customers g
                         WHERE g.customer_id = c.customer_id AND g.group_id = ?)';
    $params[] = $groupId;
}

$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) FROM users u LEFT JOIN customers c ON u.user_id = c.user_id WHERE {$whereSql}";
$st = $pdo->prepare($countSql);
$st->execute($params);
$total = (int) $st->fetchColumn();

$offset = ($page - 1) * $perPage;

$sql = "SELECT
            u.user_id, u.username, u.email, u.first_name, u.middle_name, u.last_name,
            u.status AS user_status, u.user_role, u.created_at,
            c.customer_id, c.customer_name, c.phone, c.address, c.city, c.nida_number,
            c.registration_number, c.state, c.district, c.initial_savings, c.is_deceased,
            c.gender
        FROM users u
        LEFT JOIN customers c ON u.user_id = c.user_id
        WHERE {$whereSql}
        ORDER BY u.first_name ASC, u.last_name ASC
        LIMIT {$perPage} OFFSET {$offset}";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $r) use ($canSeeSensitive, $ownUserId): array {
    $isSelf = (int) $r['user_id'] === $ownUserId;
    if (!$canSeeSensitive && !$isSelf) {
        $r = vk_mask_member_row($r);
    }

    $name = trim((string) ($r['customer_name'] ?? '')) !== ''
        ? (string) $r['customer_name']
        : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

    return [
        'member_id'  => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
        'user_id'    => (int) $r['user_id'],
        'username'   => (string) $r['username'],
        'full_name'  => $name !== '' ? $name : (string) $r['username'],
        'first_name' => (string) ($r['first_name'] ?? ''),
        'last_name'  => (string) ($r['last_name'] ?? ''),
        'gender'     => $r['gender'] ?? null,
        'status'     => (string) ($r['user_status'] ?? ''),
        'role'       => (string) ($r['user_role'] ?? ''),
        'joined_at'  => $r['created_at'] ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
        // Null on a masked row rather than absent, so the client can render a
        // consistent shape and show "hidden" instead of guessing.
        'phone'               => $r['phone'] ?? null,
        'email'               => $r['email'] ?? null,
        'nida_number'         => $r['nida_number'] ?? null,
        'registration_number' => $r['registration_number'] ?? null,
        'address'             => $r['address'] ?? null,
        'district'            => $r['district'] ?? null,
        'initial_savings'     => isset($r['initial_savings']) ? (float) $r['initial_savings'] : null,
        'is_self'             => $isSelf,
    ];
}, $rows);

vk_api_ok([
    'members' => $data,
    // Tells the client whether blank sensitive fields mean "hidden from you" or
    // "not recorded" — without it the app cannot phrase the difference.
    'sensitive_visible' => $canSeeSensitive,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($data)) < $total,
    ],
]);
