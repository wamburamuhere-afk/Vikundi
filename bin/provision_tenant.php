<?php
/**
 * bin/provision_tenant.php — create and manage the groups on the shared server.
 * Command line only. Run as the `ubuntu` user from the shared code folder.
 *
 *   Create a new group (database, MySQL user, secrets, folders, schema, first Chairperson):
 *     php bin/provision_tenant.php --create --subdomain=wisewallet --name="Wise Wallet M-KOBA" \
 *         --chair-first=Laurian --chair-last=Mushi --chair-phone=255700000000 [--chair-email=…] [--demo]
 *
 *   Register a site that already has its own database (how the existing sites move):
 *     php bin/provision_tenant.php --adopt --subdomain=ukuu --name="UKUU Msakuzi" \
 *         --db-name=bjptechn_vikundi --db-user=user_vikundi
 *     (asks for that user's database password; it is never passed on the command line)
 *
 *   php bin/provision_tenant.php --add-host=vikundi.bjptechnologies.co.tz --to=ukuu
 *   php bin/provision_tenant.php --suspend=wisewallet     (the offline page)
 *   php bin/provision_tenant.php --resume=wisewallet
 *   php bin/provision_tenant.php --archive=wisewallet     (final backup, then offline for good; deletes nothing)
 *   php bin/provision_tenant.php --features=ukuu --set=shares:off,dividends:off
 *                                                         (which optional features the group has; see includes/features.php)
 *   php bin/provision_tenant.php --list
 *   php bin/provision_tenant.php --cert                   (re-issue the shared https certificate)
 *
 * Reads /etc/vikundi/control.php (paths, base domain) and /etc/vikundi/provisioner.php
 * (the provisioner MySQL user, vikundi_prov, which may create and grant only vk_* databases and
 * write the registry). Never root, and never reachable from the web.
 *
 * --create undoes every step it took if any later step fails, so a failed run
 * leaves no half-made group behind.
 *
 * After --create and --adopt the group's address is added to the shared https
 * certificate (certbot, http check, via `sudo -n`). Needs `acme_webroot` and
 * `cert_name` in control.php; without them the step is skipped. A failed
 * certificate does not undo the group: fix the cause and run --cert.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/features.php';

$args = vk_prov_parse_args($argv);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s, int $code = 1): never { fwrite(STDERR, "Error: $s\n"); exit($code); }

function load_conf(string $env, string $default): array
{
    $file = getenv($env) ?: $default;
    $conf = is_file($file) ? require $file : null;
    if (!is_array($conf)) {
        fail("$file is missing or does not return an array.");
    }
    return $conf;
}

$control = load_conf('VIKUNDI_CONTROL_CONFIG', '/etc/vikundi/control.php');
$prov    = load_conf('VIKUNDI_PROVISIONER_CONFIG', '/etc/vikundi/provisioner.php');
$base    = (string) $control['base_domain'];
$dbHost  = (string) ($control['db_host'] ?? 'localhost');

try {
    // The registry, as the provisioner (it may write it; the web's user may only read).
    $reg = new PDO($control['dsn'], $prov['user'], $prov['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // The server itself, for CREATE DATABASE / CREATE USER / GRANT.
    $srv = new PDO('mysql:host=' . $dbHost . ';charset=utf8mb4', $prov['user'], $prov['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fail('cannot connect as the provisioner: ' . $e->getMessage());
}

/**
 * Add every group's address to the shared certificate. Returns true when done
 * or not configured; prints the command to run by hand when it fails.
 */
function update_certificate(PDO $reg, array $control, string $base): bool
{
    if (empty($control['acme_webroot']) || empty($control['cert_name'])) {
        out('Certificate: skipped (acme_webroot / cert_name not set in control.php).');
        return true;
    }
    // 'admin' = the super-admin panel's own address (admin.<base>), always on it.
    $subs = array_merge(['admin'], $reg->query('SELECT subdomain FROM tenants')->fetchAll(PDO::FETCH_COLUMN));
    $names = vk_prov_cert_names($subs, $base);
    $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
    [$ok, $cmd] = vk_prov_certbot_command($names, (string) $control['acme_webroot'], (string) $control['cert_name'], $isRoot);
    if (!$ok) {
        fwrite(STDERR, "Certificate: $cmd\n");
        return false;
    }
    out('Certificate: covering ' . count($names) . ' address(es)...');
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Certificate: certbot failed (exit $code):\n  " . implode("\n  ", array_slice($output, -8)) . "\n"
            . "Fix the cause, then run: php bin/provision_tenant.php --cert\n");
        return false;
    }
    out('Certificate: ok.');
    return true;
}

