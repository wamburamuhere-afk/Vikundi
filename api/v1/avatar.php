<?php
/**
 * GET /api/v1/avatar?name=<filename> — stream one avatar image
 *
 * ADDED — a real gap found while building Module 18, not a plan item.
 * api/v1/profile.php's own `avatar_url` initially pointed at
 * api/get_upload.php (helpers.php's vk_avatar_url()), which gates on
 * includes/require_auth.php — a SESSION check. A token-authenticated mobile
 * client has no session and would get a 401 on every avatar it tried to
 * render, including its own — confirmed live before this file existed.
 *
 * Mirrors api/get_upload.php's `type=avatar` branch exactly (same directory,
 * same filename charset whitelist, same realpath()+prefix containment
 * backstop, same byte-vs-extension check via getimagesize()) — just gated on
 * a verified token instead of a session. That branch's own comment says the
 * rule is already the simplest possible one: "Any authenticated user may see
 * them" — no per-owner check, unlike that file's signature branch, which
 * this endpoint does not need and does not replicate.
 *
 * A general top-level resource, not nested under /profile — this serves any
 * avatar by its stored filename (returned by GET /profile, and reusable by
 * any future endpoint that names a sender/member's avatar), not only the
 * caller's own.
 */

require_once __DIR__ . '/../../includes/api_bootstrap.php';

vk_api_cors();
vk_api_require_method(['GET']);

vk_api_require_auth();

const VK_API_AVATAR_MIME = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

$name = (string) ($_GET['name'] ?? '');
if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,254}$/', $name) || str_contains($name, '..')) {
    vk_api_error(400, 'invalid_name', 'Invalid asset name.');
}

$baseDir = realpath(dirname(__DIR__, 2) . '/uploads/avatars');
if ($baseDir === false) {
    vk_api_error(404, 'not_found', 'No avatar was found with that name.');
}

$path = realpath($baseDir . DIRECTORY_SEPARATOR . $name);
if ($path === false || strpos($path, $baseDir) !== 0 || !is_file($path)) {
    vk_api_error(404, 'not_found', 'No avatar was found with that name.');
}

$ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
if (!isset(VK_API_AVATAR_MIME[$ext]) || @getimagesize($path) === false) {
    vk_api_error(403, 'unsupported_type', 'Unsupported asset type.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . VK_API_AVATAR_MIME[$ext]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, max-age=300');
readfile($path);
