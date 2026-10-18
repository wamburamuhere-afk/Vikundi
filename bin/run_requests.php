<?php
/**
 * bin/run_requests.php — carry out the super-admin panel's requests.
 *
 * Run every minute as `ubuntu` (systemd timer; see the super-admin design doc):
 *
 *   php /srv/vikundi/app/bin/run_requests.php
 *
 * The panel only saves rows in vikundi_control.provision_requests; it holds no
 * rights to create databases. This worker, which does (through
 * /etc/vikundi/provisioner.php), takes each waiting request in order, checks it
 * again with the same rules as the command line, runs bin/provision_tenant.php
 * with it, and saves the result, the output and a history row.
 *
 * One worker at a time (a MySQL lock); one request at a time. A run that finds
 * the lock taken exits quietly.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/admin_requests.php';

function log_line(string $s): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $s\n"); }

$controlFile = getenv('VIKUNDI_CONTROL_CONFIG') ?: '/etc/vikundi/control.php';
$provFile    = getenv('VIKUNDI_PROVISIONER_CONFIG') ?: '/etc/vikundi/provisioner.php';
$control = is_file($controlFile) ? require $controlFile : null;
$prov    = is_file($provFile) ? require $provFile : null;
if (!is_array($control) || !is_array($prov)) {
    fwrite(STDERR, "Error: $controlFile or $provFile is missing or invalid.\n");
    exit(1);
}

try {
    $reg = new PDO($control['dsn'], $prov['user'], $prov['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Error: cannot reach the registry: ' . $e->getMessage() . "\n");
    exit(1);
}

if ((int) $reg->query("SELECT GET_LOCK('vikundi_run_requests', 0)")->fetchColumn() !== 1) {
    exit(0); // another run is still busy
}

// A request left 'running' for 30 minutes belongs to a worker that died.
$reg->exec("UPDATE provision_requests
               SET status = 'failed', finished_at = NOW(),
                   output = CONCAT(COALESCE(output, ''), '\n[The worker stopped before this request finished.]')
             WHERE status = 'running' AND started_at < NOW() - INTERVAL 30 MINUTE");
// A first-Chairperson password nobody looked at within a day is erased anyway.
$reg->exec("UPDATE provision_requests SET one_time_secret = NULL
             WHERE one_time_secret IS NOT NULL AND finished_at < NOW() - INTERVAL 1 DAY");

$base = (string) $control['base_domain'];
$script = __DIR__ . '/provision_tenant.php';

while (true) {
    $row = $reg->query("SELECT r.*, s.full_name AS admin_name
                          FROM provision_requests r LEFT JOIN superadmins s ON s.id = r.requested_by
                         WHERE r.status = 'waiting' ORDER BY r.id LIMIT 1")->fetch();
    if (!$row) {
        break;
    }
    $claim = $reg->prepare("UPDATE provision_requests SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'waiting'");
    $claim->execute([$row['id']]);
    if ($claim->rowCount() !== 1) {
        continue;
    }

    $id = (int) $row['id'];
    $action = (string) $row['action'];
    $sub = $row['subdomain'] !== null ? (string) $row['subdomain'] : null;
    $params = $row['params'] !== null ? (json_decode((string) $row['params'], true) ?: []) : [];
    log_line("#$id $action " . ($sub ?? '') . ' ...');

    // Checked again here: the panel's check is not trusted on its own.
    $taken = $reg->query('SELECT subdomain FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
    $hosts = $reg->query('SELECT host FROM tenant_hosts')->fetchAll(PDO::FETCH_COLUMN);
    $errors = vk_req_validate($action, $sub, $params, $taken, $hosts, $base);

    if ($errors) {
        [$status, $warning, $code, $output, $secret] = ['failed', null, null, "Refused:\n- " . implode("\n- ", $errors), null];
    } else {
        $cmd = array_merge([PHP_BINARY, $script], vk_req_command_args($action, $sub, $params));
        $env = getenv();
        $env['VIKUNDI_REQUEST_ID'] = (string) $id; // provision_tenant.php leaves the history row to us
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname(__DIR__), $env);
        if (!is_resource($proc)) {
            [$code, $raw] = [-1, 'Could not start bin/provision_tenant.php.'];
        } else {
            $raw = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($proc);
        }
        [$output, $secret] = vk_req_extract_secret($raw);
        $output = vk_req_trim_output($output);
        [$status, $warning] = vk_req_outcome($action, (int) $code);
    }

    $reg->prepare("UPDATE provision_requests
                      SET status = ?, exit_code = ?, warning = ?, output = ?, one_time_secret = ?, finished_at = NOW()
                    WHERE id = ?")
        ->execute([$status, $code, $warning, $output, $secret, $id]);

    $reg->prepare("INSERT INTO admin_audit_log (actor_type, actor_id, actor_name, action, subdomain, request_id, result, detail)
                   VALUES ('superadmin', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $row['requested_by'],
            (string) ($row['admin_name'] ?? 'deleted super-admin'),
            vk_req_audit_action($action),
            $sub,
            $id,
            $status,
            $warning ?? ($status === 'failed' ? mb_substr(trim((string) $output), -400) : null),
        ]);

    log_line("#$id $status" . ($code !== null ? " (exit $code)" : '') . ($warning ? " - $warning" : ''));
    if ($status === 'failed') {
        // Email to the operator comes with the panel's write side (PR 3).
        fwrite(STDERR, "#$id failed. See the Requests page or provision_requests.output.\n");
    }
}

$reg->query("SELECT RELEASE_LOCK('vikundi_run_requests')");
exit(0);
