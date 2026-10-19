<?php
/**
 * database/add_fund_bucket_contribution_types.php
 * -----------------------------------------------
 * Fund buckets: widen contributions.contribution_type so a payment can be
 * recorded against share capital, the Social Fund or the membership deposit:
 *
 *   enum('entrance','monthly','agm','fine','other')
 *     -> enum('entrance','monthly','agm','fine','other','share','social_fund','deposit')
 *
 * New values are appended, so every existing row keeps its value and its
 * meaning. No settings are seeded: an unset share price / Social Fund amount
 * means the rule is off (see includes/fund_buckets.php).
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/add_fund_bucket_contribution_types.php
 */

require_once __DIR__ . '/../includes/config.php';

$type = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contributions' AND COLUMN_NAME = 'contribution_type'")->fetchColumn();

if (!$type) {
    echo "  contributions.contribution_type not present — skipped.\n";
} elseif (strpos($type, 'social_fund') !== false) {
    echo "  contributions.contribution_type already allows the fund-bucket types.\n";
} else {
    $pdo->exec("ALTER TABLE `contributions` MODIFY COLUMN `contribution_type` enum('entrance','monthly','agm','fine','other','share','social_fund','deposit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'monthly'");
    echo "  contributions.contribution_type widened to include share, social_fund, deposit.\n";
}
