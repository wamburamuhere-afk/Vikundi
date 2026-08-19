<?php
/**
 * GET /api/v1/members/dormant
 *
 * Members who have gone dormant or are recorded as deceased. Mirrors
 * app/bms/customer/dormant_members.php.
 *
 * NOTE ON THE GATE. The web page carries no permission check of its own beyond
 * header.php's authentication, and renders phone, NIDA and address unmasked to
 * any signed-in user. That is wider than the roster page, which masks those
 * fields for an ordinary member. Rather than copy the gap, this endpoint applies
 * the same rules the roster does: the `customers` view permission to reach it,
 * and vk_mask_member_row() for anyone who may not edit members.
 *
 * Deliberately stricter than the page it mirrors. Erring toward the tighter of
 * two inconsistent rules cannot leak anything the web does not already leak, and
 * the web page is tracked separately for the same fix.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../helpers.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'customers');

$canSeeSensitive = vk_role_is_admin($auth['role_id'], $auth['user']['user_role'] ?? null)
    || vk_api_can($auth, 'edit', 'customers');
$ownUserId = $auth['user_id'];

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

// Same predicate as the web page: any status outside the live set, or flagged
// deceased. Admins are excluded from the roster everywhere.
$whereSql = "(u.status NOT IN ('active','pending','suspended','deleted')
              OR u.status IS NULL OR u.status = '' OR c.is_deceased = 1)
             AND u.user_role <> 'Admin'";

$total = (int) $pdo->query(
    "SELECT COUNT(*) FROM users u LEFT JOIN customers c ON u.user_id = c.user_id WHERE {$whereSql}"
)->fetchColumn();

$rows = $pdo->query(
    "SELECT u.user_id, u.username, u.email, u.first_name, u.middle_name, u.last_name,
            u.status AS user_status, u.user_role, u.created_at,
            c.customer_id, c.customer_name, c.phone, c.address, c.nida_number, c.is_deceased
       FROM users u
       LEFT JOIN customers c ON u.user_id = c.user_id
      WHERE {$whereSql}
      ORDER BY u.first_name ASC
      LIMIT {$perPage} OFFSET {$offset}"
)->fetchAll(PDO::FETCH_ASSOC);

$data = array_map(static function (array $r) use ($canSeeSensitive, $ownUserId): array {
    $isSelf = (int) $r['user_id'] === $ownUserId;
    if (!$canSeeSensitive && !$isSelf) {
        $r = vk_mask_member_row($r);
    }
    $name = trim((string) ($r['customer_name'] ?? '')) !== ''
        ? (string) $r['customer_name']
        : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

    return [
        'member_id'   => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
        'user_id'     => (int) $r['user_id'],
        'username'    => (string) $r['username'],
        'full_name'   => $name !== '' ? $name : (string) $r['username'],
        'status'      => (string) ($r['user_status'] ?? ''),
        'is_deceased' => (bool) ($r['is_deceased'] ?? false),
        'phone'       => $r['phone'] ?? null,
        'email'       => $r['email'] ?? null,
        'nida_number' => $r['nida_number'] ?? null,
        'address'     => $r['address'] ?? null,
        'joined_at'   => $r['created_at'] ? date(DATE_ATOM, strtotime((string) $r['created_at'])) : null,
    ];
}, $rows);

$deceased = 0;
foreach ($data as $d) {
    if ($d['is_deceased']) { $deceased++; }
}

vk_api_ok([
    'members' => $data,
    'summary' => [
        'total'         => $total,
        'deceased'      => $deceased,
        'other_dormant' => count($data) - $deceased,
    ],
    'sensitive_visible' => $canSeeSensitive,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int) ceil($total / $perPage),
        'has_more'    => ($offset + count($data)) < $total,
    ],
]);
