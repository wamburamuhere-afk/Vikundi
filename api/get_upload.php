<?php
/**
 * api/get_upload.php — gated reader for images stored under uploads/.
 *
 * Batch 1 added uploads/.htaccess (`Require all denied`) to close SEC-011: private
 * documents and member e-signature images were fetchable by URL alone, bypassing
 * vk_user_can_access_document() entirely. That deny is correct and stays. It also
 * broke the two places that rendered uploads with a direct <img src>: member
 * avatars, and — the one that matters — the e-signature images on printed
 * vouchers (includes/workflow_signature_row.php). A printed petty-cash voucher is
 * a signed financial document; losing the approving officer's signature degrades
 * a compliance artifact.
 *
 * This restores rendering through a gate instead of through the filesystem,
 * following the pattern downloadDocumentLocal()
 * (app/constant/document/document_library.php:180-188) already implements.
 *
 * DESIGN NOTES
 *
 * 1. The client never supplies a path. It supplies a `type` from a fixed
 *    whitelist and one or two constrained identifiers; the server owns the base
 *    directory and assembles the path itself.
 * 2. For signatures the identifiers are additionally checked against
 *    `user_signatures` — the request must correspond to a real signature record,
 *    so an arbitrary file dropped into the directory is still unreachable.
 * 3. realpath() + prefix containment is the backstop, copied from
 *    api/download_backup.php:18-25, which was verified traversal-safe.
 * 4. Content-Type comes from a whitelist keyed on the resolved extension, and
 *    getimagesize() must agree the bytes really are an image. The supplied
 *    extension is never echoed into a header.
 */

require_once __DIR__ . '/../roots.php';            // $pdo, session, permission helpers
require_once __DIR__ . '/../includes/require_auth.php';   // 401 for anonymous callers

/** Asset type -> base directory, relative to ROOT_DIR. The client cannot influence this. */
const VK_UPLOAD_ROOTS = [
    'avatar'    => 'uploads/avatars',
    'signature' => 'uploads/signatures',
];

/** Resolved extension -> Content-Type. Anything absent is refused. */
const VK_UPLOAD_MIME = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

/** Plain-text failure; this endpoint is consumed by <img>, so JSON would be noise. */
function vk_upload_fail(int $code, string $msg): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $msg;
    exit;
}

$type = (string) ($_GET['type'] ?? '');
if (!isset(VK_UPLOAD_ROOTS[$type])) {
    vk_upload_fail(400, 'Unknown asset type.');
}

// A single path segment, conservative charset. Rejects '/', '\' and '..' by
// construction rather than by blacklist. The first character may not be '.' (no
// dotfiles) or '-' (never let a filename look like a CLI switch); '_' is allowed
// because it is an ordinary character in stored filenames.
$name = (string) ($_GET['name'] ?? '');
if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,254}$/', $name) || str_contains($name, '..')) {
    vk_upload_fail(400, 'Invalid asset name.');
}

$baseDir = realpath(ROOT_DIR . '/' . VK_UPLOAD_ROOTS[$type]);
if ($baseDir === false) {
    vk_upload_fail(404, 'Not found.');
}

if ($type === 'signature') {
    // Signatures are stored per owner: uploads/signatures/<user_id>/<file>.
    $owner = (int) ($_GET['owner'] ?? 0);
    if ($owner <= 0) {
        vk_upload_fail(400, 'Invalid asset owner.');
    }

    // The request must name a real signature record. This is what stops an
    // arbitrary file inside the directory being served, and it also gives us the
    // owner identity the authorisation decision below needs.
    $stmt = $pdo->prepare(
        "SELECT user_id FROM user_signatures
          WHERE user_id = ? AND (file_path LIKE ? OR thumbnail_path LIKE ?)
          LIMIT 1"
    );
    $stmt->execute([$owner, '%/' . $name, '%/' . $name]);
    if ($stmt->fetchColumn() === false) {
        vk_upload_fail(404, 'Not found.');
    }

    // A signature is readable by the person who signed it, and by anyone entitled
    // to look at a surface that embeds signatures — the document workflow, or the
    // vouchers the print pages render. Deliberately matched to those pages rather
    // than made stricter: a narrower rule here would blank the signature on a
    // voucher the viewer is already permitted to open.
    $isOwner = ((int) ($_SESSION['user_id'] ?? 0)) === $owner;
    $maySee  = $isOwner
        || isAdmin()
        || canView('document_workflow')
        || canView('e_signatures')
        || canView('manage_contributions')
        || canView('death_expenses')
        || canView('expenses');

    if (!$maySee) {
        vk_upload_fail(403, 'You do not have permission to view this signature.');
    }

    $candidate = $baseDir . DIRECTORY_SEPARATOR . $owner . DIRECTORY_SEPARATOR . $name;
} else {
    // Avatars are member profile photos rendered across the profile screens. Any
    // authenticated user may see them; require_auth above is the whole gate.
    $candidate = $baseDir . DIRECTORY_SEPARATOR . $name;
}

// Containment backstop (api/download_backup.php:18-25). Even with the charset
// constraint above, never serve anything that does not resolve inside the base.
$path = realpath($candidate);
if ($path === false || strpos($path, $baseDir) !== 0 || !is_file($path)) {
    vk_upload_fail(404, 'Not found.');
}

$ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
if (!isset(VK_UPLOAD_MIME[$ext])) {
    vk_upload_fail(403, 'Unsupported asset type.');
}

// The bytes must actually be an image, so a renamed script can never be served
// with an image Content-Type even if it reached the directory.
if (@getimagesize($path) === false) {
    vk_upload_fail(403, 'Unsupported asset type.');
}

// Discard anything roots.php buffered; this response is binary.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . VK_UPLOAD_MIME[$ext]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $name . '"');
// Private: the response is authorised per session and must not land in a shared cache.
header('Cache-Control: private, max-age=300');
readfile($path);
