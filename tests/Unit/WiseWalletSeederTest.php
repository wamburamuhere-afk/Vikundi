<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The demo seeder (database/seed_wise_wallet_demo.php) and the two migrations a
 * brand-new database needs before anyone but the Chairperson can use it. The
 * seeding itself is verified by installing into an empty database; this pins
 * the safety properties and the install order.
 */
class WiseWalletSeederTest extends TestCase
{
    private static string $seeder;
    private static string $migrate;

    public static function setUpBeforeClass(): void
    {
        self::$seeder  = file_get_contents(__DIR__ . '/../../database/seed_wise_wallet_demo.php');
        self::$migrate = file_get_contents(__DIR__ . '/../../database/migrate.php');
    }

    private function before(string $first, string $second, string $haystack): void
    {
        $a = strpos($haystack, $first);
        $b = strpos($haystack, $second);
        $this->assertNotFalse($a, "missing: $first");
        $this->assertNotFalse($b, "missing: $second");
        $this->assertLessThan($b, $a, "\"$first\" must come before \"$second\"");
    }

    // ── the seeder cannot hurt real data ─────────────────────────────────────
    public function testItNeverRunsOnDeployOrFromABrowser(): void
    {
        $this->assertStringNotContainsString('seed_wise_wallet_demo', self::$migrate);
        $this->before("PHP_SAPI !== 'cli'", 'includes/config.php', self::$seeder);
    }

    public function testTheProductionGuardRunsBeforeAnythingIsWritten(): void
    {
        foreach (['TRUNCATE TABLE', 'REPLACE INTO group_settings', 'INSERT INTO users'] as $write) {
            $this->before('vk_demo_seed_guard($pdo, $argv);', $write, self::$seeder);
        }
    }

    public function testItRefusesADatabaseThatAlreadyHasPeople(): void
    {
        $this->assertStringContainsString('if ($people > 0 && !$fresh) {', self::$seeder);
        $this->before('if ($people > 0 && !$fresh) {', 'TRUNCATE TABLE', self::$seeder);
    }

    public function testFreshOnlyWipesADatabaseThisSeederCreated(): void
    {
        $this->assertStringContainsString('if ($fresh && $people > 0 && $groupName !== WW_GROUP_NAME) {', self::$seeder);
        $this->before('$groupName !== WW_GROUP_NAME', 'TRUNCATE TABLE', self::$seeder);
    }

    public function testTheGuardStillKnowsTheRealGroupAndDatabase(): void
    {
        require_once __DIR__ . '/../../database/demo_seed_guard.php';
        $this->assertNotNull(vk_demo_seed_block_reason('bjptechn_vikundi', '', true));
        $this->assertNotNull(vk_demo_seed_block_reason('bjptechn_wisewallet', 'UKUU Msakuzi', true));
        // The new demo database has no "demo" in its name, so it needs the explicit flag.
        $this->assertNotNull(vk_demo_seed_block_reason('bjptechn_wisewallet', '', false));
        $this->assertNull(vk_demo_seed_block_reason('bjptechn_wisewallet', '', true));
        $this->assertNull(vk_demo_seed_block_reason('bjptechn_wisewallet', 'Wise Wallet M-KOBA', true));
    }

    // ── nothing in it can reach a real person ────────────────────────────────
    public function testContactDetailsAreUnreachable(): void
    {
        $this->assertStringContainsString("'@wisewallet.example'", self::$seeder);   // reserved TLD
        $this->assertStringContainsString("'2557000001'", self::$seeder);            // invented block
        $this->assertDoesNotMatchRegularExpression('/@(gmail|yahoo|outlook|hotmail)\./i', self::$seeder);
    }

    public function testTheDemoPasswordIsNotFixedOrGuessable(): void
    {
        $this->assertStringContainsString('random_bytes(', self::$seeder);
        $this->assertStringContainsString('strlen($password) < 8', self::$seeder);
        $this->assertStringNotContainsString("'@123'", self::$seeder);
        // Validated before anything is wiped.
        $this->before('strlen($password) < 8', 'TRUNCATE TABLE', self::$seeder);
    }