/**
 * A row in the super-admin history for a change made here on the command line.
 * Skipped when the panel's worker ran this command (it writes its own row, with
 * the super-admin's name), and when the history table does not exist yet.
 */
function server_audit(PDO $reg, string $action, ?string $sub, string $result, ?string $detail = null): void
{
    if (getenv('VIKUNDI_REQUEST_ID')) {
        return;
    }
    $who = 'server (' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user()) . ')';
    try {
        $reg->prepare("INSERT INTO admin_audit_log (actor_type, actor_name, action, subdomain, result, detail) VALUES ('server', ?, ?, ?, ?, ?)")
            ->execute([$who, $action, $sub, $result, $detail === null ? null : mb_substr($detail, 0, 500)]);
    } catch (PDOException $e) {
        // No history table yet (before the panel is set up): nothing to record into.
    }
}

function tenant_or_fail(PDO $reg, string $sub): array
{
    $row = vk_tenant_find($reg, $sub);
    if ($row === null) {
        fail("no group with the subdomain '$sub'.");
    }
    return $row;
}

function write_secrets(string $dir, string $sub, string $dbPassword): string
{
    $file = rtrim($dir, '/') . '/' . $sub . '.php';
    if (file_exists($file)) {
        fail("$file already exists; refusing to overwrite another group's secrets.");
    }
    $old = umask(0027);
    $ok = file_put_contents($file, vk_prov_secrets_file($dbPassword, vk_prov_secret(32)), LOCK_EX);
    umask($old);
    if ($ok === false) {
        fail("cannot write $file.");
    }
    chmod($file, 0640); // group www-data comes from the folder's setgid bit
    return $file;
}

function make_dirs(string $dataRoot, string $sub): array
{
    $root = rtrim($dataRoot, '/') . '/' . $sub;
    $made = [];
    foreach (array_merge([''], vk_prov_data_dirs()) as $d) {
        $path = rtrim($root . '/' . $d, '/');
        if (!is_dir($path)) {
            if (!mkdir($path, 02770, true)) {
                fail("cannot create $path.");
            }
            chmod($path, 02770); // web server (group) reads and writes; setgid keeps the group
            $made[] = $path;
        }
    }
    return $made;
}

function run_migrations(string $sub): bool
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../database/migrate.php') . ' --tenant=' . escapeshellarg($sub);
    passthru($cmd, $code);
    return $code === 0;
}

// ── --list ───────────────────────────────────────────────────────────────────
if (isset($args['list'])) {
    $rows = $reg->query(
        "SELECT t.subdomain, t.name, t.status, t.db_name, t.is_demo, t.created_at,
                GROUP_CONCAT(h.host ORDER BY h.host SEPARATOR ', ') AS hosts
           FROM tenants t LEFT JOIN tenant_hosts h ON h.tenant_id = t.id
          GROUP BY t.id ORDER BY t.subdomain"
    )->fetchAll();
    if (!$rows) {
        out('No groups yet.');
        exit(0);
    }
    out(sprintf('%-20s %-12s %-22s %-5s %s', 'SUBDOMAIN', 'STATUS', 'DATABASE', 'DEMO', 'NAME / HOSTS'));
    foreach ($rows as $r) {
        out(sprintf('%-20s %-12s %-22s %-5s %s', $r['subdomain'], $r['status'], $r['db_name'], $r['is_demo'] ? 'yes' : '', $r['name']));
        out(sprintf('%-62s %s', '', $r['hosts'] ?? '(no host)'));
    }
    exit(0);
}

// ── --cert ───────────────────────────────────────────────────────────────────
if (isset($args['cert'])) {
    $ok = update_certificate($reg, $control, $base);
    server_audit($reg, 'update certificate', null, $ok ? 'done' : 'failed');
    exit($ok ? 0 : 1);
}

