<?php
/**
 * includes/require_login.php — central authentication gate for HTML UI pages.
 *
 * `require_once` this at the very top of a protected page — after roots.php (so
 * getUrl() is available) but BEFORE any `$_SESSION['user_id']` access. With no
 * authenticated session it redirects to the login page and stops, so an
 * anonymous hit never reaches page logic.
 *
 * This fixes pages that read the session before their auth check ran, which
 * emitted "Undefined array key user_id" warnings and ran queries with a null
 * user id (audit M5). The JSON-endpoint equivalent is require_auth.php.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    $loginUrl = function_exists('getUrl') ? getUrl('login') : 'login';
    if (!headers_sent()) {
        header('Location: ' . $loginUrl);
    }
    exit;
}
