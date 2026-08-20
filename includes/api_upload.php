<?php
/**
 * includes/api_upload.php — validated file storage for the mobile API.
 *
 * The web handlers store an upload under the extension the client supplied:
 *
 *     $ext = pathinfo($_FILES['kianzio_slip']['name'], PATHINFO_EXTENSION);
 *     move_uploaded_file($tmp, $dir . '/kianzio_' . time() . '_' . uniqid() . '.' . $ext);
 *
 * with no whitelist. Today the only thing preventing an uploaded .php from being
 * executed is uploads/.htaccess ("Require all denied"). That file is one server
 * migration or one AllowOverride change away from being ignored, and it is the
 * sole control. The API validates instead of inheriting that position.
 *
 * Three checks, all of which must pass:
 *   1. the extension is on a whitelist (never taken from the client's filename
 *      for the stored name — it is re-derived from the whitelist match);
 *   2. the real MIME type, read from the file's own bytes via finfo, agrees;
 *   3. the size is within a cap.
 *
 * (2) matters because an attacker controls the filename and the declared
 * Content-Type but not the bytes. A .php renamed to .jpg fails the sniff.
 */

if (!function_exists('vk_api_upload_error_message')) {
    /** PHP's upload error codes as something a client can act on. */
    function vk_api_upload_error_message(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder configured.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
            default               => 'The file could not be uploaded.',
        };
    }
}

if (!function_exists('vk_api_allowed_upload_types')) {
    /**
     * extension => acceptable MIME types.
     *
     * Deliberately narrow: a payment slip or a member photo is an image or a
     * PDF. Nothing here can be executed by a web server, so a lapse in the
     * uploads/.htaccess protection is no longer a code-execution route.
     */
    function vk_api_allowed_upload_types(): array
    {
        return [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf'  => ['application/pdf'],
        ];
    }
}

if (!function_exists('vk_api_sniff_mime')) {
    /** The file's real MIME type, from its bytes rather than its name. */
    function vk_api_sniff_mime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            return '';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($mime) ? $mime : '';
    }
}

if (!function_exists('vk_api_validate_upload')) {
    /**
     * Validate one entry from $_FILES. Returns null when acceptable, or a
     * client-facing reason to refuse.
     *
     * Pure enough to unit test: it takes the file array and a path rather than
     * reaching into globals.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     */
    function vk_api_validate_upload(array $file, int $maxBytes, ?string $sniffedMime = null): ?string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return vk_api_upload_error_message($error);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return 'The file is empty.';
        }
        if ($size > $maxBytes) {
            return sprintf('The file is larger than %d MB.', (int) round($maxBytes / 1048576));
        }

        $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = vk_api_allowed_upload_types();
        if ($ext === '' || !isset($allowed[$ext])) {
            return 'Only JPG, PNG, GIF, WEBP or PDF files are accepted.';
        }

        // A filename and a declared Content-Type are both attacker-controlled;
        // the bytes are not. Skipped only where finfo is unavailable, in which
        // case the extension whitelist still stands.
        if ($sniffedMime !== null && $sniffedMime !== ''
            && !in_array($sniffedMime, $allowed[$ext], true)) {
            return 'The file contents do not match its extension.';
        }

        return null;
    }
}

if (!function_exists('vk_api_store_upload')) {
    /**
     * Validate and move one upload into $destDir.
     *
     * @return array{0:?string,1:?string} [storedFilename, errorMessage]
     */
    function vk_api_store_upload(
        array $file,
        string $destDir,
        string $prefix,
        int $maxBytes = 5242880
    ): array {
        $tmp = (string) ($file['tmp_name'] ?? '');

        // Guard before sniffing: finfo on an arbitrary path would otherwise read
        // a file the request never uploaded.
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return [null, 'No uploaded file was received.'];
        }

        $reason = vk_api_validate_upload($file, $maxBytes, vk_api_sniff_mime($tmp));
        if ($reason !== null) {
            return [null, $reason];
        }

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return [null, 'The server could not create the storage folder.'];
        }

        // The stored extension comes from the whitelist key, not from the
        // client's string, so nothing the client sends ends up in the filename.
        $ext  = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!move_uploaded_file($tmp, rtrim($destDir, '/') . '/' . $name)) {
            return [null, 'The server could not save the file.'];
        }

        return [$name, null];
    }
}
