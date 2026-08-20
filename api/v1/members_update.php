<?php
/**
 * PUT|POST /api/v1/members/{id}
 *
 * Edit a member's profile. Mirrors api/process_edit_customer.php.
 *
 * Included from members_detail.php for any non-GET method; $auth is resolved
 * there.
 *
 * Gated on the `customers` EDIT permission, which is what the web page checks.
 * That means an ordinary Member cannot edit anyone, including themselves —
 * matching the web, where a member has view-only on the customers page. Self
 * profile editing is a separate module (18, Profile) with its own narrower field
 * set, and folding it in here would hand a member write access to the whole
 * customers row.
 *
 * Only whitelisted columns are writable. The request is a map of client-supplied
 * keys, so building the UPDATE from whatever arrives would let a caller set
 * user_id, customer_code, status or is_deceased — reassigning a profile to
 * another login, or marking someone dead — none of which this endpoint is for.
 */

require_once __DIR__ . '/../../includes/activity_logger.php';

/** @var array $auth  resolved by members_detail.php */
vk_api_require_permission($auth, 'edit', 'customers');

$memberId = (int) ($_GET['id'] ?? 0);
if ($memberId <= 0) {
    vk_api_error(422, 'invalid_id', 'A numeric member id is required.');
}

$body = vk_api_body();

/**
 * Columns a client may set, mirroring process_edit_customer.php's own list.
 * Anything absent from the request is left untouched, so a client can PATCH a
 * single field without resending the whole profile.
 */
const VK_MEMBER_EDITABLE = [
    'first_name', 'middle_name', 'last_name',
    'customer_type', 'email', 'phone', 'mobile',
    'address', 'city', 'state', 'district', 'ward', 'street', 'house_number',
    'country', 'postal_code',
    'gender', 'marital_status', 'dob', 'nida_number',
    'mpesa_name', 'mpesa_number',
    'next_of_kin_name', 'next_of_kin_relationship', 'next_of_kin_phone',
    'nok_age', 'nok_country', 'nok_state', 'nok_district', 'nok_ward',
    'nok_street', 'nok_house_number',
];

$st = $pdo->prepare('SELECT customer_id, user_id, customer_name, first_name, middle_name, last_name
                       FROM customers WHERE customer_id = ?');
$st->execute([$memberId]);
$existing = $st->fetch(PDO::FETCH_ASSOC);

if (!$existing) {
    vk_api_error(404, 'not_found', 'Member not found.');
}

$set = [];
$params = [];
foreach (VK_MEMBER_EDITABLE as $col) {
    if (!array_key_exists($col, $body)) {
        continue;
    }
    $value = $body[$col];
    if (is_array($value)) {
        vk_api_error(422, 'invalid_value', "{$col} must be a single value.");
    }
    $value = is_string($value) ? trim($value) : $value;
    $set[] = "`{$col}` = ?";
    $params[] = ($value === '') ? null : $value;
}

if (!$set) {
    vk_api_error(422, 'no_fields', 'No editable fields were supplied.');
}

// The web refuses to blank a name, and customer_name is derived from the parts,
// so recompute it whenever any part changes rather than letting the stored full
// name drift away from the columns it is built from.
$first  = array_key_exists('first_name', $body)  ? trim((string) $body['first_name'])  : (string) $existing['first_name'];
$last   = array_key_exists('last_name', $body)   ? trim((string) $body['last_name'])   : (string) $existing['last_name'];
$middle = array_key_exists('middle_name', $body) ? trim((string) $body['middle_name']) : (string) $existing['middle_name'];

if ($first === '' || $last === '') {
    vk_api_error(422, 'invalid_name', 'First and last names are required.');
}

$set[] = '`customer_name` = ?';
$params[] = trim(preg_replace('/\s+/', ' ', "{$first} {$middle} {$last}"));

$set[] = '`updated_by` = ?';
$params[] = $auth['user_id'];

// A phone number identifies a member here — the registration check rejects a
// duplicate — so an edit must not be able to sidestep that.
if (array_key_exists('phone', $body) && trim((string) $body['phone']) !== '') {
    $dup = $pdo->prepare(
        'SELECT u.user_id FROM users u WHERE u.phone = ? AND u.user_id <> ? LIMIT 1'
    );
    $dup->execute([trim((string) $body['phone']), (int) ($existing['user_id'] ?? 0)]);
    if ($dup->fetch()) {
        vk_api_error(409, 'phone_taken', 'That phone number belongs to another member.');
    }
}

$params[] = $memberId;

try {
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE customers SET ' . implode(', ', $set) . ' WHERE customer_id = ?')
        ->execute($params);

    // Keep the login row in step. The roster reads names from `users`, so
    // updating only `customers` would rename a member everywhere except the
    // list they are found in.
    if (!empty($existing['user_id'])) {
        $userSet = [];
        $userParams = [];
        foreach (['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last] as $col => $val) {
            $userSet[] = "`{$col}` = ?";
            $userParams[] = $val;
        }
        foreach (['phone', 'email'] as $col) {
            if (array_key_exists($col, $body) && trim((string) $body[$col]) !== '') {
                $userSet[] = "`{$col}` = ?";
                $userParams[] = trim((string) $body[$col]);
            }
        }
        $userParams[] = (int) $existing['user_id'];
        $pdo->prepare('UPDATE users SET ' . implode(', ', $userSet) . ' WHERE user_id = ?')
            ->execute($userParams);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    vk_api_error(500, 'update_failed', 'The member could not be updated.');
}

// Explicit user id: logActivity() resolves 0 from $_SESSION, which the API has
// not got.
logUpdate('Members', trim("{$first} {$last}"), "MEMBER#{$memberId}", $auth['user_id']);

vk_api_ok([
    'member_id'      => $memberId,
    'full_name'      => trim(preg_replace('/\s+/', ' ', "{$first} {$middle} {$last}")),
    'updated_fields' => array_values(array_filter(
        array_keys($body),
        static fn ($k) => in_array($k, VK_MEMBER_EDITABLE, true)
    )),
]);
