<?php
/**
 * POST /api/v1/group-settings/logo — replace the group's logo.
 *
 * Multipart only, field name `logo`. PUT /group-settings is JSON and carries no
 * file, so the upload is a separate endpoint rather than a field on that one; a
 * client does not have to switch the settings form to multipart to save a phone
 * number.
 *
 * WHO. The same admin/Secretary rule as PUT, via the same helper — a client that
 * was given the settings block to edit can also change the logo, and one that
 * was not, cannot.
 *
 * WHERE IT IS STORED. assets/images/, under a bare filename written to
 * group_settings.group_logo — the convention actions/save_group_settings.php
 * already uses, and the one eighteen web call sites and the TCPDF printouts read
 * with '/assets/images/' . $group_logo. Storing it anywhere else would mean a
 * logo uploaded from the phone rendered in the app but nowhere on the web or on
 * a single printed report.
 *
 * That directory is web-served, which is exactly why the type rules are strict:
 * the extension is whitelisted, the bytes are sniffed and must agree, and SVG is
 * refused because <svg><script>…</script></svg> served from this origin is
 * stored XSS with the session cookie in reach.
 *
 * The previous logo file is left on disk. Printed PDFs and cached pages still
 * reference it by name, and deleting the file would blank the logo on documents
 * that were already issued.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';
require_once __DIR__ . '/../../includes/api_group_settings.php';
require_once __DIR__ . '/../../includes/api_upload.php';
require_once __DIR__ . '/../../includes/activity_logger.php';

vk_api_cors();
vk_api_require_method(['POST']);

$auth = $auth ?? vk_api_require_auth();

if (!vk_group_settings_may_edit($auth)) {
    vk_api_error(403, 'forbidden', 'You do not have permission to change the group logo.');
}

if (!isset($_FILES['logo'])
    || (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    vk_api_error(422, 'no_file', 'Attach the image as a multipart field named "logo".');
}

// Refused before storing, not after: vk_api_store_upload() also accepts PDF,
// which is a valid upload but not a valid logo — it cannot render in an <img>,
// and a logo that silently fails to display is worse than one that is refused.
$ext = strtolower((string) pathinfo((string) ($_FILES['logo']['name'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($ext, vk_group_settings_logo_types(), true)) {
    vk_api_error(422, 'invalid_logo', 'The logo must be a JPG, PNG, GIF or WEBP image.');
}

// 2 MB rather than the 5 MB default: this is a header image, and a phone camera
// original at full size is a slow load on every page that renders it.
[$stored, $err] = vk_api_store_upload(
    $_FILES['logo'],
    dirname(__DIR__, 2) . '/assets/images',
    'group_logo',
    2097152
);
if ($err !== null) {
    vk_api_error(422, 'invalid_logo', $err);
}

$st = $pdo->prepare(
    'INSERT INTO group_settings (setting_key, setting_value, updated_at)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
);
$st->execute(['group_logo', $stored]);

// Explicit user id — logActivity() resolves 0 from $_SESSION, which the API has
// not got.
logUpdate('Group Settings', 'Group logo (' . $stored . ')', 'SETTINGS', $auth['user_id']);

vk_api_ok([
    'logo'     => $stored,
    'logo_url' => vk_group_settings_logo_url($stored),
    'message'  => 'The group logo was updated.',
]);
