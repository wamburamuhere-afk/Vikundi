<?php
// Start session — required for permission and auth tests
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Stub redirectTo() — the real one calls header() which can't fire in CLI tests
if (!function_exists('redirectTo')) {
    function redirectTo(string $page): never
    {
        throw new \RuntimeException("Redirect attempted to: $page");
    }
}

// Stub isAuthenticated() — used by requireViewPermission in permissions.php
if (!function_exists('isAuthenticated')) {
    function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']);
    }
}

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';
