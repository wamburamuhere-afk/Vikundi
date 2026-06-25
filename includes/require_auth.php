<?php
/**
 * includes/require_auth.php — central authentication gate for JSON endpoints.
 *
 * `require_once` this at the top of any action/api/ajax endpoint that must only
 * run for a logged-in user. With no authenticated session it emits a clean
 * JSON 401 and stops, so unauthenticated callers never reach the endpoint's
 * logic (audit B3).
 *
 * Authentication only — per-permission authorization is handled separately by
 * the canView/canCreate/... helpers.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(401);
    }
    // Include both keys so it fits either response convention used in the app.
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'message' => 'Authentication required. Please log in.',
    ]);
    exit;
}
