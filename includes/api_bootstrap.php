<?php
/**
 * includes/api_bootstrap.php — the shared front door for every /api/v1/ endpoint.
 *
 * Include this at the top of an API endpoint and it settles the things every
 * endpoint gets wrong when each one hand-rolls them: JSON content type, CORS
 * preflight, method checking, JSON body parsing, and one consistent error shape.
 *
 * Then call vk_api_require_auth() on any endpoint that needs a signed-in user.
 *
 * WHY NOT REUSE includes/require_auth.php: that gate reads $_SESSION, which a
 * mobile client never has. This is the token equivalent, and the two are kept
 * separate rather than one being taught both tricks — a single gate with two
 * authentication modes is exactly the kind of thing that ends up accidentally
 * accepting neither, or worse, either.
 *
 * NOTE ON PERMISSION CHECKS: the web app checks permissions two different ways
 * (requireViewPermission() vs. in-page role arrays — see todo.md's flagged
 * judgment call #3). The API normalizes on ONE: vk_api_require_permission(),
 * reading the same role_permissions table. Nothing here reads a role name.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api_auth.php';

// --- Response envelope --------------------------------------------------------
// One shape for every endpoint, success or failure, so the Flutter client has a
// single thing to parse rather than a per-endpoint guessing game.

if (!function_exists('vk_api_send')) {
    function vk_api_send(int $httpStatus, array $payload): void
    {
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=utf-8');
            // Responses are per-user and token-authenticated; never let a shared
            // cache hold one.
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('vk_api_ok')) {
    function vk_api_ok(array $data = [], int $httpStatus = 200): void
    {
        vk_api_send($httpStatus, ['status' => 'success', 'data' => $data]);
    }
}

if (!function_exists('vk_api_error')) {
    /**
     * @param string $code  A stable machine-readable code the client can branch on
     *                      (the human message is for display and may be reworded).
     */
    function vk_api_error(int $httpStatus, string $code, string $message): void
    {
        vk_api_send($httpStatus, ['status' => 'error', 'code' => $code, 'message' => $message]);
    }
}

// --- Request plumbing ---------------------------------------------------------

if (!function_exists('vk_api_method')) {
    function vk_api_method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
}

if (!function_exists('vk_api_require_method')) {
    /** @param string[] $allowed */
    function vk_api_require_method(array $allowed): void
    {
        if (!in_array(vk_api_method(), $allowed, true)) {
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $allowed));
            }
            vk_api_error(405, 'method_not_allowed', 'This endpoint does not accept that HTTP method.');
        }
    }
}

if (!function_exists('vk_api_body')) {
    /**
     * The request body as an array, whether it arrived as JSON or as form fields.
     *
     * A mobile client sends JSON; keeping form parsing too means the endpoints
     * stay testable with a plain curl -d during development.
     */
    function vk_api_body(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw ?: '', true);
            $cached = is_array($decoded) ? $decoded : [];
        } else {
            $cached = $_POST;
        }

        return $cached;
    }
}

// --- CORS ---------------------------------------------------------------------
// A native Flutter app is not a browser and sends no Origin, so it needs none of
// this. It is here for the browser-based tooling used while developing (Swagger
// UI, a web build). Deliberately no `Access-Control-Allow-Credentials` and no
// wildcard-with-credentials: this API authenticates by Authorization header, not
// cookies, so there is nothing for a cross-site request to ride on.

if (!function_exists('vk_api_cors')) {
    function vk_api_cors(): void
    {
        if (!headers_sent()) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Max-Age: 600');
        }
        if (vk_api_method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

// --- Authentication -----------------------------------------------------------

if (!function_exists('vk_api_require_auth')) {
    /**
     * Require a valid access token, and confirm the account is still usable.
     *
     * The status re-check matters: the token was signed when the account was
     * active, and a JWT keeps asserting that until it expires. Reading the row
     * means an account disabled five minutes ago stops working now, not in an
     * hour.
     *
     * @return array{user_id:int,role_id:int,username:string,user:array,permissions:array}
     */
    function vk_api_require_auth(): array
    {
        global $pdo;

        if (vk_api_secret() === null) {
            // A deployment that forgot the secret must fail loudly and closed,
            // never fall back to an insecure default.
            vk_api_error(500, 'server_misconfigured', 'The API is not configured for authentication.');
        }

        $claims = vk_api_verify_access_token(vk_api_bearer_token($_SERVER));
        if ($claims === null) {
            vk_api_error(401, 'unauthenticated', 'A valid access token is required.');
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$claims['sub']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            vk_api_error(401, 'unauthenticated', 'A valid access token is required.');
        }
        if (in_array((string) $user['status'], vk_api_blocked_statuses(), true)) {
            vk_api_error(403, 'account_blocked', 'This account is not currently active.');
        }

        $roleId = (int) ($user['role_id'] ?? 0);

        return [
            'user_id'     => (int) $user['user_id'],
            'role_id'     => $roleId,
            'username'    => (string) $user['username'],
            'user'        => $user,
            'permissions' => vk_api_load_permissions($pdo, $roleId),
        ];
    }
}

if (!function_exists('vk_api_require_permission')) {
    function vk_api_require_permission(array $auth, string $action, string $pageKey): void
    {
        if (!vk_api_can($auth, $action, $pageKey)) {
            vk_api_error(403, 'forbidden', 'You do not have permission to do that.');
        }
    }
}

if (!function_exists('vk_api_member_id')) {
    /**
     * The customers.customer_id linked to this user account, or 0 for accounts
     * that are not members (an Admin login has no customers row).
     */
    function vk_api_member_id(int $userId): int
    {
        global $pdo;
        $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
