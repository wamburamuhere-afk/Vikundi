<?php
/**
 * GET  /api/v1/users — the group's login accounts, paginated — ADMIN/CHAIRPERSON ONLY
 * POST /api/v1/users — create one (add_user.php equivalent)
 *
 * Mirrors app/constant/settings/users.php (list) and add_user.php (create).
 * These are staff/leadership login accounts, not member/customer records —
 * add_user.php has never created a matching `customers` row, and neither
 * does this. See includes/api_settings.php for the gating rationale.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_settings.php';
require_once __DIR__ . '/../../includes/activity_logger.php';
require_once __DIR__ . '/../../includes/registration_validator.php';

vk_api_cors();
vk_api_require_method(['GET', 'POST']);

$auth = vk_api_require_auth();
vk_api_settings_require_admin($auth);
$callerId = (int) $auth['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = vk_api_body();
    $lang = (string) ($auth['user']['preferred_language'] ?? 'en');

    $username = trim((string) ($body['username'] ?? ''));
    if (strlen($username) < 4) {
        vk_api_error(422, 'invalid_username', 'username must be at least 4 characters.');
    }

    $email = trim((string) ($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        vk_api_error(422, 'invalid_email', 'A valid email is required.');
    }

    $firstName = trim((string) ($body['first_name'] ?? ''));
    $lastName  = trim((string) ($body['last_name'] ?? ''));
    if ($firstName === '' || $lastName === '') {
        vk_api_error(422, 'name_required', 'first_name and last_name are required.');
    }

    $roleId = vk_api_users_validate_role($pdo, $body['role_id'] ?? null);
    vk_api_users_validate_unique($pdo, $username, $email);

    $password = (string) ($body['password'] ?? '');
    if ($password === '') {
        vk_api_error(422, 'password_required', 'password is required.');
    }
    vk_api_users_validate_password($password, $lang);

    $status = trim((string) ($body['status'] ?? 'active'));
    if (!in_array($status, vk_api_user_statuses(), true)) {
        vk_api_error(422, 'invalid_status', 'status must be one of: ' . implode(', ', vk_api_user_statuses()) . '.');
    }

    $st = $pdo->prepare(
        'INSERT INTO users (username, email, first_name, last_name, role_id, password, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $st->execute([$username, $email, $firstName, $lastName, $roleId, password_hash($password, PASSWORD_DEFAULT), $status]);
    $newId = (int) $pdo->lastInsertId();

    $_SESSION['user_id'] = $callerId; // logCreate() reads the session
    logCreate('Users', "$firstName $lastName ($username)", 'USER#' . $newId, $callerId);

    $row = $pdo->prepare(
        'SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?'
    );
    $row->execute([$newId]);

    vk_api_ok(['user' => vk_api_user_row($row->fetch(PDO::FETCH_ASSOC))], 201);
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

[$where, $params] = vk_api_users_filters($pdo, $_GET);
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM users u {$whereSql}");
$st->execute($params);
$total = (int) $st->fetchColumn();

$st = $pdo->prepare("
    SELECT u.*, r.role_name
      FROM users u
      LEFT JOIN roles r ON r.role_id = u.role_id
      {$whereSql}
     ORDER BY u.created_at DESC, u.user_id DESC
     LIMIT {$perPage} OFFSET {$offset}
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

vk_api_ok([
    'users' => array_map('vk_api_user_row', $rows),
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        'has_more'    => ($offset + count($rows)) < $total,
    ],
]);
