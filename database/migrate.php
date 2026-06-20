<?php
/**
 * database/migrate.php
 * --------------------
 * One entrypoint that runs every idempotent migration in order. Safe to run on
 * every deploy — each migration only changes what is missing.
 *
 * This is what keeps the production database in step with the code. The deploy
 * workflow calls it after `git pull` (see .github/workflows/deploy.yml).
 *
 * Run manually:  php database/migrate.php
 */

require_once __DIR__ . '/../includes/config.php'; // provides $pdo for the included migrations

$migrations = [
    'sync_workflow_columns.php', // add review/approve columns where missing
    'ai_assistant_setup.php',    // AI tables, prompts and permissions
];

echo "== Vikundi database migrations ==\n";
$failed = 0;
foreach ($migrations as $m) {
    $path = __DIR__ . '/' . $m;
    echo "\n-- $m --\n";
    if (!is_file($path)) { echo "  (missing, skipped)\n"; continue; }
    try {
        require $path; // each migration uses the shared $pdo and is idempotent
    } catch (Throwable $e) {
        $failed++;
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n== Migrations finished" . ($failed ? " with $failed error(s) ==\n" : " successfully ==\n");
exit($failed ? 1 : 0);
