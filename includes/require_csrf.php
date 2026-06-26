<?php
/**
 * includes/require_csrf.php — central CSRF gate for state-changing endpoints.
 *
 * `require_once` this at the top of any action/api/ajax endpoint that mutates
 * state. On an unsafe HTTP method (POST/PUT/PATCH/DELETE) it requires a valid
 * per-session CSRF token; without one it emits a clean JSON 403 and stops, so
 * forged cross-site requests never reach the endpoint's logic (audit H6).
 *
 * Safe methods (GET/HEAD/OPTIONS) pass straight through. The token may arrive
 * as the `X-CSRF-Token` header (fetch()) or the `csrf_token` POST field (HTML
 * forms) — see includes/csrf.php.
 *
 * Pairs with require_auth.php (authentication) and the canX()/requirePermission
 * helpers (authorization). Place after require_auth.php where both are used.
 */

require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__vk_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!csrf_is_safe_method($__vk_method)) {
    $__vk_token = csrf_extract_token($_SERVER, $_POST);
    if (!csrf_verify($__vk_token)) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(403);
        }
        // Include both keys so it fits either response convention used in the app.
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'message' => 'Invalid or missing security token. Please refresh the page and try again.',
        ]);
        exit;
    }
}
