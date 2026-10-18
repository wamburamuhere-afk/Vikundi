<?php
/**
 * bin/migrate_all.php — run database/migrate.php for every group on the shared
 * server, one at a time. The deploy calls this after `git pull`.
 *
 *   php bin/migrate_all.php              every active and suspended group
 *   php bin/migrate_all.php --only=ukuu  one group
 *
 * One line per group (ok, or the error), and the full migration output in
 * <data_root>/<group>/private/last_migration.log. A failing group does not stop
 * the others; the exit code is 1 if any failed, so the deploy is marked failed.
 *
 * The existing migrations only add what is missing, so running them on every
 * group on every deploy is safe — it is how production has always deployed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/provisioning.php';

$args = vk_prov_parse_args($argv);
$file = getenv('VIKUNDI_CONTROL_CONFIG') ?: '/etc/vikundi/control.php';
$control = is_file($file) ? require $file : null;
if (!is_array($control)) {
    fwrite(STDERR, "Error: $file is missing or invalid.\n");
    exit(1);
}

try {
    $reg = new PDO($control['dsn'], $control['user'], $control['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Error: cannot reach the registry: ' . $e->getMessage() . "\n");
    exit(1);
}

$groups = $reg->query("SELECT subdomain FROM tenants WHERE status IN ('active', 'suspended') ORDER BY subdomain")
    ->fetchAll(PDO::FETCH_COLUMN);
if (isset($args['only'])) {
    $groups = array_values(array_intersect($groups, [(string) $args['only']]));
    if (!$groups) {
        fwrite(STDERR, "Error: no active or suspended group '{$args['only']}'.\n");
        exit(1);
    }
}

$failed = [];
$started = microtime(true);
foreach ($groups as $sub) {
    $log = rtrim((string) $control['data_root'], '/') . '/' . $sub . '/private/last_migration.log';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../database/migrate.php')
         . ' --tenant=' . escapeshellarg($sub) . ' 2>&1';
    $output = [];
    exec($cmd, $output, $code);
    @file_put_contents($log, implode("\n", $output) . "\n");

    if ($code === 0) {
        printf("%-20s ok\n", $sub);
    } else {
        $failed[] = $sub;
        $last = trim((string) end($output));
        printf("%-20s FAILED (exit %d): %s\n", $sub, $code, $last === '' ? 'see ' . $log : $last);
    }
}

printf("\n%d group(s), %d failed, %.1fs\n", count($groups), count($failed), microtime(true) - $started);
exit($failed ? 1 : 0);
