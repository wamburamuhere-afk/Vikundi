<?php
/**
 * superadmin/src/actions.php — the only things the panel writes.
 *
 * Group changes are never made here: a request row is saved and the worker on
 * the server (bin/run_requests.php) carries it out. The panel's own writes are
 * limited to its requests, the one-time password hand-over, the super-admin's
 * own password and the history.
 */

/** Is a request for this group still waiting or running? */
function group_has_pending(string $sub): bool
{
    $st = admin_db()->prepare("SELECT 1 FROM provision_requests WHERE subdomain = ? AND status IN ('waiting','running') LIMIT 1");
    $st->execute([$sub]);
    return (bool) $st->fetchColumn();
}

/** Save a request for the worker. Returns its id. */
function queue_request(int $adminId, string $action, ?string $sub, ?array $params = null): int
{
    admin_db()->prepare('INSERT INTO provision_requests (action, subdomain, params, requested_by) VALUES (?, ?, ?, ?)')
        ->execute([$action, $sub, $params === null ? null : json_encode($params, JSON_UNESCAPED_UNICODE), $adminId]);
    return (int) admin_db()->lastInsertId();
}

function admin_audit(array $me, string $action, ?string $sub, string $result, ?string $detail = null, ?int $requestId = null): void
{
    admin_db()->prepare("INSERT INTO admin_audit_log (actor_type, actor_id, actor_name, action, subdomain, request_id, result, detail, ip)
                         VALUES ('superadmin', ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$me['id'], $me['full_name'], $action, $sub, $requestId, $result, $detail, $_SERVER['REMOTE_ADDR'] ?? null]);
}

/**
 * Hand over a create request's first Chairperson password, once. Whoever's
 * UPDATE clears it gets it; a second click (or a second admin) gets null.
 */
function take_one_time_secret(int $requestId): ?string
{
    $st = admin_db()->prepare("SELECT one_time_secret FROM provision_requests WHERE id = ? AND status = 'done'");
    $st->execute([$requestId]);
    $secret = $st->fetchColumn();
    if (!is_string($secret) || $secret === '') {
        return null;
    }
    $clear = admin_db()->prepare('UPDATE provision_requests SET one_time_secret = NULL, secret_viewed_at = NOW() WHERE id = ? AND one_time_secret = ?');
    $clear->execute([$requestId, $secret]);
    return $clear->rowCount() === 1 ? $secret : null;
}

/** Change the signed-in super-admin's own password. Returns an error message or null. */
function change_own_password(array $me, string $current, string $new, string $again): ?string
{
    $st = admin_db()->prepare('SELECT password_hash FROM superadmins WHERE id = ?');
    $st->execute([$me['id']]);
    if (!password_verify($current, (string) $st->fetchColumn())) {
        return 'Your current password is not right.';
    }
    if ($new !== $again) {
        return 'The two new passwords differ.';
    }
    if ($why = vk_superadmin_password_problem($new, $me['username'])) {
        return $why;
    }
    if (hash_equals($current, $new)) {
        return 'Choose a password different from the current one.';
    }
    admin_db()->prepare('UPDATE superadmins SET password_hash = ?, password_changed_at = NOW() WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
    return null;
}
