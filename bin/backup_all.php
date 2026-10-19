<?php
/**
 * bin/backup_all.php — nightly database backup of every group on the shared
 * server. Run from cron as the `ubuntu` user:
 *
 *   15 2 * * *  php /srv/vikundi/app/bin/backup_all.php >> /var/log/vikundi/backup_all.log 2>&1
 *
 * Each active and suspended group gets auto_backup_<date>.sql in its own backups
 * folder, so it shows on that group's Backup & Restore page, exactly like the
 * app's own daily auto-backup (whose marker it also updates, so the page does not
 * make a second one the same day). Auto backups older than 7 days are pruned with
 * the app's own rule; manual and uploaded backups are never touched.
 *
 * These copies stay on this server. Copying them off it is still an open
 * decision (see the multi-tenant design doc).
 *
 *   php bin/backup_all.php --only=ukuu   one group
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../core/backup.php';

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

$rows = $reg->query("SELECT * FROM tenants WHERE status IN ('active', 'suspended') ORDER BY subdomain")->fetchAll();
if (isset($args['only'])) {
    $rows = array_values(array_filter($rows, static fn($r) => $r['subdomain'] === (string) $args['only']));
}

$failed = 0;
echo '== ' . date('Y-m-d H:i:s') . ' ==' . "\n";
foreach ($rows as $row) {
    $sub = (string) $row['subdomain'];
    try {
        $secrets = require rtrim((string) $control['secrets_dir'], '/') . '/' . $sub . '.php';
        $pdo = new PDO(
            'mysql:host=' . ($control['db_host'] ?? 'localhost') . ';dbname=' . $row['db_name'] . ';charset=utf8mb4',
            $row['db_user'],
            $secrets['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $dir = rtrim((string) $control['data_root'], '/') . '/' . $sub . '/backups/';
        $path = $dir . 'auto_backup_' . date('Y-m-d_H-i-s') . '.sql';
        vikundi_write_dump($pdo, $path);
        @file_put_contents($dir . '.last_auto_backup', (string) time());
        $pruned = vikundi_prune_backups($dir, 7);
        printf("%-20s ok  %6.1f MB%s\n", $sub, filesize($path) / 1048576, $pruned ? '  (pruned ' . count($pruned) . ')' : '');
    } catch (Throwable $e) {
        $failed++;
        printf("%-20s FAILED: %s\n", $sub, $e->getMessage());
    }
}

printf("%d group(s), %d failed\n", count($rows), $failed);
exit($failed ? 1 : 0);
