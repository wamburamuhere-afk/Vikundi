<?php
// includes/csrf.php
//
// Minimal, dependency-free session CSRF protection.
//  - csrf_token()  : returns (and lazily creates) the per-session token
//  - csrf_field()  : returns a ready hidden <input> for forms
//  - csrf_verify() : constant-time check of a submitted token
//
// Nothing here is destructive: forms that do not yet send a token simply fail
// the verify (callers decide the message), and existing behaviour is unchanged
// for any flow that does not call these functions.

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        $t = csrf_token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $stored = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && $token !== '' && $stored !== '' && hash_equals($stored, $token);
    }
}

if (!function_exists('csrf_is_safe_method')) {
    /**
     * Safe HTTP methods carry no state change, so CSRF need not be enforced on
     * them (audit H6). Everything else (POST/PUT/PATCH/DELETE/…) is "unsafe".
     */
    function csrf_is_safe_method(string $method): bool {
        return in_array(strtoupper(trim($method)), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
    }
}

if (!function_exists('csrf_extract_token')) {
    /**
     * Pull the submitted CSRF token from a request. Browsers using fetch() send
     * it as the `X-CSRF-Token` header (works for both FormData and JSON bodies);
     * classic HTML forms send it as the `csrf_token` POST field. The header is
     * preferred when both are present.
     *
     * @param array $server Typically $_SERVER.
     * @param array $post   Typically $_POST.
     */
    function csrf_extract_token(array $server, array $post): ?string {
        $header = $server['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $field = $post['csrf_token'] ?? null;
        return is_string($field) && $field !== '' ? $field : null;
    }
}
