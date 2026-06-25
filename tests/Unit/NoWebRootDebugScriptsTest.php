<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards audit B4: one-off debug/maintenance scripts must never sit at the web
 * root. They were web-reachable and unauthenticated, and several were
 * destructive (set_balance.php overwrote the fund; clear_expenses.php deleted
 * expenses) or leaked data/schema (list_all_users.php, check_*).
 */
class NoWebRootDebugScriptsTest extends TestCase
{
    public function testDangerousDebugScriptsAreNotAtWebRoot(): void
    {
        $root   = dirname(__DIR__, 2);
        $banned = [
            'set_balance.php', 'clear_expenses.php', 'fix_db_schema.php', 'add_col.php',
            'migrate_expenses.php', 'setup_permissions.php', 'setup_granular_permissions.php',
            'sync_members.php', 'list_all_users.php', 'list_db.php', 'list_tables.php',
            'list_fields.php', 'list_account_names.php', 'compare_counts.php', 'describe_docs.php',
            'find_bank_accounts.php', 'find_route.php', 'get_tables.php',
            'check_db.php', 'check_accounts.php', 'check_banks.php', 'check_cols.php',
            'check_customer_cols.php', 'check_customers_cols.php', 'check_death_cols.php',
            'check_raw_users.php', 'check_roles.php', 'check_users_cols.php',
        ];
        foreach ($banned as $f) {
            $this->assertFileDoesNotExist(
                $root . '/' . $f,
                "$f must not exist at the web root (audit B4)"
            );
        }
    }
}
