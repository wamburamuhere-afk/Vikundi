<?php
/**
 * GET /api/v1/members/{id}
 *
 * One member's full profile. Mirrors app/bms/customer/customer_details.php.
 *
 * TWO SEPARATE RULES, both from the web page:
 *
 *  1. An ordinary member may only open THEIR OWN record. The web expresses this
 *     as a substring test on the role name (`str_contains($role,'member')`),
 *     which also catches 'mwanachama' and 'mjumbe' — and, incidentally,
 *     'Committee Member'. Restated here as "anyone who cannot edit members is
 *     confined to their own record", which produces the same outcome for every
 *     role in the system without depending on how a role happens to be spelled.
 *
 *  2. Sensitive fields are masked for anyone who is neither an admin nor able to
 *     edit members, using vk_mask_member_row() — the same function the web uses.
 *     Rule 1 already keeps a member on their own record, so this is
 *     defence-in-depth: if rule 1 were ever loosened, the fields still would not
 *     leak.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../helpers.php';

vk_api_cors();
vk_api_require_method(['GET']);

$auth = vk_api_require_auth();
vk_api_require_permission($auth, 'view', 'customers');

$memberId = (int) ($_GET['id'] ?? 0);
if ($memberId <= 0) {
    vk_api_error(422, 'invalid_id', 'A numeric member id is required.');
}

$canEditMembers  = vk_api_can($auth, 'edit', 'customers');
$isAdmin         = vk_role_is_admin($auth['role_id'], $auth['user']['user_role'] ?? null);
$canSeeSensitive = $isAdmin || $canEditMembers;

$ownMemberId = vk_api_member_id($auth['user_id']);
$isSelf      = $ownMemberId > 0 && $ownMemberId === $memberId;

// Rule 1. Refuse before the record is read, so a probing caller learns nothing
// about whether the id exists.
if (!$canSeeSensitive && !$isSelf) {
    vk_api_error(403, 'forbidden', 'You may only view your own member record.');
}

$st = $pdo->prepare(
    "SELECT c.*, u.avatar AS user_avatar, u.status AS user_status,
            u.username, u.email AS user_email, u.user_role, u.user_id AS user_id_ref
       FROM customers c
       LEFT JOIN users u ON c.user_id = u.user_id
      WHERE c.customer_id = ?"
);
$st->execute([$memberId]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    vk_api_error(404, 'not_found', 'Member not found.');
}

// Rule 2.
if (!$canSeeSensitive && !$isSelf) {
    $row = vk_mask_member_row($row);
}

// children_data is stored as JSON text; hand the client structured data rather
// than a string it has to parse itself. Masked rows carry null and stay null.
$children = [];
if (!empty($row['children_data'])) {
    $decoded = json_decode((string) $row['children_data'], true);
    $children = is_array($decoded) ? $decoded : [];
}

$name = trim((string) ($row['customer_name'] ?? '')) !== ''
    ? (string) $row['customer_name']
    : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

vk_api_ok([
    'member' => [
        'member_id'   => (int) $row['customer_id'],
        'user_id'     => $row['user_id_ref'] !== null ? (int) $row['user_id_ref'] : null,
        'username'    => (string) ($row['username'] ?? ''),
        'full_name'   => $name,
        'first_name'  => (string) ($row['first_name'] ?? ''),
        'middle_name' => (string) ($row['middle_name'] ?? ''),
        'last_name'   => (string) ($row['last_name'] ?? ''),
        'gender'      => $row['gender'] ?? null,
        'marital_status' => $row['marital_status'] ?? null,
        'dob'         => $row['dob'] ?? null,
        'status'      => (string) ($row['user_status'] ?? ($row['status'] ?? '')),
        'role'        => (string) ($row['user_role'] ?? ''),
        'is_deceased' => (bool) ($row['is_deceased'] ?? false),
        'joined_at'   => $row['created_at'] ? date(DATE_ATOM, strtotime((string) $row['created_at'])) : null,

        'customer_code'       => $row['customer_code'] ?? null,
        'registration_number' => $row['registration_number'] ?? null,

        'contact' => [
            'phone'  => $row['phone'] ?? null,
            'mobile' => $row['mobile'] ?? null,
            'email'  => $row['email'] ?? null,
        ],
        'identity' => [
            'nida_number' => $row['nida_number'] ?? null,
        ],
        'location' => [
            'address'  => $row['address'] ?? null,
            'ward'     => $row['ward'] ?? null,
            'district' => $row['district'] ?? null,
            'city'     => $row['city'] ?? null,
            'country'  => $row['country'] ?? null,
        ],
        'financial' => [
            'initial_savings' => isset($row['initial_savings']) ? (float) $row['initial_savings'] : null,
        ],
        'next_of_kin' => [
            'name'         => $row['next_of_kin_name'] ?? null,
            'relationship' => $row['next_of_kin_relationship'] ?? null,
            'phone'        => $row['next_of_kin_phone'] ?? null,
        ],
        'children' => $children,
    ],
    'is_self'           => $isSelf,
    'sensitive_visible' => $canSeeSensitive || $isSelf,
]);