// ── --suspend / --resume / --archive ─────────────────────────────────────────
foreach (['suspend', 'resume', 'archive'] as $action) {
    if (!isset($args[$action])) {
        continue;
    }
    $row = tenant_or_fail($reg, (string) $args[$action]);
    $change = vk_prov_status_change($action, (string) $row['status']);
    if (is_string($change)) {
        fail($change);
    }
    [$status, $stampCol] = $change;

    if ($action === 'archive') {
        // A final dump into the group's own backups folder before it goes dark.
        require_once __DIR__ . '/../core/backup.php';
        $secrets = require rtrim((string) $control['secrets_dir'], '/') . '/' . $row['subdomain'] . '.php';
        $tdb = new PDO('mysql:host=' . $dbHost . ';dbname=' . $row['db_name'] . ';charset=utf8mb4', $row['db_user'], $secrets['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $dump = rtrim((string) $control['data_root'], '/') . '/' . $row['subdomain'] . '/backups/archive_' . date('Y-m-d_H-i-s') . '.sql';
        vikundi_write_dump($tdb, $dump);
        out("Final backup: $dump");
    }

    $sql = 'UPDATE tenants SET status = ?' . ($stampCol ? ", $stampCol = NOW()" : '') . ($action === 'resume' ? ', suspended_at = NULL' : '') . ' WHERE id = ?';
    $reg->prepare($sql)->execute([$status, $row['id']]);
    out("{$row['subdomain']}: {$row['status']} -> $status");
    server_audit($reg, "$action group", $row['subdomain'], 'done');
    exit(0);
}

// ── --features ───────────────────────────────────────────────────────────────
if (isset($args['features'])) {
    $row = tenant_or_fail($reg, (string) $args['features']);
    if (!array_key_exists('features', $row)) {
        fail('the registry has no features column yet: run database/control/add_tenant_features.sql as MySQL root.');
    }
    $want = vk_features_from_spec((string) ($args['set'] ?? ''));
    if (is_string($want)) {
        fail("--set: $want (e.g. --set=" . vk_features_to_spec([]) . ')');
    }
    $before = vk_features_to_spec(vk_features_parse($row['features']));
    $after  = vk_features_to_spec($want);
    $reg->prepare('UPDATE tenants SET features = ? WHERE id = ?')->execute([vk_features_encode($want), $row['id']]);
    out("{$row['subdomain']}: $before -> $after");
    server_audit($reg, 'set features', $row['subdomain'], 'done', $after);
    exit(0);
}

// ── --add-host ───────────────────────────────────────────────────────────────
if (isset($args['add-host'])) {
    $host = vk_tenant_normalize_host((string) $args['add-host']);
    if ($host === '' || !str_contains($host, '.')) {
        fail('give a full host name, e.g. --add-host=vikundi.bjptechnologies.co.tz');
    }
    $row = tenant_or_fail($reg, (string) ($args['to'] ?? ''));
    $st = $reg->prepare('SELECT t.subdomain FROM tenant_hosts h JOIN tenants t ON t.id = h.tenant_id WHERE h.host = ?');
    $st->execute([$host]);
    if ($other = $st->fetchColumn()) {
        fail("$host already points at '$other'.");
    }
    $reg->prepare('INSERT INTO tenant_hosts (host, tenant_id) VALUES (?, ?)')->execute([$host, $row['id']]);
    out("$host -> {$row['subdomain']}");
    server_audit($reg, 'add host', $row['subdomain'], 'done', $host);
    exit(0);
}

// ── --create / --adopt ───────────────────────────────────────────────────────
$adopt = isset($args['adopt']);
if (!isset($args['create']) && !$adopt) {
    fail('nothing to do. See the comment at the top of this file for the commands.');
}

$sub  = (string) ($args['subdomain'] ?? '');
$name = trim((string) ($args['name'] ?? ''));
if ($name === '') {
    fail('--name is required.');
}
$taken = $reg->query('SELECT subdomain FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
$hosts = $reg->query('SELECT host FROM tenant_hosts')->fetchAll(PDO::FETCH_COLUMN);
if ($why = vk_prov_validate_new($sub, $taken, $hosts, $base)) {
    fail($why);
}

if ($adopt) {
    $dbName = (string) ($args['db-name'] ?? '');
    $dbUser = (string) ($args['db-user'] ?? '');
    if (!vk_prov_valid_db_identifier($dbName) || !vk_prov_valid_db_identifier($dbUser)) {
        fail('--db-name and --db-user are required (letters, digits and _ only).');
    }
    fwrite(STDOUT, "Database password for $dbUser: ");
    system('stty -echo 2>/dev/null');
    $dbPassword = rtrim((string) fgets(STDIN), "\r\n");
    system('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    try {
        new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbName, $dbUser, $dbPassword);
    } catch (PDOException $e) {
        fail("cannot log in to $dbName as $dbUser with that password.");
    }
} else {
    $first = trim((string) ($args['chair-first'] ?? ''));
    $last  = trim((string) ($args['chair-last'] ?? ''));
    $phone = preg_replace('/\D/', '', (string) ($args['chair-phone'] ?? ''));
    if ($first === '' || $last === '' || $phone === '') {
        fail('--chair-first, --chair-last and --chair-phone are required: the group needs its first Chairperson.');
    }
    $dbName = $dbUser = vk_prov_db_name($sub);
    $dbPassword = vk_prov_db_password();
}

// Every step that changes something registers how to undo it.
$undo = [];
$rollback = static function () use (&$undo): void {
    foreach (array_reverse($undo) as $step) {
        try { $step(); } catch (Throwable $e) { fwrite(STDERR, '  (undo step failed: ' . $e->getMessage() . ")\n"); }
    }
};

try {
    $reg->prepare('INSERT INTO tenants (subdomain, name, db_name, db_user, status, is_demo) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$sub, $name, $dbName, $dbUser, 'provisioning', isset($args['demo']) ? 1 : 0]);
    $tenantId = (int) $reg->lastInsertId();
    $undo[] = static fn() => $reg->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tenantId]);
    $reg->prepare('INSERT INTO tenant_hosts (host, tenant_id) VALUES (?, ?)')->execute([$sub . '.' . $base, $tenantId]);
    out("1/6 Registered $sub.$base (status: provisioning)");

    if (!$adopt) {
        $srv->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $undo[] = static fn() => $srv->exec("DROP DATABASE IF EXISTS `$dbName`");
        $srv->exec("CREATE USER '$dbUser'@'localhost' IDENTIFIED BY " . $srv->quote($dbPassword));
        $undo[] = static fn() => $srv->exec("DROP USER IF EXISTS '$dbUser'@'localhost'");
        // Through root's one-purpose routine: MySQL will not let the provisioner
        // GRANT on a database it reaches only by its vk_% wildcard (1044).
        // See database/control/procedures.sql.
        $srv->prepare('CALL vikundi_control.vk_grant_group(?)')->execute([$dbName]);
        out("2/6 Created database and MySQL user $dbName");
    } else {
        out("2/6 Using the existing database $dbName");
    }

    $secretsFile = write_secrets((string) $control['secrets_dir'], $sub, $dbPassword);
    $undo[] = static fn() => @unlink($secretsFile);
    out("3/6 Wrote $secretsFile");

    $made = make_dirs((string) $control['data_root'], $sub);
    $undo[] = static function () use ($made): void {
        foreach (array_reverse($made) as $d) { @rmdir($d); } // only folders this run made, and only while empty
    };
    out('4/6 Folders under ' . rtrim((string) $control['data_root'], '/') . "/$sub");

    out('5/6 Migrations:');
    if (!run_migrations($sub)) {
        throw new RuntimeException('migrations failed (output above).');
    }

    $chairLine = '';
    if (!$adopt) {
        $tdb = new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $tdb->prepare('REPLACE INTO group_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['group_name', $name]);
        $roleId = (int) $tdb->query("SELECT role_id FROM roles WHERE role_name = 'Chairperson' LIMIT 1")->fetchColumn();
        if ($roleId === 0) {
            throw new RuntimeException('the Chairperson role is missing after migrations.');
        }
        $username = (string) ($args['chair-username'] ?? vk_prov_username($first, $last));
        $chairPassword = vk_prov_secret(9);
        // Never empty: the app finds a member's record by the login's email.
        $email = vk_prov_chair_email(isset($args['chair-email']) ? (string) $args['chair-email'] : null, $username, $sub, $base);
        $tdb->prepare(
            "INSERT INTO users (username, password, email, phone, role, user_role, role_id, status, is_active, is_admin,
                                first_name, last_name, language, preferred_language, created_at)
             VALUES (?, ?, ?, ?, 'Chairperson', 'Chairperson', ?, 'active', 1, 0, ?, ?, 'en', 'en', NOW())"
        )->execute([$username, password_hash($chairPassword, PASSWORD_DEFAULT), $email, $phone, $roleId, $first, $last]);
        $uid = (int) $tdb->lastInsertId();
        $tdb->prepare(
            "INSERT INTO customers (user_id, first_name, last_name, customer_code, customer_name, customer_type,
                                    phone, mobile, email, status, created_at, created_by)
             VALUES (?, ?, ?, 'M001', ?, 'individual', ?, ?, ?, 'active', NOW(), ?)"
        )->execute([$uid, $first, $last, "$first $last", $phone, $phone, $email, $uid]);
        $chairLine = "\n  First Chairperson: username $username, password $chairPassword\n"
                   . "  (shown once; give it to the Chairperson and ask them to change it under My Settings)";
    }

    $reg->prepare("UPDATE tenants SET status = 'active' WHERE id = ?")->execute([$tenantId]);
    out("6/6 Active: https://$sub.$base" . $chairLine);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\nUndoing the steps already taken...\n");
    $rollback();
    fwrite(STDERR, "Nothing was left behind. Fix the cause and run the command again.\n");
    server_audit($reg, $adopt ? 'adopt group' : 'create group', $sub, 'failed', $e->getMessage());
    exit(1);
}

// The group is complete; a certificate problem must not undo it.
$certOk = update_certificate($reg, $control, $base);
server_audit($reg, $adopt ? 'adopt group' : 'create group', $sub, 'done', $certOk ? null : 'https certificate failed');
exit($certOk ? 0 : 2);
