<?php
/**
 * bin/superadmin.php — manage super-admin panel logins. Server only: the panel
 * has no sign-up page and cannot create logins.
 *
 *   php bin/superadmin.php --create --username=jabir --name="Jabir Mussa"
 *   php bin/superadmin.php --reset-password=jabir      (also unlocks the login)
 *   php bin/superadmin.php --disable=jabir
 *   php bin/superadmin.php --enable=jabir
 *   php bin/superadmin.php --list
 *
 * Passwords are typed at a hidden prompt, twice, and stored only as a hash.
 * Run as ubuntu from the shared code folder (uses /etc/vikundi/provisioner.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/admin_requests.php';

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, "Error: $s\n"); exit(1); }

function ask_password(string $username): string
{
    for ($try = 0; $try < 3; $try++) {
        fwrite(STDOUT, 'New password (12+ characters, letters and a digit): ');
        system('stty -echo 2>/dev/null');
        $a = rtrim((string) fgets(STDIN), "\r\n");
        fwrite(STDOUT, "\nType it again: ");
        $b = rtrim((string) fgets(STDIN), "\r\n");
        system('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
        if ($a !== $b) {
            out('The two passwords differ. Try again.');
            continue;
        }
        if ($why = vk_superadmin_password_problem($a, $username)) {
            out($why);
            continue;
        }
        return $a;
    }
    fail('no password set.');
}

$args = vk_prov_parse_args($argv);
$controlFile = getenv('VIKUNDI_CONTROL_CONFIG') ?: '/etc/vikundi/control.php';
$provFile    = getenv('VIKUNDI_PROVISIONER_CONFIG') ?: '/etc/vikundi/provisioner.php';
$control = is_file($controlFile) ? require $controlFile : null;
$prov    = is_file($provFile) ? require $provFile : null;
if (!is_array($control) || !is_array($prov)) {
    fail("$controlFile or $provFile is missing or invalid.");
}
try {
    $reg = new PDO($control['dsn'], $prov['user'], $prov['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fail('cannot reach the registry: ' . $e->getMessage());
}

function audit(PDO $reg, string $action, string $username): void
{
    $who = 'server (' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user()) . ')';
    $reg->prepare("INSERT INTO admin_audit_log (actor_type, actor_name, action, result, detail) VALUES ('server', ?, ?, 'done', ?)")
        ->execute([$who, $action, "super-admin: $username"]);
}

function find_admin(PDO $reg, string $username): array
{
    $st = $reg->prepare('SELECT * FROM superadmins WHERE username = ?');
    $st->execute([$username]);
    return $st->fetch() ?: fail("no super-admin '$username'.");
}

if (isset($args['list'])) {
    $rows = $reg->query('SELECT username, full_name, is_active, locked_until, last_login_at, created_at FROM superadmins ORDER BY username')->fetchAll();
    if (!$rows) {
        out('No super-admins yet.');
        exit(0);
    }
    out(sprintf('%-20s %-28s %-9s %-20s %s', 'USERNAME', 'NAME', 'ACTIVE', 'LAST LOGIN', 'LOCKED UNTIL'));
    foreach ($rows as $r) {
        out(sprintf('%-20s %-28s %-9s %-20s %s', $r['username'], $r['full_name'], $r['is_active'] ? 'yes' : 'no', $r['last_login_at'] ?? '-', $r['locked_until'] ?? ''));
    }
    exit(0);
}

if (isset($args['create'])) {
    $username = strtolower(trim((string) ($args['username'] ?? '')));
    $name = trim((string) ($args['name'] ?? ''));
    if (!vk_superadmin_valid_username($username)) {
        fail('--username: 3 to 50 lowercase letters, digits, dot, dash or underscore.');
    }
    if ($name === '' || mb_strlen($name) > 120) {
        fail('--name is required (up to 120 characters).');
    }
    $st = $reg->prepare('SELECT 1 FROM superadmins WHERE username = ?');
    $st->execute([$username]);
    if ($st->fetchColumn()) {
        fail("'$username' already exists. Use --reset-password=$username to change its password.");
    }
    $hash = password_hash(ask_password($username), PASSWORD_DEFAULT);
    $reg->prepare('INSERT INTO superadmins (username, full_name, password_hash, password_changed_at) VALUES (?, ?, ?, NOW())')
        ->execute([$username, $name, $hash]);
    audit($reg, 'create super-admin', $username);
    out("Super-admin '$username' created. Log in at https://admin." . $control['base_domain']);
    exit(0);
}

if (isset($args['reset-password'])) {
    $row = find_admin($reg, strtolower((string) $args['reset-password']));
    $hash = password_hash(ask_password($row['username']), PASSWORD_DEFAULT);
    $reg->prepare('UPDATE superadmins SET password_hash = ?, password_changed_at = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = ?')
        ->execute([$hash, $row['id']]);
    audit($reg, 'reset super-admin password', $row['username']);
    out("Password changed for '{$row['username']}' (login unlocked).");
    exit(0);
}

foreach (['disable' => 0, 'enable' => 1] as $flag => $active) {
    if (isset($args[$flag])) {
        $row = find_admin($reg, strtolower((string) $args[$flag]));
        $reg->prepare('UPDATE superadmins SET is_active = ? WHERE id = ?')->execute([$active, $row['id']]);
        audit($reg, "$flag super-admin", $row['username']);
        out("'{$row['username']}' " . ($active ? 'enabled' : 'disabled') . '.');
        exit(0);
    }
}

fail('nothing to do. See the comment at the top of this file for the commands.');
