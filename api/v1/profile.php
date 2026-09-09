<?php
/**
 * GET /api/v1/profile — the caller's own account
 * PUT /api/v1/profile — edit it (name/email/phone)
 *
 * Mirrors app/constant/profile/my_settings.php's "Profile" tab, not
 * app/constant/profile/profile.php — see includes/api_profile.php's file
 * header for why. Self only, every authenticated user, no leadership gate:
 * my_settings.php has never had one.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_profile.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['GET', 'PUT']);

$auth = vk_api_require_auth();
$callerId = (int) $auth['user_id'];

$load = function () use ($pdo, $callerId): array {
    $st = $pdo->prepare(
        'SELECT u.*, r.role_name, c.customer_id
           FROM users u
           LEFT JOIN roles r ON r.role_id = u.role_id
           LEFT JOIN customers c ON LOWER(c.email) = LOWER(u.email)
          WHERE u.user_id = ?'
    );
    $st->execute([$callerId]);
    return $st->fetch(PDO::FETCH_ASSOC);
};

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $existing = $load();
    $body = vk_api_body();

    $firstName = trim((string) ($body['first_name'] ?? $existing['first_name']));
    $middleName = trim((string) ($body['middle_name'] ?? $existing['middle_name']));
    $lastName  = trim((string) ($body['last_name'] ?? $existing['last_name']));
    if ($firstName === '' || $lastName === '') {
        vk_api_error(422, 'name_required', 'first_name and last_name are required.');
    }

    $email = trim((string) ($body['email'] ?? $existing['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        vk_api_error(422, 'invalid_email', 'A valid email is required.');
    }
    if (strcasecmp($email, (string) $existing['email']) !== 0) {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND user_id != ?');
        $st->execute([$email, $callerId]);
        if ($st->fetchColumn()) {
            vk_api_error(409, 'email_taken', 'That email is already in use.');
        }
    }

    $phone = trim((string) ($body['phone'] ?? $existing['phone']));

    // The old email, captured before the update — mirrors my_settings.php's
    // own ordering exactly: the linked customers row is found by the email
    // the user HAD, then updated to the new name/email/phone, keeping the
    // member record in sync rather than leaving it silently stale.
    $oldEmail = (string) $existing['email'];

    $pdo->prepare('UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ? WHERE user_id = ?')
        ->execute([$firstName, $middleName, $lastName, $email, $phone, $callerId]);

    $pdo->prepare('UPDATE customers SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ? WHERE LOWER(email) = LOWER(?)')
        ->execute([$firstName, $middleName, $lastName, $email, $phone, $oldEmail]);

    $_SESSION['user_id'] = $callerId; // logUpdate() reads the session
    logUpdate('Profile', "$firstName $lastName", 'USER#' . $callerId, $callerId);

    vk_api_ok(['profile' => vk_api_profile_row($load())]);
}

vk_api_ok(['profile' => vk_api_profile_row($load())]);
