<?php
/**
 * database/add_contributions_indexes.php
 * --------------------------------------
 * The Transactions table is a server-side (paginated) DataTable over the
 * `contributions` table, which grows without bound (a 1,000-member group at a
 * few transactions/day reaches ~1M rows a year). Server-side paging, filtering
 * and sorting without indexes = a full-table scan per page = timeouts at scale.
 *
 * Adds the supporting indexes the endpoint (api/get_transactions.php) relies on:
 *   - contribution_date          (date-range filter + sort)
 *   - status                     (status filter)
 *   - created_at                 (default "newest first" sort)
 *   - (status, contribution_date) composite for the common "filter by status,
 *                                 order by date" path
 * (`member_id` is already indexed.)
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_contributions_indexes.php
 */

require_once __DIR__ . '/../includes/config.php';

$table = 'contributions';

// name => column list (SQL) for the index
$indexes = [
    'idx_contrib_date'        => '(`contribution_date`)',
    'idx_contrib_status'      => '(`status`)',
    'idx_contrib_created'     => '(`created_at`)',
    'idx_contrib_status_date' => '(`status`, `contribution_date`)',
];

$tableExists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
$idxExists   = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");

$tableExists->execute([$table]);
if ((int) $tableExists->fetchColumn() === 0) {
    echo "Contributions index sync: table `$table` not present here — skipped.\n";
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

echo "Contributions index sync complete.\n";
echo $added
    ? ("  Added " . count($added) . " index(es): " . implode(', ', $added) . "\n")
    : "  No missing indexes — already in sync.\n";
