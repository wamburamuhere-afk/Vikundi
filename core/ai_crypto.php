<?php
/**
 * core/ai_crypto.php
 * ------------------
 * Tiny symmetric encryption for the AI provider API key, so it is never stored
 * in plain text in the database. Uses AES-256-CBC with a random key kept in a
 * gitignored file (includes/ai_secret.key), generated automatically on first use.
 *
 *   aiEncryptSecret(string $plain): string   — returns base64 ciphertext (iv prepended)
 *   aiDecryptSecret(string $cipher): ?string  — returns plain text, or null on failure
 */

if (!function_exists('_aiSecretKey')) {
    function _aiSecretKey(): string
    {
        $keyFile = __DIR__ . '/../includes/ai_secret.key';
        if (is_file($keyFile)) {
            $raw = trim((string)file_get_contents($keyFile));
            $key = base64_decode($raw, true);
            if ($key !== false && strlen($key) === 32) {
                return $key;
            }
        }
        // Generate and persist a fresh 256-bit key.
        $key = random_bytes(32);
        @file_put_contents($keyFile, base64_encode($key));
        @chmod($keyFile, 0600);
        return $key;
    }
}

if (!function_exists('aiEncryptSecret')) {
    function aiEncryptSecret(string $plain): string
    {
        if ($plain === '') return '';
        $key = _aiSecretKey();
        $iv  = random_bytes(16);
        $ct  = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ct === false) return '';
        return base64_encode($iv . $ct);
    }
}

if (!function_exists('aiDecryptSecret')) {
    function aiDecryptSecret(string $cipher): ?string
    {
        if ($cipher === '') return null;
        $blob = base64_decode($cipher, true);
        if ($blob === false || strlen($blob) <= 16) return null;
        $key = _aiSecretKey();
        $iv  = substr($blob, 0, 16);
        $ct  = substr($blob, 16);
        $pt  = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $pt === false ? null : $pt;
    }
}
