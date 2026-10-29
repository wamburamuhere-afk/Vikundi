<?php
/**
 * bin/demo_data.php — add or remove the sample data of a demo group.
 * Command line only; run by bin/provision_tenant.php, not by hand on the panel's behalf.
 *
 *   php bin/demo_data.php --tenant=pamoja --seed    (a new demo group: just its first Chairperson)
 *   php bin/demo_data.php --tenant=pamoja --clear   (remove every sample; see includes/demo_data.php)
 *
 * Works only on the shared server, on a group the registry marks as a demo.
 * A real group is refused, whatever the flags.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/config.php'; // $pdo for --tenant=<group>
require_once __DIR__ . '/../includes/demo_data.php';

$group = vk_tenant_current();
if ($group === null) {
    fwrite(STDERR, "Error: run this on the shared server with --tenant=<group>.\n");
    exit(1);
}
if (!(int) ($group['is_demo'] ?? 0)) {
    fwrite(STDERR, "Error: {$group['subdomain']} is not a demo group. Its data is not touched.\n");
    exit(1);
}

$seed  = in_array('--seed', $argv, true);
$clear = in_array('--clear', $argv, true);
if ($seed === $clear) {
    fwrite(STDERR, "Error: give --seed or --clear.\n");
    exit(1);
}

try {
    if ($seed) {
        $r = vk_demo_seed($pdo);
        echo "Demo data added: {$r['members']} sample members, {$r['contributions']} contributions, {$r['loans']} loans.\n";
    } else {
        $r = vk_demo_clear($pdo);
        $parts = [];
        foreach ($r as $table => $rows) {
            if ($rows > 0) {
                $parts[] = "$table $rows";
            }
        }
        echo 'Demo data removed: ' . ($parts ? implode(', ', $parts) : 'nothing was left') . ".\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
