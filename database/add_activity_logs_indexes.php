<?php
/**
 * database/add_activity_logs_indexes.php
 * --------------------------------------
 * `activity_logs` is the audit trail: every login, create/edit/delete and page
 * view inserts a row, so on a live group it grows fast (page-view logging alone
 * adds a row per navigation). Yet the audit-logs viewer (app/audit_logs.php)
 * filters and sorts on `created_at`, `user_id`, `module` and `action` on every
 * request. The table shipped with only a PRIMARY KEY, so each of those queries
 * was a full-table scan — fine at 500 rows, slow at 500,000.
 *
 * Adds the supporting indexes the viewer (and the upcoming action filter /
 * per-user timeline) relies on:
 *   - (created_at)              default "newest first" sort + date-range filter
 *   - (user_id, created_at)     "this user's activity, newest first" (User filter + timeline)
 *   - (module, created_at)      Type filter + date sort, and the page-view split
 *   - action(20)                Action filter (action is TEXT, so a prefix index)
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_activity_logs_indexes.php
 */

require_once __DIR__ . '/../includes/config.php';

$table = 'activity_logs';

// name => column list (SQL) for the index
$indexes = [
    'idx_alog_created'        => '(`created_at`)',
    'idx_alog_user_created'   => '(`user_id`, `created_at`)',
    'idx_alog_module_created' => '(`module`, `created_at`)',
    'idx_alog_action'         => '(`action`(20))', // `action` is TEXT — prefix index
];

$tableExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
$idxExists   = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");

$tableExists->execute([$table]);
if ((int) $tableExists->fetchColumn() === 0) {
    echo "Activity-logs index sync: table `$table` not present here — skipped.\n";
    return;
}

$added = [];
foreach ($indexes as $name => $cols) {
    $idxExists->execute([$table, $name]);
    if ((int) $idxExists->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
        $added[] = $name;
    }
}

echo "Activity-logs index sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " index(es): " . implode(', ', $added) . "\n")
    : "  No missing indexes — already in sync.\n";
