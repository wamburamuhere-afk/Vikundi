<?php
/**
 * GET /api/v1/users/{id} — one login account
 * PUT /api/v1/users/{id} — edit it (edit_user.php equivalent)
 *
 * ADMIN/CHAIRPERSON ONLY. No DELETE here — deliberately excluded, see
 * includes/api_settings.php's file header.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_settings.php';
require_once __DIR__ . '/../../includes/activity_logger.php';
require_once __DIR__ . '/../../includes/registration_validator.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();
vk_api_settings_require_admin($auth);
$callerId = (int) $auth['user_id'];

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    vk_api_error(422, 'invalid_id', 'A user id is required.');
}

$load = function () use ($pdo, $id): array {
    $st = $pdo->prepare('SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        vk_api_error(404, 'not_found', 'No user was found with that id.');
    }
    return $row;
};

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $existing = $load();
    $body = vk_api_body();
    $lang = (string) ($auth['user']['preferred_language'] ?? 'en');

    $username = trim((string) ($body['username'] ?? $existing['username']));
    if (strlen($username) < 4) {
        vk_api_error(422, 'invalid_username', 'username must be at least 4 characters.');
    }

    $email = trim((string) ($body['email'] ?? $existing['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        vk_api_error(422, 'invalid_email', 'A valid email is required.');
    }

    $firstName = trim((string) ($body['first_name'] ?? $existing['first_name']));
    $lastName  = trim((string) ($body['last_name'] ?? $existing['last_name']));
    if ($firstName === '' || $lastName === '') {
        vk_api_error(422, 'name_required', 'first_name and last_name are required.');
    }

    $roleId = array_key_exists('role_id', $body)
        ? vk_api_users_validate_role($pdo, $body['role_id'])
        : (int) $existing['role_id'];

    $status = trim((string) ($body['status'] ?? $existing['status']));
    if (!in_array($status, vk_api_user_statuses(), true)) {
        vk_api_error(422, 'invalid_status', 'status must be one of: ' . implode(', ', vk_api_user_statuses()) . '.');
    }

    vk_api_users_validate_unique($pdo, $username, $email, $id);

    $password = (string) ($body['password'] ?? '');
    if ($password !== '') {
        vk_api_users_validate_password($password, $lang);
        $st = $pdo->prepare(
            'UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, role_id = ?, status = ?, password = ? WHERE user_id = ?'
        );
        $st->execute([$username, $email, $firstName, $lastName, $roleId, $status, password_hash($password, PASSWORD_DEFAULT), $id]);
    } else {
        $st = $pdo->prepare(
            'UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, role_id = ?, status = ? WHERE user_id = ?'
        );
        $st->execute([$username, $email, $firstName, $lastName, $roleId, $status, $id]);
    }

    $_SESSION['user_id'] = $callerId; // logUpdate() reads the session
    logUpdate('Users', "$firstName $lastName ($username)", 'USER#' . $id, $callerId);

    vk_api_ok(['user' => vk_api_user_row($load())]);
}

vk_api_ok(['user' => vk_api_user_row($load())]);
