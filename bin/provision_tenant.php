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
 *   php bin/provision_tenant.php --list
 *
 * Reads /etc/vikundi/control.php (paths, base domain) and /etc/vikundi/provisioner.php
 * (the vk_provisioner MySQL user, which may create and grant only vk_* databases and
 * write the registry). Never root, and never reachable from the web.
 *
 * --create undoes every step it took if any later step fails, so a failed run
 * leaves no half-made group behind.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/provisioning.php';

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
    $dbPassword = vk_prov_secret();
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
        $srv->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'localhost'");
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
        $email = isset($args['chair-email']) ? (string) $args['chair-email'] : null;
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
    exit(1);
}
