<?php
/**
 * includes/session_guard.php — session lifetime enforcement (audit SEC-009).
 *
 * The session cookie is configured with `lifetime => 0` (roots.php:16), i.e. it
 * dies with the browser — but nothing on the server side ever expired a session.
 * A session that was never logged out stayed valid indefinitely, which is what
 * makes SEC-010 (permissions cached at login, `reloadPermissions()` never called)
 * open-ended: a treasurer whose rights are revoked keeps them until they choose
 * to log out.
 *
 * Two independent bounds, both enforced on every authenticated request:
 *
 *   IDLE     — time since the last request. Deliberately generous: this system is
 *              used live during group meetings, where a treasurer's screen can sit
 *              untouched for hours between entries. An aggressive idle timeout
 *              would log people out mid-meeting, which is real friction for no
 *              real gain.
 *   ABSOLUTE — time since login, regardless of activity. Bounds a stolen or fixed
 *              session ID to at most one day.
 *
 * Tune these two constants; nothing else needs to change.
 */

// 8 hours — covers a full working day and any plausible meeting.
if (!defined('VK_SESSION_IDLE_SECONDS'))     define('VK_SESSION_IDLE_SECONDS', 8 * 60 * 60);
// 24 hours — a user logs in at most once a day.
if (!defined('VK_SESSION_ABSOLUTE_SECONDS')) define('VK_SESSION_ABSOLUTE_SECONDS', 24 * 60 * 60);

if (!function_exists('vk_session_expired')) {
    /**
     * Has the current session outlived either bound?
     *
     * Returns false when there is no session to expire, and false when the
     * markers are absent — a session established before this code shipped has
     * neither, and must not be killed mid-request. Those sessions are stamped by
     * vk_session_touch() on their next request and bounded from then on.
     */
    function vk_session_expired(?int $now = null): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        $now = $now ?? time();

        $loginTime = $_SESSION['login_time'] ?? null;
        if (is_int($loginTime) && ($now - $loginTime) > VK_SESSION_ABSOLUTE_SECONDS) {
            return true;
        }

        $lastActivity = $_SESSION['last_activity'] ?? null;
        if (is_int($lastActivity) && ($now - $lastActivity) > VK_SESSION_IDLE_SECONDS) {
            return true;
        }

        return false;
    }
}

if (!function_exists('vk_session_touch')) {
    /** Record this request as activity, and backfill the markers on legacy sessions. */
    function vk_session_touch(?int $now = null): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }
        $now = $now ?? time();
        if (!isset($_SESSION['login_time'])) {
            $_SESSION['login_time'] = $now;
        }
        $_SESSION['last_activity'] = $now;
    }
}

if (!function_exists('vk_session_end')) {
    /**
     * Tear down an expired session completely — data, cookie and server-side
     * record — so the expired ID cannot be presented again.
     */
    function vk_session_end(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
