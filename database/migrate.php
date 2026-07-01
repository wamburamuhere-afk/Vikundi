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
    'sync_schema.php',              // create any base tables missing on the target DB
    'sync_workflow_columns.php',    // add review/approve columns + widen status enums
    'add_parent_detail_columns.php',// parent structured name + 6-field location + photo (registration PR-B)
    'add_guarantor_detail_columns.php', // guarantor member link + 6-field location (registration PR-C)
    'add_spouse_photo_column.php',   // optional spouse passport photo
    'seed_vicoba_roles.php',        // the four VICOBA system roles + remove BMS roles
    'add_transaction_fields.php',   // contributions.receipt_number + account (Transactions form)
    'fix_death_expense_schema.php', // widen deceased_type, add 'dormant' + customers.is_active
    'ai_assistant_setup.php',       // AI tables, prompts and permissions
    'add_member_expense_column.php',// general_expenses.member_id (per-member vs whole-org expense)
    'add_document_relation_columns.php', // documents.related_type/related_id (attach docs to a record)
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
