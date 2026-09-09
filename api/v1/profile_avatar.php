<?php
/**
 * POST /api/v1/profile/avatar — replace the caller's own avatar
 *
 * Multipart only, field name `avatar`. Mirrors api/v1/group-settings_logo.php's
 * shape: PUT /profile is JSON and carries no file, so the upload is its own
 * endpoint. Added beyond todo.md's plan for the same reason every prior
 * module has added a necessary action the plan text didn't spell out — a
 * mobile profile screen with no way to set a photo would be missing the
 * single most obvious thing such a screen does.
 *
 * Built on includes/api_upload.php's vk_api_store_upload() (extension
 * whitelist + byte-sniffing + a random filename), not my_settings.php's own
 * extension-only check — the established, more secure convention already
 * used by every other upload endpoint in this API.
 *
 * Stored in uploads/avatars/, matching my_settings.php's own directory and
 * vk_avatar_url()'s (helpers.php) expectation. The previous avatar file is
 * left on disk, same reasoning as group-settings_logo.php: nothing else in
 * this codebase deletes an old upload on replacement.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_profile.php';
require_once __DIR__ . '/../../includes/api_upload.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = vk_api_require_auth();
$callerId = (int) $auth['user_id'];

if (!isset($_FILES['avatar'])
    || (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    vk_api_error(422, 'no_file', 'Attach the image as a multipart field named "avatar".');
}

[$stored, $err] = vk_api_store_upload(
    $_FILES['avatar'],
    dirname(__DIR__, 2) . '/uploads/avatars',
    'avatar',
    2097152 // 2 MB — a phone camera original at full size is unnecessarily large for an avatar
);
if ($err !== null) {
    vk_api_error(422, 'invalid_avatar', $err);
}

$pdo->prepare('UPDATE users SET avatar = ? WHERE user_id = ?')->execute([$stored, $callerId]);

$_SESSION['user_id'] = $callerId; // logUpdate() reads the session
logUpdate('Profile', 'Avatar changed', 'USER#' . $callerId, $callerId);

vk_api_ok(['avatar' => $stored, 'avatar_url' => vk_api_avatar_url($stored)]);
