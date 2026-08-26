<?php
/**
 * includes/api_auth.php — token issuing and verification for the mobile API.
 *
 * The web app authenticates with a PHP session cookie. A mobile app cannot use
 * that: there is no browser to hold the cookie, and the app has to survive
 * being closed and reopened days later. So the API authenticates with tokens.
 *
 * TWO TOKENS, DELIBERATELY DIFFERENT IN KIND
 *
 *   ACCESS token  — a stateless JWT, short-lived (1 hour). Sent on every
 *                   request as `Authorization: Bearer <token>`. Verifying it
 *                   is a signature check with no database round trip, which is
 *                   the whole point: it keeps the per-request cost near zero.
 *                   The cost of that choice is that it CANNOT be revoked
 *                   before it expires — hence the second token.
 *
 *   REFRESH token — an opaque random string, long-lived (30 days), stored as a
 *                   SHA-256 hash in `api_refresh_tokens`. Revocable, because
 *                   every use checks the row. Used only to obtain a new access
 *                   token, never to authenticate a normal request.
 *
 * The practical effect: a stolen access token is useful for at most an hour, and
 * a logout or a disabled account kills the refresh token immediately, so the
 * attacker cannot mint another. That is the trade the short access lifetime buys.
 *
 * WHAT THE ACCESS TOKEN DOES NOT CARRY: permissions. They are read fresh from
 * the database on each request (vk_api_load_permissions). Baking them into the
 * token would mean a member stripped of treasurer rights keeps them until the
 * token expires — the exact bug SEC-010 describes in the web app's session
 * cache, which there is no reason to rebuild here.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

if (!defined('VK_API_ACCESS_TTL'))  define('VK_API_ACCESS_TTL', 60 * 60);            // 1 hour
if (!defined('VK_API_REFRESH_TTL')) define('VK_API_REFRESH_TTL', 30 * 24 * 60 * 60); // 30 days
if (!defined('VK_API_JWT_ALG'))     define('VK_API_JWT_ALG', 'HS256');

/**
 * The signing secret, or null when it is missing or still the placeholder.
 *
 * Returning null rather than falling back to a default is the point: a default
 * secret is a secret every deployment shares, which is the same as no secret
 * at all. Callers refuse the request instead.
 */
function vk_api_secret(): ?string
{
    if (!defined('JWT_SECRET')) {
        return null;
    }
    $secret = (string) JWT_SECRET;
    if ($secret === '' || str_starts_with($secret, 'REPLACE_ME')) {
        return null;
    }
    return $secret;
}

/** Issue a signed access token for a user. */
function vk_api_issue_access_token(int $userId, int $roleId, ?int $now = null): string
{
    $secret = vk_api_secret();
    if ($secret === null) {
        throw new RuntimeException('JWT_SECRET is not configured.');
    }
    $now = $now ?? time();

    return JWT::encode([
        'sub'  => $userId,
        'rid'  => $roleId,
        'iat'  => $now,
        'exp'  => $now + VK_API_ACCESS_TTL,
        'typ'  => 'access',
    ], $secret, VK_API_JWT_ALG);
}

/**
 * Verify an access token.
 *
 * @return array{sub:int,rid:int}|null  Claims on success, null on any failure.
 */
function vk_api_verify_access_token(string $token): ?array
{
    $secret = vk_api_secret();
    if ($secret === null || $token === '') {
        return null;
    }

    try {
        $claims = (array) JWT::decode($token, new Key($secret, VK_API_JWT_ALG));
    } catch (ExpiredException $e) {
        return null;
    } catch (Throwable $e) {
        // Bad signature, malformed token, wrong algorithm — all indistinguishable
        // to the caller on purpose. A specific error tells an attacker which part
        // of a forged token to fix next.
        return null;
    }

    // A refresh token must never be accepted where an access token is expected.
    if (($claims['typ'] ?? null) !== 'access') {
        return null;
    }
    if (empty($claims['sub'])) {
        return null;
    }

    return ['sub' => (int) $claims['sub'], 'rid' => (int) ($claims['rid'] ?? 0)];
}

/**
 * Mint a refresh token: returns the raw value (given to the client once, never
 * stored) and writes only its hash.
 */
function vk_api_issue_refresh_token(PDO $pdo, int $userId, ?string $userAgent = null, ?int $now = null): string
{
    $now = $now ?? time();
    $raw = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("
        INSERT INTO api_refresh_tokens (user_id, token_hash, issued_at, expires_at, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        hash('sha256', $raw),
        date('Y-m-d H:i:s', $now),
        date('Y-m-d H:i:s', $now + VK_API_REFRESH_TTL),
        $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
    ]);

    return $raw;
}

/**
 * Look up a live refresh token by its raw value.
 *
 * @return array{id:int,user_id:int}|null  null when unknown, revoked or expired.
 */
