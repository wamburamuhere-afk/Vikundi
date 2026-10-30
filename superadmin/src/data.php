<?php
/**
 * superadmin/src/data.php — what the panel reads. Only vikundi_control and each
 * group's data folder (for last backup and disk use); never a group's database.
 */

/** Groups, newest first, optionally filtered by status and a search on name/address. */
function data_groups(string $status = '', string $q = ''): array
{
    $sql = 'SELECT t.*, GROUP_CONCAT(h.host ORDER BY h.host SEPARATOR \', \') AS hosts
              FROM tenants t LEFT JOIN tenant_hosts h ON h.tenant_id = t.id WHERE 1 = 1';
    $args = [];
    if ($status !== '') {
        $sql .= ' AND t.status = ?';
        $args[] = $status;
    }
    if ($q !== '') {
        $sql .= ' AND (t.name LIKE ? OR t.subdomain LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($args, $like, $like);
    }
    $sql .= ' GROUP BY t.id ORDER BY t.created_at DESC, t.id DESC';
    $st = admin_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function data_group(string $sub): ?array
{
    $st = admin_db()->prepare('SELECT * FROM tenants WHERE subdomain = ?');
    $st->execute([$sub]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $h = admin_db()->prepare('SELECT host FROM tenant_hosts WHERE tenant_id = ? ORDER BY host');
    $h->execute([$row['id']]);
    $row['hosts'] = $h->fetchAll(PDO::FETCH_COLUMN);
    return $row;
}

/** The group's data folder, or null when the subdomain is not a plain name. */
function data_group_dir(string $sub): ?string
{
    if (!preg_match('/^[a-z0-9-]{3,30}$/', $sub)) {
        return null;
    }
    return rtrim((string) admin_config()['data_root'], '/') . '/' . $sub;
}

/** Newest .sql file in the group's backups folder: [file name, Unix time] or null. */
function data_last_backup(string $sub): ?array
{
    $dir = data_group_dir($sub);
    $files = $dir ? (glob($dir . '/backups/*.sql') ?: []) : [];
    if (!$files) {
        return null;
    }
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    return [basename($files[0]), (int) filemtime($files[0])];
}

/** Bytes used by the group's data folder (uploads, backups, logos, sessions). */
function data_disk_use(string $sub): ?int
{
    $dir = data_group_dir($sub);
    if (!$dir || !is_dir($dir)) {
        return null;
    }
    $total = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $total += $f->getSize();
            }
        }
    } catch (UnexpectedValueException $e) {
        return null; // a folder we may not read
    }
    return $total;
}

function data_requests(string $status = '', ?string $sub = null, int $limit = 100): array
{
    $sql = 'SELECT r.id, r.action, r.subdomain, r.status, r.warning, r.created_at, r.started_at, r.finished_at,
                   s.full_name AS admin_name
              FROM provision_requests r LEFT JOIN superadmins s ON s.id = r.requested_by WHERE 1 = 1';
    $args = [];
    if ($status !== '') {
        $sql .= ' AND r.status = ?';
        $args[] = $status;
    }
    if ($sub !== null) {
        $sql .= ' AND r.subdomain = ?';
        $args[] = $sub;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT ' . max(1, min(500, $limit));
    $st = admin_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** One request, without the one-time password itself (only whether one is waiting). */
function data_request(int $id): ?array
{
    $st = admin_db()->prepare('SELECT r.id, r.action, r.subdomain, r.params, r.status, r.exit_code, r.warning, r.output,
                                      (r.one_time_secret IS NOT NULL) AS has_secret, r.secret_viewed_at,
                                      r.created_at, r.started_at, r.finished_at, s.full_name AS admin_name
                                 FROM provision_requests r LEFT JOIN superadmins s ON s.id = r.requested_by
                                WHERE r.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function data_history(?string $sub = null, int $limit = 200): array
{
    $sql = 'SELECT * FROM admin_audit_log WHERE 1 = 1';
    $args = [];
    if ($sub !== null && $sub !== '') {
        $sql .= ' AND subdomain = ?';
        $args[] = $sub;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit));
    $st = admin_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Requests still waiting or running: the badge beside Requests in the menu. */
function data_pending_count(): int
{
    static $n = null;
    return $n ??= (int) admin_db()->query("SELECT COUNT(*) FROM provision_requests WHERE status IN ('waiting', 'running')")->fetchColumn();
}

/** Requests in each status, for the Requests page's tabs. */
function data_request_counts(): array
{
    $c = ['' => 0, 'waiting' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
    foreach (admin_db()->query('SELECT status, COUNT(*) AS n FROM provision_requests GROUP BY status') as $r) {
        $c[$r['status']] = (int) $r['n'];
        $c[''] += (int) $r['n'];
    }
    return $c;
}
