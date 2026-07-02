<?php
// includes/upload_guard.php
//
// Shared guard for oversized uploads. When a multipart POST exceeds the
// server's post_max_size, PHP silently discards the ENTIRE $_POST and $_FILES
// (the request still runs), which otherwise surfaces as a misleading
// "required field missing" error. These helpers detect that and produce a clear
// message, and size the client-side guard to the server's real limits.

if (!function_exists('vk_ini_bytes')) {
    /**
     * Convert a PHP ini size string ("8M", "512K", "1G", "1048576") to bytes.
     */
    function vk_ini_bytes($val): int {
        $val = trim((string) $val);
        if ($val === '') return 0;
        $n = (int) $val;
        switch (strtolower(substr($val, -1))) {
            case 'g': return $n * 1073741824;
            case 'm': return $n * 1048576;
            case 'k': return $n * 1024;
            default:  return $n;
        }
    }
}

if (!function_exists('vk_post_exceeded_limit')) {
    /**
     * True when this looks like a POST whose body was dropped for exceeding
     * post_max_size: a POST method, empty $_POST and $_FILES, but a non-zero
     * Content-Length.
     */
    function vk_post_exceeded_limit(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && empty($_POST) && empty($_FILES)
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }
}

if (!function_exists('vk_upload_limit_message')) {
    /** A clear "attachments too large" message naming the server limit. */
    function vk_upload_limit_message(bool $is_sw = false): string {
        $limit = ini_get('post_max_size') ?: '?';
        return $is_sw
            ? "Nyaraka ulizoambatisha ni kubwa mno (ukomo wa seva ni $limit). Tafadhali tumia faili ndogo zaidi."
            : "The attached document(s) are too large (server limit is $limit). Please attach smaller files.";
    }
}