function vk_api_find_refresh_token(PDO $pdo, string $raw, ?int $now = null): ?array
{
    if ($raw === '') {
        return null;
    }
    $now = $now ?? time();

    $stmt = $pdo->prepare("
        SELECT id, user_id
          FROM api_refresh_tokens
         WHERE token_hash = ?
           AND revoked_at IS NULL
           AND expires_at > ?
         LIMIT 1
    ");
    $stmt->execute([hash('sha256', $raw), date('Y-m-d H:i:s', $now)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']] : null;
}

/** Revoke a single refresh token by its raw value. Returns true if a row was revoked. */
function vk_api_revoke_refresh_token(PDO $pdo, string $raw, ?int $now = null): bool
{
    if ($raw === '') {
        return false;
    }
    $stmt = $pdo->prepare("
        UPDATE api_refresh_tokens
           SET revoked_at = ?
         WHERE token_hash = ?
           AND revoked_at IS NULL
    ");
    $stmt->execute([date('Y-m-d H:i:s', $now ?? time()), hash('sha256', $raw)]);

    return $stmt->rowCount() > 0;
}

/**
 * Revoke every live refresh token for a user.
 *
 * Used when an account is disabled: the point of a revocable refresh token is
 * that disabling an account can actually cut off the phone in someone's pocket.
 */
function vk_api_revoke_all_for_user(PDO $pdo, int $userId, ?int $now = null): int
{
    $stmt = $pdo->prepare("
        UPDATE api_refresh_tokens
           SET revoked_at = ?
         WHERE user_id = ?
           AND revoked_at IS NULL
    ");
    $stmt->execute([date('Y-m-d H:i:s', $now ?? time()), $userId]);

    return $stmt->rowCount();
}

/** Read the bearer token out of the request, or '' when absent. */
function vk_api_bearer_token(array $server, ?array $headers = null): string
{
    $header = $server['HTTP_AUTHORIZATION']
        ?? $server['REDIRECT_HTTP_AUTHORIZATION']  // Apache strips it into this under some configs
        ?? '';

    // Neither key is guaranteed. On this stack (apache2handler) $_SERVER carries
    // no Authorization entry at all — verified by probing a live request — while
    // getallheaders() returns it intact. Without this fallback every
    // authenticated endpoint returns exactly the same 401 for a valid token as
    // for no token, which is indistinguishable from a broken secret.
    if ((!is_string($header) || $header === '')) {
        if ($headers === null && function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
        }
        if (is_array($headers)) {
            // HTTP header names are case-insensitive and the SAPI decides the
            // casing it hands back, so never match on an exact key.
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
    }

    if (!is_string($header) || $header === '') {
        return '';
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
        return '';
    }
    return $m[1];
}

/** The statuses that may never hold a live API token. Mirrors actions/login.php. */
function vk_api_blocked_statuses(): array
{
    return ['pending', 'rejected', 'inactive', 'suspended', 'deleted'];
}

/**
 * Load a user's permissions fresh from the database.
 *
 * Deliberately NOT read from the token or a session cache — see the file header.
 *
 * @return array<string,array<string,bool>>
 */
function vk_api_load_permissions(PDO $pdo, int $roleId): array
{
    $stmt = $pdo->prepare("
        SELECT p.page_key, rp.can_view, rp.can_create, rp.can_edit, rp.can_delete, rp.can_review, rp.can_approve
          FROM role_permissions rp
          JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE rp.role_id = ?
    ");
    $stmt->execute([$roleId]);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[$row['page_key']] = [
            'view'    => (bool) $row['can_view'],
            'create'  => (bool) $row['can_create'],
            'edit'    => (bool) $row['can_edit'],
            'delete'  => (bool) $row['can_delete'],
            'review'  => (bool) $row['can_review'],
            'approve' => (bool) $row['can_approve'],
        ];
    }
    return $out;
}

// --- Authorisation ------------------------------------------------------------
// These two are pure — a role id and a permission map in, a boolean out — and
// live here beside vk_api_load_permissions() rather than in api_bootstrap.php so
// that code needing only the RULES can load them without pulling in config.php
// and a database connection. api_bootstrap.php requires this file, so every
// endpoint still gets them.

if (!function_exists('vk_api_is_admin')) {
    /**
     * Admin bypass, matching core/permissions.php's isAdmin() by role_id.
     *
     * Only the numeric role ids are honoured here. The web app also treats a set
     * of role *names* as admin, which is finding SEC-015 — renaming a role
     * silently grants or revokes admin. That behaviour is not carried into the
     * API.
     */
    function vk_api_is_admin(int $roleId): bool
    {
        return in_array($roleId, [1, 2, 12], true);
    }
}

if (!function_exists('vk_api_can')) {
    /** @param array $auth The array returned by vk_api_require_auth() */
    function vk_api_can(array $auth, string $action, string $pageKey): bool
    {
        if (vk_api_is_admin((int) $auth['role_id'])) {
            return true;
        }
        return !empty($auth['permissions'][$pageKey][$action]);
    }
}
