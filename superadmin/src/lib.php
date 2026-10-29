<?php
/**
 * superadmin/src/lib.php — small pure helpers for the super-admin panel.
 * No database, no session, no output: everything here is unit-tested.
 */

if (!function_exists('vk_admin_route')) {
    /**
     * Match a request path to a page. Returns [page, param|null] or null.
     *   /                   → groups
     *   /groups             → groups
     *   /groups/<sub>       → group, <sub>
     *   /requests           → requests
     *   /requests/<id>      → request, <id>
     *   /create             → create (the new-group form)
 *   /history, /login, /logout, /account
     */
    function vk_admin_route(string $path): ?array
    {
        $path = '/' . trim((string) parse_url($path, PHP_URL_PATH), '/');
        return match (true) {
            $path === '/', $path === '/groups' => ['groups', null],
            (bool) preg_match('#^/groups/([a-z0-9-]{3,30})$#', $path, $m) => ['group', $m[1]],
            $path === '/create' => ['create', null],
            $path === '/requests' => ['requests', null],
            (bool) preg_match('#^/requests/([1-9][0-9]{0,9})$#', $path, $m) => ['request', $m[1]],
            $path === '/history' => ['history', null],
            $path === '/login' => ['login', null],
            $path === '/logout' => ['logout', null],
            $path === '/account' => ['account', null],
            default => null,
        };
    }
}

if (!function_exists('vk_admin_after_failed_login')) {
    /**
     * A wrong password: the new failed-attempt count, and the lock end time
     * (Unix seconds) when this failure is the 5th, else null. A lock resets
     * the count.
     *
     * @return array{0:int,1:?int}
     */
    function vk_admin_after_failed_login(int $failedSoFar, int $now, int $maxTries = 5, int $lockSeconds = 900): array
    {
        $failed = $failedSoFar + 1;
        if ($failed >= $maxTries) {
            return [0, $now + $lockSeconds];
        }
        return [$failed, null];
    }
}

if (!function_exists('vk_admin_is_locked')) {
    function vk_admin_is_locked(?string $lockedUntil, int $now): bool
    {
        return $lockedUntil !== null && $lockedUntil !== '' && strtotime($lockedUntil) > $now;
    }
}

if (!function_exists('vk_admin_session_expired')) {
    /** Idle for more than $idleSeconds (default 30 minutes). */
    function vk_admin_session_expired(?int $lastSeen, int $now, int $idleSeconds = 1800): bool
    {
        return $lastSeen === null || ($now - $lastSeen) > $idleSeconds;
    }
}

if (!function_exists('vk_admin_bytes')) {
    /** 1536 → "1.5 KB"; one decimal under 10, none above. */
    function vk_admin_bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        if ($i === 0) {
            return $bytes . ' B';
        }
        return ($v < 10 ? number_format($v, 1) : number_format($v, 0)) . ' ' . $units[$i];
    }
}

if (!function_exists('vk_admin_db_ts')) {
    /**
     * A DATETIME from MySQL as a Unix time. MySQL writes NOW() in its own time
     * zone, which may differ from PHP's: $mysqlOffset is MySQL's offset from UTC
     * in seconds (SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())).
     */
    function vk_admin_db_ts(?string $datetime, int $mysqlOffset): ?int
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $t = strtotime($datetime . ' UTC');
        return $t === false ? null : $t - $mysqlOffset;
    }
}

if (!function_exists('vk_admin_when')) {
    /** "Today 02:15", "Yesterday 18:40", or "12 Oct 2026 09:05", in PHP's time zone. '' for null. */
    function vk_admin_when(?int $t, int $now): string
    {
        if ($t === null) {
            return '';
        }
        $day = date('Y-m-d', $t);
        if ($day === date('Y-m-d', $now)) {
            return 'Today ' . date('H:i', $t);
        }
        if ($day === date('Y-m-d', strtotime('-1 day', $now))) {
            return 'Yesterday ' . date('H:i', $t);
        }
        return date('j M Y H:i', $t);
    }
}

if (!function_exists('vk_admin_status_class')) {
    /** Bootstrap colour for a group or request status. */
    function vk_admin_status_class(string $status): string
    {
        return match ($status) {
            'active', 'done' => 'success',
            'suspended', 'waiting', 'running', 'provisioning' => 'warning',
            'failed' => 'danger',
            default => 'secondary', // archived, unknown
        };
    }
}

if (!function_exists('vk_admin_filter')) {
    /** A filter value from the query string, or '' when it is not one of $allowed. */
    function vk_admin_filter(?string $value, array $allowed): string
    {
        $value = (string) $value;
        return in_array($value, $allowed, true) ? $value : '';
    }
}

if (!function_exists('vk_admin_search')) {
    /** A search box value: trimmed, at most 60 characters, no control characters. */
    function vk_admin_search(?string $q): string
    {
        $q = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $q));
        return mb_substr((string) $q, 0, 60);
    }
}

if (!function_exists('vk_admin_csrf_valid')) {
    function vk_admin_csrf_valid(?string $sessionToken, ?string $sent): bool
    {
        return is_string($sessionToken) && $sessionToken !== '' && is_string($sent) && hash_equals($sessionToken, $sent);
    }
}

if (!function_exists('vk_admin_security_headers')) {
    /**
     * Headers for every panel page. The panel runs no JavaScript at all, so
     * scripts are refused outright; styles only from here and jsdelivr.
     */
    function vk_admin_security_headers(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'none'; style-src 'self' https://cdn.jsdelivr.net; font-src https://cdn.jsdelivr.net; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'Strict-Transport-Security' => 'max-age=31536000',
        ];
    }
}

if (!function_exists('vk_admin_allowed_actions')) {
    /**
     * What the group page offers for a group's status. A group being created
     * or already archived offers nothing; archive is never undone from the panel.
     * A demo group can also have its sample data cleared (it then becomes a real group).
     */
    function vk_admin_allowed_actions(string $status, bool $isDemo = false): array
    {
        $actions = match ($status) {
            'active'    => ['suspend', 'archive', 'cert', 'features'],
            'suspended' => ['resume', 'archive', 'cert', 'features'],
            default     => [],
        };
        return ($actions && $isDemo) ? [...$actions, 'clear_demo'] : $actions;
    }
}

if (!function_exists('vk_admin_username_from_output')) {
    /** The first Chairperson's username from a create request's (redacted) output. */
    function vk_admin_username_from_output(?string $output): ?string
    {
        return preg_match('/First Chairperson: username (\S+),/', (string) $output, $m) ? $m[1] : null;
    }
}
