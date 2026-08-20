<?php
/**
 * POST /api/v1/members   (multipart/form-data)
 *
 * Register a member. Mirrors actions/add_member.php.
 *
 * MULTIPART, NOT JSON, and not by preference. add_member.php makes the payment
 * slip mandatory — a member is not registered until the entrance fee is
 * evidenced — so the endpoint has to carry a file. Accepting JSON without a slip
 * would let the mobile app create members the web refuses to, which is the kind
 * of divergence that ends with two systems disagreeing about who has paid.
 *
 * Included from members.php on POST; $auth is already resolved there.
 *
 * Fields: first_name, last_name (required), middle_name, phone (required),
 * gender, dob, nida_number, address, ward, district, city, marital_status,
 * next_of_kin_name, next_of_kin_relationship, next_of_kin_phone,
 * initial_savings, preferred_language, and the file `kianzio_slip`.
 *
 * Username and password follow add_member.php exactly: username is the first
 * initial plus the surname, deduplicated with a numeric suffix, and the initial
 * password is username@123. The app must tell the member to change it.
 */

require_once __DIR__ . '/../../includes/api_upload.php';
require_once __DIR__ . '/../../includes/member_identity.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

/** @var array $auth  resolved by members.php */
vk_api_require_permission($auth, 'create', 'customers');

$body = vk_api_body(); // multipart lands in $_POST; see includes/api_bootstrap.php

$first  = trim((string) ($body['first_name'] ?? ''));
$middle = trim((string) ($body['middle_name'] ?? ''));
$last   = trim((string) ($body['last_name'] ?? ''));
$phone  = trim((string) ($body['phone'] ?? ''));

$missing = [];
foreach (['first_name' => $first, 'last_name' => $last, 'phone' => $phone] as $k => $v) {
    if ($v === '') {
        $missing[] = $k;
    }
}
if ($missing) {
    vk_api_error(422, 'missing_fields', 'Required: ' . implode(', ', $missing) . '.');
}

// The slip is mandatory on the web, so it is mandatory here.
if (!isset($_FILES['kianzio_slip'])) {
    vk_api_error(422, 'slip_required',
        'A payment slip (kianzio_slip) must be uploaded to complete registration.');
}

// Duplicate phone is the web's own check and the one that actually catches
// double registration — names repeat in a village, phone numbers do not.
$st = $pdo->prepare('SELECT user_id FROM users WHERE phone = ?');
$st->execute([$phone]);
if ($st->fetch()) {
    vk_api_error(409, 'phone_taken', 'That phone number is already registered to another member.');
}

[$slip, $uploadError] = vk_api_store_upload(
    $_FILES['kianzio_slip'],
    __DIR__ . '/../../uploads/contributions',
    'kianzio'
);
if ($slip === null) {
    vk_api_error(422, 'invalid_slip', $uploadError ?? 'The payment slip could not be accepted.');
}

$username = vk_unique_username($pdo, vk_build_username($first, $last));
$email    = vk_build_member_email($username, vk_member_email_domain($pdo));
$password = $username . '@123';

$fullName = trim(preg_replace('/\s+/', ' ', "{$first} {$middle} {$last}"));

$memberRoleId = (int) ($pdo->query(
    "SELECT role_id FROM roles WHERE LOWER(role_name) = 'member' LIMIT 1"
)->fetchColumn() ?: 0);

if ($memberRoleId <= 0) {
    // Never guess. The Member role is id 13 on a fresh install and 15 on the
    // live system; a hard-coded fallback here is how someone ends up with
    // role_id 2 and administrative access.
    vk_api_error(500, 'member_role_missing',
        'No Member role exists in this system. Run database/migrate.php.');
}

$lang = (string) ($body['preferred_language'] ?? 'sw');
if (!in_array($lang, ['en', 'sw'], true)) {
    $lang = 'sw';
}

try {
    $pdo->beginTransaction();

    $st = $pdo->prepare(
        'INSERT INTO users
            (first_name, middle_name, last_name, email, phone, username, password,
             role, user_role, role_id, status, is_active, is_admin, language, preferred_language, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, "Member", "Member", ?, "active", 1, 0, ?, ?, NOW())'
    );
    $st->execute([
        $first, $middle, $last, $email, $phone, $username,
        password_hash($password, PASSWORD_DEFAULT), $memberRoleId, $lang, $lang,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $st = $pdo->prepare(
        'INSERT INTO customers
            (user_id, first_name, middle_name, last_name, customer_name, customer_type,
             gender, marital_status, dob, nida_number, phone, mobile, email,
             address, city, district, ward, country, status, initial_savings,
             next_of_kin_name, next_of_kin_relationship, next_of_kin_phone,
             created_at, created_by, category_id)
         VALUES (?, ?, ?, ?, ?, "individual",
                 ?, ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, "Tanzania", "active", ?,
                 ?, ?, ?, NOW(), ?, 0)'
    );
    $st->execute([
        $userId, $first, $middle, $last, $fullName,
        $body['gender'] ?? null,
        $body['marital_status'] ?? null,
        ($body['dob'] ?? '') !== '' ? $body['dob'] : null,
        $body['nida_number'] ?? null,
        $phone, $phone, $email,
        $body['address'] ?? null,
        $body['city'] ?? null,
        $body['district'] ?? null,
        $body['ward'] ?? null,
        (float) ($body['initial_savings'] ?? 0),
        $body['next_of_kin_name'] ?? null,
        $body['next_of_kin_relationship'] ?? null,
        $body['next_of_kin_phone'] ?? null,
        $auth['user_id'],
    ]);
    $memberId = (int) $pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // The slip is already on disk; drop it rather than leave an orphan that no
    // record points at.
    @unlink(__DIR__ . '/../../uploads/contributions/' . $slip);
    vk_api_error(500, 'create_failed', 'The member could not be registered.');
}

// The user id is passed explicitly. logActivity() resolves a 0 from $_SESSION,
// and the API has no session — omitting it would file every mobile action
// against user 0 and quietly break the audit trail.
logCreate('Members', trim("{$first} {$last}"), "MEMBER#{$memberId}", $auth['user_id']);

vk_api_ok([
    'member' => [
        'member_id' => $memberId,
        'user_id'   => $userId,
        'full_name' => $fullName,
        'username'  => $username,
        'email'     => $email,
        'phone'     => $phone,
        'status'    => 'active',
    ],
    // Returned once, at creation, so the registering officer can pass it on.
    // It is the same deterministic value add_member.php sets, so this discloses
    // nothing that knowing the username does not already give away — which is
    // itself worth fixing, in both places, another day.
    'initial_password' => $password,
    'must_change_password' => true,
], 201);