    // ── the data is what the application itself would produce ────────────────
    public function testLoansGoThroughTheRealWorkflow(): void
    {
        foreach (['vl_apply(', 'vl_respond_guarantee(', 'vl_decide(', 'vl_disburse(', 'vl_record_payment('] as $step) {
            $this->assertStringContainsString($step, self::$seeder);
        }
        $this->assertStringNotContainsString('INSERT INTO vicoba_loans', self::$seeder);
        $this->assertStringNotContainsString('INSERT INTO vicoba_loan_installments', self::$seeder);
    }

    public function testTheStatementFileUsesTheRealMkobaColumns(): void
    {
        $this->assertStringContainsString(
            "['NO', 'TRANS_ID', 'RECEIPT', 'DATE', 'MEMBER NAME', 'MEMBER ID', 'SOURCE', 'DESTINATION', 'AMOUNT', 'TRANS TYPE']",
            self::$seeder
        );
        // ...and the importer reads exactly those headers.
        require_once __DIR__ . '/../../includes/transaction_import.php';
        $parsed = mkoba_parse_row([
            'no' => '2', 'trans_id' => 'DBS2N6S4DVM', 'receipt' => 'DBS2N6S4DVM', 'date' => '10/10/2026 08:07',
            'member name' => 'SALIM JUMA', 'member id' => '255700000104', 'source' => '255700000104',
            'destination' => '70000000999', 'amount' => '20,000.00', 'trans type' => 'Member Contribution',
        ]);
        $this->assertSame('700000104', $parsed['phone']);
        $this->assertSame(20000.0, $parsed['amount']);
        $this->assertSame('2026-10-10', $parsed['date']);
        $this->assertNull(mkoba_parse_row(['trans type' => 'Group Transfer', 'member id' => '', 'amount' => '250,000.00']));
        $this->assertNull(mkoba_parse_row(['trans type' => 'Opening an account on cbs', 'member id' => '', 'amount' => '']));
    }

    // ── a brand-new database is usable by the officers ───────────────────────
    public function testPermissionKeysAreRegisteredBeforeRolesAreSeeded(): void
    {
        foreach (['add_core_page_permissions.php', 'add_loan_permissions.php', 'create_vicoba_dividend_tables.php'] as $m) {
            $this->before("'$m'", "'seed_vicoba_roles.php'", self::$migrate);
        }
    }

    public function testTheLoanKeysAreCreatedAndGrantedToLeadershipOnly(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/add_loan_permissions.php');
        $this->assertStringContainsString("'approve_loan'", $src);
        $this->assertStringContainsString("'disburse_loan'", $src);
        $this->assertStringNotContainsString("'member'", strtolower($src));

        require_once __DIR__ . '/../../includes/role_grants.php';
        foreach (['approve_loan', 'disburse_loan'] as $key) {
            $this->assertNull(vk_role_grants('view', $key), "a Member must not be granted $key");
            $this->assertSame([1, 1, 1, 1, 1, 1], vk_role_grants('operational', $key));
        }
    }

    public function testTheCorePageKeysMatchWhatExistingInstallsHave(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/add_core_page_permissions.php');
        foreach (['customers', 'expenses', 'document_library', 'document_templates', 'document_workflow', 'e_signatures',
                  'message_center', 'notification_center', 'system_settings', 'users', 'user_roles'] as $key) {
            $this->assertStringContainsString("'$key'", $src);
        }
        // It registers keys only; who gets them is the role seeder's decision.
        $this->assertStringNotContainsString('role_permissions', $src);
        // Sensitive keys no existing install has are not introduced here.
        foreach (['backup_restore', 'audit_logs'] as $never) {
            $this->assertStringNotContainsString("'$never'", $src);
        }

        require_once __DIR__ . '/../../includes/role_grants.php';
        foreach (['system_settings', 'users', 'user_roles'] as $adminOnly) {
            $this->assertNull(vk_role_grants('view', $adminOnly));
            $this->assertNull(vk_role_grants('operational', $adminOnly));
        }
    }
}
