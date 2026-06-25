<?php
/**
 * includes/env.php — minimal environment detection (no dependencies).
 *
 * Loaded at the very top of roots.php to decide whether PHP errors may be
 * DISPLAYED. Production must never show error text to end users (audit B1):
 * leaked internals are a security risk and they corrupt AJAX/JSON responses.
 *
 * Errors are always reported and logged; only the on-screen DISPLAY is gated.
 */

if (!function_exists('vikundi_is_dev_host')) {
    /**
     * True only for local/development contexts. Unknown or empty hosts are
     * treated as production (safe default: errors hidden).
     *
     * @param string|null $host Typically $_SERVER['HTTP_HOST'].
     * @param string|null $sapi Override for PHP_SAPI (testing).
     */
    function vikundi_is_dev_host(?string $host, ?string $sapi = null): bool
    {
        $sapi = $sapi ?? PHP_SAPI;
        if ($sapi === 'cli' || $sapi === 'cli-server') {
            return true; // developer terminal / built-in server
        }

        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return false; // unknown host -> assume production
        }
        // IPv6 loopback (contains colons; handle before stripping the :port).
        if ($host === '::1' || $host === '[::1]') {
            return true;
        }
        $host = explode(':', $host)[0]; // strip :port for host:port forms

        if ($host === 'localhost' || $host === '127.0.0.1') {
            return true;
        }
        return str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }
}
