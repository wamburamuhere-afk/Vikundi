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
