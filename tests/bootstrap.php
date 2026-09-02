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

// Stub vk_api_error() — the real one (includes/api_bootstrap.php) echoes JSON
// and calls exit, which a unit test cannot observe or recover from. The
// shared api_*.php helper files (api_contributions.php, api_fines.php,
// api_transactions.php, api_death_expenses.php) are deliberately config-free
// so they can be unit-tested without a database, which means they never load
// the real vk_api_error() at all.
//
// Without this stub, a test that expects a refusal — expectException(Throwable
// ::class) around a call that should hit vk_api_error() — was actually
// catching PHP's own "call to undefined function" fatal (an \Error, which
// does implement \Throwable) rather than the refusal it claimed to prove. That
// passes identically whether the validation being tested is correct or absent
// entirely. Found while building the Condolences module; it had been true of
// every expectException(Throwable::class) test in FinesApiTest and
// TransactionsApiTest since they were written — none of them were wrong about
// what should happen, they just were not proving it.
//
// Thrown as a RuntimeException carrying the status/code/message, mirroring
// redirectTo() above, so a test can assert on the specific refusal reason
// rather than merely that "something" was thrown.
if (!function_exists('vk_api_error')) {
    function vk_api_error(int $httpStatus, string $code, string $message): never
    {
        throw new \RuntimeException("[$httpStatus $code] $message");
    }
}

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/permissions.php';
