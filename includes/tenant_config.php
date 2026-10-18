<?php
/**
 * includes/tenant_config.php — finds the group (tenant) and opens its database on
 * the shared, multi-group server. Every other site, and local development, keeps
 * its own includes/config.php exactly as before and never loads this file.
 *
 * On the shared server it is wired in twice:
 *
 *   1. Apache runs it before every PHP script:
 *          php_value auto_prepend_file /srv/vikundi/app/includes/tenant_config.php
 *      so the group is known before ANY script starts a session. Several entry
 *      points call session_start() before they include config.php; without the
 *      prepend those sessions would be stored in a different folder from the rest
 *      and members would be logged out at random.
 *
 *   2. includes/config.php is:
 *          <?php require_once __DIR__ . '/tenant_config.php'; $pdo = $GLOBALS['pdo'];
 *      which re-uses the connection the prepend made (and is what command-line
 *      scripts, which have no prepend, go through).
 *
 * What it does, once per request:
 *   1. load /etc/vikundi/control.php (the registry's connection and server paths);
 *   2. find the group — from the host name on the web, from --tenant=<name> or
 *      VIKUNDI_TENANT on the command line;
 *   3. unknown group → 404 page, suspended → 503 page, and stop. Nothing falls back
 *      to a default database: a mistake must show nothing, never another group;
 *   4. load the group's secrets from /etc/vikundi/tenants/<subdomain>.php and
 *      connect to its own database as its own MySQL user;
 *   5. point stored files and PHP session files at the group's own folders.
 *
 * Provides $pdo and JWT_SECRET, exactly as a single-site config.php does.
 */

require_once __DIR__ . '/tenant.php';

if (!function_exists('vk_tenant_stop')) {
    /** End the request with the not-found or offline page (or a CLI error). */
    function vk_tenant_stop(string $decision, ?array $row = null): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $decision === 'suspended'
                ? "Group '{$row['subdomain']}' is suspended.\n"
                : "No such group. Pass --tenant=<subdomain> (or set VIKUNDI_TENANT).\n");
            exit(1);
        }
        require_once __DIR__ . '/tenant_pages.php';
        vk_tenant_render_page($decision, $row);
        exit;
    }
}

if (vk_tenant_current() === null) {
    (function () {
        $controlFile = getenv('VIKUNDI_CONTROL_CONFIG') ?: '/etc/vikundi/control.php';
        $control = is_file($controlFile) ? require $controlFile : null;
        if (!is_array($control)) {
            error_log('Vikundi: tenant mode but ' . $controlFile . ' is missing or invalid.');
            vk_tenant_stop('not_found');
        }

        // A session that is already running was started before the group was
        // known, in the shared session folder: the auto_prepend_file line is
        // missing from the vhost. Refuse rather than run with sessions that are
        // not separated by group.
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            error_log('Vikundi: session started before tenant_config.php; add auto_prepend_file to the vhost.');
            vk_tenant_stop('not_found');
        }

        try {
            $controlPdo = new PDO($control['dsn'], $control['user'], $control['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            error_log('Vikundi: registry connection failed: ' . $e->getMessage());
            vk_tenant_stop('not_found');
        }

        if (PHP_SAPI === 'cli') {
            $name = vk_tenant_cli_name($GLOBALS['argv'] ?? [], getenv('VIKUNDI_TENANT') ?: null);
            $row = $name === null ? null : vk_tenant_find($controlPdo, $name);
            // Scripts may work on a group that is suspended (migrations, backups) or
            // still being created by the provisioning command; only a missing or
            // archived group is refused.
            if ($row === null || $row['status'] === 'archived') {
                vk_tenant_stop('not_found');
            }
        } else {
            $row = vk_tenant_lookup($controlPdo, (string) ($_SERVER['HTTP_HOST'] ?? ''), (string) $control['base_domain']);
            $decision = vk_tenant_decide($row);
            if ($decision !== 'serve') {
                vk_tenant_stop($decision, $row);
            }
        }

        $secretsFile = rtrim((string) $control['secrets_dir'], '/') . '/' . $row['subdomain'] . '.php';
        $secrets = is_file($secretsFile) ? require $secretsFile : null;
        if (!is_array($secrets) || empty($secrets['db_password']) || empty($secrets['jwt_secret'])) {
            error_log('Vikundi: secrets missing for group ' . $row['subdomain']);
            vk_tenant_stop('not_found');
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . ($control['db_host'] ?? 'localhost') . ';dbname=' . $row['db_name'] . ';charset=utf8mb4',
                $row['db_user'],
                $secrets['db_password']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Vikundi: database connection failed for group ' . $row['subdomain'] . ': ' . $e->getMessage());
            die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
        }

        $GLOBALS['pdo'] = $pdo;
        define('JWT_SECRET', (string) $secrets['jwt_secret']);
        vk_tenant_set($row, rtrim((string) $control['data_root'], '/') . '/' . $row['subdomain']);

        if (PHP_SAPI !== 'cli') {
            // Each group's session files live in its own folder, so a session id
            // carried over from another group's address finds nothing here.
            // Debian turns PHP's own clean-up off and only sweeps the default
            // folder, so switch it back on for this one.
            session_save_path(rtrim(vk_storage_dir('sessions', 0700), '/'));
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '1000');
        }
    })();
}

// Included from a function's scope in many handlers: hand $pdo to that scope too.
$pdo = $GLOBALS['pdo'];
